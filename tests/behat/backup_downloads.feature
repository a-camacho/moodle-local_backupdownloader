@local @local_backupdownloader
Feature: Download course backup files
  In order to retrieve backups of my course
  As a teacher
  I need a page listing the backup files with download links

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following config values are set as admin:
      | enableasyncbackup | 0 |

  @javascript
  Scenario: A teacher granted the plugin capability sees the tab, the backup file and a download link in a frozen course
    # Editing teachers lack moodle/backup:userinfo, so their backups land in the private area.
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    And I backup "Course 1" course using this options:
      | Confirmation | Filename | test_backup.mbz |
    And the "C1" course is frozen
    And the following "role capabilities" exist:
      | role           | local/backupdownloader:download |
      | editingteacher | allow                           |
    When I am on the "Course 1" "Course" page
    And I navigate to "Backup downloads" in current page administration
    Then I should see "Backup downloads"
    And "test_backup.mbz" "table_row" should exist
    And I should see "Private backup" in the "test_backup.mbz" "table_row"
    And I should see "No backup files are available in the course backup area."
    And following "Download" should download between "1000" and "10000000" bytes

  Scenario: A teacher without backups sees the empty state in a frozen course
    Given the "C1" course is frozen
    And the following "role capabilities" exist:
      | role           | local/backupdownloader:download |
      | editingteacher | allow                           |
    When I am on the "C1" "local_backupdownloader > Backup downloads" page logged in as "teacher1"
    Then I should see "No backup files are available in the course backup area."
    And I should see "No backup files are available in your private backup area."
    And "Download" "link" should not exist

  Scenario: A student does not see the tab
    Given the "C1" course is frozen
    When I am on the "Course 1" "Course" page logged in as "student1"
    Then "Backup downloads" "link" should not exist in the ".secondary-navigation" "css_element"

  Scenario: Outside frozen contexts the tab is hidden and the page points to the restore page
    Given I am on the "Course 1" "Course" page logged in as "teacher1"
    Then "Backup downloads" "link" should not exist in the ".secondary-navigation" "css_element"
    When I am on the "C1" "local_backupdownloader > Backup downloads" page
    Then I should see "This page is only available when the course is frozen."
    And "Back to course" "link" should exist
    And "Filename" "table" should not exist
    And I click on "Go to restore page" "link"
    And I should see "Course backup area"

  Scenario: Outside frozen contexts a user who cannot restore the course is only offered the way back
    Given the following "role capabilities" exist:
      | role    | local/backupdownloader:download |
      | student | allow                           |
    When I am on the "C1" "local_backupdownloader > Backup downloads" page logged in as "student1"
    Then I should see "This page is only available when the course is frozen."
    And "Go to restore page" "link" should not exist
    And I click on "Back to course" "link"
    And I should see "Course 1" in the "page-header" "region"

  @javascript
  Scenario: A teacher loses the tab in a frozen course unless granted the plugin capability
    Given the following config values are set as admin:
      | contextlocking | 1 |
    And I am on the "Course 1" "Course" page logged in as "admin"
    # Administrators hold moodle/backup:userinfo, so their backups land in the course area.
    And I backup "Course 1" course using this options:
      | Confirmation | Filename | admin_backup.mbz |
    And I am on the "Course 1" "Course" page
    And I navigate to "Freeze this context" in current page administration
    And I click on "Continue" "button"
    And I log out
    When I am on the "Course 1" "Course" page logged in as "teacher1"
    Then "Backup downloads" "link" should not exist in the ".secondary-navigation" "css_element"
    And I log out
    And the following "role capabilities" exist:
      | role           | local/backupdownloader:download |
      | editingteacher | allow                           |
    When I am on the "Course 1" "Course" page logged in as "teacher1"
    And I navigate to "Backup downloads" in current page administration
    Then "admin_backup.mbz" "table_row" should exist
    And I should see "Course backup" in the "admin_backup.mbz" "table_row"
    And following "Download" should download between "1000" and "10000000" bytes
