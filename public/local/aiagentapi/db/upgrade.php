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

    return true;
}

