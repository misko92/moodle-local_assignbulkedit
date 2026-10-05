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
 * Language strings.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowsubmissionsfromdate'] = 'Allow submissions from';
$string['allsettings'] = 'All settings (except name, visibility and dates)';
$string['applytoselected'] = 'Apply to selected';
$string['assignment'] = 'Assignment';
$string['attemptreopenmethod'] = 'Additional attempts';
$string['backtobulkedit'] = 'Back to Bulk edit assignments';
$string['blindmarking'] = 'Anonymous submissions';
$string['blindmarking_hint'] = 'locked once there are submissions';
$string['bulkeditassignments'] = 'Bulk edit assignments';
$string['changedonly'] = 'Show changed assignments only';
$string['changessaved'] = '{$a} assignment(s) updated.';
$string['clearvalue'] = 'Leave a date empty to turn it off.';
$string['columns'] = 'Columns';
$string['columnsdefault'] = 'Reset to default columns';
$string['confirmdates'] = 'Students with an extension or an override keep their own dates: changing the assignment\'s dates doesn\'t move those.';
$string['confirmnone'] = '(none)';
$string['confirmsave'] = 'Save';
$string['confirmsummary'] = '{$a->settings} change(s) to {$a->assignments} assignment(s):';
$string['confirmtitle'] = 'Review changes';
$string['confirmtypeoff'] = 'Turning off a submission type hides what students have already submitted with it (it is kept, and shows again if you turn it back on).';
$string['copyfrom'] = 'Same as {$a}';
$string['create'] = 'Add assignments';
$string['create_desc'] = 'Create several assignments at once. Each is a copy of the model assignment (all its settings, description and files, rubric and restrictions, but no student work, grades or extensions), with its own name, section and dates. Leave a date empty for none.';
$string['createaddrow'] = 'Add row';
$string['createbutton'] = 'Create assignments';
$string['created'] = '{$a} assignment(s) created.';
$string['createhidden'] = 'Create them hidden from students';
$string['createmodel'] = 'Copy settings from';
$string['createmodel_help'] = 'Its "Remind me to grade by" date is copied as the same time after each new due date.';
$string['createpaste'] = 'Paste a list of names';
$string['createpaste_help'] = 'One name per line';
$string['createpasteadd'] = 'Add these names';
$string['createpreview'] = '{$a->count} assignment(s) will be created as copies of {$a->model}. Nothing has been created yet: check the table, then press Create assignments.';
$string['createremoverow'] = 'Remove row';
$string['cutoffdate'] = 'Cut-off date';
$string['duedate'] = 'Due date';
$string['editsettings'] = 'Edit all settings for {$a}';
$string['errorallocation'] = 'Marking allocation needs the marking workflow.';
$string['errorchoice'] = 'Invalid choice.';
$string['errorcreate'] = 'Could not create {$a}.';
$string['errordate'] = 'Invalid date.';
$string['errorfiletypes'] = 'Unknown file type. Use extensions or groups, e.g. .pdf, .docx, document.';
$string['errorgrade'] = 'Must be a whole number, 1 or more.';
$string['errorgradehasgrades'] = 'The maximum grade can\'t be changed here once students have grades. Use the assignment\'s settings page.';
$string['errorgradepass'] = 'Must be a number, 0 or more.';
$string['errorgradetype'] = 'This assignment uses a scale or no grade.';
$string['errorlocked'] = 'This can\'t be changed once the assignment has submissions.';
$string['errormultimarking'] = 'This assignment uses multiple markers. Change this on its settings page.';
$string['errorname'] = 'The name must not be empty, and at most 255 characters.';
$string['errornogradeitem'] = 'This assignment has no gradebook item, so it has no grade to pass.';
$string['errornorows'] = 'Enter at least one assignment name.';
$string['errorvisibility'] = 'You are not allowed to show or hide this assignment.';
$string['errorwordlimit'] = 'Must be a whole number, 0 or more.';
$string['extensionnotsaved'] = 'The extension for {$a} could not be saved. Nothing was changed.';
$string['fbcomments'] = 'Feedback comments';
$string['fbeditpdf'] = 'Annotate PDF';
$string['fbfile'] = 'Feedback files';
$string['fboffline'] = 'Offline grading worksheet';
$string['field'] = 'Setting';
$string['file'] = 'File submissions';
$string['filetypes'] = 'Accepted file types';
$string['filetypes_hint'] = 'e.g. .pdf, .docx; empty = any';
$string['filter'] = 'Filter';
$string['filtercount'] = 'Showing {$a->shown} of {$a->total} assignments';
$string['filterplaceholder'] = 'Assignment or section name';
$string['fixerrors'] = 'Nothing was saved. Please fix the highlighted values and save again.';
$string['grade'] = 'Maximum grade';
$string['grade_hint'] = 'locked once graded';
$string['gradepass'] = 'Grade to pass';
$string['gradingduedate'] = 'Remind me to grade by';
$string['gradingduedate_hint'] = 'shown to teachers only';
$string['group_availability'] = 'Availability';
$string['group_feedbacktypes'] = 'Feedback types';
$string['group_general'] = 'General';
$string['group_grade'] = 'Grade';
$string['group_submissionsettings'] = 'Submission settings';
$string['group_submissiontypes'] = 'Submission types';
$string['hidegrader'] = 'Hide grader identity from students';
$string['lockedgrades'] = 'Locked: has grades';
$string['lockedmultimarking'] = 'Multiple markers';
$string['lockednograde'] = 'No grade';
$string['lockedscale'] = 'Scale';
$string['lockedsubmissions'] = 'Locked: has submissions';
$string['markingallocation'] = 'Use marking allocation';
$string['markingworkflow'] = 'Use marking workflow';
$string['maxattempts'] = 'Maximum attempts';
$string['maxfiles'] = 'Maximum number of files';
$string['maxsizebytes'] = 'Maximum submission size';
$string['name'] = 'Assignment name';
$string['noassignments'] = 'There are no assignments in this course that you can edit.';
$string['nochanges'] = 'No changes to save.';
$string['onlinetext'] = 'Online text';
$string['ovaction'] = 'Action';
$string['ovaction_help'] = 'An extension lets a student submit after the due date and the cut-off date, up to the extension date; it is the same as Grant extension on the assignment\'s Submissions page. An override gives a student or group their own dates (and time limit, if used) instead of the assignment\'s; it is the same as the assignment\'s Overrides page.';
$string['ovactiondelete'] = 'Remove overrides';
$string['ovactionextend'] = 'Grant extensions';
$string['ovactionsave'] = 'Add or update overrides';
$string['ovactionunextend'] = 'Remove extensions';
$string['ovaddamount'] = 'Add';
$string['ovaddtocutoffdate'] = 'Add time to the assignment\'s cut-off date';
$string['ovaddtodue'] = 'Add time to the student\'s due date';
$string['ovaddtoduedate'] = 'Add time to the assignment\'s due date';
$string['ovaddtotimelimit'] = 'Add time to the assignment\'s time limit';
$string['ovallassignments'] = 'All {$a} assignments in this course';
$string['ovallowsubmissionsfromdate'] = 'Allow submissions from';
$string['ovallowsubmissionsfromdatevalue'] = 'Allow submissions from';
$string['ovapply'] = 'Apply';
$string['ovassignments'] = 'Assignments';
$string['ovchooseassignments'] = 'Assignments';
$string['ovcolaction'] = 'Action';
$string['ovcolafter'] = 'New';
$string['ovcolassignment'] = 'Assignment';
$string['ovcolbefore'] = 'Current';
$string['ovcolnote'] = 'Note';
$string['ovcolsettings'] = 'Settings';
$string['ovcolwho'] = 'Student or group';
$string['ovcurrent'] = 'Current extensions and overrides in this course';
$string['ovcurrentnone'] = 'No assignment in this course has extensions or overrides.';
$string['ovcutoffdate'] = 'Cut-off date';
$string['ovcutoffdatevalue'] = 'Cut-off date';
$string['ovdocreate'] = 'Create';
$string['ovdodelete'] = 'Remove';
$string['ovdone'] = '{$a->saved} saved, {$a->removed} removed.';
$string['ovdoskip'] = 'Skip';
$string['ovdoupdate'] = 'Update';
$string['ovduedate'] = 'Due date';
$string['ovduedatevalue'] = 'Due date';
$string['overrides'] = 'Extensions & overrides';
$string['overrides_desc'] = 'Give students extensions, or give students or groups their own dates, on many assignments at once. These are standard assignment extensions and overrides: each also shows on its assignment\'s Submissions or Overrides page.';
$string['ovextendby'] = 'Extend by';
$string['ovextendto'] = 'Extend to a date';
$string['ovextension'] = 'Extension';
$string['ovextensiondate'] = 'Extension date';
$string['ovextensionduedate'] = 'Extension';
$string['ovextensionmode'] = 'Extension';
$string['ovfactor'] = 'Multiply by';
$string['ovgroup'] = 'Group: {$a}';
$string['ovgroups'] = 'Groups';
$string['ovgroups_help'] = 'Overrides can be given to a whole group. Extensions are per student, so choosing a group gives an extension to each of its students.';
$string['ovkeep'] = 'Don\'t change';
$string['ovmultiply'] = 'Multiply the assignment\'s time limit';
$string['ovneedassignment'] = 'Choose at least one assignment.';
$string['ovneedsetting'] = 'Choose at least one setting to change.';
$string['ovneedwho'] = 'Choose at least one student or group.';
$string['ovnochange'] = 'Already set.';
$string['ovnocutoff'] = 'The assignment has no cut-off date to extend.';
$string['ovnodue'] = 'There is no due date to extend.';
$string['ovnoextension'] = 'No extension to remove.';
$string['ovnone'] = 'None';
$string['ovnoneselected'] = 'None selected';
$string['ovnooverride'] = 'No override to remove.';
$string['ovnopermission'] = 'You are not allowed to do this on this assignment.';
$string['ovnotimelimit'] = 'The assignment has no time limit to change.';
$string['ovnotnegative'] = 'Must not be negative.';
$string['ovpositive'] = 'Must be more than 0.';
$string['ovpreview'] = 'Preview';
$string['ovpreviewheading'] = 'Preview';
$string['ovpreviewsummary'] = '{$a->todo} to save or remove, {$a->skipped} skipped. Nothing has been saved yet: check the table, then press Apply.';
$string['ovreason'] = 'Reason';
$string['ovreason_help'] = 'An optional note saved with each override, e.g. "IEP: extra time". It shows on the assignment\'s Overrides page.';
$string['ovsameasassign'] = 'Same as the assignment\'s own settings, so no override is needed.';
$string['ovset'] = 'Set to';
$string['ovstudents'] = 'Students';
$string['ovtimelimit'] = 'Time limit';
$string['ovtimelimitvalue'] = 'Time limit';
$string['ovwhat'] = 'Override settings';
$string['ovwho'] = 'Students and groups';
$string['pluginname'] = 'Assignment bulk edit';
$string['privacy:metadata'] = 'The Assignment bulk edit plugin does not store any personal data.';
$string['requiresubmissionstatement'] = 'Require submission statement';
$string['savechanges'] = 'Save changes';
$string['select'] = 'Select {$a}';
$string['selectall'] = 'Select all assignments';
$string['selectfirst'] = 'Select one or more assignments first.';
$string['submissiondrafts'] = 'Require students to click Submit';
$string['submissiondrafts_hint'] = 'locked once there are submissions';
$string['value'] = 'Value';
$string['visible'] = 'Visibility';
$string['visible_hide'] = 'Hidden';
$string['visible_show'] = 'Shown';
$string['wordlimit'] = 'Word limit';
$string['wordlimit_hint'] = '0 = no limit';
