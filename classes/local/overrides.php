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
use cache;
use context_module;
use mod_assign\override_manager;
use stdClass;

/**
 * Plan and save extensions and user/group overrides on many assignments at once.
 *
 * Extensions are saved with assign::save_user_extension(), as on the
 * assignment's own Grant extension page. Overrides are saved through
 * mod_assign's override_manager (Moodle 5.3+), or on Moodle 5.2 the way its
 * overrideedit.php does it. Either way a save replaces all of an override's
 * settings, so new values are merged into any existing override first.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides {
    /** @var string[] Settings an override can change. */
    public const SETTINGS = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'timelimit'];

    /** @var string[] Actions on extensions rather than overrides. */
    public const EXTENSIONACTIONS = ['extend', 'unextend'];

    /**
     * Whether assignment time limits are turned on for the site.
     *
     * @return bool
     */
    public static function timelimit_enabled(): bool {
        return (bool) get_config('assign', 'enabletimelimit');
    }

    /**
     * The assignments in a course whose extensions or overrides the current user may manage, in course order.
     *
     * @param stdClass $course
     * @return array assign id => ['cm' => cm_info, 'assign' => stdClass (full assign record)]
     */
    public static function get_assignments(stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $modinfo = get_fast_modinfo($course);
        $cms = array_filter(
            $modinfo->get_instances_of('assign'),
            fn($cm) => has_any_capability(['mod/assign:manageoverrides', 'mod/assign:grantextension'], $cm->context)
        );
        if (!$cms) {
            return [];
        }
        $records = $DB->get_records_list('assign', 'id', array_keys($cms));
        $order = array_flip(array_keys($modinfo->get_cms()));
        uasort($cms, fn($a, $b) => $order[$a->id] <=> $order[$b->id]);
        $assignments = [];
        foreach ($cms as $assignid => $cm) {
            $assignments[$assignid] = ['cm' => $cm, 'assign' => $records[$assignid]];
        }
        return $assignments;
    }

    /**
     * Work out what to do for each student/group and assignment.
     *
     * @param stdClass $course
     * @param array $assignments the chosen assignments, from get_assignments()
     * @param array $targets list of ['userid' => id] or ['groupid' => id], each with a 'name';
     *     extensions are per student, so pass students only for extension actions
     * @param stdClass $data form data (action, *mode fields and values, reason)
     * @return array rows of [assignid, target, existing, values (array|null), action, note] where action is
     *     'create', 'update', 'delete' or 'skip', and existing is the override record (or null), or for extension
     *     actions the current extension date (0 if none)
     */
    public static function plan(stdClass $course, array $assignments, array $targets, stdClass $data): array {
        global $DB;
        $extension = in_array($data->action, self::EXTENSIONACTIONS);
        $rows = [];
        foreach ($assignments as $assignid => ['cm' => $cm, 'assign' => $assign]) {
            $cap = $extension ? 'mod/assign:grantextension' : 'mod/assign:manageoverrides';
            $allowed = has_capability($cap, $cm->context);
            $instance = $extension && $allowed ? new assign($cm->context, $cm, $course) : null;
            foreach ($targets as $target) {
                $row = ['assignid' => $assignid, 'target' => $target, 'values' => null, 'note' => ''];
                if ($extension) {
                    $row['existing'] = (int) $DB->get_field(
                        'assign_user_flags',
                        'extensionduedate',
                        ['assignment' => $assignid, 'userid' => $target['userid']]
                    );
                } else {
                    $who = isset($target['userid']) ? ['userid' => $target['userid']] : ['groupid' => $target['groupid']];
                    $row['existing'] = $DB->get_record('assign_overrides', ['assignid' => $assignid] + $who) ?: null;
                }
                if (!$allowed) {
                    $row['action'] = 'skip';
                    $row['note'] = get_string('ovnopermission', 'local_assignbulkedit');
                } else if ($extension) {
                    self::plan_extension($row, $instance, $data);
                } else if ($data->action === 'delete') {
                    $row['action'] = $row['existing'] ? 'delete' : 'skip';
                    $row['note'] = $row['existing'] ? '' : get_string('ovnooverride', 'local_assignbulkedit');
                } else {
                    self::plan_override($row, $assign, $cm, $data);
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Plan one student's extension on one assignment.
     *
     * @param array $row plan row, filled in
     * @param assign $assign
     * @param stdClass $data form data
     */
    protected static function plan_extension(array &$row, assign $assign, stdClass $data): void {
        $userid = $row['target']['userid'];
        $current = $row['existing'];
        if ($data->action === 'unextend') {
            $row['action'] = $current ? 'delete' : 'skip';
            $row['note'] = $current ? '' : get_string('ovnoextension', 'local_assignbulkedit');
            return;
        }
        // The student's own dates, after any override.
        $effective = $assign->get_instance($userid);
        $base = $assign->get_instance();
        if ($data->extensionmode === 'add') {
            if (!$effective->duedate) {
                $row['action'] = 'skip';
                $row['note'] = get_string('ovnodue', 'local_assignbulkedit');
                return;
            }
            $date = (int) ($effective->duedate + $data->extensionadd);
        } else {
            $date = (int) $data->extensiondate;
        }
        // The rules of assign::save_user_extension(), which checks the assignment's own dates.
        if ($base->duedate && $date < $base->duedate) {
            $row['action'] = 'skip';
            $row['note'] = get_string('extensionnotafterduedate', 'assign');
        } else if ($base->allowsubmissionsfromdate && $date < $base->allowsubmissionsfromdate) {
            $row['action'] = 'skip';
            $row['note'] = get_string('extensionnotafterfromdate', 'assign');
        } else if ($date === $current) {
            $row['action'] = 'skip';
            $row['note'] = get_string('ovnochange', 'local_assignbulkedit');
        } else {
            $row['action'] = $current ? 'update' : 'create';
            $row['values'] = ['extensionduedate' => $date];
        }
    }

    /**
     * Plan one override on one assignment.
     *
     * @param array $row plan row, filled in
     * @param stdClass $assign
     * @param \cm_info $cm
     * @param stdClass $data form data
     */
    protected static function plan_override(array &$row, stdClass $assign, \cm_info $cm, stdClass $data): void {
        $existing = $row['existing'];
        [$values, $notes] = self::new_values($assign, $existing, $data);
        // Values equal to the assignment's own settings are dropped when saving.
        // The reason is only a note, so on its own it doesn't call for an override.
        $effective = array_filter(
            array_intersect_key($values, array_flip(self::SETTINGS)),
            fn($value, $key) => $value !== null && $value != $assign->$key,
            ARRAY_FILTER_USE_BOTH
        );
        $unchanged = $existing && !array_filter(
            self::SETTINGS,
            fn($key) => (string) ($existing->$key ?? '') !== (string) ($values[$key] ?? '')
        );
        $reasonchanged = $existing && isset($values['reason']) && $values['reason'] !== (string) $existing->reason;

        if (!$effective) {
            $row['action'] = 'skip';
            // A more specific note (e.g. no due date to extend) already explains it.
            $notes = $notes ?: [get_string('ovsameasassign', 'local_assignbulkedit')];
        } else if ($unchanged && !$reasonchanged) {
            $row['action'] = 'skip';
            $notes[] = get_string('ovnochange', 'local_assignbulkedit');
        } else {
            $error = self::validate($assign, $cm, $row['target'], $values, $existing);
            if ($error !== null) {
                $row['action'] = 'skip';
                $notes[] = $error;
            } else {
                $row['action'] = $existing ? 'update' : 'create';
                $row['values'] = $values;
            }
        }
        $row['note'] = implode(' ', $notes);
    }

    /**
     * Check an override's dates, as mod_assign does when saving one.
     *
     * The dates are checked as the student will have them, i.e. with the
     * assignment's own date wherever the override doesn't set one.
     *
     * @param stdClass $assign
     * @param \cm_info $cm
     * @param array $target ['userid' => id] or ['groupid' => id]
     * @param array $values override settings
     * @param stdClass|null $existing existing override
     * @return string|null error message, or null if fine
     */
    protected static function validate(
        stdClass $assign,
        \cm_info $cm,
        array $target,
        array $values,
        ?stdClass $existing
    ): ?string {
        global $DB;
        $from = $values['allowsubmissionsfromdate'] ?? $assign->allowsubmissionsfromdate;
        $due = $values['duedate'] ?? $assign->duedate;
        $cutoff = $values['cutoffdate'] ?? $assign->cutoffdate;
        if ($from && $due && $due <= $from) {
            return get_string('duedateaftersubmissionvalidation', 'assign');
        }
        if ($cutoff && $due && $cutoff < $due) {
            return get_string('cutoffdatevalidation', 'assign');
        }
        if ($from && $cutoff && $cutoff < $from) {
            return get_string('cutoffdatefromdatevalidation', 'assign');
        }
        // An extension must stay after the dates the override gives.
        if (isset($target['userid'])) {
            $extension = (int) $DB->get_field(
                'assign_user_flags',
                'extensionduedate',
                ['assignment' => $assign->id, 'userid' => $target['userid']]
            );
        } else {
            $members = array_keys(groups_get_members($target['groupid'], 'u.id'));
            $extension = 0;
            if ($members) {
                [$insql, $params] = $DB->get_in_or_equal($members);
                $extension = (int) $DB->get_field_sql(
                    "SELECT MAX(extensionduedate) FROM {assign_user_flags} WHERE assignment = ? AND userid $insql",
                    array_merge([$assign->id], $params)
                );
            }
        }
        if ($extension && !empty($values['duedate']) && $extension < $values['duedate']) {
            return get_string('extensionnotafterduedate', 'assign');
        }
        if ($extension && !empty($values['allowsubmissionsfromdate']) && $extension < $values['allowsubmissionsfromdate']) {
            return get_string('extensionnotafterfromdate', 'assign');
        }
        // Anything else Moodle 5.3+ itself would refuse, so that saving can't fail half way.
        if (class_exists(override_manager::class)) {
            $manager = new override_manager($assign, $cm->context);
            $errors = $manager->validate_data(self::form_data($target, $values, $existing));
            if ($errors) {
                return implode(' ', $errors);
            }
        }
        return null;
    }

    /**
     * The override settings after applying the form's changes on top of any existing override.
     *
     * @param stdClass $assign
     * @param stdClass|null $existing existing override
     * @param stdClass $data form data
     * @return array [settings => value|null (plus reason when given), notes]
     */
    protected static function new_values(stdClass $assign, ?stdClass $existing, stdClass $data): array {
        $values = [];
        foreach (self::SETTINGS as $key) {
            $values[$key] = isset($existing->$key) ? (int) $existing->$key : null;
        }
        $notes = [];

        if (($data->allowsubmissionsfromdatemode ?? 'none') === 'set') {
            $values['allowsubmissionsfromdate'] = (int) $data->allowsubmissionsfromdate;
        }
        foreach (['duedate' => 'ovnodue', 'cutoffdate' => 'ovnocutoff'] as $key => $nonestring) {
            switch ($data->{$key . 'mode'} ?? 'none') {
                case 'set':
                    $values[$key] = (int) $data->$key;
                    break;
                case 'add':
                    if (!$assign->$key) {
                        $notes[] = get_string($nonestring, 'local_assignbulkedit');
                        break;
                    }
                    $values[$key] = (int) ($assign->$key + $data->{$key . 'add'});
                    break;
            }
        }
        switch ($data->timelimitmode ?? 'none') {
            case 'multiply':
            case 'add':
                if (!$assign->timelimit) {
                    $notes[] = get_string('ovnotimelimit', 'local_assignbulkedit');
                    break;
                }
                $values['timelimit'] = $data->timelimitmode === 'multiply' ?
                    (int) round($assign->timelimit * $data->timelimitfactor) :
                    (int) ($assign->timelimit + $data->timelimitadd);
                break;
            case 'set':
                $values['timelimit'] = (int) $data->timelimit;
                break;
        }
        if (trim($data->reason ?? '') !== '') {
            $values['reason'] = trim($data->reason);
        }
        return [$values, $notes];
    }

    /**
     * Override data in the shape mod_assign's override form gives.
     *
     * @param array $target ['userid' => id] or ['groupid' => id]
     * @param array $values override settings (and reason)
     * @param stdClass|null $existing existing override
     * @return array
     */
    protected static function form_data(array $target, array $values, ?stdClass $existing): array {
        $formdata = $values + array_intersect_key($target, ['userid' => 1, 'groupid' => 1]);
        if (isset($formdata['reason'])) {
            $formdata['reasonformat'] = FORMAT_PLAIN;
        }
        if ($existing) {
            $formdata['id'] = (int) $existing->id;
        }
        return $formdata;
    }

    /**
     * Carry out a plan.
     *
     * @param stdClass $course
     * @param array $assignments from get_assignments()
     * @param array $rows from plan()
     * @param string $action the form's action
     * @return array [saved count, removed count]
     */
    public static function apply(stdClass $course, array $assignments, array $rows, string $action): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $saved = 0;
        $removed = 0;
        // All or nothing.
        $transaction = $DB->start_delegated_transaction();
        foreach ($rows as $row) {
            if ($row['action'] === 'skip') {
                continue;
            }
            ['cm' => $cm, 'assign' => $record] = $assignments[$row['assignid']];
            $context = context_module::instance($cm->id);
            if (in_array($action, self::EXTENSIONACTIONS)) {
                $assign = new assign($context, $cm, $course);
                $date = $row['action'] === 'delete' ? 0 : $row['values']['extensionduedate'];
                if (!$assign->save_user_extension($row['target']['userid'], $date)) {
                    throw new \moodle_exception('extensionnotsaved', 'local_assignbulkedit', '', $row['target']['name']);
                }
            } else {
                require_capability('mod/assign:manageoverrides', $context);
                if ($row['action'] === 'delete') {
                    self::delete_override($course, $cm, $record, $row['existing']);
                } else {
                    self::save_override(
                        $course,
                        $cm,
                        $record,
                        self::form_data($row['target'], $row['values'], $row['existing'])
                    );
                }
            }
            if ($row['action'] === 'delete') {
                $removed++;
            } else {
                $saved++;
            }
        }
        $transaction->allow_commit();
        return [$saved, $removed];
    }

    /**
     * Save one override.
     *
     * @param stdClass $course
     * @param \cm_info $cm
     * @param stdClass $record assign record
     * @param array $formdata from form_data()
     */
    protected static function save_override(stdClass $course, \cm_info $cm, stdClass $record, array $formdata): void {
        global $DB;
        if (class_exists(override_manager::class)) {
            (new override_manager($record, $cm->context))->save_overrides([$formdata]);
            return;
        }

        // Moodle 5.2: as mod/assign/overrideedit.php.
        $override = (object) $formdata;
        $override->assignid = $record->id;
        foreach (self::SETTINGS as $key) {
            if (!isset($override->$key) || $override->$key == $record->$key) {
                $override->$key = null;
            }
        }
        $groupmode = !empty($override->groupid);
        $params = ['context' => $cm->context, 'other' => ['assignid' => $record->id]];
        if (!empty($override->id)) {
            $DB->update_record('assign_overrides', $override);
            $params['objectid'] = $override->id;
            $created = false;
        } else {
            $override->id = $DB->insert_record('assign_overrides', $override);
            if ($groupmode) {
                $countgroup = $DB->count_records('assign_overrides', ['userid' => null, 'assignid' => $record->id]);
                $countall = $DB->count_records('assign_overrides', ['assignid' => $record->id]);
                $override->sortorder = (!$countgroup && $countall) ? 1 : $countgroup;
                $DB->update_record('assign_overrides', $override);
                reorder_group_overrides($record->id);
            }
            $params['objectid'] = $override->id;
            $created = true;
        }
        $cachekey = $groupmode ? "{$record->id}_g_{$override->groupid}" : "{$record->id}_u_{$override->userid}";
        cache::make('mod_assign', 'overrides')->delete($cachekey);
        if ($groupmode) {
            $params['other']['groupid'] = $override->groupid;
            $event = $created ? \mod_assign\event\group_override_created::create($params) :
                \mod_assign\event\group_override_updated::create($params);
        } else {
            $params['relateduserid'] = $override->userid;
            $event = $created ? \mod_assign\event\user_override_created::create($params) :
                \mod_assign\event\user_override_updated::create($params);
        }
        $event->trigger();
        if ($groupmode && !isset($override->sortorder)) {
            // Keep the calendar event's priority, as Moodle 5.3 does.
            $override->sortorder = $DB->get_field('assign_overrides', 'sortorder', ['id' => $override->id]);
        }
        assign_update_events(new assign($cm->context, $cm, $course), $override);
    }

    /**
     * Remove one override.
     *
     * @param stdClass $course
     * @param \cm_info $cm
     * @param stdClass $record assign record
     * @param stdClass $override
     */
    protected static function delete_override(stdClass $course, \cm_info $cm, stdClass $record, stdClass $override): void {
        if (class_exists(override_manager::class)) {
            (new override_manager($record, $cm->context))->delete_overrides_by_id([$override->id]);
        } else {
            (new assign($cm->context, $cm, $course))->delete_override($override->id);
        }
    }

    /**
     * A one-line description of override settings, e.g. "Due: 3 October 2026, 5:00 PM; Time limit: 1 hour".
     *
     * @param array|stdClass|null $values settings, null values meaning "as the assignment"
     * @return string
     */
    public static function describe($values): string {
        $values = (array) $values;
        $parts = [];
        foreach ([...self::SETTINGS, 'extensionduedate'] as $key) {
            if (!isset($values[$key])) {
                continue;
            }
            $value = $values[$key];
            if ($key === 'timelimit') {
                $shown = $value ? format_time($value) : get_string('ovnone', 'local_assignbulkedit');
            } else {
                $shown = $value ? userdate($value) : get_string('ovnone', 'local_assignbulkedit');
            }
            $parts[] = get_string('ov' . $key, 'local_assignbulkedit') . ': ' . $shown;
        }
        return $parts ? implode('; ', $parts) : '–';
    }
}
