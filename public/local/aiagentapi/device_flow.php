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
 * Browser/device login endpoint for CLI clients.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('REQUIRE_CORRECT_ACCESS', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    die();
}

if (!$CFG->enablewebservices) {
    local_aiagentapi_emit_device_error('server_error', get_string('enablewsdescription', 'webservice'), 503);
}

$action = required_param('action', PARAM_ALPHA);

if ($action === 'start') {
    $serviceshortname = optional_param('service', 'local_aiagentapi', PARAM_ALPHANUMEXT);

    try {
        local_aiagentapi_get_enabled_service($serviceshortname);
    } catch (Throwable $e) {
        local_aiagentapi_emit_device_error('invalid_request', $e->getMessage(), 400);
    }

    $now = time();
    $record = new stdClass();
    $devicecode = local_aiagentapi_generate_urlsafe_code();
    $record->devicecodehash = local_aiagentapi_hash_code($devicecode);
    $usercode = local_aiagentapi_generate_user_code();
    $record->usercodehash = local_aiagentapi_hash_code(local_aiagentapi_normalize_user_code($usercode));
    $record->service = $serviceshortname;
    $record->status = LOCAL_AIAGENTAPI_DEVICE_PENDING;
    $record->userid = 0;
    $record->username = '';
    $record->wstoken = '';
    $record->intervalsecs = LOCAL_AIAGENTAPI_DEVICE_INTERVAL;
    $record->expiresat = $now + LOCAL_AIAGENTAPI_DEVICE_TTL;
    $record->lastpolledat = 0;
    $record->timecreated = $now;
    $record->timemodified = $now;

    $DB->insert_record('local_aiagentapi_deviceauth', $record);

    $verifyurl = local_aiagentapi_device_verify_url($usercode);
    local_aiagentapi_emit_json([
        'device_code' => $devicecode,
        'user_code' => $usercode,
        'verification_uri' => $verifyurl->out(false),
        'verification_uri_complete' => $verifyurl->out(false),
        'expires_in' => LOCAL_AIAGENTAPI_DEVICE_TTL,
        'interval' => LOCAL_AIAGENTAPI_DEVICE_INTERVAL,
    ]);
}

if ($action === 'poll') {
    $devicecode = required_param('device_code', PARAM_RAW_TRIMMED);
    $record = local_aiagentapi_get_device_request_by_device_code($devicecode);
    if (!$record) {
        local_aiagentapi_emit_device_error('invalid_grant', 'Unknown device code', 400);
    }

    $record = local_aiagentapi_mark_request_expired($record);
    $record->lastpolledat = time();
    $record->timemodified = time();
    $DB->update_record('local_aiagentapi_deviceauth', $record);

    if ((string)$record->status === LOCAL_AIAGENTAPI_DEVICE_PENDING) {
        local_aiagentapi_emit_device_error('authorization_pending', 'Waiting for browser approval', 428);
    }

    if ((string)$record->status === LOCAL_AIAGENTAPI_DEVICE_DENIED) {
        local_aiagentapi_emit_device_error('access_denied', 'Authorization denied by user', 403);
    }

    if ((string)$record->status === LOCAL_AIAGENTAPI_DEVICE_EXPIRED) {
        local_aiagentapi_emit_device_error('expired_token', 'Device code expired, please try again', 400);
    }

    if ((string)$record->status !== LOCAL_AIAGENTAPI_DEVICE_APPROVED || empty($record->wstoken)) {
        local_aiagentapi_emit_device_error('server_error', 'Authorization state is invalid', 500);
    }

    local_aiagentapi_emit_json([
        'access_token' => (string)$record->wstoken,
        'token_type' => 'Bearer',
        'scope' => $record->service,
        'expires_in' => max(0, (int)$record->expiresat - time()),
        'user' => [
            'userid' => (int)$record->userid,
            'username' => (string)$record->username,
        ],
    ]);
}

local_aiagentapi_emit_device_error('invalid_request', 'Unsupported action', 400);
