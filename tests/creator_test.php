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

use local_assignbulkedit\local\creator;
use local_assignbulkedit\local\updater;

/**
 * Tests for creating assignments as copies of a model.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_assignbulkedit\local\creator
 */
final class creator_test extends \advanced_testcase {
    /** @var int A minute-aligned time a few days ahead. */
    private int $due;

    /**
     * Course with a model assignment that has a submission, and an editing teacher, logged in.
     *
     * @return array [course, model]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $this->due = (int) (floor(time() / 60) * 60) + 3 * DAYSECS;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 3]);
        $model = $gen->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'name' => 'Lab 1',
            'section' => 1,
            'duedate' => $this->due,
            'gradingduedate' => $this->due + 2 * DAYSECS,
            'cutoffdate' => 0,
            'allowsubmissionsfromdate' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 4,
            'assignsubmission_file_maxsizebytes' => 0,
            'assignfeedback_comments_enabled' => 1,
            'grade' => 25,
            'maxattempts' => 3,
        ]);
        $student = $gen->create_and_enrol($course, 'student');
        $gen->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $model->cmid,
            'onlinetext' => 'My lab',
        ]);
        $this->setUser($gen->create_and_enrol($course, 'editingteacher'));
        return [$course, $model];
    }

    /**
     * Form input for one row.
     *
     * @param string $name
     * @param int $section
     * @param int $due
     * @param int $cutoff
     * @return array
     */
    private function row(string $name, int $section, int $due = 0, int $cutoff = 0): array {
        return [
            'name' => $name,
            'section' => (string) $section,
            'allowsubmissionsfromdate' => '',
            'duedate' => updater::format_value('duedate', $due),
            'cutoffdate' => updater::format_value('cutoffdate', $cutoff),
        ];
    }

    public function test_create_copies(): void {
        global $DB;
        [$course, $model] = $this->setup_course();
        $empty = ['name' => '', 'section' => '1', 'allowsubmissionsfromdate' => '', 'duedate' => '', 'cutoffdate' => ''];
        [$rows, $errors] = creator::parse_rows($course, [
            $this->row('Lab 2', 1, $this->due + WEEKSECS, $this->due + WEEKSECS + DAYSECS),
            $empty,
            $this->row('Lab 3', 2),
        ]);
        $this->assertSame([], $errors);
        $this->assertCount(2, $rows);

        $cmids = creator::create($course, $model->id, $rows, true);
        $this->assertCount(2, $cmids);
        $modinfo = get_fast_modinfo($course);
        [$lab2, $lab3] = [$modinfo->get_cm($cmids[0]), $modinfo->get_cm($cmids[1])];
        $this->assertSame(['Lab 2', 1, 0], [$lab2->name, (int) $lab2->sectionnum, (int) $lab2->visible]);
        $this->assertSame(['Lab 3', 2, 0], [$lab3->name, (int) $lab3->sectionnum, (int) $lab3->visible]);
        // New ones go after the model, in row order.
        $this->assertSame([(int) $model->cmid, (int) $lab2->id], array_map('intval', $modinfo->sections[1]));

        $record = $DB->get_record('assign', ['id' => $lab2->instance]);
        $this->assertEquals($this->due + WEEKSECS, $record->duedate);
        $this->assertEquals($this->due + WEEKSECS + DAYSECS, $record->cutoffdate);
        // The model's grading reminder is two days after its due date, and so is the copy's.
        $this->assertEquals($this->due + WEEKSECS + 2 * DAYSECS, $record->gradingduedate);
        $this->assertEquals(25, $record->grade);
        $this->assertEquals(3, $record->maxattempts);
        $this->assertEquals(0, $DB->get_field('assign', 'duedate', ['id' => $lab3->instance]));
        $this->assertEquals(0, $DB->get_field('assign', 'gradingduedate', ['id' => $lab3->instance]));

        $assignments = updater::get_assignments($course);
        $copy = $assignments[$lab2->instance]['assign'];
        $this->assertSame(['1', '1', '4', '1'], [$copy->onlinetext, $copy->file, $copy->maxfiles, $copy->fbcomments]);
        // No student work comes with the copy.
        $this->assertFalse($copy->hassubmissions);
        $this->assertSame(0, $DB->count_records('assign_submission', ['assignment' => $lab2->instance]));

        $event = $DB->get_record('event', ['modulename' => 'assign', 'instance' => $lab2->instance, 'eventtype' => 'due']);
        $this->assertEquals($this->due + WEEKSECS, $event->timestart);
        $item = \grade_item::fetch(['courseid' => $course->id, 'itemmodule' => 'assign', 'iteminstance' => $lab2->instance]);
        $this->assertSame('Lab 2', $item->itemname);
        $this->assertEquals(25, $item->grademax);
    }

    public function test_row_validation(): void {
        [$course] = $this->setup_course();
        [, $errors] = creator::parse_rows($course, [
            $this->row('', 1, $this->due),
            $this->row('Lab', 99),
            $this->row('Lab', 1, $this->due, $this->due - HOURSECS),
            ['duedate' => 'nonsense', 'name' => 'Lab', 'section' => '1'],
        ]);
        $this->assertSame(get_string('errorname', 'local_assignbulkedit'), $errors[0]['name']);
        $this->assertSame(get_string('errorchoice', 'local_assignbulkedit'), $errors[1]['section']);
        $this->assertSame(get_string('cutoffdatevalidation', 'assign'), $errors[2]['cutoffdate']);
        $this->assertSame(get_string('errordate', 'local_assignbulkedit'), $errors[3]['duedate']);
    }

    public function test_needs_duplicate_capabilities(): void {
        global $DB;
        [$course] = $this->setup_course();
        $this->assertTrue(creator::can_create($course));
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/backup:backuptargetimport', CAP_PROHIBIT, $role, \context_course::instance($course->id));
        $this->assertFalse(creator::can_create($course));
    }
}
