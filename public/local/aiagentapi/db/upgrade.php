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
 * Upgrade steps for local_aiagentapi.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_aiagentapi upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_aiagentapi_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // 2026021300: replace CAP_PROHIBIT with CAP_PREVENT for default archetype roles.
    if ($oldversion < 2026021300) {
        $caps = [
            'local/aiagentapi:use',
            'local/aiagentapi:calendarwrite',
        ];

        // Roles that commonly apply to most users and must not be CAP_PROHIBIT here.
        $roles = $DB->get_records_list('role', 'shortname', ['user', 'student', 'teacher', 'editingteacher'], '', 'id,shortname');
        $roleids = array_map(static fn($r) => (int)$r->id, $roles);

        if (!empty($roleids)) {
            [$inrolesql, $inroleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');
            [$incapsql, $incapparms] = $DB->get_in_or_equal($caps, SQL_PARAMS_NAMED, 'cap');

            $params = array_merge($inroleparams, $incapparms, [
                'prohibit' => CAP_PROHIBIT,
                'prevent' => CAP_PREVENT,
                'timemodified' => time(),
            ]);

            $sql = "UPDATE {role_capabilities}
                       SET permission = :prevent,
                           timemodified = :timemodified
                     WHERE roleid $inrolesql
                       AND capability $incapsql
                       AND permission = :prohibit";
            $DB->execute($sql, $params);
        }

        upgrade_plugin_savepoint(true, 2026021300, 'local', 'aiagentapi');
    }

    // 2026041100: add device/browser login request table for CLI auth.
    if ($oldversion < 2026041100) {
        $table = new xmldb_table('local_aiagentapi_deviceauth');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('devicecodehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usercodehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('service', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('wstoken', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('intervalsecs', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '5');
        $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lastpolledat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('uq_devicecodehash', XMLDB_INDEX_UNIQUE, ['devicecodehash']);
        $table->add_index('uq_usercodehash', XMLDB_INDEX_UNIQUE, ['usercodehash']);
        $table->add_index('idx_status_exp', XMLDB_INDEX_NOTUNIQUE, ['status', 'expiresat']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041100, 'local', 'aiagentapi');
    }

    // 2026051400: lock the CLI web service to explicitly authorised users.
    if ($oldversion < 2026051400) {
        $service = $DB->get_record('external_services', ['shortname' => 'local_aiagentapi']);
        if ($service) {
            $service->requiredcapability = 'local/aiagentapi:use';
            $service->restrictedusers = 1;
            $service->enabled = 1;
            $service->timemodified = time();
            $DB->update_record('external_services', $service);
        }

        upgrade_plugin_savepoint(true, 2026051400, 'local', 'aiagentapi');
    }

    return true;
}
