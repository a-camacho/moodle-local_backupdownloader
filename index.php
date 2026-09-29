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

/**
 * Lists the backup files of a course (and of the user's private area) for download.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

use core\context\course as course_context;
use local_backupdownloader\event\backup_list_viewed;
use local_backupdownloader\hook\callbacks;
use local_backupdownloader\local\access;
use local_backupdownloader\local\backup_finder;
use local_backupdownloader\output\backup_list;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

// The front page has no "Backup downloads" tab, keep the page consistent with the navigation.
if ($id === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}

$course = get_course($id);
require_login($course);

$context = course_context::instance($course->id);
if (!access::can_download_course_backups($context)) {
    throw new required_capability_exception($context, access::CAP_DOWNLOAD, 'nopermissions', '');
}

$url = new moodle_url('/local/backupdownloader/index.php', ['id' => $course->id]);
$title = get_string('pluginname', 'local_backupdownloader');

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(implode(moodle_page::TITLE_SEPARATOR, [
    $title,
    format_string($course->fullname, true, ['context' => $context]),
]));
$PAGE->set_heading($course->fullname);
$PAGE->set_secondary_active_tab(callbacks::NAV_KEY);

// Outside frozen contexts the core restore page lists and serves the backup files.
if (!access::is_frozen($context)) {
    $restoreurl = null;
    if (has_capability('moodle/restore:restorecourse', $context)) {
        $restoreurl = (new moodle_url('/backup/restorefile.php', ['contextid' => $context->id]))->out(false);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading($title);
    echo $OUTPUT->render_from_template('local_backupdownloader/not_frozen', [
        'restoreurl' => $restoreurl,
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    ]);
    echo $OUTPUT->footer();
    exit;
}

backup_list_viewed::create(['context' => $context])->trigger();

$finder = new backup_finder($context, $USER);
$list = new backup_list($finder->find(), $course);

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render($list);
echo $OUTPUT->footer();
