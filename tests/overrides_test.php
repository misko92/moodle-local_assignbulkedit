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

use local_assignbulkedit\local\overrides;

/**
 * Tests for bulk extensions and overrides.
 *
 * @package    local_assignbulkedit
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_assignbulkedit\local\overrides
 */
final class overrides_test extends \advanced_testcase {
    /** @var int Due date of the first assignment. */
    private int $due;

    /**
     * Course with two assignments (one without a due date), two students in a group, and a teacher, logged in.
     *
     * @return array [course, assign1, assign2, student1, student2, group]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $this->due = (int) (floor(time() / 60) * 60) + 3 * DAYSECS;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $assigngen = $gen->get_plugin_generator('mod_assign');
        $assign1 = $assigngen->create_instance(['course' => $course->id, 'duedate' => $this->due,
            'allowsubmissionsfromdate' => 0, 'cutoffdate' => $this->due + DAYSECS]);
        $assign2 = $assigngen->create_instance(['course' => $course->id, 'duedate' => 0,
            'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0]);
        $student1 = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ann']);
        $student2 = $gen->create_and_enrol($course, 'student', ['firstname' => 'Bob']);
        $group = $gen->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $student1->id]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $student2->id]);
        $this->setUser($gen->create_and_enrol($course, 'editingteacher'));
        return [$course, $assign1, $assign2, $student1, $student2, $group];
    }

    /**
     * Form data with nothing chosen.
     *
     * @param array $data values to set
     * @return \stdClass
     */
    private function data(array $data): \stdClass {
        return (object) ($data + [
            'allowsubmissionsfromdatemode' => 'none',
            'duedatemode' => 'none',
            'cutoffdatemode' => 'none',
            'timelimitmode' => 'none',
            'extensionmode' => 'set',
            'reason' => '',
        ]);
    }

    /**
     * Plan and apply.
     *
     * @param \stdClass $course
     * @param array $targets
     * @param \stdClass $data
     * @return array plan rows
     */
    private function run_plan(\stdClass $course, array $targets, \stdClass $data): array {
        $assignments = overrides::get_assignments($course);
        $plan = overrides::plan($course, $assignments, $targets, $data);
        overrides::apply($course, $assignments, $plan, $data->action);
        return $plan;
    }

    public function test_extensions(): void {
        global $DB;
        [$course, $assign1, $assign2, $student1, $student2] = $this->setup_course();
        $targets = [['userid' => $student1->id, 'name' => 'Ann'], ['userid' => $student2->id, 'name' => 'Bob']];
        $plan = $this->run_plan($course, $targets, $this->data([
            'action' => 'extend', 'extensionmode' => 'add', 'extensionadd' => 2 * DAYSECS,
        ]));
        // Assignment 2 has no due date to extend.
        $this->assertSame(['create', 'create', 'skip', 'skip'], array_column($plan, 'action'));
        $this->assertSame(get_string('ovnodue', 'local_assignbulkedit'), $plan[2]['note']);
        $flags = $DB->get_records_menu('assign_user_flags', ['assignment' => $assign1->id], '', 'userid, extensionduedate');
        $this->assertEquals([$student1->id => $this->due + 2 * DAYSECS, $student2->id => $this->due + 2 * DAYSECS], $flags);
        $this->assertSame(2, $DB->count_records('event', ['modulename' => 'assign', 'instance' => $assign1->id,
            'eventtype' => ASSIGN_EVENT_TYPE_EXTENSION]));

        // A date before the due date is refused, as on the Grant extension page.
        $plan = $this->run_plan($course, [$targets[0]], $this->data([
            'action' => 'extend', 'extensiondate' => $this->due - HOURSECS,
        ]));
        $this->assertSame('skip', $plan[0]['action']);
        $this->assertSame(get_string('extensionnotafterduedate', 'assign'), $plan[0]['note']);
        $this->assertSame('create', $plan[1]['action']);

        $plan = $this->run_plan($course, $targets, $this->data(['action' => 'unextend']));
        $this->assertSame(['delete', 'delete', 'delete', 'skip'], array_column($plan, 'action'));
        $this->assertSame(0, $DB->count_records_select('assign_user_flags', 'extensionduedate > 0'));
        $this->assertSame(0, $DB->count_records('event', ['eventtype' => ASSIGN_EVENT_TYPE_EXTENSION]));
    }

