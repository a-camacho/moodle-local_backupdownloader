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
use core\context\user as user_context;
use stdClass;
use stored_file;

/**
 * Collects the Moodle backup files (.mbz) a user may download for a given course.
 *
 * The file areas mirror what {@see \backup_helper::store_backup_file()} writes:
 *
 * | Origin            | Context | Component | File area   | Item id    |
 * |-------------------|---------|-----------|-------------|------------|
 * | Course backup     | course  | backup    | course      | 0          |
 * | Section backup    | course  | backup    | section     | section id |
 * | Automated backup  | course  | backup    | automated   | 0          |
 * | User private area | user    | user      | backup      | 0          |
 *
 * Access rules are those of {@see access}: the checks performed by file_pluginfile() in
 * lib/filelib.php, or the plugin's own read-type capabilities which survive context
 * freezing. Files are served by download.php, which relies on {@see self::can_serve()}
 * to apply exactly the same rules, so every link produced here is actually downloadable.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_finder {
    /** @var string Extension of Moodle backup files. */
    private const BACKUP_EXTENSION = '.mbz';

    /**
     * Constructor.
     *
     * @param course_context $coursecontext Context of the course being inspected.
     * @param stdClass $user The user whose private backup area is also listed (usually $USER).
     */
    public function __construct(
        /** @var course_context Context of the course being inspected. */
        private readonly course_context $coursecontext,
        /** @var stdClass The user whose private backup area is also listed. */
        private readonly stdClass $user,
    ) {
    }

    /**
     * Return every downloadable backup file, most recent first.
     *
     * Each entry is a stdClass with:
     *  - file:        {@see stored_file}
     *  - origin:      area token saying where the file comes from: course, section, automated or user
     *  - downloadurl: {@see \moodle_url} pointing to download.php with forced download
     *
     * @return stdClass[]
     */
    public function find(): array {
        $backups = [];
        foreach ($this->rules() as $rule) {
            if (!$rule->allowed) {
                continue;
            }
            $backups = array_merge(
                $backups,
                $this->collect($rule->contextid, $rule->component, $rule->filearea, $rule->itemid, $rule->origin),
            );
        }

        usort($backups, static fn(stdClass $a, stdClass $b): int =>
            $b->file->get_timemodified() <=> $a->file->get_timemodified());

        return $backups;
    }

    /**
     * Whether download.php may serve the given file to the user.
     *
     * The file has to be a backup archive stored in one of the listed areas of this
     * course (or of the user's private area), and the user has to pass the access rule
     * of that area. This is the exact counterpart of {@see self::find()}.
     *
     * @param stored_file $file
     * @return bool
     */
    public function can_serve(stored_file $file): bool {
        if (!$this->is_backup_file($file)) {
            return false;
        }

        foreach ($this->rules() as $rule) {
            if (
                (int) $file->get_contextid() === $rule->contextid
                && $file->get_component() === $rule->component
                && $file->get_filearea() === $rule->filearea
                && ($rule->itemid === false || (int) $file->get_itemid() === $rule->itemid)
            ) {
                return $rule->allowed;
            }
        }

        return false;
    }

    /**
     * Describe every file area the plugin knows about, with the outcome of its access rule.
     *
     * Each rule is a stdClass with contextid, component, filearea, itemid (int, or false for
     * any item id), origin (area token: course, section, automated or user) and allowed (bool).
     *
     * @return stdClass[]
     */
    private function rules(): array {
        $contextid = $this->coursecontext->id;

        // Course and section backups share the same rule. file_pluginfile() only serves
        // item id 0 of the course area, while the section area uses the section id.
        $course = access::can_download_course_backups($this->coursecontext, $this->user);
        $automated = access::can_download_automated_backups($this->coursecontext, $this->user);
        $private = access::can_download_private_backups($this->user);

        $rules = [
            $this->rule($contextid, 'backup', 'course', 0, 'course', $course),
            $this->rule($contextid, 'backup', 'section', false, 'section', $course),
            $this->rule($contextid, 'backup', 'automated', 0, 'automated', $automated),
        ];

        // The private backup area belongs to the user context, never to the course.
        $usercontext = user_context::instance((int) $this->user->id, IGNORE_MISSING);
        if ($usercontext) {
            $rules[] = $this->rule($usercontext->id, 'user', 'backup', 0, 'user', $private);
        }

        return $rules;
    }

    /**
     * Build one rule entry for {@see self::rules()}.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int|false $itemid
     * @param string $origin Area token: course, section, automated or user.
     * @param bool $allowed
     * @return stdClass
     */
    private function rule(
        int $contextid,
        string $component,
        string $filearea,
        int|false $itemid,
        string $origin,
        bool $allowed,
    ): stdClass {
        $rule = new stdClass();
        $rule->contextid = $contextid;
        $rule->component = $component;
        $rule->filearea = $filearea;
        $rule->itemid = $itemid;
        $rule->origin = $origin;
        $rule->allowed = $allowed;

        return $rule;
    }

    /**
     * List the .mbz files of a single file area.
     *
     * @param int $contextid Context id owning the file area.
     * @param string $component File component (backup or user).
     * @param string $filearea File area name.
     * @param int|false $itemid Item id served for this area, or false for all item ids.
     * @param string $origin Area token: course, section, automated or user.
     * @return stdClass[]
     */
    private function collect(int $contextid, string $component, string $filearea, int|false $itemid, string $origin): array {
        $fs = get_file_storage();

        // Sorted by modification time, directories excluded.
        $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'timemodified DESC', false);

        $result = [];
        foreach ($files as $file) {
            if (!$this->is_backup_file($file)) {
                continue;
            }

            $entry = new stdClass();
            $entry->file = $file;
            $entry->origin = $origin;
            $entry->downloadurl = $this->build_download_url($file);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Whether the stored file is a Moodle backup archive.
     *
     * @param stored_file $file
     * @return bool
     */
    private function is_backup_file(stored_file $file): bool {
        if ($file->is_directory()) {
            return false;
        }

        return str_ends_with(strtolower($file->get_filename()), self::BACKUP_EXTENSION);
    }

    /**
     * Build the download.php URL serving the given backup file.
     *
     * @param stored_file $file
     * @return \moodle_url
     */
    private function build_download_url(stored_file $file): \moodle_url {
        return new \moodle_url('/local/backupdownloader/download.php', [
            'id' => $this->coursecontext->instanceid,
            'file' => $file->get_id(),
        ]);
    }
}
