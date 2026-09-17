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

declare(strict_types=1);

namespace local_backupdownloader\event;

use core\event\base;

/**
 * Event fired when a user views the list of downloadable backup files of a course.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_list_viewed extends base {
    /**
     * Initialise the event data.
     *
     * @return void
     */
    #[\Override]
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_backup_list_viewed', 'local_backupdownloader');
    }

    /**
     * Non-localised description of what happened.
     *
     * @return string
     */
    #[\Override]
    public function get_description(): string {
        return "The user with id '{$this->userid}' viewed the list of downloadable backup files " .
            "of the course with id '{$this->courseid}'.";
    }

    /**
     * URL of the page where the event happened.
     *
     * @return \moodle_url
     */
    #[\Override]
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/backupdownloader/index.php', ['id' => $this->courseid]);
    }

    /**
     * Validate the event data.
     *
     * @return void
     * @throws \coding_exception when the context is not a course context.
     */
    #[\Override]
    protected function validate_data(): void {
        parent::validate_data();

        if ($this->contextlevel !== CONTEXT_COURSE) {
            throw new \coding_exception('The backup_list_viewed event must be triggered in a course context.');
        }
    }
}
