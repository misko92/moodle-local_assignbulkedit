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

namespace local_assignbulkedit\output;

use local_assignbulkedit\local\updater;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The bulk assignment settings table.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editor implements renderable, templatable {
    /** @var array Input types of the free-input fields. */
    protected const TYPES = [
        'name' => 'text',
        'allowsubmissionsfromdate' => 'datetime-local',
        'duedate' => 'datetime-local',
        'cutoffdate' => 'datetime-local',
        'gradingduedate' => 'datetime-local',
        'wordlimit' => 'number',
        'filetypes' => 'text',
        'grade' => 'number',
        'gradepass' => 'number',
    ];

    /** @var string[] Columns with a hint under the heading. */
    protected const HINTS = ['wordlimit', 'filetypes', 'grade', 'gradingduedate', 'submissiondrafts', 'blindmarking'];

    /**
     * Constructor.
     *
     * @param stdClass $course
     * @param array $assignments from updater::get_assignments()
     * @param array $submitted assign id => field => string, to redisplay after a failed save
     * @param array $original assign id => field => string originally shown, kept across a failed save
     * @param array $errors assign id => field => message
     * @param string $filter keyword the assignment list is filtered by
     */
    public function __construct(
        /** @var stdClass course */
        protected stdClass $course,
        /** @var array assignments */
        protected array $assignments,
        /** @var array submitted values */
        protected array $submitted = [],
        /** @var array original values */
        protected array $original = [],
        /** @var array validation errors */
        protected array $errors = [],
        /** @var string keyword filter */
        protected string $filter = ''
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $columns = updater::columns();
        $shown = array_flip($this->shown_columns());

        $copychoices = [];
        foreach ($this->assignments as $assignid => ['cm' => $cm]) {
            $copychoices[$assignid] = get_string('copyfrom', 'local_assignbulkedit', $cm->get_formatted_name());
        }

        $fields = [];
        foreach ($columns as $field) {
            $fields[] = [
                'field' => $field,
                'label' => get_string($field, 'local_assignbulkedit'),
                'hint' => in_array($field, self::HINTS) ? get_string($field . '_hint', 'local_assignbulkedit') : null,
                'colhidden' => !isset($shown[$field]),
            ] + $this->control($field, updater::choices($field, $this->course), '');
        }

        $groups = [];
        foreach (updater::column_groups() as $group => $groupcolumns) {
            $groups[] = [
                'label' => get_string('group_' . $group, 'local_assignbulkedit'),
                'columns' => array_map(fn($field) => [
                    'field' => $field,
                    'label' => get_string($field, 'local_assignbulkedit'),
                    'checked' => isset($shown[$field]),
                ], $groupcolumns),
            ];
        }

        $rows = [];
        foreach ($this->assignments as $assignid => ['cm' => $cm, 'assign' => $assign]) {
            $name = $cm->get_formatted_name();
            $cells = [];
            foreach ($columns as $field) {
                $original = $this->original[$assignid][$field] ?? updater::format_value($field, $assign->$field);
                $value = $this->submitted[$assignid][$field] ?? $original;
                $note = $this->lock_note($field, $cm, $assign);
                $cells[] = [
                    'field' => $field,
                    'original' => $original,
                    'changed' => $value !== $original,
                    'error' => $this->errors[$assignid][$field] ?? null,
                    'label' => get_string($field, 'local_assignbulkedit') . ': ' . $name,
                    'colhidden' => !isset($shown[$field]),
                    'disabled' => $note !== null,
                    'note' => $note === '' ? null : $note,
                ] + $this->control($field, updater::choices($field, $this->course), $value, $original);
            }

            $rows[] = [
                'assignid' => $assignid,
                'name' => $name,
                'url' => (new moodle_url('/mod/assign/view.php', ['id' => $cm->id]))->out(false),
                'editurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false),
                'section' => get_section_name($cm->get_course(), $cm->sectionnum),
                'hidden' => !$cm->visible,
                'cells' => $cells,
            ];
        }

        return [
            'actionurl' => (new moodle_url('/local/assignbulkedit/index.php'))->out(false),
            'courseid' => $this->course->id,
            'sesskey' => sesskey(),
            'fields' => $fields,
            'groups' => $groups,
            'defaultcolumns' => implode(',', updater::DEFAULTCOLUMNS),
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'filter' => $this->filter,
            'allchoices' => array_map(
                fn($assignid, $label) => ['value' => $assignid, 'label' => $label],
                array_keys($copychoices),
                $copychoices
            ),
        ];
    }

    /**
     * Why a cell can't be edited.
     *
     * @param string $field
     * @param \cm_info $cm
     * @param stdClass $assign from updater::get_assignments()
     * @return string|null null if editable, otherwise the note to show ('' for none)
     */
    protected function lock_note(string $field, \cm_info $cm, stdClass $assign): ?string {
        if ($field === 'visible' && !has_capability('moodle/course:activityvisibility', $cm->context)) {
            return '';
        }
        if ($assign->hassubmissions && in_array($field, updater::LOCKEDWITHSUBMISSIONS)) {
            return get_string('lockedsubmissions', 'local_assignbulkedit');
        }
        if ($field === 'grade' && $assign->grade <= 0) {
            return get_string($assign->grade < 0 ? 'lockedscale' : 'lockednograde', 'local_assignbulkedit');
        }
        if ($field === 'grade' && $assign->hasgrades) {
            return get_string('lockedgrades', 'local_assignbulkedit');
        }
        if ($field === 'gradepass' && !$assign->hasgradeitem) {
            return get_string('lockednograde', 'local_assignbulkedit');
        }
        if ($assign->multimarking && in_array($field, ['markingworkflow', 'markingallocation'])) {
            return get_string('lockedmultimarking', 'local_assignbulkedit');
        }
        return null;
    }

    /**
     * The columns to show: the user's choice, plus any with an error or unsaved change to redisplay.
     *
     * @return string[]
     */
    protected function shown_columns(): array {
        $columns = updater::columns();
        $pref = get_user_preferences('local_assignbulkedit_columns', '');
        $shown = $pref === '' ? updater::DEFAULTCOLUMNS : array_intersect($columns, explode(',', $pref));
        foreach ($this->errors as $fields) {
            array_push($shown, ...array_keys($fields));
        }
        foreach ($this->submitted as $assignid => $fields) {
            foreach ($fields as $field => $value) {
                if ($value !== ($this->original[$assignid][$field] ?? null)) {
                    $shown[] = $field;
                }
            }
        }
        // Keep display order.
        return array_values(array_intersect($columns, $shown));
    }

    /**
     * Template data for one input or drop-down.
     *
     * @param string $field
     * @param array|null $choices value => label, for drop-downs
     * @param string $value current value
     * @param string|null $original value stored now, kept as an option even if no longer offered
     * @return array
     */
    protected function control(string $field, ?array $choices, string $value, ?string $original = null): array {
        if ($choices !== null) {
            // E.g. a file size above today's site limit: keep it rather than silently change it.
            if ($original !== null && !array_key_exists($original, $choices)) {
                $choices = [$original => $field === 'maxsizebytes' ? display_size((int) $original) : $original] + $choices;
            }
            $options = [];
            foreach ($choices as $optvalue => $label) {
                $options[] = ['value' => $optvalue, 'label' => $label, 'selected' => (string) $optvalue === $value];
            }
            return ['isselect' => true, 'options' => $options];
        }
        $type = self::TYPES[$field] ?? 'text';
        return [
            'isselect' => false,
            'type' => $type,
            'value' => $value,
            'isnumber' => $type === 'number',
            'step' => $field === 'gradepass' ? 'any' : '1',
        ];
    }
}
