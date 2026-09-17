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

use core\context\course as course_context;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the backup_list_viewed event.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(backup_list_viewed::class)]
final class backup_list_viewed_test extends \advanced_testcase {
    /**
     * The event carries the course context and points back to the plugin page.
     */
    public function test_event(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $context = course_context::instance($course->id);

        $sink = $this->redirectEvents();
        backup_list_viewed::create(['context' => $context])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf(backup_list_viewed::class, $event);
        $this->assertSame('r', $event->crud);
        $this->assertSame(backup_list_viewed::LEVEL_OTHER, $event->edulevel);
        $this->assertSame($context->id, $event->contextid);
        $this->assertSame((int) $course->id, (int) $event->courseid);
        $this->assertSame((int) $teacher->id, (int) $event->userid);
        $this->assertSame(get_string('event_backup_list_viewed', 'local_backupdownloader'), $event::get_name());
        $this->assertStringContainsString("'{$teacher->id}'", $event->get_description());
        $this->assertStringContainsString("'{$course->id}'", $event->get_description());
        $this->assertStringContainsString(
            '/local/backupdownloader/index.php?id=' . $course->id,
            $event->get_url()->out(false)
        );
        $this->assertEventContextNotUsed($event);
    }

    /**
     * The event refuses any context other than a course context.
     */
    public function test_event_requires_course_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\coding_exception::class);
        backup_list_viewed::create(['context' => \core\context\system::instance()]);
    }
}
