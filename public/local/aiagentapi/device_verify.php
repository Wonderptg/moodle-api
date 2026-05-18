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
 * Browser approval page for classroom device login.
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
$PAGE->set_title(get_string('deviceverify_title', 'local_aiagentapi'));
$PAGE->set_heading(format_string($SITE->fullname));

require_login();

if (!$record) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('deviceverify_invalidcode', 'local_aiagentapi'), 'notifyproblem');
    echo $OUTPUT->footer();
    die();
}

$record = local_aiagentapi_mark_request_expired($record);

if ((int)$record->expiresat <= time()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('deviceverify_expiredcode', 'local_aiagentapi'), 'notifyproblem');
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
            echo $OUTPUT->notification(get_string('deviceverify_servicenotavailable', 'local_aiagentapi', s($e->getMessage())), 'notifyproblem');
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
        if (!has_capability('local/aiagentapi:use', $systemcontext)) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification(get_string('deviceverify_notallowed', 'local_aiagentapi'), 'notifyproblem');
            echo $OUTPUT->footer();
            die();
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
        echo html_writer::tag('h2', get_string('deviceverify_approved_heading', 'local_aiagentapi'));
        echo html_writer::div(get_string('deviceverify_approved_message', 'local_aiagentapi'), 'alert alert-success');
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
        echo html_writer::tag('h2', get_string('deviceverify_denied_heading', 'local_aiagentapi'));
        echo html_writer::div(get_string('deviceverify_denied_message', 'local_aiagentapi'), 'alert alert-warning');
        echo $OUTPUT->footer();
        die();
    }
}

$status = (string)$record->status;

echo $OUTPUT->header();
echo html_writer::tag('h2', get_string('deviceverify_heading', 'local_aiagentapi'));

if ($status === LOCAL_AIAGENTAPI_DEVICE_APPROVED) {
    echo $OUTPUT->notification(get_string('deviceverify_alreadyapproved', 'local_aiagentapi'), 'notifysuccess');
    echo $OUTPUT->footer();
    die();
}

if ($status === LOCAL_AIAGENTAPI_DEVICE_DENIED) {
    echo $OUTPUT->notification(get_string('deviceverify_alreadydenied', 'local_aiagentapi'), 'notifyproblem');
    echo $OUTPUT->footer();
    die();
}

$details = (object) [
    'user' => fullname($USER) . ' (' . s($USER->username) . ')',
    'service' => s((string)$record->service),
    'code' => s($usercode),
    'expiresat' => userdate((int)$record->expiresat),
];
echo html_writer::div(get_string('deviceverify_intro', 'local_aiagentapi'), 'mb-3');
echo html_writer::alist([
    get_string('deviceverify_detail_user', 'local_aiagentapi', $details),
    get_string('deviceverify_detail_service', 'local_aiagentapi', $details),
    get_string('deviceverify_detail_code', 'local_aiagentapi', $details),
    get_string('deviceverify_detail_expiresat', 'local_aiagentapi', $details),
]);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'user_code', 'value' => $usercode]);
echo html_writer::start_div('mt-3');
echo html_writer::tag('button', get_string('deviceverify_approve_button', 'local_aiagentapi'), [
    'type' => 'submit',
    'name' => 'decision',
    'value' => 'approve',
    'class' => 'btn btn-primary me-2',
]);
echo html_writer::tag('button', get_string('deviceverify_deny_button', 'local_aiagentapi'), [
    'type' => 'submit',
    'name' => 'decision',
    'value' => 'deny',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
