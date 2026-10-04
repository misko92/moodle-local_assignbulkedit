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
 * Grant extensions, or add, update or remove overrides, for students or groups on many assignments at once.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_assignbulkedit\form\overrides_form;
use local_assignbulkedit\local\overrides;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('moodle/course:manageactivities', $context);

$url = new moodle_url('/local/assignbulkedit/overrides.php', ['id' => $course->id]);
$backurl = new moodle_url('/local/assignbulkedit/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('overrides', 'local_assignbulkedit'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('bulkeditassignments', 'local_assignbulkedit'), $backurl);
$PAGE->navbar->add(get_string('overrides', 'local_assignbulkedit'), $url);

$assignments = overrides::get_assignments($course);

// Students and groups this user may give overrides to.
$allgroups = has_capability('moodle/site:accessallgroups', $context);
$mygroups = $allgroups ? null : array_keys(groups_get_all_groups($course->id, $USER->id));
$users = [];
foreach (get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true) as $user) {
    if ($allgroups || array_intersect($mygroups, array_keys(groups_get_all_groups($course->id, $user->id)))) {
        $users[$user->id] = fullname($user);
    }
}
$groups = [];
foreach (groups_get_all_groups($course->id) as $group) {
    if ($allgroups || in_array($group->id, $mygroups)) {
        $groups[$group->id] = format_string($group->name, true, ['context' => $context]);
    }
}
$assignnames = array_map(fn($entry) => $entry['cm']->get_formatted_name(), $assignments);

$customdata = [
    'courseid' => $course->id,
    'users' => $users,
    'groups' => $groups,
    'assignments' => $assignnames,
    // Offer Apply once a preview has been shown, i.e. on any submission of the form.
    'canapply' => optional_param('previewbutton', '', PARAM_RAW) !== '' || optional_param('applybutton', '', PARAM_RAW) !== '',
];
$form = new overrides_form($url, $customdata);

if ($form->is_cancelled()) {
    redirect($backurl);
}

$plan = null;
if ($data = $form->get_data()) {
    $chosen = !empty($data->allassignments) ? $assignments :
        array_intersect_key($assignments, array_flip($data->assignments ?? []));
    $extension = in_array($data->action, overrides::EXTENSIONACTIONS);
    $targets = [];
    $userids = array_intersect(array_keys($users), $data->users ?? []);
    foreach ($data->groups ?? [] as $groupid) {
        if (!isset($groups[$groupid])) {
            continue;
        }
        if ($extension) {
            // Extensions are per student: give one to each member.
            $userids = array_merge($userids, array_intersect(array_keys($users), array_keys(groups_get_members($groupid, 'u.id'))));
        } else {
            $targets[] = ['groupid' => (int) $groupid, 'name' => get_string('ovgroup', 'local_assignbulkedit', $groups[$groupid])];
        }
    }
    foreach (array_unique($userids) as $userid) {
        $targets[] = ['userid' => (int) $userid, 'name' => $users[$userid]];
    }
    $plan = overrides::plan($course, $chosen, $targets, $data);

    if (!empty($data->applybutton)) {
        [$saved, $removed] = overrides::apply($course, $assignments, $plan, $data->action);
        redirect(
            $url,
            get_string('ovdone', 'local_assignbulkedit', (object) ['saved' => $saved, 'removed' => $removed]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overrides', 'local_assignbulkedit'));
echo html_writer::tag('p', get_string('overrides_desc', 'local_assignbulkedit'));
echo html_writer::link($backurl, get_string('backtobulkedit', 'local_assignbulkedit'), ['class' => 'd-inline-block mb-3']);

if (!$assignments) {
    echo $OUTPUT->notification(get_string('noassignments', 'local_assignbulkedit'), 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($plan !== null) {
    $todo = array_filter($plan, fn($row) => $row['action'] !== 'skip');
    echo $OUTPUT->heading(get_string('ovpreviewheading', 'local_assignbulkedit'), 3);
    echo $OUTPUT->notification(get_string(
        'ovpreviewsummary',
        'local_assignbulkedit',
        (object) ['todo' => count($todo), 'skipped' => count($plan) - count($todo)]
    ), 'info', false);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = array_map(
        fn($key) => get_string($key, 'local_assignbulkedit'),
        ['ovcolassignment', 'ovcolwho', 'ovcolaction', 'ovcolbefore', 'ovcolafter', 'ovcolnote']
    );
    foreach ($plan as $row) {
        $existing = $row['existing'];
        if (!is_object($existing)) {
            // An extension date.
            $existing = $existing ? ['extensionduedate' => $existing] : null;
        }
        $table->data[] = [
            $assignnames[$row['assignid']],
            s($row['target']['name']),
            get_string('ovdo' . $row['action'], 'local_assignbulkedit'),
            overrides::describe($existing),
            $row['action'] === 'delete' ? '–' : ($row['values'] === null ? '' : overrides::describe($row['values'])),
            s($row['note']),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

$form->display();

// Current extensions and overrides in the course, so it's easy to see who has what.
[$insql, $params] = $DB->get_in_or_equal(array_keys($assignments));
$current = $DB->get_records_select('assign_overrides', "assignid $insql", $params, 'assignid, groupid, userid');
$extensions = $DB->get_records_select(
    'assign_user_flags',
    "assignment $insql AND extensionduedate > 0",
    $params,
    'assignment, userid',
    'id, assignment, userid, extensionduedate'
);
echo $OUTPUT->heading(get_string('ovcurrent', 'local_assignbulkedit'), 3, 'mt-4');
if (!$current && !$extensions) {
    echo html_writer::tag('p', get_string('ovcurrentnone', 'local_assignbulkedit'), ['class' => 'text-muted']);
} else {
    $userids = array_unique(array_merge(array_filter(array_column($current, 'userid')), array_column($extensions, 'userid')));
    $names = $userids ? array_map('fullname', $DB->get_records_list('user', 'id', $userids)) : [];
    $allgroupnames = array_map(fn($group) => format_string($group->name), groups_get_all_groups($course->id));
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table-sm';
    $table->head = array_map(
        fn($key) => get_string($key, 'local_assignbulkedit'),
        ['ovcolassignment', 'ovcolwho', 'ovcolsettings', 'ovreason']
    );
    foreach ($assignments as $assignid => ['cm' => $cm]) {
        foreach ($current as $override) {
            if ($override->assignid != $assignid) {
                continue;
            }
            $who = $override->userid ? ($names[$override->userid] ?? '?') :
                get_string('ovgroup', 'local_assignbulkedit', $allgroupnames[$override->groupid] ?? '?');
            $link = new moodle_url('/mod/assign/overrides.php', [
                'cmid' => $cm->id, 'mode' => $override->userid ? 'user' : 'group',
            ]);
            $table->data[] = [
                html_writer::link($link, $assignnames[$assignid]),
                s($who),
                overrides::describe($override),
                s($override->reason ?? ''),
            ];
        }
        foreach ($extensions as $flags) {
            if ($flags->assignment != $assignid) {
                continue;
            }
            $link = new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grading']);
            $table->data[] = [
                html_writer::link($link, $assignnames[$assignid]),
                s($names[$flags->userid] ?? '?'),
                overrides::describe($flags),
                '',
            ];
        }
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

echo $OUTPUT->footer();
