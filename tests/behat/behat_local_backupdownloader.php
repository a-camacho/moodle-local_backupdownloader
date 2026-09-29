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

// phpcs:disable moodle.NamingConventions.ValidVariableName.VariableNameLowerCase
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

declare(strict_types=1);

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat page resolvers for local_backupdownloader.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_backupdownloader extends behat_base {
    /**
     * Convert page names to URLs for steps like 'When I am on the "C1" "local_backupdownloader > Backup downloads" page'.
     *
     * Recognised page names:
     * | pagetype         | identifier       | description                          |
     * | Backup downloads | Course shortname | The backup downloads page of a course |
     *
     * @param string $page Page type.
     * @param string $identifier Identifier (course shortname).
     * @return moodle_url
     * @throws Exception when the page type is unknown.
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        switch (strtolower($page)) {
            case 'backup downloads':
                return new moodle_url('/local/backupdownloader/index.php', [
                    'id' => $this->get_course_id($identifier),
                ]);
            default:
                throw new Exception("Unrecognised page type '{$page}'");
        }
    }

    /**
     * Enable context freezing and freeze a course.
     *
     * @Given /^the "(?P<shortname_string>(?:[^"]|\\")*)" course is frozen$/
     * @param string $shortname Course shortname.
     * @return void
     */
    public function the_course_is_frozen(string $shortname): void {
        set_config('contextlocking', 1);
        \core\context\course::instance($this->get_course_id($shortname))->set_locked(true);
    }
}
