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

namespace local_assignbulkedit\form;

use local_assignbulkedit\local\overrides;
use moodleform;

/**
 * Form for granting extensions, or adding, updating or removing overrides, on many assignments at once.
 *
 * Custom data: users (id => name), groups (id => name), assignments (id => name), canapply (bool).
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides_form extends moodleform {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $users = $this->_customdata['users'];
        $groups = $this->_customdata['groups'];
        $assignments = $this->_customdata['assignments'];
        $str = fn($identifier, $a = null) => get_string($identifier, 'local_assignbulkedit', $a);

        $mform->addElement('hidden', 'id', $this->_customdata['courseid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('select', 'action', $str('ovaction'), [
            'extend' => $str('ovactionextend'),
            'unextend' => $str('ovactionunextend'),
            'save' => $str('ovactionsave'),
            'delete' => $str('ovactiondelete'),
        ]);
        $mform->addHelpButton('action', 'ovaction', 'local_assignbulkedit');

        // Who.
        $mform->addElement('header', 'whohdr', $str('ovwho'));
        $mform->setExpanded('whohdr');
        $mform->addElement(
            'autocomplete',
            'users',
            $str('ovstudents'),
            $users,
            ['multiple' => true, 'noselectionstring' => $str('ovnoneselected')]
        );
        if ($groups) {
            $mform->addElement(
                'autocomplete',
                'groups',
                $str('ovgroups'),
                $groups,
                ['multiple' => true, 'noselectionstring' => $str('ovnoneselected')]
            );
            $mform->addHelpButton('groups', 'ovgroups', 'local_assignbulkedit');
        }

        // Which assignments.
        $mform->addElement('header', 'assignhdr', $str('ovassignments'));
        $mform->setExpanded('assignhdr');
        $mform->addElement('advcheckbox', 'allassignments', '', $str('ovallassignments', count($assignments)));
        $mform->addElement(
            'autocomplete',
            'assignments',
            $str('ovchooseassignments'),
            $assignments,
            ['multiple' => true, 'noselectionstring' => $str('ovnoneselected')]
        );
        $mform->hideIf('assignments', 'allassignments', 'checked');

        // Extension.
        $mform->addElement('header', 'extensionhdr', $str('ovextension'));
        $mform->setExpanded('extensionhdr');
        $mform->hideIf('extensionhdr', 'action', 'neq', 'extend');
        $mform->addElement('select', 'extensionmode', $str('ovextensionmode'), [
            'set' => $str('ovextendto'),
            'add' => $str('ovaddtodue'),
        ]);
        $mform->addElement('date_time_selector', 'extensiondate', $str('ovextensiondate'));
        $mform->hideIf('extensiondate', 'extensionmode', 'neq', 'set');
        $mform->addElement('duration', 'extensionadd', $str('ovextendby'), [
            'units' => [HOURSECS, DAYSECS, WEEKSECS],
            'defaultunit' => DAYSECS,
        ]);
        $mform->setDefault('extensionadd', DAYSECS);
        $mform->hideIf('extensionadd', 'extensionmode', 'neq', 'add');

        // Override settings.
        $mform->addElement('header', 'whathdr', $str('ovwhat'));
        $mform->setExpanded('whathdr');
        $mform->hideIf('whathdr', 'action', 'neq', 'save');
        $keep = $str('ovkeep');
        $set = $str('ovset');

        $mform->addElement(
            'select',
            'allowsubmissionsfromdatemode',
            $str('ovallowsubmissionsfromdate'),
            ['none' => $keep, 'set' => $set]
        );
        $mform->addElement('date_time_selector', 'allowsubmissionsfromdate', $str('ovallowsubmissionsfromdatevalue'));
        $mform->hideIf('allowsubmissionsfromdate', 'allowsubmissionsfromdatemode', 'neq', 'set');

        foreach (['duedate', 'cutoffdate'] as $key) {
            $mform->addElement('select', $key . 'mode', $str('ov' . $key), [
                'none' => $keep,
                'set' => $set,
                'add' => $str('ovaddto' . $key),
            ]);
            $mform->addElement('date_time_selector', $key, $str('ov' . $key . 'value'));
            $mform->hideIf($key, $key . 'mode', 'neq', 'set');
            $mform->addElement('duration', $key . 'add', $str('ovaddamount'), ['units' => [MINSECS, HOURSECS, DAYSECS, WEEKSECS]]);
            $mform->setDefault($key . 'add', DAYSECS);
            $mform->hideIf($key . 'add', $key . 'mode', 'neq', 'add');
        }

        if (overrides::timelimit_enabled()) {
            $mform->addElement('select', 'timelimitmode', $str('ovtimelimit'), [
                'none' => $keep,
                'multiply' => $str('ovmultiply'),
                'add' => $str('ovaddtotimelimit'),
                'set' => $set,
            ]);
            $mform->addElement('float', 'timelimitfactor', $str('ovfactor'));
            $mform->setDefault('timelimitfactor', 1.5);
            $mform->hideIf('timelimitfactor', 'timelimitmode', 'neq', 'multiply');
            $mform->addElement('duration', 'timelimitadd', $str('ovaddamount'), ['units' => [MINSECS, HOURSECS]]);
            $mform->setDefault('timelimitadd', 30 * MINSECS);
            $mform->hideIf('timelimitadd', 'timelimitmode', 'neq', 'add');
            $mform->addElement('duration', 'timelimit', $str('ovtimelimitvalue'), ['units' => [MINSECS, HOURSECS]]);
            $mform->setDefault('timelimit', HOURSECS);
            $mform->hideIf('timelimit', 'timelimitmode', 'neq', 'set');
        }

        $mform->addElement('text', 'reason', $str('ovreason'), ['size' => 50]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addHelpButton('reason', 'ovreason', 'local_assignbulkedit');

        $buttons = [$mform->createElement('submit', 'previewbutton', $str('ovpreview'))];
        if ($this->_customdata['canapply']) {
            $buttons[] = $mform->createElement('submit', 'applybutton', $str('ovapply'));
        }
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
        // Keep the buttons out of the sections hidden for some actions.
        $mform->closeHeaderBefore('buttonar');
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $str = fn($identifier) => get_string($identifier, 'local_assignbulkedit');
        if (empty($data['users']) && empty($data['groups'])) {
            $errors['users'] = $str('ovneedwho');
        }
        if (empty($data['allassignments']) && empty($data['assignments'])) {
            $errors['assignments'] = $str('ovneedassignment');
        }
        if ($data['action'] === 'extend' && $data['extensionmode'] === 'add' && $data['extensionadd'] < 0) {
            $errors['extensionadd'] = $str('ovnotnegative');
        }
        if ($data['action'] === 'save') {
            $modes = ['allowsubmissionsfromdatemode', 'duedatemode', 'cutoffdatemode', 'timelimitmode'];
            if (!array_filter($modes, fn($mode) => ($data[$mode] ?? 'none') !== 'none') && trim($data['reason'] ?? '') === '') {
                $errors['allowsubmissionsfromdatemode'] = $str('ovneedsetting');
            }
            foreach (['duedate', 'cutoffdate'] as $key) {
                if ($data[$key . 'mode'] === 'add' && $data[$key . 'add'] < 0) {
                    $errors[$key . 'add'] = $str('ovnotnegative');
                }
            }
            $timelimitmode = $data['timelimitmode'] ?? 'none';
            if ($timelimitmode === 'multiply' && $data['timelimitfactor'] <= 0) {
                $errors['timelimitfactor'] = $str('ovpositive');
            }
            if ($timelimitmode === 'add' && $data['timelimitadd'] < 0) {
                $errors['timelimitadd'] = $str('ovnotnegative');
            }
            if ($timelimitmode === 'set' && $data['timelimit'] <= 0) {
                $errors['timelimit'] = $str('ovpositive');
            }
            if (
                $data['allowsubmissionsfromdatemode'] === 'set' && $data['duedatemode'] === 'set' &&
                    $data['duedate'] <= $data['allowsubmissionsfromdate']
            ) {
                $errors['duedate'] = get_string('duedateaftersubmissionvalidation', 'assign');
            }
            if ($data['duedatemode'] === 'set' && $data['cutoffdatemode'] === 'set' && $data['cutoffdate'] < $data['duedate']) {
                $errors['cutoffdate'] = get_string('cutoffdatevalidation', 'assign');
            }
        }
        return $errors;
    }
}
