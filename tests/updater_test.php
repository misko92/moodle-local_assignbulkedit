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

namespace local_assignbulkedit;

use local_assignbulkedit\local\updater;

/**
 * Tests for the updater.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_assignbulkedit\local\updater
 */
final class updater_test extends \advanced_testcase {
    /** @var int A minute-aligned time a few days ahead, as the date inputs only hold minutes. */
    private int $due;

    /**
     * Course with two assignments and an editing teacher, logged in.
     *
     * @return array [course, assign1, assign2, teacher]
     */
    private function setup_course(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $this->due = (int) (floor(time() / 60) * 60) + 3 * DAYSECS;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $assigngen = $gen->get_plugin_generator('mod_assign');
        $assign1 = $assigngen->create_instance([
            'course' => $course->id,
            'name' => 'Essay',
            'duedate' => $this->due,
            'allowsubmissionsfromdate' => 0,
            'cutoffdate' => 0,
            'gradingduedate' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
            'assignfeedback_comments_enabled' => 1,
            'grade' => 100,
        ]);
        $assign2 = $assigngen->create_instance([
            'course' => $course->id,
            'name' => 'Lab report',
            'duedate' => 0,
            'allowsubmissionsfromdate' => 0,
            'cutoffdate' => 0,
            'gradingduedate' => 0,
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 2,
            'assignsubmission_file_maxsizebytes' => 0,
        ]);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $assign1, $assign2, $teacher];
    }

    /**
     * Submitted form values equal to what is shown on page load.
     *
     * @param array $assignments
     * @return array
     */
    private function shown(array $assignments): array {
        $shown = [];
        foreach ($assignments as $assignid => ['assign' => $assign]) {
            foreach (updater::FIELDS as $field) {
                $shown[$assignid][$field] = updater::format_value($field, $assign->$field);
            }
        }
        return $shown;
    }

    /**
     * Collect changes from the given edits on top of the page as shown.
     *
     * @param \stdClass $course
     * @param array $edits assign id => field => submitted string
     * @return array [changes, errors]
     */
    private function edit(\stdClass $course, array $edits): array {
        $assignments = updater::get_assignments($course);
        $shown = $this->shown($assignments);
        $submitted = array_replace_recursive($shown, $edits);
        return updater::collect_changes($assignments, $submitted, $shown, $course);
    }

    /**
     * A plugin setting of an assignment.
     *
     * @param int $assignid
     * @param string $subtype
     * @param string $plugin
     * @param string $name
     * @return string|false
     */
    private function config(int $assignid, string $subtype, string $plugin, string $name) {
        global $DB;
        return $DB->get_field(
            'assign_plugin_config',
            'value',
            ['assignment' => $assignid, 'subtype' => $subtype, 'plugin' => $plugin, 'name' => $name]
        );
    }

    public function test_untouched_form_has_no_changes(): void {
        [$course] = $this->setup_course();
        $assignments = updater::get_assignments($course);
        $this->assertCount(2, $assignments);
        $this->assertSame(['Essay', 'Lab report'], array_values(array_map(fn($a) => $a['assign']->name, $assignments)));
        $this->assertSame([[], []], $this->edit($course, []));
    }

    public function test_dates_update_assignment_and_calendar(): void {
        global $DB;
        [$course, $assign1] = $this->setup_course();
        $newdue = $this->due + DAYSECS;
        [$changes, $errors] = $this->edit($course, [$assign1->id => [
            'duedate' => updater::format_value('duedate', $newdue),
            'cutoffdate' => updater::format_value('cutoffdate', $newdue + DAYSECS),
        ]]);
        $this->assertSame([], $errors);
        $this->assertSame([$assign1->id => ['duedate' => $newdue, 'cutoffdate' => $newdue + DAYSECS]], $changes);

        $sink = $this->redirectEvents();
        $this->assertSame(1, updater::apply($course, $changes));
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \core\event\course_module_updated);
        $this->assertCount(1, $events);

        $record = $DB->get_record('assign', ['id' => $assign1->id]);
        $this->assertEquals($newdue, $record->duedate);
        $this->assertEquals($newdue + DAYSECS, $record->cutoffdate);
        $event = $DB->get_record('event', ['modulename' => 'assign', 'instance' => $assign1->id, 'eventtype' => 'due']);
        $this->assertEquals($newdue, $event->timestart);

        // Clearing the due date removes its calendar event.
        [$changes, $errors] = $this->edit($course, [$assign1->id => ['duedate' => '', 'cutoffdate' => '']]);
        $this->assertSame([], $errors);
        updater::apply($course, $changes);
        $this->assertFalse($DB->record_exists('event', ['modulename' => 'assign', 'instance' => $assign1->id,
            'eventtype' => 'due']));
    }

    public function test_submission_types_leave_other_plugins_alone(): void {
        global $DB;
        [$course, $assign1, $assign2] = $this->setup_course();
        [$changes, $errors] = $this->edit($course, [
            $assign1->id => ['onlinetext' => '0', 'file' => '1', 'maxfiles' => '3', 'filetypes' => 'pdf, .DOCX'],
            $assign2->id => ['wordlimit' => '500', 'onlinetext' => '1'],
        ]);
        $this->assertSame([], $errors);
        $this->assertSame('.pdf,.docx', $changes[$assign1->id]['filetypes']);
        updater::apply($course, $changes);

        $this->assertSame('0', $this->config($assign1->id, 'assignsubmission', 'onlinetext', 'enabled'));
        $this->assertSame('1', $this->config($assign1->id, 'assignsubmission', 'file', 'enabled'));
        $this->assertSame('3', $this->config($assign1->id, 'assignsubmission', 'file', 'maxfilesubmissions'));
        $this->assertSame('.pdf,.docx', $this->config($assign1->id, 'assignsubmission', 'file', 'filetypeslist'));
        // Untouched: feedback comments (update_instance() with partial data would turn them off).
        $this->assertSame('1', $this->config($assign1->id, 'assignfeedback', 'comments', 'enabled'));
        $this->assertSame('1', $this->config($assign2->id, 'assignsubmission', 'file', 'enabled'));
        $this->assertSame('500', $this->config($assign2->id, 'assignsubmission', 'onlinetext', 'wordlimit'));
        $this->assertSame('1', $this->config($assign2->id, 'assignsubmission', 'onlinetext', 'wordlimitenabled'));
        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign1->id]));

        // Turning every submission type off makes it a "no submissions" assignment.
        [$changes] = $this->edit($course, [$assign1->id => ['file' => '0']]);
        updater::apply($course, $changes);
        $this->assertEquals(1, $DB->get_field('assign', 'nosubmissions', ['id' => $assign1->id]));
        $assignments = updater::get_assignments($course);
        $this->assertSame('0', $assignments[$assign1->id]['assign']->file);
        $this->assertSame(0, $assignments[$assign1->id]['assign']->wordlimit);

        // A word limit of 0 turns the limit off.
        [$changes] = $this->edit($course, [$assign2->id => ['wordlimit' => '0']]);
        updater::apply($course, $changes);
        $this->assertSame('0', $this->config($assign2->id, 'assignsubmission', 'onlinetext', 'wordlimitenabled'));
    }

    public function test_validation(): void {
        [$course, $assign1, $assign2] = $this->setup_course();
        $fmt = fn($time) => updater::format_value('duedate', $time);
        [, $errors] = $this->edit($course, [
            $assign1->id => [
                'allowsubmissionsfromdate' => $fmt($this->due + HOURSECS),
                'filetypes' => '.notarealtype',
                'grade' => '0',
                'maxattempts' => '99',
                'name' => ' ',
            ],
            $assign2->id => [
                'duedate' => $fmt($this->due),
                'cutoffdate' => $fmt($this->due - HOURSECS),
                'gradingduedate' => 'nonsense',
            ],
        ]);
        $this->assertEqualsCanonicalizing(
            ['filetypes', 'grade', 'maxattempts', 'name', 'allowsubmissionsfromdate'],
            array_keys($errors[$assign1->id])
        );
        $this->assertSame(
            get_string('duedateaftersubmissionvalidation', 'assign'),
            $errors[$assign1->id]['allowsubmissionsfromdate']
        );
        $this->assertSame(get_string('errordate', 'local_assignbulkedit'), $errors[$assign2->id]['gradingduedate']);
        $this->assertSame(get_string('cutoffdatevalidation', 'assign'), $errors[$assign2->id]['cutoffdate']);
    }

    public function test_settings_locked_by_submissions_and_grades(): void {
        global $DB;
        [$course, $assign1] = $this->setup_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign1->cmid,
            'onlinetext' => 'My essay',
        ]);
        $record = $DB->get_record('assign', ['id' => $assign1->id]);
        [, $errors] = $this->edit($course, [$assign1->id => [
            'submissiondrafts' => $record->submissiondrafts ? '0' : '1',
            'blindmarking' => $record->blindmarking ? '0' : '1',
            'grade' => '50',
        ]]);
        $this->assertSame(get_string('errorlocked', 'local_assignbulkedit'), $errors[$assign1->id]['submissiondrafts']);
        $this->assertSame(get_string('errorlocked', 'local_assignbulkedit'), $errors[$assign1->id]['blindmarking']);
        // Submitted but not graded: the maximum grade can still change.
        $this->assertArrayNotHasKey('grade', $errors[$assign1->id]);

        $cm = get_coursemodule_from_instance('assign', $assign1->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $grade = $assign->get_user_grade($student->id, true);
        $grade->grade = 80;
        $assign->update_grade($grade);
        [, $errors] = $this->edit($course, [$assign1->id => ['grade' => '50']]);
        $this->assertSame(get_string('errorgradehasgrades', 'local_assignbulkedit'), $errors[$assign1->id]['grade']);
        $this->assertEquals(100, $DB->get_field('assign', 'grade', ['id' => $assign1->id]));
    }

    public function test_grade_grade_to_pass_and_name(): void {
        global $DB;
        [$course, $assign1] = $this->setup_course();
        [$changes, $errors] = $this->edit($course, [$assign1->id => [
            'grade' => '40', 'gradepass' => '20', 'name' => 'Persuasive essay',
        ]]);
        $this->assertSame([], $errors);
        updater::apply($course, $changes);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemmodule' => 'assign', 'iteminstance' => $assign1->id]);
        $this->assertEquals(40, $item->grademax);
        $this->assertEquals(20, $item->gradepass);
        $this->assertSame('Persuasive essay', $item->itemname);
        $this->assertSame('Persuasive essay', $DB->get_field('assign', 'name', ['id' => $assign1->id]));
        $this->assertSame('Persuasive essay', get_fast_modinfo($course)->get_cm($assign1->cmid)->name);

        [, $errors] = $this->edit($course, [$assign1->id => ['gradepass' => '41']]);
        $this->assertSame(get_string('gradepassgreaterthangrade', 'grades', 40), $errors[$assign1->id]['gradepass']);
    }

    public function test_marking_workflow_rules(): void {
        global $DB;
        [$course, $assign1] = $this->setup_course();
        [, $errors] = $this->edit($course, [$assign1->id => ['markingallocation' => '1']]);
        $this->assertSame(get_string('errorallocation', 'local_assignbulkedit'), $errors[$assign1->id]['markingallocation']);

        [$changes, $errors] = $this->edit($course, [$assign1->id => ['markingworkflow' => '1', 'markingallocation' => '1']]);
        $this->assertSame([], $errors);
        updater::apply($course, $changes);
        $this->assertEquals(1, $DB->get_field('assign', 'markingallocation', ['id' => $assign1->id]));

        // Turning the workflow off also turns allocation off, as the settings form does.
        [$changes] = $this->edit($course, [$assign1->id => ['markingworkflow' => '0']]);
        updater::apply($course, $changes);
        $this->assertEquals(0, $DB->get_field('assign', 'markingallocation', ['id' => $assign1->id]));
    }

    public function test_attempts_and_reopen_method(): void {
        global $DB;
        [$course, $assign1] = $this->setup_course();
        [$changes, $errors] = $this->edit($course, [$assign1->id => [
            'maxattempts' => (string) ASSIGN_UNLIMITED_ATTEMPTS,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
            'submissiondrafts' => '1',
            'requiresubmissionstatement' => '1',
        ]]);
        $this->assertSame([], $errors);
        updater::apply($course, $changes);
        $record = $DB->get_record('assign', ['id' => $assign1->id]);
        $this->assertEquals(ASSIGN_UNLIMITED_ATTEMPTS, $record->maxattempts);
        $this->assertSame(ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL, $record->attemptreopenmethod);
        $this->assertEquals(1, $record->submissiondrafts);
        $this->assertEquals(1, $record->requiresubmissionstatement);
    }

    public function test_visibility_needs_capability(): void {
        [$course, $assign1] = $this->setup_course();
        $teacherrole = \core\di::get(\moodle_database::class)->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/course:activityvisibility', CAP_PROHIBIT, $teacherrole, \context_course::instance($course->id));
        [$changes, $errors] = $this->edit($course, [$assign1->id => ['visible' => '0']]);
        $this->assertSame([], $changes);
        $this->assertSame(get_string('errorvisibility', 'local_assignbulkedit'), $errors[$assign1->id]['visible']);
    }

    public function test_assignments_without_capability_are_ignored(): void {
        [$course, $assign1, $assign2] = $this->setup_course();
        $teacherrole = \core\di::get(\moodle_database::class)->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'moodle/course:manageactivities',
            CAP_PROHIBIT,
            $teacherrole,
            \context_module::instance($assign2->cmid)
        );
        $assignments = updater::get_assignments($course);
        $this->assertSame([(int) $assign1->id], array_keys($assignments));
        // Edits sent for it anyway are dropped.
        $shown = $this->shown($assignments);
        [$changes, $errors] = updater::collect_changes($assignments, [$assign2->id => ['grade' => '5']], $shown, $course);
        $this->assertSame([[], []], [$changes, $errors]);
    }

    public function test_stale_page_does_not_clobber_other_edits(): void {
        global $DB;
        [$course, $assign1] = $this->setup_course();
        $assignments = updater::get_assignments($course);
        $shown = $this->shown($assignments);
        // Someone else changes the grade after the page was loaded.
        $DB->set_field('assign', 'grade', 70, ['id' => $assign1->id]);
        $assignments = updater::get_assignments($course);
        [$changes, $errors] = updater::collect_changes($assignments, $shown, $shown, $course);
        $this->assertSame([[], []], [$changes, $errors]);
    }
}
