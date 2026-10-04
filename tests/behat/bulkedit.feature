@local @local_assignbulkedit @javascript
Feature: Bulk edit assignment settings
  In order to save time
  As a teacher
  I need to change settings of many assignments on one page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name       | course | assignsubmission_onlinetext_enabled | assignsubmission_file_enabled | assignsubmission_file_maxfiles | assignsubmission_file_maxsizebytes | duedate               |
      | assign   | Essay      | C1     | 1                                   | 0                             | 1                              | 0                                  | ##2030-01-10 17:00## |
      | assign   | Lab report | C1     | 0                                   | 1                             | 3                              | 0                                  | ##2030-01-10 17:00## |
      | assign   | Poster     | C1     | 1                                   | 1                             | 1                              | 0                                  | ##2030-01-11 17:00## |

  Scenario: Set the same due date and file submissions on selected assignments
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Bulk edit assignments" in current page administration
    And I click on "Select Essay" "checkbox"
    And I click on "Select Poster" "checkbox"
    And I set the field "Setting" to "Due date"
    And I set the field "Value" to "2030-01-17T09:30"
    And I press "Apply to selected"
    And I set the field "Setting" to "File submissions"
    And I set the field "Value" to "Yes"
    And I press "Apply to selected"
    And I set the field "Maximum number of files: Essay" to "5"
    And I press "Save changes"
    Then I should see "4 change(s) to 2 assignment(s):" in the "Review changes" "dialogue"
    And I click on "Save" "button" in the "Review changes" "dialogue"
    And I should see "2 assignment(s) updated."
    And the field "Due date: Essay" matches value "2030-01-17T09:30"
    And the field "Due date: Poster" matches value "2030-01-17T09:30"
    And the field "Due date: Lab report" matches value "2030-01-10T17:00"
    And the field "File submissions: Essay" matches value "Yes"
    And the field "Maximum number of files: Essay" matches value "5"

  Scenario: Copy all settings from another assignment, but not its dates
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Bulk edit assignments" in current page administration
    And I click on "Select Essay" "checkbox"
    And I set the field "Setting" to "All settings (except name, visibility and dates)"
    And I set the field "Value" to "Same as Lab report"
    And I press "Apply to selected"
    And I press "Save changes"
    And I should see "Turning off a submission type" in the "Review changes" "dialogue"
    And I click on "Save" "button" in the "Review changes" "dialogue"
    Then I should see "1 assignment(s) updated."
    And the field "Online text: Essay" matches value "No"
    And the field "File submissions: Essay" matches value "Yes"
    And the field "Maximum number of files: Essay" matches value "3"
    And the field "Due date: Essay" matches value "2030-01-10T17:00"

  Scenario: Invalid dates are refused and nothing is saved
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Bulk edit assignments" in current page administration
    And I click on "Columns" "button"
    And I click on "Cut-off date" "checkbox"
    And I press "Columns"
    And I set the field "Cut-off date: Essay" to "2030-01-10T09:00"
    And I set the field "Online text: Poster" to "No"
    And I press "Save changes"
    And I click on "Save" "button" in the "Review changes" "dialogue"
    Then I should see "Nothing was saved."
    And I should see "Cut-off date cannot be earlier than the due date"
    And I am on the "Course 1" course page
    And I navigate to "Bulk edit assignments" in current page administration
    And the field "Online text: Poster" matches value "Yes"

  Scenario: Filter by keyword, then bulk changes only touch the assignments shown
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Bulk edit assignments" in current page administration
    And I set the field "Filter" to "lab"
    Then I should see "Showing 1 of 3 assignments"
    And "Select Essay" "checkbox" should not be visible
    And I click on "Select all assignments" "checkbox"
    And I set the field "Setting" to "Visibility"
    And I set the field "Value" to "Hidden"
    And I press "Apply to selected"
    And I press "Save changes"
    And I click on "Save" "button" in the "Review changes" "dialogue"
    And I should see "1 assignment(s) updated."
    And the field "Visibility: Lab report" matches value "Hidden"
    And the field "Visibility: Essay" matches value "Shown"
