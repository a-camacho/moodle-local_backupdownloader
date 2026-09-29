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

namespace local_backupdownloader\local;

use core\context\course as course_context;
use stdClass;

/**
 * Access rules of the plugin.
 *
 * Every rule accepts either the core capabilities checked by file_pluginfile() or the
 * plugin's own read-type capabilities. The latter are unaffected by context freezing,
 * which blocks every write capability (and therefore the core backup capabilities).
 *
 * The guest user and not-logged-in users never pass the course rules, whatever the
 * role definitions say: core refuses them any write capability, and the plugin
 * capabilities must not open a door core keeps closed.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** @var string Plugin capability granting course and section backups. */
    public const CAP_DOWNLOAD = 'local/backupdownloader:download';

    /** @var string Plugin capability granting automated backups (in addition to CAP_DOWNLOAD). */
    public const CAP_DOWNLOAD_AUTOMATED = 'local/backupdownloader:downloadautomated';

    /**
     * Whether the context is frozen, directly or through a parent category or the site.
     *
     * context::is_locked() ignores the contextlocking setting, while has_capability() only
     * blocks write capabilities when it is enabled. Both are checked so that a context keeping
     * its lock flag after the feature has been disabled is not considered frozen.
     *
     * @param course_context $context
     * @return bool
     */
    public static function is_frozen(course_context $context): bool {
        global $CFG;

        return !empty($CFG->contextlocking) && $context->is_locked();
    }

    /**
     * Whether the user may see the page and download course and section backups.
     *
     * @param course_context $context
     * @param stdClass|null $user Defaults to the current user.
     * @return bool
     */
    public static function can_download_course_backups(course_context $context, ?stdClass $user = null): bool {
        if (self::is_guest($user)) {
            return false;
        }

        return has_capability('moodle/backup:downloadfile', $context, $user)
            || has_capability(self::CAP_DOWNLOAD, $context, $user);
    }

    /**
     * Whether the user may download automated backups.
     *
     * The core rule is the one of file_pluginfile() and of the download link of the core
     * restore page: moodle/backup:downloadfile and moodle/restore:userinfo. The plugin
     * rule requires both plugin capabilities.
     *
     * @param course_context $context
     * @param stdClass|null $user Defaults to the current user.
     * @return bool
     */
    public static function can_download_automated_backups(course_context $context, ?stdClass $user = null): bool {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if (self::is_guest($user)) {
            return false;
        }

        $core = can_download_from_backup_filearea('automated', $context, $user);
        $plugin = has_capability(self::CAP_DOWNLOAD, $context, $user)
            && has_capability(self::CAP_DOWNLOAD_AUTOMATED, $context, $user);

        return $core || $plugin;
    }

    /**
     * Whether the user may download the backups of their own private area.
     *
     * The area is served to its owner only, except for the guest user, as in file_pluginfile().
     *
     * @param stdClass $user
     * @return bool
     */
    public static function can_download_private_backups(stdClass $user): bool {
        return !isguestuser($user);
    }

    /**
     * Whether the given user (or the current one) is the guest user or not logged in.
     *
     * @param stdClass|null $user
     * @return bool
     */
    private static function is_guest(?stdClass $user): bool {
        global $USER;

        $userid = (int) ($user->id ?? $USER->id ?? 0);

        return $userid === 0 || isguestuser($userid);
    }
}
