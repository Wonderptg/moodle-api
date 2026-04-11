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
 * Browser approval page for CLI device login.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$usercode = required_param('user_code', PARAM_RAW_TRIMMED);
$record = local_aiagentapi_get_device_request_by_user_code($usercode);

$systemcontext = context_system::instance();
$url = new moodle_url('/local/aiagentapi/device_verify.php', ['user_code' => $usercode]);

$PAGE->set_url($url);
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('CLI Login Approval');
$PAGE->set_heading(format_string($SITE->fullname));

require_login();

if (!$record) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('This device code is invalid.', 'notifyproblem');
    echo $OUTPUT->footer();
    die();
}

$record = local_aiagentapi_mark_request_expired($record);

if ((int)$record->expiresat <= time()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('This device code has expired. Please start login again from the CLI.', 'notifyproblem');
    echo $OUTPUT->footer();
    die();
}

$decision = optional_param('decision', '', PARAM_ALPHA);
if ($decision !== '') {
    require_sesskey();

    if ($decision === 'approve') {
        try {
            $service = local_aiagentapi_get_enabled_service((string)$record->service);
        } catch (Throwable $e) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification('The requested CLI service is not available: ' . s($e->getMessage()), 'notifyproblem');
            echo $OUTPUT->footer();
            die();
        }

        $siteadmin = has_capability('moodle/site:config', $systemcontext, $USER);
        if (!empty($CFG->maintenance_enabled) && !$siteadmin) {
            throw new moodle_exception('sitemaintenance', 'admin');
        }
        if (isguestuser()) {
            throw new moodle_exception('noguest');
        }
        if (empty($USER->confirmed)) {
            throw new moodle_exception('usernotconfirmed', 'moodle', '', $USER->username);
        }

        $token = \core_external\util::generate_token_for_current_user($service);
        \core_external\util::log_token_request($token);

        $record->status = LOCAL_AIAGENTAPI_DEVICE_APPROVED;
        $record->userid = (int)$USER->id;
        $record->username = (string)$USER->username;
        $record->wstoken = (string)$token->token;
        $record->timemodified = time();
        $DB->update_record('local_aiagentapi_deviceauth', $record);

        echo $OUTPUT->header();
        echo html_writer::tag('h2', 'CLI login approved');
        echo html_writer::div('You can now return to the terminal. The CLI will finish logging in automatically.', 'alert alert-success');
        echo $OUTPUT->footer();
        die();
    }

    if ($decision === 'deny') {
        $record->status = LOCAL_AIAGENTAPI_DEVICE_DENIED;
        $record->userid = (int)$USER->id;
        $record->username = (string)$USER->username;
        $record->timemodified = time();
        $DB->update_record('local_aiagentapi_deviceauth', $record);

        echo $OUTPUT->header();
        echo html_writer::tag('h2', 'CLI login denied');
        echo html_writer::div('The terminal request was denied. You can close this page.', 'alert alert-warning');
        echo $OUTPUT->footer();
        die();
    }
}

$status = (string)$record->status;

echo $OUTPUT->header();
echo html_writer::tag('h2', 'Approve CLI login');

if ($status === LOCAL_AIAGENTAPI_DEVICE_APPROVED) {
    echo $OUTPUT->notification('This CLI login has already been approved. You can return to the terminal.', 'notifysuccess');
    echo $OUTPUT->footer();
    die();
}

if ($status === LOCAL_AIAGENTAPI_DEVICE_DENIED) {
    echo $OUTPUT->notification('This CLI login has already been denied.', 'notifyproblem');
    echo $OUTPUT->footer();
    die();
}

echo html_writer::div('A CLI on another device is requesting access to this Moodle account.', 'mb-3');
echo html_writer::alist([
    'User: ' . fullname($USER) . ' (' . s($USER->username) . ')',
    'Service: ' . s((string)$record->service),
    'Code: ' . s($usercode),
    'Expires at: ' . userdate((int)$record->expiresat),
]);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'user_code', 'value' => $usercode]);
echo html_writer::start_div('mt-3');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'decision',
    'value' => 'approve',
    'class' => 'btn btn-primary me-2',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'decision',
    'value' => 'deny',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
