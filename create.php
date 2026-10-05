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
 * Create several assignments at once as copies of a model assignment, each with its own name, section and dates.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_assignbulkedit\local\creator;
use local_assignbulkedit\local\updater;

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('moodle/course:manageactivities', $context);

$url = new moodle_url('/local/assignbulkedit/create.php', ['id' => $course->id]);
$backurl = new moodle_url('/local/assignbulkedit/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('create', 'local_assignbulkedit'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('bulkeditassignments', 'local_assignbulkedit'), $backurl);
$PAGE->navbar->add(get_string('create', 'local_assignbulkedit'), $url);

if (!creator::can_create($course)) {
    throw new required_capability_exception($context, 'moodle/backup:backuptargetimport', 'nopermissions', '');
}
$models = array_map(fn($entry) => $entry['cm']->get_formatted_name(), updater::get_assignments($course));
if (!$models) {
    redirect($backurl);
}
$sections = creator::sections($course);

$modelid = (int) array_key_first($models);
$hidden = false;
$input = [];
$rows = [];
$errors = [];
$preview = false;
if ($data = data_submitted()) {
    require_sesskey();
    $modelid = clean_param($data->model ?? 0, PARAM_INT);
    if (!isset($models[$modelid])) {
        throw new moodle_exception('invalidrecord', 'error', '', 'assign');
    }
    $hidden = !empty($data->hidden);
    foreach ((array) ($data->r ?? []) as $i => $fields) {
        foreach (['name', 'section', ...creator::DATEFIELDS] as $field) {
            $input[clean_param($i, PARAM_INT)][$field] = clean_param(((array) $fields)[$field] ?? '', PARAM_RAW_TRIMMED);
        }
    }
    [$rows, $errors] = creator::parse_rows($course, $input);
    if (!$rows && !$errors) {
        $errors[-1] = get_string('errornorows', 'local_assignbulkedit');
    }
    if (!$errors && !empty($data->createbutton)) {
        $cmids = creator::create($course, $modelid, $rows, $hidden);
        redirect(
            $backurl,
            get_string('created', 'local_assignbulkedit', count($cmids)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $preview = !$errors;
}

// Start with a few empty rows in the model's section.
if (!$input) {
    $modelsection = get_fast_modinfo($course)->get_instances_of('assign')[$modelid]->sectionnum;
    $input = array_fill(0, 5, ['name' => '', 'section' => (string) $modelsection] + array_fill_keys(creator::DATEFIELDS, ''));
}

$model = $DB->get_record('assign', ['id' => $modelid], '*', MUST_EXIST);
$templaterows = [];
foreach ($input as $i => $fields) {
    $cells = [];
    foreach (creator::DATEFIELDS as $field) {
        $cells[] = [
            'field' => $field,
            'value' => $fields[$field],
            'label' => get_string($field, 'local_assignbulkedit'),
            'error' => $errors[$i][$field] ?? null,
        ];
    }
    $templaterows[] = [
        'index' => $i,
        'name' => $fields['name'],
        'nameerror' => $errors[$i]['name'] ?? null,
        'sectionerror' => $errors[$i]['section'] ?? null,
        'sections' => array_map(fn($num, $name) => [
            'value' => $num,
            'label' => $name,
            'selected' => (string) $num === $fields['section'],
        ], array_keys($sections), $sections),
        'dates' => $cells,
    ];
}

$previewrows = [];
if ($preview) {
    $shown = fn($time) => $time ? userdate($time) : get_string('confirmnone', 'local_assignbulkedit');
    foreach ($rows as $row) {
        $previewrows[] = [
            'name' => $row->name,
            'section' => $sections[$row->section],
            'allowsubmissionsfromdate' => $shown($row->allowsubmissionsfromdate),
            'duedate' => $shown($row->duedate),
            'cutoffdate' => $shown($row->cutoffdate),
            'gradingduedate' => $shown(creator::grading_due_date($model, $row->duedate)),
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('create', 'local_assignbulkedit'));
echo html_writer::tag('p', get_string('create_desc', 'local_assignbulkedit'));
echo html_writer::link($backurl, get_string('backtobulkedit', 'local_assignbulkedit'), ['class' => 'd-inline-block mb-3']);
if ($errors) {
    echo $OUTPUT->notification($errors[-1] ?? get_string('fixerrors', 'local_assignbulkedit'), 'error');
}
echo $OUTPUT->render_from_template('local_assignbulkedit/create', [
    'actionurl' => $url->out(false),
    'courseid' => $course->id,
    'sesskey' => sesskey(),
    'models' => array_map(fn($id, $name) => [
        'value' => $id,
        'label' => $name,
        'selected' => $id === $modelid,
    ], array_keys($models), $models),
    'hidden' => $hidden,
    'rows' => $templaterows,
    'preview' => $preview,
    'previewrows' => $previewrows,
    'previewsummary' => get_string('createpreview', 'local_assignbulkedit', (object) [
        'count' => count($previewrows),
        'model' => $models[$modelid],
    ]),
]);
echo $OUTPUT->footer();
