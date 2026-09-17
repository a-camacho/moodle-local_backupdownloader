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
use core\context\system as system_context;
use core\context\user as user_context;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the backup finder.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(backup_finder::class)]
final class backup_finder_test extends \advanced_testcase {
    /**
     * Create a file in the given area.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     * @param int $timemodified
     * @return \stored_file
     */
    private function create_file(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid,
        string $filename,
        int $timemodified,
    ): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'timecreated' => $timemodified,
            'timemodified' => $timemodified,
        ], 'dummy backup content');
    }

    /**
     * Populate every backup area of a course with one .mbz file plus some noise.
     *
     * @param \stdClass $course
     * @param \stdClass $user Owner of the private area.
     * @return void
     */
    private function populate_areas(\stdClass $course, \stdClass $user): void {
        $coursectx = course_context::instance($course->id);
        $userctx = user_context::instance($user->id);

        $this->create_file($coursectx->id, 'backup', 'course', 0, 'course.mbz', 100);
        $this->create_file($coursectx->id, 'backup', 'section', 5, 'section.mbz', 300);
        $this->create_file($coursectx->id, 'backup', 'automated', 0, 'automated.mbz', 200);
        $this->create_file($userctx->id, 'user', 'backup', 0, 'private.mbz', 400);
        // Noise: a non-backup file in the course area.
        $this->create_file($coursectx->id, 'backup', 'course', 0, 'notes.txt', 500);
    }

    /**
     * Map finder entries to "filename => origin".
     *
     * @param \stdClass[] $entries
     * @return array
     */
    private function summarise(array $entries): array {
        $result = [];
        foreach ($entries as $entry) {
            $result[$entry->file->get_filename()] = $entry->origin;
        }
        return $result;
    }

    /**
     * Editing teachers can download course, section and private backups, but not automated ones.
     */
    public function test_find_as_editing_teacher(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->populate_areas($course, $teacher);
        $this->setUser($teacher);

        $finder = new backup_finder(course_context::instance($course->id), $teacher);
        $entries = $finder->find();

        // Sorted most recent first, automated excluded, notes.txt excluded.
        $this->assertSame([
            'private.mbz' => 'user',
            'section.mbz' => 'section',
            'course.mbz' => 'course',
        ], $this->summarise($entries));
    }

    /**
     * Managers hold moodle/restore:userinfo and therefore also see automated backups.
     */
    public function test_find_as_manager(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->populate_areas($course, $manager);
        $this->setUser($manager);

        $finder = new backup_finder(course_context::instance($course->id), $manager);
        $summary = $this->summarise($finder->find());

        $this->assertArrayHasKey('automated.mbz', $summary);
        $this->assertSame('automated', $summary['automated.mbz']);
        $this->assertCount(4, $summary);
    }

    /**
     * Users without download capability only get their own private area.
     */
    public function test_find_as_student(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->populate_areas($course, $student);
        $this->setUser($student);

        $finder = new backup_finder(course_context::instance($course->id), $student);

        $this->assertSame(['private.mbz' => 'user'], $this->summarise($finder->find()));
    }

    /**
     * Download URLs point to the plugin download script with the course id and the file id.
     */
    public function test_download_urls(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->populate_areas($course, $teacher);
        $this->setUser($teacher);

        $finder = new backup_finder(course_context::instance($course->id), $teacher);
        $entries = $finder->find();
        $this->assertNotEmpty($entries);

        foreach ($entries as $entry) {
            $this->assertStringEndsWith(
                "/local/backupdownloader/download.php?id={$course->id}&file=" . $entry->file->get_id(),
                $entry->downloadurl->out(false),
                $entry->file->get_filename()
            );
        }
    }

    /**
     * Enable context locking and freeze the given context.
     *
     * @param course_context $context
     * @return void
     */
    private function freeze(course_context $context): void {
        set_config('contextlocking', 1);
        $context->set_locked(true);
    }

    /**
     * Create a role holding the given capabilities at system level and assign it to the user in the course.
     *
     * @param string[] $capabilities
     * @param int $userid
     * @param course_context $context
     * @return void
     */
    private function grant(array $capabilities, int $userid, course_context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, system_context::instance()->id, true);
        }
        role_assign($roleid, $userid, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * In a frozen course, users relying on the core capabilities only get their private area.
     */
    public function test_find_frozen_as_manager(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->populate_areas($course, $manager);
        $this->setUser($manager);

        $context = course_context::instance($course->id);
        $this->freeze($context);

        $this->assertSame(['private.mbz' => 'user'], $this->summarise((new backup_finder($context, $manager))->find()));
    }

    /**
     * In a frozen course, the plugin capabilities give access to every area.
     */
    public function test_find_frozen_with_plugin_capabilities(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->populate_areas($course, $user);
        $this->setUser($user);

        $context = course_context::instance($course->id);
        $this->grant([access::CAP_DOWNLOAD], (int) $user->id, $context);
        $this->freeze($context);

        $this->assertSame([
            'private.mbz' => 'user',
            'section.mbz' => 'section',
            'course.mbz' => 'course',
        ], $this->summarise((new backup_finder($context, $user))->find()));

        $this->grant([access::CAP_DOWNLOAD_AUTOMATED], (int) $user->id, $context);

        $this->assertSame([
            'private.mbz' => 'user',
            'section.mbz' => 'section',
            'automated.mbz' => 'automated',
            'course.mbz' => 'course',
        ], $this->summarise((new backup_finder($context, $user))->find()));
    }

    /**
     * can_serve() accepts exactly the files find() lists, and refuses everything else.
     */
    public function test_can_serve(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->populate_areas($course, $teacher);
        $this->setUser($teacher);

        $coursectx = course_context::instance($course->id);
        $otherctx = course_context::instance($other->id);
        $managerctx = user_context::instance($manager->id);

        $coursefile = $this->create_file($coursectx->id, 'backup', 'course', 0, 'served-course.mbz', 100);
        $sectionfile = $this->create_file($coursectx->id, 'backup', 'section', 3, 'served-section.mbz', 100);
        $automatedfile = $this->create_file($coursectx->id, 'backup', 'automated', 0, 'served-automated.mbz', 100);
        $privatefile = $this->create_file(user_context::instance($teacher->id)->id, 'user', 'backup', 0, 'served.mbz', 100);
        $notbackup = $this->create_file($coursectx->id, 'backup', 'course', 0, 'served.txt', 100);
        $badcourseitem = $this->create_file($coursectx->id, 'backup', 'course', 9, 'served-item9.mbz', 100);
        $othercourse = $this->create_file($otherctx->id, 'backup', 'course', 0, 'other-course.mbz', 100);
        $managerprivate = $this->create_file($managerctx->id, 'user', 'backup', 0, 'manager-private.mbz', 100);
        $directory = get_file_storage()->create_directory($coursectx->id, 'backup', 'course', 0, '/folder.mbz/');
        $wrongarea = $this->create_file($coursectx->id, 'course', 'summary', 0, 'summary.mbz', 100);

        $finder = new backup_finder($coursectx, $teacher);

        $this->assertTrue($finder->can_serve($coursefile));
        $this->assertTrue($finder->can_serve($sectionfile));
        $this->assertTrue($finder->can_serve($privatefile));
        // Editing teachers lack moodle/restore:userinfo.
        $this->assertFalse($finder->can_serve($automatedfile));
        $this->assertFalse($finder->can_serve($notbackup));
        $this->assertFalse($finder->can_serve($badcourseitem));
        $this->assertFalse($finder->can_serve($othercourse));
        // The manager's private area belongs to the manager only.
        $this->assertFalse($finder->can_serve($managerprivate));
        $this->assertFalse($finder->can_serve($wrongarea));
        $this->assertFalse($finder->can_serve($directory));

        // Managers may download automated backups and their own private area, never the teacher's.
        $managerfinder = new backup_finder($coursectx, $manager);
        $this->assertTrue($managerfinder->can_serve($automatedfile));
        $this->assertTrue($managerfinder->can_serve($managerprivate));
        $this->assertFalse($managerfinder->can_serve($privatefile));

        // Every listed entry is servable.
        foreach ($finder->find() as $entry) {
            $this->assertTrue($finder->can_serve($entry->file), $entry->file->get_filename());
        }
    }

    /**
     * can_serve() follows the freezing rules: core capabilities are blocked, plugin ones are not.
     */
    public function test_can_serve_frozen(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $coursectx = course_context::instance($course->id);
        $coursefile = $this->create_file($coursectx->id, 'backup', 'course', 0, 'course.mbz', 100);
        $privatefile = $this->create_file(user_context::instance($teacher->id)->id, 'user', 'backup', 0, 'private.mbz', 100);

        $this->freeze($coursectx);
        $finder = new backup_finder($coursectx, $teacher);

        $this->assertFalse($finder->can_serve($coursefile));
        $this->assertTrue($finder->can_serve($privatefile));

        $this->grant([access::CAP_DOWNLOAD], (int) $teacher->id, $coursectx);

        $this->assertTrue($finder->can_serve($coursefile));
    }

    /**
     * The automated area requires moodle/restore:userinfo, as in file_pluginfile(), but nothing more.
     */
    public function test_find_automated_requires_userinfo_only(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->populate_areas($course, $manager);
        $this->setUser($manager);

        $context = course_context::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        // Losing the restore page capability does not affect downloads.
        assign_capability('moodle/restore:viewautomatedfilearea', CAP_PROHIBIT, $roleid, $context->id, true);
        $summary = $this->summarise((new backup_finder($context, $manager))->find());
        $this->assertArrayHasKey('automated.mbz', $summary);

        // Losing moodle/restore:userinfo does.
        assign_capability('moodle/restore:userinfo', CAP_PROHIBIT, $roleid, $context->id, true);
        $summary = $this->summarise((new backup_finder($context, $manager))->find());
        $this->assertArrayNotHasKey('automated.mbz', $summary);
        $this->assertCount(3, $summary);
    }

    /**
     * Files stored with an item id that download.php does not serve are not listed.
     */
    public function test_find_ignores_unservable_itemids(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $this->setUser($manager);

        $coursectx = course_context::instance($course->id);
        $userctx = user_context::instance($manager->id);
        $this->create_file($coursectx->id, 'backup', 'course', 7, 'course-item7.mbz', 100);
        $this->create_file($coursectx->id, 'backup', 'automated', 7, 'automated-item7.mbz', 200);
        $this->create_file($userctx->id, 'user', 'backup', 7, 'private-item7.mbz', 300);
        // Section backups legitimately use the section id as item id.
        $this->create_file($coursectx->id, 'backup', 'section', 7, 'section-item7.mbz', 400);

        $summary = $this->summarise((new backup_finder($coursectx, $manager))->find());

        $this->assertSame(['section-item7.mbz' => 'section'], $summary);
    }

    /**
     * The guest user never gets a private area listed, as with file_pluginfile().
     */
    public function test_find_as_guest(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $guest = guest_user();
        $this->setGuestUser();

        $userctx = user_context::instance($guest->id);
        $this->create_file($userctx->id, 'user', 'backup', 0, 'guest.mbz', 100);

        $finder = new backup_finder(course_context::instance($course->id), $guest);

        $this->assertSame([], $finder->find());
    }

    /**
     * An empty course yields an empty list without errors.
     */
    public function test_find_empty(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $finder = new backup_finder(course_context::instance($course->id), $teacher);

        $this->assertSame([], $finder->find());
    }
}
