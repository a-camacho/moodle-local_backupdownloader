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
 * Serves a backup file listed by the plugin.
 *
 * pluginfile.php enforces write capabilities that context freezing blocks, so the plugin
 * streams the files itself after applying its own access rules (see backup_finder::can_serve()).
 * Files are served in frozen contexts only; elsewhere the core restore page links them.
 *
 * @package    local_backupdownloader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

use core\context\course as course_context;
use local_backupdownloader\local\access;
use local_backupdownloader\local\backup_finder;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$fileid = required_param('file', PARAM_INT);

if ($id === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}

$course = get_course($id);
require_login($course);

$context = course_context::instance($course->id);
$PAGE->set_url(new moodle_url('/local/backupdownloader/download.php', ['id' => $course->id, 'file' => $fileid]));
$PAGE->set_context($context);

$file = get_file_storage()->get_file_by_id($fileid);
$finder = new backup_finder($context, $USER);

if (!access::is_frozen($context) || $file === false || !$finder->can_serve($file)) {
    send_file_not_found();
}

\core\session\manager::write_close(); // Unlock session during file serving.
send_stored_file($file, 0, 0, true);
