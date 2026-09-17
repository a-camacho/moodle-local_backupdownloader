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

namespace local_backupdownloader\output;

use core\context\course as course_context;
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use stdClass;

/**
 * Renderable table of downloadable backup files.
 *
 * Rendered with the local_backupdownloader/backup_list template as two sections: the
 * course areas (course, section and automated backups) and the user's private area.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_list implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass[] $backups Entries as produced by {@see \local_backupdownloader\local\backup_finder::find()}.
     * @param stdClass $course The course record the page belongs to.
     */
    public function __construct(
        /** @var stdClass[] Backup entries (file, origin, downloadurl). */
        private readonly array $backups,
        /** @var stdClass The course record the page belongs to. */
        private readonly stdClass $course,
    ) {
    }

    /**
     * Export the data for the Mustache template.
     *
     * @param renderer_base $output
     * @return array
     */
    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $rows = ['course' => [], 'user' => []];
        foreach ($this->backups as $backup) {
            $file = $backup->file;
            $area = $backup->origin === 'user' ? 'user' : 'course';
            $rows[$area][] = [
                'filename' => $file->get_filename(),
                'size' => display_size((int) $file->get_filesize()),
                'timemodified' => userdate((int) $file->get_timemodified()),
                'origin' => self::origin_label($backup->origin),
                'downloadurl' => $backup->downloadurl->out(false),
            ];
        }

        // The string keys are spelled out rather than built from the area token, so that the
        // Moodle string tools can find every string this plugin uses.
        $sections = [
            [
                'title' => get_string('area_course', 'local_backupdownloader'),
                'hasbackups' => !empty($rows['course']),
                'backups' => $rows['course'],
                'nobackups' => get_string('nobackups_course', 'local_backupdownloader'),
            ],
            [
                'title' => get_string('area_user', 'local_backupdownloader'),
                'hasbackups' => !empty($rows['user']),
                'backups' => $rows['user'],
                'nobackups' => get_string('nobackups_user', 'local_backupdownloader'),
            ],
        ];

        $coursecontext = course_context::instance((int) $this->course->id);
        // Already HTML-escaped by format_string(); it must not be escaped again by the template,
        // which is why backup_list.mustache prints the intro with a triple mustache.
        $coursename = format_string($this->course->fullname, true, ['context' => $coursecontext]);

        return [
            'sections' => $sections,
            'intro' => get_string('intro', 'local_backupdownloader', (object) ['coursename' => $coursename]),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $this->course->id]))->out(false),
        ];
    }

    /**
     * Translate the area token of an entry into its localised label.
     *
     * @param string $origin Area token as produced by {@see \local_backupdownloader\local\backup_finder::find()}.
     * @return string
     * @throws \coding_exception when the token is unknown.
     */
    private static function origin_label(string $origin): string {
        return match ($origin) {
            'course' => get_string('origin_course', 'local_backupdownloader'),
            'section' => get_string('origin_section', 'local_backupdownloader'),
            'automated' => get_string('origin_automated', 'local_backupdownloader'),
            'user' => get_string('origin_user', 'local_backupdownloader'),
            default => throw new \coding_exception("Unknown backup area token '{$origin}'."),
        };
    }
}