    public function test_overrides_merge_into_existing(): void {
        global $DB;
        [$course, $assign1, , $student1] = $this->setup_course();
        $target = [['userid' => $student1->id, 'name' => 'Ann']];
        $assignments = [$assign1->id => overrides::get_assignments($course)[$assign1->id]];

        $data = $this->data(['action' => 'save', 'cutoffdatemode' => 'add', 'cutoffdateadd' => 2 * DAYSECS]);
        $plan = overrides::plan($course, $assignments, $target, $data);
        overrides::apply($course, $assignments, $plan, 'save');
        $this->assertSame('create', $plan[0]['action']);

        $data = $this->data(['action' => 'save', 'duedatemode' => 'add', 'duedateadd' => DAYSECS, 'reason' => 'IEP']);
        $plan = overrides::plan($course, $assignments, $target, $data);
        $this->assertSame('update', $plan[0]['action']);
        overrides::apply($course, $assignments, $plan, 'save');

        $override = $DB->get_record('assign_overrides', ['assignid' => $assign1->id, 'userid' => $student1->id]);
        $this->assertEquals($this->due + DAYSECS, $override->duedate);
        $this->assertEquals($this->due + 3 * DAYSECS, $override->cutoffdate);
        $this->assertNull($override->allowsubmissionsfromdate);
        $this->assertSame('IEP', $override->reason);
        $this->assertSame(1, $DB->count_records('assign_overrides'));
        $event = $DB->get_record('event', ['modulename' => 'assign', 'instance' => $assign1->id,
            'userid' => $student1->id, 'eventtype' => 'due']);
        $this->assertEquals($this->due + DAYSECS, $event->timestart);

        // Same again: nothing to do.
        $plan = overrides::plan($course, $assignments, $target, $data);
        $this->assertSame('skip', $plan[0]['action']);
    }

    public function test_override_dates_are_checked(): void {
        [$course, $assign1, $assign2, $student1] = $this->setup_course();
        $target = [['userid' => $student1->id, 'name' => 'Ann']];
        $assignments = overrides::get_assignments($course);
        // Due after the cut-off date.
        $plan = overrides::plan($course, $assignments, $target, $this->data([
            'action' => 'save', 'duedatemode' => 'set', 'duedate' => $this->due + 2 * DAYSECS,
        ]));
        $this->assertSame(['skip', 'create'], array_column($plan, 'action'));
        $this->assertSame(get_string('cutoffdatevalidation', 'assign'), $plan[0]['note']);

        // Same as the assignment's own setting.
        $plan = overrides::plan($course, $assignments, $target, $this->data([
            'action' => 'save', 'duedatemode' => 'set', 'duedate' => $this->due,
        ]));
        $this->assertSame('skip', $plan[0]['action']);
        $this->assertSame(get_string('ovsameasassign', 'local_assignbulkedit'), $plan[0]['note']);
    }

    public function test_group_overrides_and_removal(): void {
        global $DB;
        [$course, $assign1, , , , $group] = $this->setup_course();
        $target = [['groupid' => $group->id, 'name' => 'Group A']];
        $this->run_plan($course, $target, $this->data([
            'action' => 'save', 'allowsubmissionsfromdatemode' => 'set', 'allowsubmissionsfromdate' => $this->due - DAYSECS,
        ]));
        $override = $DB->get_record('assign_overrides', ['assignid' => $assign1->id, 'groupid' => $group->id]);
        $this->assertEquals(1, $override->sortorder);
        $this->assertSame(2, $DB->count_records('assign_overrides'));

        $plan = $this->run_plan($course, $target, $this->data(['action' => 'delete']));
        $this->assertSame(['delete', 'delete'], array_column($plan, 'action'));
        $this->assertSame(0, $DB->count_records('assign_overrides'));
    }

    public function test_capabilities_are_checked_per_assignment(): void {
        global $DB;
        [$course, $assign1, $assign2, $student1] = $this->setup_course();
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('mod/assign:manageoverrides', CAP_PROHIBIT, $role, \context_module::instance($assign1->cmid));
        $assignments = overrides::get_assignments($course);
        // Still listed, as extensions may be granted.
        $this->assertCount(2, $assignments);
        $plan = overrides::plan($course, $assignments, [['userid' => $student1->id, 'name' => 'Ann']], $this->data([
            'action' => 'save', 'cutoffdatemode' => 'set', 'cutoffdate' => $this->due + 2 * DAYSECS,
        ]));
        $this->assertSame(['skip', 'create'], array_column($plan, 'action'));
        $this->assertSame(get_string('ovnopermission', 'local_assignbulkedit'), $plan[0]['note']);
    }
}
