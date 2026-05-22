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
 * Simple admin page for granting Xiaolin Classroom login access.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

admin_externalpage_setup('local_aiagentapi_access');

$url = new moodle_url('/local/aiagentapi/manage_access.php');
$action = optional_param('action', '', PARAM_ALPHA);
$identifier = optional_param('identifier', '', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$message = '';
$messagetype = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    try {
        if ($action === 'grant') {
            $user = local_aiagentapi_find_access_user($identifier);
            if (!$user) {
                throw new moodle_exception('accessmanage_usernotfound', 'local_aiagentapi');
            }
            $result = local_aiagentapi_grant_classroom_access($user);
            $message = get_string('accessmanage_granted', 'local_aiagentapi', (object)$result);
        } else if ($action === 'revoke' && $userid > 0) {
            $user = core_user::get_user($userid, '*', MUST_EXIST);
            local_aiagentapi_revoke_classroom_access($userid);
            $message = get_string('accessmanage_revoked', 'local_aiagentapi', fullname($user));
        }
    } catch (Throwable $e) {
        $messagetype = 'error';
        $message = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('accessmanage_title', 'local_aiagentapi'));
echo html_writer::div(get_string('accessmanage_intro', 'local_aiagentapi'), 'mb-3');

if ($message !== '') {
    echo $OUTPUT->notification($message, $messagetype === 'success' ? 'notifysuccess' : 'notifyproblem');
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'grant']);
echo html_writer::start_div('form-group');
echo html_writer::label(get_string('accessmanage_identifier', 'local_aiagentapi'), 'id_identifier');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'identifier',
    'id' => 'id_identifier',
    'class' => 'form-control',
    'required' => 'required',
    'placeholder' => get_string('accessmanage_identifier_placeholder', 'local_aiagentapi'),
]);
echo html_writer::div(get_string('accessmanage_identifier_help', 'local_aiagentapi'), 'form-text text-muted');
echo html_writer::end_div();
echo html_writer::tag('button', get_string('accessmanage_grantbutton', 'local_aiagentapi'), [
    'type' => 'submit',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

$users = local_aiagentapi_list_classroom_access_users();
if (empty($users)) {
    echo $OUTPUT->notification(get_string('accessmanage_empty', 'local_aiagentapi'), 'notifymessage');
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->head = [
    get_string('accessmanage_table_user', 'local_aiagentapi'),
    get_string('accessmanage_table_username', 'local_aiagentapi'),
    get_string('accessmanage_table_phone', 'local_aiagentapi'),
    get_string('accessmanage_table_status', 'local_aiagentapi'),
    get_string('accessmanage_table_token', 'local_aiagentapi'),
    get_string('accessmanage_table_action', 'local_aiagentapi'),
];
$table->data = [];

foreach ($users as $user) {
    $status = [];
    if (!empty($user->confirmed)) {
        $status[] = get_string('accessmanage_status_confirmed', 'local_aiagentapi');
    } else {
        $status[] = get_string('accessmanage_status_unconfirmed', 'local_aiagentapi');
    }
    if (!empty($user->suspended)) {
        $status[] = get_string('accessmanage_status_suspended', 'local_aiagentapi');
    }

    $revokeform = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    $revokeform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $revokeform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'revoke']);
    $revokeform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => (int)$user->id]);
    $revokeform .= html_writer::tag('button', get_string('accessmanage_revokebutton', 'local_aiagentapi'), [
        'type' => 'submit',
        'class' => 'btn btn-secondary btn-sm',
    ]);
    $revokeform .= html_writer::end_tag('form');

    $table->data[] = [
        fullname($user),
        s($user->username),
        s((string)$user->phone1),
        implode(' / ', $status),
        ((int)$user->tokencount > 0)
            ? get_string('accessmanage_token_ready', 'local_aiagentapi')
            : get_string('accessmanage_token_missing', 'local_aiagentapi'),
        $revokeform,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
