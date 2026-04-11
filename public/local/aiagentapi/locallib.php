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
 * Shared helpers for local_aiagentapi browser/device login.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Device auth record lifetime in seconds.
 */
const LOCAL_AIAGENTAPI_DEVICE_TTL = 600;

/**
 * Suggested client poll interval in seconds.
 */
const LOCAL_AIAGENTAPI_DEVICE_INTERVAL = 5;

/**
 * Pending status.
 */
const LOCAL_AIAGENTAPI_DEVICE_PENDING = 'pending';

/**
 * Approved status.
 */
const LOCAL_AIAGENTAPI_DEVICE_APPROVED = 'approved';

/**
 * Denied status.
 */
const LOCAL_AIAGENTAPI_DEVICE_DENIED = 'denied';

/**
 * Expired status.
 */
const LOCAL_AIAGENTAPI_DEVICE_EXPIRED = 'expired';

/**
 * Generate a URL-safe random code.
 *
 * @param int $length
 * @return string
 * @throws Exception
 */
function local_aiagentapi_generate_urlsafe_code(int $length = 48): string {
    $bytes = random_bytes((int)ceil($length * 3 / 4));
    $value = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    return substr($value, 0, $length);
}

/**
 * Generate a short human-readable code like ABCD-EFGH.
 *
 * @return string
 * @throws Exception
 */
function local_aiagentapi_generate_user_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $chars = [];
    for ($i = 0; $i < 8; $i++) {
        $chars[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return implode('', array_slice($chars, 0, 4)) . '-' . implode('', array_slice($chars, 4, 4));
}

/**
 * Normalize a human user code.
 *
 * @param string $code
 * @return string
 */
function local_aiagentapi_normalize_user_code(string $code): string {
    $code = strtoupper(trim($code));
    return preg_replace('/[^A-Z0-9]/', '', $code);
}

/**
 * Hash a device or user code for storage.
 *
 * @param string $code
 * @return string
 */
function local_aiagentapi_hash_code(string $code): string {
    return hash('sha256', trim($code));
}

/**
 * Find a device auth request by device code.
 *
 * @param string $devicecode
 * @return stdClass|null
 */
function local_aiagentapi_get_device_request_by_device_code(string $devicecode): ?stdClass {
    global $DB;

    $hash = local_aiagentapi_hash_code($devicecode);
    return $DB->get_record('local_aiagentapi_deviceauth', ['devicecodehash' => $hash]) ?: null;
}

/**
 * Find a device auth request by user code.
 *
 * @param string $usercode
 * @return stdClass|null
 */
function local_aiagentapi_get_device_request_by_user_code(string $usercode): ?stdClass {
    global $DB;

    $normalized = local_aiagentapi_normalize_user_code($usercode);
    if ($normalized === '') {
        return null;
    }
    $hash = local_aiagentapi_hash_code($normalized);
    return $DB->get_record('local_aiagentapi_deviceauth', ['usercodehash' => $hash]) ?: null;
}

/**
 * Mark a request as expired when needed.
 *
 * @param stdClass $record
 * @return stdClass
 */
function local_aiagentapi_mark_request_expired(stdClass $record): stdClass {
    global $DB;

    if ((string)$record->status !== LOCAL_AIAGENTAPI_DEVICE_EXPIRED && (int)$record->expiresat <= time()) {
        $record->status = LOCAL_AIAGENTAPI_DEVICE_EXPIRED;
        $record->timemodified = time();
        $DB->update_record('local_aiagentapi_deviceauth', $record);
    }
    return $record;
}

/**
 * Ensure the named external service exists and is enabled.
 *
 * @param string $serviceshortname
 * @return stdClass
 */
function local_aiagentapi_get_enabled_service(string $serviceshortname): stdClass {
    global $DB;

    $service = $DB->get_record('external_services', [
        'shortname' => $serviceshortname,
        'enabled' => 1,
    ]);
    if (!$service) {
        throw new moodle_exception('servicenotavailable', 'webservice');
    }
    return $service;
}

/**
 * Emit a JSON response and stop execution.
 *
 * @param array $payload
 * @param int $statuscode
 * @return never
 */
function local_aiagentapi_emit_json(array $payload, int $statuscode = 200): void {
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    http_response_code($statuscode);
    echo json_encode($payload);
    die();
}

/**
 * Emit a standardized device-flow JSON error.
 *
 * @param string $error
 * @param string $message
 * @param int $statuscode
 * @return never
 */
function local_aiagentapi_emit_device_error(string $error, string $message, int $statuscode = 400): void {
    local_aiagentapi_emit_json([
        'error' => $error,
        'error_description' => $message,
    ], $statuscode);
}

/**
 * Build the full verification URL for browser approval.
 *
 * @param string $usercode
 * @return moodle_url
 */
function local_aiagentapi_device_verify_url(string $usercode): moodle_url {
    global $CFG;

    return new moodle_url($CFG->wwwroot . '/local/aiagentapi/device_verify.php', [
        'user_code' => $usercode,
    ]);
}
