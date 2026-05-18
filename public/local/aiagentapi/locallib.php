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

/**
 * Find a non-deleted Moodle user by username, phone number, or unique email.
 *
 * @param string $identifier
 * @return stdClass|null
 */
function local_aiagentapi_find_access_user(string $identifier): ?stdClass {
    global $CFG, $DB;

    $identifier = trim(core_text::strtolower($identifier));
    if ($identifier === '') {
        return null;
    }

    $baseconditions = [
        'mnethostid' => $CFG->mnet_localhost_id,
        'deleted' => 0,
    ];

    $user = $DB->get_record('user', $baseconditions + ['username' => $identifier]);
    if ($user) {
        return $user;
    }

    $phone = preg_replace('/\D+/', '', $identifier);
    if ($phone !== '') {
        $user = $DB->get_record('user', $baseconditions + ['phone1' => $phone]);
        if ($user) {
            return $user;
        }
    }

    if (clean_param($identifier, PARAM_EMAIL) === $identifier) {
        $users = $DB->get_records_select(
            'user',
            'mnethostid = :mnethostid AND deleted = 0 AND LOWER(email) = LOWER(:email)',
            ['mnethostid' => $CFG->mnet_localhost_id, 'email' => $identifier],
            'id ASC',
            '*',
            0,
            2
        );
        if (count($users) === 1) {
            return reset($users);
        }
    }

    return null;
}

/**
 * Ensure the small system role used to grant local_aiagentapi access exists.
 *
 * @return int Role id.
 */
function local_aiagentapi_ensure_access_role(): int {
    global $DB;

    $systemcontext = context_system::instance();
    $role = $DB->get_record('role', ['shortname' => 'aiagentapiuser']);
    if ($role) {
        $roleid = (int)$role->id;
    } else {
        $roleid = create_role(
            get_string('accessrole_name', 'local_aiagentapi'),
            'aiagentapiuser',
            get_string('accessrole_description', 'local_aiagentapi')
        );
    }

    if (!$DB->record_exists('role_context_levels', ['roleid' => $roleid, 'contextlevel' => CONTEXT_SYSTEM])) {
        $record = (object) [
            'roleid' => $roleid,
            'contextlevel' => CONTEXT_SYSTEM,
        ];
        $DB->insert_record('role_context_levels', $record);
    }

    assign_capability('local/aiagentapi:use', CAP_ALLOW, $roleid, $systemcontext, true);
    return $roleid;
}

/**
 * Grant a user access to Xiaolin Classroom's local_aiagentapi service.
 *
 * @param stdClass $user
 * @return array
 */
function local_aiagentapi_grant_classroom_access(stdClass $user): array {
    global $CFG, $DB;

    require_once($CFG->libdir . '/externallib.php');

    if (!empty($user->deleted)) {
        throw new moodle_exception('accessmanage_userdeleted', 'local_aiagentapi');
    }
    if (!empty($user->suspended)) {
        throw new moodle_exception('accessmanage_usersuspended', 'local_aiagentapi');
    }
    if (empty($user->confirmed)) {
        throw new moodle_exception('accessmanage_userunconfirmed', 'local_aiagentapi');
    }

    $systemcontext = context_system::instance();
    $service = local_aiagentapi_get_enabled_service('local_aiagentapi');
    $roleid = local_aiagentapi_ensure_access_role();
    role_assign($roleid, (int)$user->id, $systemcontext, 'local_aiagentapi');

    $allowed = $DB->get_record('external_services_users', [
        'externalserviceid' => (int)$service->id,
        'userid' => (int)$user->id,
    ]);
    if (!$allowed) {
        $allowed = (object) [
            'externalserviceid' => (int)$service->id,
            'userid' => (int)$user->id,
            'iprestriction' => '',
            'validuntil' => 0,
            'timecreated' => time(),
        ];
        $DB->insert_record('external_services_users', $allowed);
    } else {
        $allowed->iprestriction = '';
        $allowed->validuntil = 0;
        $DB->update_record('external_services_users', $allowed);
    }

    $tokencreated = false;
    $tokens = $DB->get_records('external_tokens', [
        'userid' => (int)$user->id,
        'externalserviceid' => (int)$service->id,
        'tokentype' => EXTERNAL_TOKEN_PERMANENT,
    ], 'timecreated ASC');

    if (empty($tokens)) {
        \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            (int)$user->id,
            $systemcontext,
            time() + (int)$CFG->tokenduration,
            '',
            get_string('accessmanage_tokenname', 'local_aiagentapi')
        );
        $tokencreated = true;
    }

    return [
        'userid' => (int)$user->id,
        'username' => (string)$user->username,
        'fullname' => fullname($user),
        'tokencreated' => $tokencreated,
    ];
}

/**
 * Revoke local_aiagentapi service access for a user.
 *
 * @param int $userid
 * @return void
 */
function local_aiagentapi_revoke_classroom_access(int $userid): void {
    global $DB;

    $service = local_aiagentapi_get_enabled_service('local_aiagentapi');
    $role = $DB->get_record('role', ['shortname' => 'aiagentapiuser']);
    if ($role) {
        role_unassign((int)$role->id, $userid, context_system::instance()->id, 'local_aiagentapi');
    }

    $DB->delete_records('external_services_users', [
        'externalserviceid' => (int)$service->id,
        'userid' => $userid,
    ]);
    $DB->delete_records('external_tokens', [
        'externalserviceid' => (int)$service->id,
        'userid' => $userid,
        'tokentype' => EXTERNAL_TOKEN_PERMANENT,
    ]);
}

/**
 * List users currently authorised for local_aiagentapi.
 *
 * @return array
 */
function local_aiagentapi_list_classroom_access_users(): array {
    global $DB;

    $service = local_aiagentapi_get_enabled_service('local_aiagentapi');
    return $DB->get_records_sql(
        "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.phone1,
                u.suspended, u.confirmed, esu.timecreated,
                COUNT(t.id) AS tokencount
           FROM {external_services_users} esu
           JOIN {user} u ON u.id = esu.userid
      LEFT JOIN {external_tokens} t
             ON t.userid = u.id
            AND t.externalserviceid = esu.externalserviceid
            AND t.tokentype = :tokentype
          WHERE esu.externalserviceid = :serviceid
            AND u.deleted = 0
       GROUP BY u.id, u.username, u.firstname, u.lastname, u.email, u.phone1,
                u.suspended, u.confirmed, esu.timecreated
       ORDER BY esu.timecreated DESC, u.id DESC",
        ['serviceid' => (int)$service->id, 'tokentype' => EXTERNAL_TOKEN_PERMANENT]
    );
}
