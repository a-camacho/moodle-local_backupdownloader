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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the access rules.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(access::class)]
final class access_test extends \advanced_testcase {
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
     * The core capabilities grant course backups to editing teachers and automated backups to managers.
     */
    public function test_core_capabilities(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course_context::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertTrue(access::can_download_course_backups($context, $teacher));
        $this->assertFalse(access::can_download_automated_backups($context, $teacher));

        $this->assertTrue(access::can_download_course_backups($context, $manager));
        $this->assertTrue(access::can_download_automated_backups($context, $manager));

        $this->assertFalse(access::can_download_course_backups($context, $student));
        $this->assertFalse(access::can_download_automated_backups($context, $student));
    }

    /**
     * Context freezing blocks the core (write) capabilities.
     */
    public function test_frozen_context_blocks_core_capabilities(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course_context::instance($course->id);
        $manager = $this->getDataGenerator()->create_and_enrol($course, 'manager');

        $this->freeze($context);

        $this->assertFalse(access::can_download_course_backups($context, $manager));
        $this->assertFalse(access::can_download_automated_backups($context, $manager));
    }

    /**
     * The plugin (read) capabilities keep working in a frozen context.
     */
    public function test_plugin_capabilities_survive_freezing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course_context::instance($course->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant([access::CAP_DOWNLOAD], (int) $student->id, $context);

        $this->freeze($context);

        $this->assertTrue(access::can_download_course_backups($context, $student));
        $this->assertFalse(access::can_download_automated_backups($context, $student));
    }

    /**
     * Automated backups require both plugin capabilities.
     */
    public function test_automated_requires_both_plugin_capabilities(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course_context::instance($course->id);
        $this->freeze($context);

        $onlyautomated = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant([access::CAP_DOWNLOAD_AUTOMATED], (int) $onlyautomated->id, $context);

        $this->assertFalse(access::can_download_course_backups($context, $onlyautomated));
        $this->assertFalse(access::can_download_automated_backups($context, $onlyautomated));

        $both = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant([access::CAP_DOWNLOAD, access::CAP_DOWNLOAD_AUTOMATED], (int) $both->id, $context);

        $this->assertTrue(access::can_download_course_backups($context, $both));
        $this->assertTrue(access::can_download_automated_backups($context, $both));
    }

    /**
     * The guest user never passes the course rules, even when the plugin capabilities are allowed to the guest role.
     */
    public function test_guest_never_passes_course_rules(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course_context::instance($course->id);
        $guestroleid = $DB->get_field('role', 'id', ['shortname' => 'guest'], MUST_EXIST);
        assign_capability(access::CAP_DOWNLOAD, CAP_ALLOW, $guestroleid, system_context::instance()->id, true);
        assign_capability(access::CAP_DOWNLOAD_AUTOMATED, CAP_ALLOW, $guestroleid, system_context::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $guest = guest_user();
        $this->setGuestUser();

        // The role definition itself is effective: this is exactly what the plugin rules must ignore.
        $this->assertTrue(has_capability(access::CAP_DOWNLOAD, $context, $guest));

        $this->assertFalse(access::can_download_course_backups($context, $guest));
        $this->assertFalse(access::can_download_automated_backups($context, $guest));
        // Same outcome for the current user (no explicit user passed).
        $this->assertFalse(access::can_download_course_backups($context));
        $this->assertFalse(access::can_download_automated_backups($context));

        // Not logged in at all.
        $this->setUser(null);
        $this->assertFalse(access::can_download_course_backups($context));
    }

    /**
     * Site administrators keep access in a frozen context even when context locking applies to them:
     * the core write capabilities are blocked, but administrators hold the plugin read capabilities.
     */
    public function test_site_admin_in_frozen_context(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = course_context::instance($course->id);
        $this->setAdminUser();
        $this->freeze($context);
        set_config('contextlockappliestoadmin', 1);

        $this->assertFalse(has_capability('moodle/backup:downloadfile', $context));
        $this->assertTrue(has_capability(access::CAP_DOWNLOAD, $context));

        $this->assertTrue(access::can_download_course_backups($context));
        $this->assertTrue(access::can_download_automated_backups($context));
    }

    /**
     * The private area is available to every real user but never to the guest user.
     */
    public function test_private_backups(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(access::can_download_private_backups($user));
        $this->assertFalse(access::can_download_private_backups(guest_user()));
    }

    /**
     * A course is frozen when it, its category or the site is locked, and only while context freezing is enabled.
     */
    public function test_is_frozen(): void {
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $context = course_context::instance($course->id);
        $categorycontext = \core\context\coursecat::instance($category->id);

        $this->assertFalse(access::is_frozen($context));

        $this->freeze($context);
        $this->assertTrue(access::is_frozen($context));

        set_config('contextlocking', 0);
        $this->assertFalse(access::is_frozen($context));

        set_config('contextlocking', 1);
        $context->set_locked(false);
        $this->assertFalse(access::is_frozen($context));

        $categorycontext->set_locked(true);
        $this->assertTrue(access::is_frozen(course_context::instance($course->id)));
    }
}
