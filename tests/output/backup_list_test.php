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
use local_backupdownloader\local\backup_finder;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the backup list renderable.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(backup_list::class)]
final class backup_list_test extends \advanced_testcase {
    /**
     * Exported data contains one formatted row per backup file.
     */
    public function test_export_for_template(): void {
        global $PAGE;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Backup <b>course</b> R&D']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $context = course_context::instance($course->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'backup',
            'filearea' => 'course',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'course.mbz',
        ], str_repeat('x', 2048));

        $list = new backup_list((new backup_finder($context, $teacher))->find(), $course);
        $data = $list->export_for_template($PAGE->get_renderer('core'));

        [$coursesection, $usersection] = $data['sections'];
        $this->assertSame(get_string('area_course', 'local_backupdownloader'), $coursesection['title']);
        $this->assertTrue($coursesection['hasbackups']);
        $this->assertCount(1, $coursesection['backups']);
        $this->assertSame(get_string('area_user', 'local_backupdownloader'), $usersection['title']);
        $this->assertFalse($usersection['hasbackups']);
        $this->assertSame([], $usersection['backups']);

        $row = $coursesection['backups'][0];
        $this->assertSame('course.mbz', $row['filename']);
        $this->assertSame(display_size(2048), $row['size']);
        $this->assertSame(get_string('origin_course', 'local_backupdownloader'), $row['origin']);
        $this->assertStringContainsString('/local/backupdownloader/download.php?id=' . $course->id . '&file=', $row['downloadurl']);
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $data['courseurl']);
        // The course name goes through format_string (tags stripped, ampersand escaped once) and
        // the intro is built in PHP so the template does not escape it a second time.
        $this->assertSame(
            get_string('intro', 'local_backupdownloader', (object) ['coursename' => 'Backup course R&amp;D']),
            $data['intro']
        );
        $this->assertStringNotContainsString('&amp;amp;', $data['intro']);
    }

    /**
     * The rendered template shows one row per file with a download link naming the file.
     */
    public function test_render_rows(): void {
        global $PAGE;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $context = course_context::instance($course->id);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'backup',
            'filearea' => 'course',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'rendered.mbz',
        ], 'x');

        $html = $PAGE->get_renderer('core')->render(new backup_list((new backup_finder($context, $teacher))->find(), $course));

        $this->assertStringContainsString('<td class="text-break">rendered.mbz</td>', $html);
        $this->assertStringContainsString(get_string('origin_course', 'local_backupdownloader'), $html);
        $this->assertMatchesRegularExpression(
            '~<a href="[^"]*/local/backupdownloader/download\.php\?id=' . $course->id . '&amp;file=' . $file->get_id()
                . '" class="btn btn-primary btn-sm"~',
            $html
        );
        // The accessible name of the button is one translatable string, not "Download" glued to the file name.
        $this->assertStringContainsString(
            'aria-label="' . get_string('downloadfile', 'local_backupdownloader', 'rendered.mbz') . '"',
            $html
        );
        // The private area is empty: its section shows the empty state, not a table.
        $this->assertStringContainsString(get_string('nobackups_user', 'local_backupdownloader'), $html);
        $this->assertStringNotContainsString(get_string('nobackups_course', 'local_backupdownloader'), $html);
    }

    /**
     * The rendered template shows the course name escaped exactly once.
     */
    public function test_render_escapes_course_name_once(): void {
        global $PAGE;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'R&D']);
        $html = $PAGE->get_renderer('core')->render(new backup_list([], $course));

        $this->assertStringContainsString('"R&amp;D"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * Private backups land in the user section, course-level ones in the course section.
     */
    public function test_export_for_template_splits_areas(): void {
        global $PAGE;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->setUser($manager);

        $context = course_context::instance($course->id);
        $usercontext = \core\context\user::instance($manager->id);
        $files = [
            [$context->id, 'backup', 'course', 0, 'course.mbz'],
            [$context->id, 'backup', 'section', 2, 'section.mbz'],
            [$context->id, 'backup', 'automated', 0, 'automated.mbz'],
            [$usercontext->id, 'user', 'backup', 0, 'private.mbz'],
        ];
        foreach ($files as [$contextid, $component, $filearea, $itemid, $filename]) {
            get_file_storage()->create_file_from_string([
                'contextid' => $contextid,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => $itemid,
                'filepath' => '/',
                'filename' => $filename,
            ], 'x');
        }

        $list = new backup_list((new backup_finder($context, $manager))->find(), $course);
        $data = $list->export_for_template($PAGE->get_renderer('core'));

        [$coursesection, $usersection] = $data['sections'];
        $this->assertEqualsCanonicalizing(
            ['course.mbz', 'section.mbz', 'automated.mbz'],
            array_column($coursesection['backups'], 'filename')
        );
        $this->assertSame(['private.mbz'], array_column($usersection['backups'], 'filename'));
    }

    /**
     * Both sections show their empty state when no backup exists.
     */
    public function test_export_for_template_empty(): void {
        global $PAGE;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = (new backup_list([], $course))->export_for_template($PAGE->get_renderer('core'));

        $this->assertCount(2, $data['sections']);
        foreach ($data['sections'] as $section) {
            $this->assertFalse($section['hasbackups']);
            $this->assertSame([], $section['backups']);
            $this->assertNotEmpty($section['nobackups']);
        }
        $this->assertSame(get_string('nobackups_course', 'local_backupdownloader'), $data['sections'][0]['nobackups']);
        $this->assertSame(get_string('nobackups_user', 'local_backupdownloader'), $data['sections'][1]['nobackups']);
    }
}
