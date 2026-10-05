<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_assignbulkedit\local;

use assign;
use context_course;
use core_courseformat\formatactions;
use stdClass;

/**
 * Create several assignments at once, each a copy of a model assignment with its own name, section and dates.
 *
 * Copies are made with Moodle's own Duplicate (a backup and restore of the
 * model without user data), so every setting, the description and its files,
 * advanced grading, restrictions and completion settings carry over; student
 * submissions, grades, extensions and overrides do not.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class creator {
    /** @var string[] Date fields of a row. */
    public const DATEFIELDS = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate'];

    /** @var string[] Capabilities core's Duplicate needs in the course. */
    public const CAPABILITIES = [
        'moodle/course:manageactivities',
        'moodle/backup:backuptargetimport',
        'moodle/restore:restoretargetimport',
    ];

    /**
     * Whether the current user may create assignments by copying in this course.
     *
     * @param stdClass $course
     * @return bool
     */
    public static function can_create(stdClass $course): bool {
        $context = context_course::instance($course->id);
        return has_all_capabilities(self::CAPABILITIES, $context) && has_capability('mod/assign:addinstance', $context);
    }

    /**
     * The course's sections, for the section drop-down.
     *
     * @param stdClass $course
     * @return array section number => name
     */
    public static function sections(stdClass $course): array {
        $sections = [];
        foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
            if ($section->is_delegated()) {
                continue;
            }
            $sections[$section->section] = get_section_name($course, $section);
        }
        return $sections;
    }

    /**
     * Read and check the submitted rows.
     *
     * Rows left completely empty are ignored.
     *
     * @param stdClass $course
     * @param array $input row index => field => submitted string (name, section and the date fields)
     * @return array [rows (index => stdClass with name, section and dates as timestamps), errors (index => field => message)]
     */
    public static function parse_rows(stdClass $course, array $input): array {
        $sections = self::sections($course);
        $rows = [];
        $errors = [];
        foreach ($input as $i => $fields) {
            $fields = array_map(fn($value) => trim((string) $value), (array) $fields);
            $filled = array_filter(array_intersect_key($fields, array_flip(['name', ...self::DATEFIELDS])), 'strlen');
            if (!$filled) {
                continue;
            }
            $row = new stdClass();
            $name = updater::parse_value('name', $fields['name'] ?? '');
            if ($name === null) {
                $errors[$i]['name'] = get_string('errorname', 'local_assignbulkedit');
            }
            $row->name = $name;
            $section = $fields['section'] ?? '';
            if (!preg_match('/^\d+$/', $section) || !isset($sections[(int) $section])) {
                $errors[$i]['section'] = get_string('errorchoice', 'local_assignbulkedit');
            }
            $row->section = (int) $section;
            foreach (self::DATEFIELDS as $field) {
                $value = updater::parse_value($field, $fields[$field] ?? '');
                if ($value === null) {
                    $errors[$i][$field] = get_string('errordate', 'local_assignbulkedit');
                }
                $row->$field = (int) $value;
            }
            if (empty($errors[$i])) {
                $error = self::date_error($row);
                if ($error) {
                    $errors[$i][$error[0]] = $error[1];
                }
            }
            $rows[$i] = $row;
        }
        return [$rows, $errors];
    }

    /**
     * The assignment settings form's rules on these three dates.
     *
     * @param stdClass $row
     * @return array|null [field, message], or null if fine
     */
    protected static function date_error(stdClass $row): ?array {
        $from = $row->allowsubmissionsfromdate;
        $due = $row->duedate;
        $cutoff = $row->cutoffdate;
        if ($from && $due && $due <= $from) {
            return ['duedate', get_string('duedateaftersubmissionvalidation', 'assign')];
        }
        if ($cutoff && $due && $cutoff < $due) {
            return ['cutoffdate', get_string('cutoffdatevalidation', 'assign')];
        }
        if ($from && $cutoff && $cutoff < $from) {
            return ['cutoffdate', get_string('cutoffdatefromdatevalidation', 'assign')];
        }
        return null;
    }

    /**
     * The "remind me to grade by" date of a copy: as far after its due date as the model's is after the model's.
     *
     * @param stdClass $model assign record
     * @param int $duedate the copy's due date
     * @return int 0 for none
     */
    public static function grading_due_date(stdClass $model, int $duedate): int {
        if (!$model->gradingduedate || !$model->duedate || !$duedate) {
            return 0;
        }
        return $duedate + max(0, $model->gradingduedate - $model->duedate);
    }

    /**
     * Create the assignments.
     *
     * Rows are created one by one (each copy is a backup and restore, which
     * can't share one database transaction), so if one fails the earlier ones
     * stay; the exception says which row failed.
     *
     * @param stdClass $course
     * @param int $modelid assign id of the model assignment
     * @param array $rows from parse_rows(), without errors
     * @param bool $hidden create them hidden from students
     * @return int[] course module ids of the new assignments
     */
    public static function create(stdClass $course, int $modelid, array $rows, bool $hidden): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_capability('mod/assign:addinstance', context_course::instance($course->id));
        foreach (self::CAPABILITIES as $capability) {
            require_capability($capability, context_course::instance($course->id));
        }
        $model = $DB->get_record('assign', ['id' => $modelid, 'course' => $course->id], '*', MUST_EXIST);
        $modelcm = get_fast_modinfo($course)->get_instances_of('assign')[$modelid] ?? null;
        if (!$modelcm || !has_capability('moodle/course:manageactivities', $modelcm->context)) {
            throw new \required_capability_exception(
                context_course::instance($course->id),
                'moodle/course:manageactivities',
                'nopermissions',
                ''
            );
        }
        \core_php_time_limit::raise(max(300, 30 * count($rows)));

        $actions = formatactions::cm($course->id);
        $cmids = [];
        foreach ($rows as $row) {
            $sectionid = get_fast_modinfo($course)->get_section_info($row->section, MUST_EXIST)->id;
            $newcm = $actions->duplicate($modelcm->id, $sectionid, $row->name);
            if (!$newcm) {
                throw new \moodle_exception('errorcreate', 'local_assignbulkedit', '', s($row->name));
            }
            // Duplicate places a copy in the model's section right after it: keep the new ones in row order instead.
            $actions->move_end_section($newcm->id, $sectionid);

            $DB->update_record('assign', (object) [
                'id' => $newcm->instance,
                'allowsubmissionsfromdate' => $row->allowsubmissionsfromdate,
                'duedate' => $row->duedate,
                'cutoffdate' => $row->cutoffdate,
                'gradingduedate' => self::grading_due_date($model, $row->duedate),
                'timemodified' => time(),
            ]);
            $actions->set_visibility($newcm->id, $hidden ? 0 : 1);

            rebuild_course_cache($course->id, true);
            $cm = get_fast_modinfo($course)->get_cm($newcm->id);
            assign_prepare_update_events($DB->get_record('assign', ['id' => $cm->instance]), $course, $cm);
            (new assign($cm->context, $cm, $course))->update_gradebook(false, $cm->id);
            \core\event\course_module_updated::create_from_cm($cm, $cm->context)->trigger();
            $cmids[] = $cm->id;
        }
        return $cmids;
    }
}
