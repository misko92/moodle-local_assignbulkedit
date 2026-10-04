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
use cm_info;
use context_module;
use core_courseformat\formatactions;
use core_date;
use DateTime;
use grade_item;
use stdClass;

/**
 * Reads, validates and saves the assignment settings edited on the bulk page.
 *
 * Settings are written directly rather than through assign::update_instance(),
 * which expects the full settings form: given a partial record it disables
 * every submission and feedback type not mentioned and resets other settings.
 * Assignment fields go to the assign table; submission and feedback type
 * settings go through each plugin's own enable()/disable()/set_config(). The
 * follow-up work update_instance() would do is reproduced: calendar events
 * (including overrides), gradebook, the nosubmissions cache, the course
 * cache and the course_module_updated log event.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class updater {
    /** @var array Table columns by group, in display order. */
    public const COLUMNGROUPS = [
        'general' => ['name', 'visible'],
        'availability' => ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate'],
        'submissiontypes' => ['onlinetext', 'wordlimit', 'file', 'maxfiles', 'maxsizebytes', 'filetypes'],
        'feedbacktypes' => ['fbcomments', 'fbfile', 'fbeditpdf', 'fboffline'],
        'submissionsettings' => ['submissiondrafts', 'requiresubmissionstatement', 'maxattempts', 'attemptreopenmethod'],
        'grade' => ['grade', 'gradepass', 'blindmarking', 'hidegrader', 'markingworkflow', 'markingallocation'],
    ];

    /** @var string[] Columns shown until the user picks their own. */
    public const DEFAULTCOLUMNS = [
        'visible', 'allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'onlinetext', 'file', 'maxfiles', 'grade',
    ];

    /** @var string[] Editable columns of the assign table. */
    public const ASSIGNFIELDS = [
        'allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate', 'submissiondrafts',
        'requiresubmissionstatement', 'maxattempts', 'attemptreopenmethod', 'grade', 'blindmarking', 'hidegrader',
        'markingworkflow', 'markingallocation',
    ];

    /** @var array Submission/feedback plugin fields: field => [subtype, plugin, config name or 'enabled']. */
    public const PLUGINFIELDS = [
        'onlinetext' => ['assignsubmission', 'onlinetext', 'enabled'],
        'wordlimit' => ['assignsubmission', 'onlinetext', 'wordlimit'],
        'file' => ['assignsubmission', 'file', 'enabled'],
        'maxfiles' => ['assignsubmission', 'file', 'maxfilesubmissions'],
        'maxsizebytes' => ['assignsubmission', 'file', 'maxsubmissionsizebytes'],
        'filetypes' => ['assignsubmission', 'file', 'filetypeslist'],
        'fbcomments' => ['assignfeedback', 'comments', 'enabled'],
        'fbfile' => ['assignfeedback', 'file', 'enabled'],
        'fbeditpdf' => ['assignfeedback', 'editpdf', 'enabled'],
        'fboffline' => ['assignfeedback', 'offline', 'enabled'],
    ];

    /** @var string[] Fields saved through their own core API. */
    public const SPECIALFIELDS = ['name', 'visible', 'gradepass'];

    /** @var string[] All editable fields. */
    public const FIELDS = [...self::SPECIALFIELDS, ...self::ASSIGNFIELDS, 'onlinetext', 'wordlimit', 'file', 'maxfiles',
        'maxsizebytes', 'filetypes', 'fbcomments', 'fbfile', 'fbeditpdf', 'fboffline'];

    /** @var string[] Date fields. */
    public const DATEFIELDS = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate'];

    /** @var string[] Yes/no fields. */
    protected const YESNO = ['onlinetext', 'file', 'fbcomments', 'fbfile', 'fbeditpdf', 'fboffline', 'submissiondrafts',
        'requiresubmissionstatement', 'blindmarking', 'hidegrader', 'markingworkflow', 'markingallocation', 'visible'];

    /** @var string[] Fields the settings form locks once an assignment has submissions or grades. */
    public const LOCKEDWITHSUBMISSIONS = ['submissiondrafts', 'blindmarking'];

    /**
     * Whether a submission or feedback plugin is installed and enabled on this site.
     *
     * @param string $subtype assignsubmission or assignfeedback
     * @param string $plugin
     * @return bool
     */
    public static function plugin_available(string $subtype, string $plugin): bool {
        $info = \core_plugin_manager::instance()->get_plugin_info("{$subtype}_{$plugin}");
        return $info && $info->is_enabled() !== false;
    }

    /**
     * The table columns, in display order, leaving out unavailable plugins.
     *
     * @return string[]
     */
    public static function columns(): array {
        return array_merge(...array_values(self::column_groups()));
    }

    /**
     * The table columns by group, leaving out unavailable plugins.
     *
     * @return array group => column names
     */
    public static function column_groups(): array {
        $groups = [];
        foreach (self::COLUMNGROUPS as $group => $columns) {
            $groups[$group] = array_values(array_filter($columns, function ($field) {
                if (!isset(self::PLUGINFIELDS[$field])) {
                    return true;
                }
                [$subtype, $plugin] = self::PLUGINFIELDS[$field];
                return self::plugin_available($subtype, $plugin);
            }));
        }
        return array_filter($groups);
    }

    /**
     * The assignments in a course the current user may edit, in course order.
     *
     * Each record also carries visible (from the course module), every plugin
     * field, gradepass, hassubmissions (submissions or grades exist, which
     * locks some settings) and hasgrades.
     *
     * @param stdClass $course
     * @return array assign id => ['cm' => cm_info, 'assign' => stdClass]
     */
    public static function get_assignments(stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $modinfo = get_fast_modinfo($course);
        $cms = [];
        foreach ($modinfo->get_instances_of('assign') as $cm) {
            if (has_capability('moodle/course:manageactivities', $cm->context)) {
                $cms[$cm->instance] = $cm;
            }
        }
        if (!$cms) {
            return [];
        }
        $records = $DB->get_records_list(
            'assign',
            'id',
            array_keys($cms),
            '',
            '*'
        );
        [$insql, $params] = $DB->get_in_or_equal(array_keys($cms));
        $configs = $DB->get_recordset_select(
            'assign_plugin_config',
            "assignment $insql",
            $params,
            '',
            'id, assignment, subtype, plugin, name, value'
        );
        $config = [];
        foreach ($configs as $c) {
            $config[$c->assignment]["{$c->subtype}/{$c->plugin}/{$c->name}"] = $c->value;
        }
        $configs->close();
        $gradepasses = $DB->get_records_menu('grade_items', [
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign', 'itemnumber' => 0,
        ], '', 'iteminstance, gradepass');

        $order = array_flip(array_keys($modinfo->get_cms()));
        uasort($cms, fn(cm_info $a, cm_info $b) => $order[$a->id] <=> $order[$b->id]);

        $assignments = [];
        foreach ($cms as $assignid => $cm) {
            if (!isset($records[$assignid])) {
                continue;
            }
            $a = $records[$assignid];
            $a->visible = (int) $cm->visible;
            foreach (self::PLUGINFIELDS as $field => [$subtype, $plugin, $name]) {
                $a->$field = $config[$assignid]["$subtype/$plugin/$name"] ?? self::plugin_default($subtype, $plugin, $name);
            }
            // The word limit only applies when enabled.
            if (empty($config[$assignid]['assignsubmission/onlinetext/wordlimitenabled'])) {
                $a->wordlimit = 0;
            }
            $a->gradepass = (float) ($gradepasses[$assignid] ?? 0);
            $a->hasgradeitem = isset($gradepasses[$assignid]);
            $instance = new assign($cm->context, $cm, $course);
            $a->hasgrades = $instance->count_grades() > 0;
            $a->hassubmissions = $instance->has_submissions_or_grades();
            // Moodle 5.3+ multiple marking depends on the marking workflow and allocation settings.
            $a->multimarking = ($a->markercount ?? 1) + ($a->optionalmarkercount ?? 0) > 1;
            $assignments[$assignid] = ['cm' => $cm, 'assign' => $a];
        }
        return $assignments;
    }

    /**
     * The value a plugin setting has when an assignment hasn't stored one.
     *
     * @param string $subtype
     * @param string $plugin
     * @param string $name
     * @return string
     */
    protected static function plugin_default(string $subtype, string $plugin, string $name): string {
        if ($name === 'enabled') {
            return '0';
        }
        $defaults = [
            'maxfilesubmissions' => get_config('assignsubmission_file', 'maxfiles'),
            'maxsubmissionsizebytes' => get_config('assignsubmission_file', 'maxbytes'),
            'filetypeslist' => get_config('assignsubmission_file', 'filetypes'),
            'wordlimit' => 0,
        ];
        return (string) ($defaults[$name] ?? '');
    }

    /**
     * The allowed values of a drop-down field.
     *
     * @param string $field
     * @param stdClass|null $course needed for the file size limits
     * @return array|null value => label, or null if the field is free input
     */
    public static function choices(string $field, ?stdClass $course = null): ?array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        if ($field === 'visible') {
            return [
                1 => get_string('visible_show', 'local_assignbulkedit'),
                0 => get_string('visible_hide', 'local_assignbulkedit'),
            ];
        }
        if (in_array($field, self::YESNO)) {
            return [0 => get_string('no'), 1 => get_string('yes')];
        }
        switch ($field) {
            case 'maxattempts':
                return [ASSIGN_UNLIMITED_ATTEMPTS => get_string('unlimitedattempts', 'mod_assign')]
                    + array_combine(range(1, 30), range(1, 30));
            case 'attemptreopenmethod':
                $choices = [ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL => get_string('attemptreopenmethod_manual', 'mod_assign')];
                if (defined('ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC')) {
                    $choices[ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC] =
                        get_string('attemptreopenmethod_automatic', 'mod_assign');
                }
                $choices[ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS] = get_string('attemptreopenmethod_untilpass', 'mod_assign');
                return $choices;
            case 'maxfiles':
                $max = (int) get_config('assignsubmission_file', 'maxfiles');
                return array_combine(range(1, max(1, $max)), range(1, max(1, $max)));
            case 'maxsizebytes':
                return get_max_upload_sizes(
                    $CFG->maxbytes,
                    $course->maxbytes ?? 0,
                    get_config('assignsubmission_file', 'maxbytes')
                );
        }
        return null;
    }

    /**
     * Format a stored value as the string shown in the form input.
     *
     * @param string $field
     * @param mixed $value
     * @return string
     */
    public static function format_value(string $field, $value): string {
        if (in_array($field, self::DATEFIELDS)) {
            if (empty($value)) {
                return '';
            }
            $dt = new DateTime('@' . $value);
            $dt->setTimezone(core_date::get_user_timezone_object());
            return $dt->format('Y-m-d\TH:i');
        }
        if (in_array($field, ['grade', 'gradepass'])) {
            return (string) (0 + (float) $value);
        }
        if (in_array($field, ['name', 'filetypes', 'attemptreopenmethod'])) {
            return (string) $value;
        }
        return (string) (int) $value;
    }

    /**
     * Parse a submitted input string into a stored value.
     *
     * @param string $field
     * @param string $input
     * @param stdClass|null $course
     * @return mixed the value, or null if invalid
     */
    public static function parse_value(string $field, string $input, ?stdClass $course = null) {
        $input = trim($input);
        $choices = self::choices($field, $course);
        if ($choices !== null) {
            foreach (array_keys($choices) as $key) {
                if ((string) $key === $input) {
                    return $key;
                }
            }
            return null;
        }
        if (in_array($field, self::DATEFIELDS)) {
            if ($input === '') {
                return 0;
            }
            $dt = DateTime::createFromFormat('!Y-m-d\TH:i', $input, core_date::get_user_timezone_object());
            return ($dt && $dt->format('Y-m-d\TH:i') === $input) ? $dt->getTimestamp() : null;
        }
        switch ($field) {
            case 'name':
                $name = clean_param($input, PARAM_TEXT);
                return ($name === '' || \core_text::strlen($name) > 255) ? null : $name;
            case 'wordlimit':
                if ($input === '') {
                    return 0;
                }
                return preg_match('/^\d+$/', $input) ? (int) $input : null;
            case 'grade':
                return (preg_match('/^\d+$/', $input) && (int) $input >= 1) ? (int) $input : null;
            case 'gradepass':
                if ($input === '') {
                    return 0.0;
                }
                return (is_numeric($input) && $input >= 0) ? (float) $input : null;
            case 'filetypes':
                $util = new \core_form\filetypes_util();
                $types = $util->normalize_file_types($input);
                if ($util->get_unknown_file_types($types)) {
                    return null;
                }
                return implode(',', $types);
        }
        return null;
    }

    /**
     * Work out which fields changed and validate them.
     *
     * A field counts as changed only when the submitted string differs from
     * the string originally sent to the browser.
     *
     * @param array $assignments from get_assignments()
     * @param array $submitted assign id => field => submitted string
     * @param array $original assign id => field => string originally shown
     * @param stdClass|null $course
     * @return array [changes (assign id => field => value), errors (assign id => field => message)]
     */
    public static function collect_changes(
        array $assignments,
        array $submitted,
        array $original,
        ?stdClass $course = null
    ): array {
        $changes = [];
        $errors = [];
        foreach ($submitted as $assignid => $fields) {
            if (!isset($assignments[$assignid]) || !is_array($fields)) {
                continue;
            }
            ['cm' => $cm, 'assign' => $a] = $assignments[$assignid];
            foreach (self::FIELDS as $field) {
                if (!isset($fields[$field])) {
                    continue;
                }
                $input = (string) $fields[$field];
                $orig = (string) ($original[$assignid][$field] ?? self::format_value($field, $a->$field));
                if ($input === $orig) {
                    continue;
                }
                $value = self::parse_value($field, $input, $course);
                if ($value === null) {
                    $errors[$assignid][$field] = get_string(self::error_string($field), 'local_assignbulkedit');
                    continue;
                }
                if ($a->hassubmissions && in_array($field, self::LOCKEDWITHSUBMISSIONS)) {
                    $errors[$assignid][$field] = get_string('errorlocked', 'local_assignbulkedit');
                    continue;
                }
                if ($field === 'grade' && ($a->grade <= 0 || $a->hasgrades)) {
                    $errors[$assignid][$field] = get_string(
                        $a->grade <= 0 ? 'errorgradetype' : 'errorgradehasgrades',
                        'local_assignbulkedit'
                    );
                    continue;
                }
                if ($a->multimarking && in_array($field, ['markingworkflow', 'markingallocation'])) {
                    $errors[$assignid][$field] = get_string('errormultimarking', 'local_assignbulkedit');
                    continue;
                }
                if ($field === 'visible' && !has_capability('moodle/course:activityvisibility', $cm->context)) {
                    $errors[$assignid][$field] = get_string('errorvisibility', 'local_assignbulkedit');
                    continue;
                }
                $same = in_array($field, ['grade', 'gradepass']) ? abs($value - (float) $a->$field) < 1e-7
                    : (string) $value === (string) $a->$field;
                if (!$same) {
                    $changes[$assignid][$field] = $value;
                }
            }
            if (!empty($changes[$assignid])) {
                self::validate_combination($a, $changes[$assignid], $errors[$assignid]);
            }
            if (empty($errors[$assignid])) {
                unset($errors[$assignid]);
            }
        }
        return [$changes, $errors];
    }

    /**
     * The settings form's rules that involve several fields, checked on the merged values.
     *
     * @param stdClass $a current assignment
     * @param array $changes field => new value
     * @param array|null $errors field => message, added to
     */
    protected static function validate_combination(stdClass $a, array $changes, ?array &$errors): void {
        $v = fn($f) => $changes[$f] ?? $a->$f;
        $blame = function (array $fields) use ($changes) {
            foreach ($fields as $f) {
                if (array_key_exists($f, $changes)) {
                    return $f;
                }
            }
            return $fields[0];
        };
        $add = function (array $fields, string $string, string $component = 'assign') use (&$errors, $blame) {
            $f = $blame($fields);
            if (empty($errors[$f])) {
                $errors[$f] = get_string($string, $component);
            }
        };
        $from = $v('allowsubmissionsfromdate');
        $due = $v('duedate');
        $cutoff = $v('cutoffdate');
        $grading = $v('gradingduedate');
        if ($from && $due && $due <= $from) {
            $add(['duedate', 'allowsubmissionsfromdate'], 'duedateaftersubmissionvalidation');
        }
        if ($cutoff && $due && $cutoff < $due) {
            $add(['cutoffdate', 'duedate'], 'cutoffdatevalidation');
        }
        if ($from && $cutoff && $cutoff < $from) {
            $add(['cutoffdate', 'allowsubmissionsfromdate'], 'cutoffdatefromdatevalidation');
        }
        if ($grading && $from && $from > $grading) {
            $add(['gradingduedate', 'allowsubmissionsfromdate'], 'gradingduefromdatevalidation');
        }
        if ($grading && $due && $due > $grading) {
            $add(['gradingduedate', 'duedate'], 'gradingdueduedatevalidation');
        }
        if (array_key_exists('markingallocation', $changes) && $v('markingallocation') && !$v('markingworkflow')) {
            $add(['markingallocation'], 'errorallocation', 'local_assignbulkedit');
        }
        $multiple = $v('maxattempts') > 1 || $v('maxattempts') == ASSIGN_UNLIMITED_ATTEMPTS;
        if ($v('blindmarking') && $multiple && $v('attemptreopenmethod') === ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS) {
            $add(['attemptreopenmethod', 'blindmarking', 'maxattempts'], 'reopenuntilpassincompatiblewithblindmarking');
        }
        if (array_key_exists('grade', $changes) || array_key_exists('gradepass', $changes)) {
            $grade = (float) $v('grade');
            $gradepass = (float) $v('gradepass');
            if (array_key_exists('gradepass', $changes) && !$a->hasgradeitem) {
                $add(['gradepass'], 'errornogradeitem', 'local_assignbulkedit');
            } else if ($grade > 0 && $gradepass > $grade) {
                $f = $blame(['gradepass', 'grade']);
                $errors[$f] = $errors[$f] ?? get_string('gradepassgreaterthangrade', 'grades', $grade);
            }
        }
    }

    /**
     * The lang string shown when a value can't be parsed.
     *
     * @param string $field
     * @return string
     */
    protected static function error_string(string $field): string {
        if (in_array($field, self::DATEFIELDS)) {
            return 'errordate';
        }
        if (in_array($field, ['name', 'wordlimit', 'grade', 'gradepass', 'filetypes'])) {
            return 'error' . $field;
        }
        return 'errorchoice';
    }

    /**
     * Save changes to the assignments of one course.
     *
     * @param stdClass $course
     * @param array $changes assign id => field => value, from collect_changes()
     * @return int number of assignments updated
     */
    public static function apply(stdClass $course, array $changes): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $transaction = $DB->start_delegated_transaction();
        $count = 0;
        foreach ($changes as $assignid => $fields) {
            $fields = array_intersect_key($fields, array_flip(self::FIELDS));
            if (!$fields) {
                continue;
            }
            $record = $DB->get_record('assign', ['id' => $assignid, 'course' => $course->id], '*', MUST_EXIST);
            $cm = get_coursemodule_from_instance('assign', $assignid, $course->id, false, MUST_EXIST);
            $context = context_module::instance($cm->id);
            require_capability('moodle/course:manageactivities', $context);

            // Assignment fields, keeping the settings form's dependent fields consistent.
            $update = array_intersect_key($fields, array_flip(self::ASSIGNFIELDS));
            $workflow = $update['markingworkflow'] ?? $record->markingworkflow;
            $blind = $update['blindmarking'] ?? $record->blindmarking;
            if (!$workflow && ($update['markingallocation'] ?? $record->markingallocation)) {
                $update['markingallocation'] = 0;
            }
            if ((!$workflow || !$blind) && $record->markinganonymous) {
                $update['markinganonymous'] = 0;
            }
            if ($update) {
                $update['id'] = $assignid;
                $update['timemodified'] = time();
                $DB->update_record('assign', (object) $update);
            }

            if (isset($fields['name'])) {
                formatactions::cm($course->id)->rename($cm->id, $fields['name']);
            }
            if (isset($fields['visible']) && $fields['visible'] != $cm->visible) {
                require_capability('moodle/course:activityvisibility', $context);
                set_coursemodule_visible($cm->id, $fields['visible'], 1, false);
            }

            // Fresh instance, so it sees the new settings.
            $assign = new assign($context, $cm, $course);
            $pluginschanged = false;
            foreach (array_intersect_key($fields, self::PLUGINFIELDS) as $field => $value) {
                [$subtype, $type, $name] = self::PLUGINFIELDS[$field];
                $plugin = $subtype === 'assignsubmission' ? $assign->get_submission_plugin_by_type($type)
                    : $assign->get_feedback_plugin_by_type($type);
                if (!$plugin) {
                    continue;
                }
                if ($name === 'enabled') {
                    $value ? $plugin->enable() : $plugin->disable();
                    $pluginschanged = true;
                } else {
                    $plugin->set_config($name, $value);
                    if ($field === 'wordlimit') {
                        $plugin->set_config('wordlimitenabled', $value > 0 ? 1 : 0);
                    }
                }
            }
            if ($pluginschanged) {
                $assign = new assign($context, $cm, $course);
                $DB->set_field(
                    'assign',
                    'nosubmissions',
                    $assign->is_any_submission_plugin_enabled() ? 0 : 1,
                    ['id' => $assignid]
                );
            }

            if (array_intersect_key($fields, array_flip(self::DATEFIELDS))) {
                // Main and override calendar events, as when the course is reset.
                assign_prepare_update_events($DB->get_record('assign', ['id' => $assignid]), $course, $cm);
            }
            if (array_intersect_key($fields, array_flip(['grade', 'name', 'blindmarking', 'markingworkflow']))) {
                $assign = new assign($context, $cm, $course);
                $assign->update_gradebook(false, $cm->id);
            }
            if (isset($fields['gradepass'])) {
                $gradeitem = grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
                    'iteminstance' => $assignid, 'itemnumber' => 0]);
                if ($gradeitem) {
                    $gradeitem->gradepass = $fields['gradepass'];
                    $gradeitem->update('local_assignbulkedit');
                }
            }

            \core\event\course_module_updated::create_from_cm($cm, $context)->trigger();
            $count++;
        }
        $transaction->allow_commit();
        if ($count) {
            rebuild_course_cache($course->id, true);
        }
        return $count;
    }
}
