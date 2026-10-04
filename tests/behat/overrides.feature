@local @local_assignbulkedit @javascript
Feature: Extensions and overrides on many assignments at once
  In order to give students their accommodations quickly
  As a teacher
  I need to grant extensions and overrides on many assignments at once

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Sam       | Student  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name      | course | duedate            | cutoffdate |
      | assign   | Essay 1   | C1     | ##tomorrow 17:00## | 0          |
      | assign   | Essay 2   | C1     | ##+3 days 17:00##  | 0          |
      | assign   | Reading   | C1     | 0                  | 0          |

  Scenario: Give a student a two-day extension on every assignment, then remove it
    Given I am on the "Course 1" course page logged in as teacher1
    And I navigate to "Bulk edit assignments" in current page administration
    When I click on "Extensions & overrides" "link"
    And I set the field "Students" to "Sam Student"
    And I set the field "All 3 assignments in this course" to "1"
    And I set the field "Extension" to "Add time to the student's due date"
    And I set the field "extensionadd[number]" to "2"
    And I set the field "extensionadd[timeunit]" to "days"
    And I press "Preview"
    Then I should see "2 to save or remove, 1 skipped"
    And I should see "There is no due date to extend."
    And I should see "##+3 days 17:00##%A, %d %B %Y##"
    And I press "Apply"
    And I should see "2 saved, 0 removed."
    And I should see "##+5 days 17:00##%A, %d %B %Y##"
    # Remove them again.
    And I set the field "Action" to "Remove extensions"
    And I set the field "Students" to "Sam Student"
    And I set the field "All 3 assignments in this course" to "1"
    And I press "Preview"
    And I press "Apply"
    And I should see "0 saved, 2 removed."
    And I should see "No assignment in this course has extensions or overrides."

  Scenario: Give a student a later due date on chosen assignments
    Given I am on the "Course 1" course page logged in as teacher1
    And I navigate to "Bulk edit assignments" in current page administration
    When I click on "Extensions & overrides" "link"
    And I set the field "Action" to "Add or update overrides"
    And I set the field "Students" to "Sam Student"
    And I set the field "Assignments" to "Essay 1, Essay 2"
    And I set the field "Due date" to "Add time to the assignment's due date"
    And I set the field "duedateadd[number]" to "1"
    And I set the field "duedateadd[timeunit]" to "days"
    And I set the field "Reason" to "IEP"
    And I press "Preview"
    And I press "Apply"
    Then I should see "2 saved, 0 removed."
    And I am on the "Essay 2" "assign activity" page
    And I navigate to "Overrides" in current page administration
    And I should see "##+4 days 17:00##%A, %d %B %Y##"
