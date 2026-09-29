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

namespace local_backupdownloader\hook;

use core\context\course as course_context;
use core\hook\navigation\secondary_extend;
use core\output\pix_icon;
use local_backupdownloader\local\access;

/**
 * Hook callbacks for local_backupdownloader.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class callbacks {
    /** @var string Navigation node key, also used by index.php to mark the tab as active. */
    public const NAV_KEY = 'local_backupdownloader';

    /**
     * Add a "Backup downloads" node to the course secondary navigation.
     *
     * Core dispatches {@see secondary_extend} at the end of the course navigation build,
     * which also runs for the front page and for the module-context home page of
     * single-activity courses. The node is therefore added only for a real course
     * context (single-activity courses never get the tab) that is frozen, and only when
     * the current user may download backup files from this course through either the core
     * capability or the plugin one (see {@see access}). Outside frozen contexts the core
     * restore page already lists the backup files.
     *
     * @param secondary_extend $hook The hook instance carrying the secondary navigation view.
     * @return void
     */
    public static function extend_secondary_navigation(secondary_extend $hook): void {
        global $PAGE;

        $context = $PAGE->context;
        if (!$context instanceof course_context) {
            return;
        }

        $courseid = (int) $context->instanceid;
        if ($courseid === (int) SITEID) {
            return;
        }

        if (!access::is_frozen($context) || !access::can_download_course_backups($context)) {
            return;
        }

        $url = new \moodle_url('/local/backupdownloader/index.php', ['id' => $courseid]);

        $hook->get_secondaryview()->add(
            get_string('pluginname', 'local_backupdownloader'),
            $url,
            \navigation_node::TYPE_CUSTOM,
            null,
            self::NAV_KEY,
            new pix_icon('i/backup', ''),
        );
    }
}
