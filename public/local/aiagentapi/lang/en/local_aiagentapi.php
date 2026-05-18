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
 * Language strings for local_aiagentapi.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI agent API';

$string['aiagentapi:use'] = 'Use AI agent API';
$string['aiagentapi:calendarwrite'] = 'Write calendar entries via AI agent API';

$string['deviceverify_title'] = 'Xiaolin Classroom login approval';
$string['deviceverify_heading'] = 'Classroom login';
$string['deviceverify_intro'] = 'Xiaolin Classroom is requesting access to this Moodle account.';
$string['deviceverify_invalidcode'] = 'This login code is invalid.';
$string['deviceverify_expiredcode'] = 'This login code has expired. Please start Xiaolin Classroom login again.';
$string['deviceverify_servicenotavailable'] = 'The requested classroom service is not available: {$a}';
$string['deviceverify_notallowed'] = 'Your account is not allowed to use Xiaolin Classroom.';
$string['deviceverify_approved_heading'] = 'Authorization complete';
$string['deviceverify_approved_message'] = 'Authorization is complete. You can return to Xiaolin Classroom.';
$string['deviceverify_denied_heading'] = 'Classroom login denied';
$string['deviceverify_denied_message'] = 'The login request was denied. You can close this page.';
$string['deviceverify_alreadyapproved'] = 'This classroom login has already been approved. You can return to Xiaolin Classroom.';
$string['deviceverify_alreadydenied'] = 'This classroom login has already been denied.';
$string['deviceverify_detail_user'] = 'User: {$a->user}';
$string['deviceverify_detail_service'] = 'Application: Xiaolin Classroom';
$string['deviceverify_detail_code'] = 'Code: {$a->code}';
$string['deviceverify_detail_expiresat'] = 'Expires at: {$a->expiresat}';
$string['deviceverify_approve_button'] = 'Authorize login';
$string['deviceverify_deny_button'] = 'Deny';

$string['accessmanage_title'] = 'Xiaolin Classroom authorized users';
$string['accessmanage_intro'] = 'Add only the students who are allowed to use Xiaolin Classroom AI login. This page grants the Moodle capability, adds the user to the local_aiagentapi service allowlist, and prepares a service-scoped token. Tokens are never shown here.';
$string['accessmanage_identifier'] = 'Student account';
$string['accessmanage_identifier_placeholder'] = 'Phone number, username, or email';
$string['accessmanage_identifier_help'] = 'Use the Moodle username when possible. Phone number lookup uses the user phone field.';
$string['accessmanage_grantbutton'] = 'Enable Xiaolin Classroom';
$string['accessmanage_revokebutton'] = 'Disable';
$string['accessmanage_empty'] = 'No students are currently authorized for Xiaolin Classroom.';
$string['accessmanage_granted'] = 'Enabled Xiaolin Classroom for {$a->fullname} ({$a->username}).';
$string['accessmanage_revoked'] = 'Disabled Xiaolin Classroom for {$a}.';
$string['accessmanage_usernotfound'] = 'No active Moodle user was found for this account.';
$string['accessmanage_userdeleted'] = 'This user has been deleted.';
$string['accessmanage_usersuspended'] = 'This user is suspended.';
$string['accessmanage_userunconfirmed'] = 'This user has not confirmed the account yet.';
$string['accessmanage_table_user'] = 'User';
$string['accessmanage_table_username'] = 'Username';
$string['accessmanage_table_phone'] = 'Phone';
$string['accessmanage_table_status'] = 'Status';
$string['accessmanage_table_token'] = 'Token';
$string['accessmanage_table_action'] = 'Action';
$string['accessmanage_status_confirmed'] = 'Confirmed';
$string['accessmanage_status_unconfirmed'] = 'Unconfirmed';
$string['accessmanage_status_suspended'] = 'Suspended';
$string['accessmanage_token_ready'] = 'Ready';
$string['accessmanage_token_missing'] = 'Missing';
$string['accessrole_name'] = 'Xiaolin Classroom user';
$string['accessrole_description'] = 'Allows selected users to sign in to Xiaolin Classroom through the local_aiagentapi Moodle service.';
$string['accessmanage_tokenname'] = 'Xiaolin Classroom token';
