@local @local_assignbulkedit @javascript
Feature: Create several assignments as copies of a model assignment
  In order to set up a term's assignments quickly
  As a teacher
  I need to create many assignments with the same settings but their own dates

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "courses" exist:
      | fullname | shortname | numsections |
      | Course 1 | C1        | 3           |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name  | course | section | assignsubmission_onlinetext_enabled | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | duedate              |
      | assign   | Lab 1 | C1     | 1       | 0                                   | 1                             | 4                              | 0                                  | ##2030-01-10 17:00## |

  Scenario: Paste names, give each its own due date, preview, then create
    Given I am on the "Course 1" course page logged in as teacher1
    And I navigate to "Bulk edit assignments" in current page administration
    When I click on "Add assignments" "link"
    And I click on "Paste a list of names" "text"
    And I set the field "One name per line" to multiline:
      """
      Lab 2
      Lab 3
      """
    And I press "Add these names"
    And I set the field "r[0][duedate]" to "2030-01-17T17:00"
    And I set the field "r[1][duedate]" to "2030-01-24T17:00"
    And I set the field "r[1][cutoffdate]" to "2030-01-23T17:00"
    And I press "Preview"
    Then I should see "Cut-off date cannot be earlier than the due date"
    And I set the field "r[1][cutoffdate]" to "2030-01-25T17:00"
    And I press "Preview"
    And I should see "2 assignment(s) will be created as copies of Lab 1."
    And I press "Create assignments"
    And I should see "2 assignment(s) created."
    And the field "Due date: Lab 2" matches value "2030-01-17T17:00"
    And the field "Due date: Lab 3" matches value "2030-01-24T17:00"
    And the field "File submissions: Lab 3" matches value "Yes"
    And the field "Maximum number of files: Lab 3" matches value "4"
    And the field "Visibility: Lab 3" matches value "Shown"
