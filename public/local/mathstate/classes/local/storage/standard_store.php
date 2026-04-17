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

namespace local_mathstate\local\storage;

use local_mathstate\local\support\normalizer;

defined('MOODLE_INTERNAL') || die();

/**
 * Storage helpers for standards tables.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class standard_store {
    public static function upsert_record(string $table, string $keyfield, string $keyvalue, array $values, int $timestamp): array {
        global $DB;

        $existing = $DB->get_record($table, [$keyfield => $keyvalue], '*', IGNORE_MISSING);
        $record = (object)$values;
        if ($existing) {
            $record->id = $existing->id;
            $record->timemodified = $timestamp;
            $DB->update_record($table, $record);
            return ['record_id' => (int)$existing->id, 'action' => 'updated'];
        }

        $record->timecreated = $timestamp;
        $record->timemodified = $timestamp;
        $id = $DB->insert_record($table, $record);
        return ['record_id' => (int)$id, 'action' => 'created'];
    }

    public static function standard_name(string $type, string $ref): string {
        global $DB;

        if ($ref === '') {
            return '';
        }

        if ($type === 'kp') {
            $record = $DB->get_record('local_mathstate_std_kp', ['kg_id' => $ref], 'name', IGNORE_MISSING);
            return $record ? (string)$record->name : $ref;
        }

        if ($type === 'qtype') {
            $record = $DB->get_record('local_mathstate_std_qtype', ['qg_id' => $ref], 'name', IGNORE_MISSING);
            return $record ? (string)$record->name : $ref;
        }

        return $ref;
    }

    public static function qtype_kg_ids(string $qgid): array {
        global $DB;

        if ($qgid === '') {
            return [];
        }

        $record = $DB->get_record(
            'local_mathstate_std_qtype',
            ['qg_id' => $qgid],
            'knowledge_points_json',
            IGNORE_MISSING
        );
        if (!$record) {
            return [];
        }

        return normalizer::normalize_string_list(
            normalizer::decode_json_list((string)$record->knowledge_points_json)
        );
    }
}
