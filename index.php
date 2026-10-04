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

/**
 * Edit the settings of all assignments in a course on one page.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_assignbulkedit\local\updater;
use local_assignbulkedit\output\editor;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('moodle/course:manageactivities', $context);

$filter = optional_param('filter', '', PARAM_TEXT);
$url = new moodle_url('/local/assignbulkedit/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('bulkeditassignments', 'local_assignbulkedit'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('bulkeditassignments', 'local_assignbulkedit'), $url);

// Keep the keyword filter across the save redirect, but not in the page URL itself.
$returnurl = new moodle_url($url, $filter === '' ? [] : ['filter' => $filter]);
$assignments = updater::get_assignments($course);
$submitted = [];
$original = [];
$errors = [];

if ($data = data_submitted()) {
    require_sesskey();
    // Nested arrays: q[assignid][field] (new value) and o[assignid][field] (value originally shown).
    foreach (['q' => &$submitted, 'o' => &$original] as $key => &$target) {
        foreach ((array) ($data->$key ?? []) as $assignid => $fields) {
            $assignid = clean_param($assignid, PARAM_INT);
            foreach ((array) $fields as $field => $value) {
                if (in_array($field, updater::FIELDS, true)) {
                    $target[$assignid][$field] = clean_param($value, PARAM_RAW_TRIMMED);
                }
            }
        }
    }
    unset($target);

    [$changes, $errors] = updater::collect_changes($assignments, $submitted, $original, $course);
    if (!$errors) {
        if (!$changes) {
            redirect($returnurl, get_string('nochanges', 'local_assignbulkedit'), null, \core\output\notification::NOTIFY_INFO);
        }
        $count = updater::apply($course, $changes);
        redirect(
            $returnurl,
            get_string('changessaved', 'local_assignbulkedit', $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bulkeditassignments', 'local_assignbulkedit'));
echo html_writer::link(
    new moodle_url('/local/assignbulkedit/overrides.php', ['id' => $course->id]),
    get_string('overrides', 'local_assignbulkedit'),
    ['class' => 'btn btn-outline-primary mb-3']
);
if ($errors) {
    echo $OUTPUT->notification(get_string('fixerrors', 'local_assignbulkedit'), 'error');
}
echo $OUTPUT->render(new editor($course, $assignments, $submitted, $original, $errors, $filter));
echo $OUTPUT->footer();
