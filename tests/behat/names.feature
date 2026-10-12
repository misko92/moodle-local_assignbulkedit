@local @local_assignbulkedit @javascript
Feature: Assignment, section and group names show as typed
  In order to recognise my assignments and groups
  As a teacher
  I need names with special characters like & to show correctly, not as "&amp;"

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Sam       | Student  |
    And the following "courses" exist:
      | fullname | shortname | initsections |
      | Course 1 | C1        | 1            |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "groups" exist:
      | name      | course | idnumber |
      | Lab A & B | C1     | G1       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
    And the following "activities" exist:
      | activity | name         | course | section | duedate            |
      | assign   | Moles & Mass | C1     | 1       | ##tomorrow 17:00## |
    And I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    And I edit the section "1" and I fill the form with:
      | Section name | Atoms & Ions |

  Scenario: Names on the bulk edit and add assignments pages
    When I navigate to "Bulk edit assignments" in current page administration
    Then I should see "Moles & Mass" in the ".local-assignbulkedit-table" "css_element"
    And I should see "Atoms & Ions" in the ".local-assignbulkedit-table" "css_element"
    And I should not see "&amp;"
    And I click on "Add assignments" "link"
    And I should not see "&amp;"
    And I should see "Moles & Mass" in the "#local_assignbulkedit_model" "css_element"
    And I should see "Atoms & Ions" in the "#local_assignbulkedit_r0_section" "css_element"

  Scenario: Names on the extensions and overrides page
    When I navigate to "Bulk edit assignments" in current page administration
    And I click on "Extensions & overrides" "link"
    And I set the field "Action" to "Add or update overrides"
    And I set the field "Groups" to "Lab A & B"
    And I set the field "All 1 assignments in this course" to "1"
    And I set the field "Due date" to "Add time to the assignment's due date"
    And I set the field "duedateadd[number]" to "1"
    And I set the field "duedateadd[timeunit]" to "days"
    And I press "Preview"
    Then I should see "Moles & Mass" in the "table.generaltable" "css_element"
    And I should see "Lab A & B" in the "table.generaltable" "css_element"
    And I should not see "&amp;"
    And I press "Apply"
    And I should see "Group: Lab A & B"
    And I should not see "&amp;"
