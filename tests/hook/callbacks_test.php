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
use core\context\system as system_context;
use core\hook\navigation\secondary_extend;
use core\navigation\views\secondary;
use local_backupdownloader\local\access;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the navigation hook callback.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(callbacks::class)]
final class callbacks_test extends \advanced_testcase {
    /**
     * Build a secondary navigation view for the course page and run the callback.
     *
     * @param \stdClass $course
     * @return secondary
     */
    private function run_callback_for_course(\stdClass $course): secondary {
        global $PAGE;

        $PAGE->set_course($course);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);

        $view = new secondary($PAGE);
        callbacks::extend_secondary_navigation(new secondary_extend($view));

        return $view;
    }

    /**
     * Give the user a role holding the plugin download capability in the course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return void
     */
    private function grant_plugin_capability(\stdClass $user, \stdClass $course): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(access::CAP_DOWNLOAD, CAP_ALLOW, $roleid, system_context::instance()->id, true);
        role_assign($roleid, $user->id, course_context::instance($course->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Outside frozen contexts the core restore page lists the backups, so no node is added.
     */
    public function test_node_not_added_when_not_frozen(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->grant_plugin_capability($teacher, $course);
        $this->setUser($teacher);

        $view = $this->run_callback_for_course($course);

        $this->assertFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }

    /**
     * A lock flag left on the course after context freezing has been disabled does not count.
     */
    public function test_node_not_added_when_context_locking_disabled(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant_plugin_capability($user, $course);
        $this->setUser($user);

        set_config('contextlocking', 1);
        course_context::instance($course->id)->set_locked(true);
        set_config('contextlocking', 0);

        $view = $this->run_callback_for_course($course);

        $this->assertFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }

    /**
     * The node is not added for users without the capability.
     */
    public function test_node_not_added_for_student(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $view = $this->run_callback_for_course($course);

        $this->assertFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }

    /**
     * Context freezing blocks the core capability, so the node disappears for editing teachers.
     */
    public function test_node_not_added_in_frozen_context_for_teacher(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        set_config('contextlocking', 1);
        course_context::instance($course->id)->set_locked(true);

        $view = $this->run_callback_for_course($course);

        $this->assertFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }

    /**
     * The plugin capability keeps the node in a frozen context.
     */
    public function test_node_added_in_frozen_context_with_plugin_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant_plugin_capability($user, $course);
        $this->setUser($user);

        set_config('contextlocking', 1);
        course_context::instance($course->id)->set_locked(true);

        $view = $this->run_callback_for_course($course);
        $node = $view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM);

        $this->assertNotFalse($node);
        $this->assertSame(get_string('pluginname', 'local_backupdownloader'), $node->text);
        $this->assertStringContainsString(
            '/local/backupdownloader/index.php?id=' . $course->id,
            $node->action->out(false),
        );
    }

    /**
     * Freezing the parent category freezes the course too.
     */
    public function test_node_added_when_category_frozen(): void {
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant_plugin_capability($user, $course);
        $this->setUser($user);

        set_config('contextlocking', 1);
        \core\context\coursecat::instance($category->id)->set_locked(true);

        $view = $this->run_callback_for_course($course);

        $this->assertNotFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }

    /**
     * The node is never added on the front page.
     */
    public function test_node_not_added_on_site_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $view = $this->run_callback_for_course(get_site());

        $this->assertFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }

    /**
     * The callback is registered and executed by the hook manager during navigation initialisation.
     */
    public function test_hook_dispatched_by_core(): void {
        global $PAGE;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->grant_plugin_capability($user, $course);
        $this->setUser($user);

        set_config('contextlocking', 1);
        course_context::instance($course->id)->set_locked(true);

        $PAGE->set_course($course);
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);

        $view = new secondary($PAGE);
        $view->initialise();

        $this->assertNotFalse($view->find(callbacks::NAV_KEY, \navigation_node::TYPE_CUSTOM));
    }
}
