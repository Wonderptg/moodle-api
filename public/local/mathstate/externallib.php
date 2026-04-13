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
 * External API implementations for local_mathstate.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class local_mathstate_external extends external_api {
    private const MAX_BATCH_ITEMS = 500;

    private static function system_context(bool $manage = false): \context_system {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability($manage ? 'local/mathstate:manage' : 'local/mathstate:view', $context);
        return $context;
    }

    private static function clamp_score(float $score): float {
        if ($score < 0.0) {
            return 0.0;
        }
        if ($score > 100.0) {
            return 100.0;
        }
        return round($score, 2);
    }

    private static function encode_json($value): string {
        if ($value === null) {
            return '';
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }

    private static function decode_json_list(?string $value): array {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function normalize_string_list(array $items): array {
        $result = [];
        foreach ($items as $item) {
            $text = trim((string)$item);
            if ($text === '') {
                continue;
            }
            $result[$text] = $text;
        }
        return array_values($result);
    }

    private static function resolve_userid(int $userid): int {
        global $USER;
        return $userid > 0 ? $userid : (int)$USER->id;
    }

    private static function stage_from_score(float $score, int $streak): string {
        if ($score < 40.0) {
            return 'weak';
        }
        if ($score < 70.0) {
            return 'learning';
        }
        if ($score < 85.0) {
            return 'review';
        }
        if ($streak >= 3) {
            return 'stable';
        }
        return 'review';
    }

    private static function review_interval_days(int $reviewstage): int {
        $map = [
            0 => 1,
            1 => 1,
            2 => 3,
            3 => 7,
            4 => 14,
            5 => 30,
        ];
        return $map[$reviewstage] ?? 30;
    }

    private static function next_review_time(int $reviewstage, string $result, int $timestamp): int {
        if ($result === 'wrong' || $result === 'partial' || $result === 'skipped') {
            return $timestamp + DAYSECS;
        }
        return $timestamp + (self::review_interval_days($reviewstage) * DAYSECS);
    }

    private static function standard_name(string $type, string $ref): string {
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

    private static function standard_qtype_kg_ids(string $qgid): array {
        global $DB;
        if ($qgid === '') {
            return [];
        }
        $record = $DB->get_record('local_mathstate_std_qtype', ['qg_id' => $qgid], 'knowledge_points_json', IGNORE_MISSING);
        return $record ? self::normalize_string_list(self::decode_json_list((string)$record->knowledge_points_json)) : [];
    }

    private static function question_map_record(int $questionid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_mathstate_question_map', ['questionid' => $questionid]) ?: null;
    }

    private static function upsert_std_record(string $table, string $keyfield, string $keyvalue, array $values, int $timestamp): array {
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

    private static function default_kp_state(int $userid, int $courseid, int $kpstdid, string $kgid, int $timestamp): \stdClass {
        $record = new \stdClass();
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->kp_std_id = $kpstdid ?: null;
        $record->kg_id = $kgid;
        $record->mastery_score = 0.0;
        $record->stage = 'new';
        $record->review_stage = 0;
        $record->correct_count = 0;
        $record->wrong_count = 0;
        $record->recent_streak = 0;
        $record->last_result = null;
        $record->first_mastered_at = null;
        $record->last_seen_at = null;
        $record->last_correct_at = null;
        $record->next_review_at = $timestamp + DAYSECS;
        $record->stability_days = 0.0;
        $record->ease_factor = 2.30;
        $record->source_last_evidence_id = null;
        $record->status_note = null;
        $record->timecreated = $timestamp;
        $record->timemodified = $timestamp;
        return $record;
    }

    private static function default_qtype_state(int $userid, int $courseid, int $qtypestdid, string $qgid, int $timestamp): \stdClass {
        $record = new \stdClass();
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->qtype_std_id = $qtypestdid ?: null;
        $record->qg_id = $qgid;
        $record->mastery_score = 0.0;
        $record->stage = 'new';
        $record->review_stage = 0;
        $record->correct_count = 0;
        $record->wrong_count = 0;
        $record->recent_streak = 0;
        $record->last_result = null;
        $record->last_seen_at = null;
        $record->last_correct_at = null;
        $record->next_review_at = $timestamp + DAYSECS;
        $record->source_last_evidence_id = null;
        $record->timecreated = $timestamp;
        $record->timemodified = $timestamp;
        return $record;
    }

    private static function apply_result_to_metrics(\stdClass $record, string $result, int $timestamp, int $evidenceid = 0): \stdClass {
        $score = (float)$record->mastery_score;

        switch ($result) {
            case 'correct':
                $score += 6.0;
                $record->correct_count = (int)$record->correct_count + 1;
                $record->recent_streak = (int)$record->recent_streak + 1;
                $record->last_correct_at = $timestamp;
                $record->review_stage = min(5, (int)$record->review_stage + 1);
                break;
            case 'partial':
                $score += 2.0;
                $record->recent_streak = 0;
                break;
            case 'skipped':
                $record->recent_streak = 0;
                break;
            case 'wrong':
            default:
                $score -= 8.0;
                $record->wrong_count = (int)$record->wrong_count + 1;
                $record->recent_streak = 0;
                $record->review_stage = max(0, (int)$record->review_stage - 1);
                break;
        }

        $record->mastery_score = self::clamp_score($score);
        $record->last_result = $result;
        $record->last_seen_at = $timestamp;
        $record->next_review_at = self::next_review_time((int)$record->review_stage, $result, $timestamp);
        $record->stage = self::stage_from_score((float)$record->mastery_score, (int)$record->recent_streak);
        if (property_exists($record, 'stability_days')) {
            $record->stability_days = (float)self::review_interval_days((int)$record->review_stage);
        }
        if (property_exists($record, 'source_last_evidence_id')) {
            $record->source_last_evidence_id = $evidenceid > 0 ? $evidenceid : null;
        }
        if ((float)$record->mastery_score >= 85.0 && property_exists($record, 'first_mastered_at') && empty($record->first_mastered_at)) {
            $record->first_mastered_at = $timestamp;
        }
        $record->timemodified = $timestamp;
        return $record;
    }

    private static function save_kp_state(int $userid, int $courseid, string $kgid, string $result, int $timestamp, int $evidenceid): \stdClass {
        global $DB;
        $existing = $DB->get_record('local_mathstate_student_kp', [
            'userid' => $userid,
            'courseid' => $courseid,
            'kg_id' => $kgid,
        ], '*', IGNORE_MISSING);
        $std = $DB->get_record('local_mathstate_std_kp', ['kg_id' => $kgid], 'id', IGNORE_MISSING);
        $record = $existing ?: self::default_kp_state($userid, $courseid, $std ? (int)$std->id : 0, $kgid, $timestamp);
        if ($existing && $std && empty($record->kp_std_id)) {
            $record->kp_std_id = (int)$std->id;
        }
        $record = self::apply_result_to_metrics($record, $result, $timestamp, $evidenceid);
        if (!empty($record->id)) {
            $DB->update_record('local_mathstate_student_kp', $record);
        } else {
            $record->id = $DB->insert_record('local_mathstate_student_kp', $record);
        }
        return $record;
    }

    private static function save_qtype_state(int $userid, int $courseid, string $qgid, string $result, int $timestamp, int $evidenceid): \stdClass {
        global $DB;
        $existing = $DB->get_record('local_mathstate_student_qtype', [
            'userid' => $userid,
            'courseid' => $courseid,
            'qg_id' => $qgid,
        ], '*', IGNORE_MISSING);
        $std = $DB->get_record('local_mathstate_std_qtype', ['qg_id' => $qgid], 'id', IGNORE_MISSING);
        $record = $existing ?: self::default_qtype_state($userid, $courseid, $std ? (int)$std->id : 0, $qgid, $timestamp);
        if ($existing && $std && empty($record->qtype_std_id)) {
            $record->qtype_std_id = (int)$std->id;
        }
        $record = self::apply_result_to_metrics($record, $result, $timestamp, $evidenceid);
        if (!empty($record->id)) {
            $DB->update_record('local_mathstate_student_qtype', $record);
        } else {
            $record->id = $DB->insert_record('local_mathstate_student_qtype', $record);
        }
        return $record;
    }

    private static function upsert_review_task(int $userid, int $courseid, string $targettype, string $targetref, \stdClass $state, string $sourcereason, int $timestamp): void {
        global $DB;
        if ($targetref === '') {
            return;
        }

        $task = $DB->get_record_select(
            'local_mathstate_review_task',
            'userid = :userid AND courseid = :courseid AND target_type = :targettype AND target_ref = :targetref AND status IN (:todo, :doing)',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'targettype' => $targettype,
                'targetref' => $targetref,
                'todo' => 'todo',
                'doing' => 'doing',
            ],
            '*',
            IGNORE_MISSING
        );

        $name = self::standard_name($targettype, $targetref);
        $priority = self::clamp_score(100.0 - (float)$state->mastery_score + ($state->stage === 'weak' ? 20.0 : 0.0));
        $payload = [
            'target_type' => $targettype,
            'target_ref' => $targetref,
            'current_stage' => (string)$state->stage,
            'mastery_score' => (float)$state->mastery_score,
        ];

        if ($task) {
            $task->title = '复习：' . $name;
            $task->task_kind = $state->stage === 'weak' ? 'remediation' : 'review';
            $task->priority = $priority;
            $task->source_reason = $sourcereason;
            $task->recommended_payload_json = self::encode_json($payload);
            $task->status = 'todo';
            $task->due_at = (int)$state->next_review_at;
            $task->timemodified = $timestamp;
            $DB->update_record('local_mathstate_review_task', $task);
            return;
        }

        $task = (object)[
            'userid' => $userid,
            'courseid' => $courseid,
            'target_type' => $targettype,
            'target_ref' => $targetref,
            'title' => '复习：' . $name,
            'task_kind' => $state->stage === 'weak' ? 'remediation' : 'review',
            'priority' => $priority,
            'source_reason' => $sourcereason,
            'recommended_payload_json' => self::encode_json($payload),
            'status' => 'todo',
            'due_at' => (int)$state->next_review_at,
            'completed_at' => null,
            'linked_doc_url' => null,
            'timecreated' => $timestamp,
            'timemodified' => $timestamp,
        ];
        $DB->insert_record('local_mathstate_review_task', $task);
    }

    private static function create_wrong_item(int $userid, int $courseid, int $evidenceid, ?int $questionid, string $qgid, array $kgids, int $timestamp): void {
        global $DB;
        $record = (object)[
            'userid' => $userid,
            'courseid' => $courseid,
            'evidence_id' => $evidenceid,
            'questionid' => $questionid ?: null,
            'qg_id' => $qgid !== '' ? $qgid : null,
            'kg_ids_json' => self::encode_json($kgids),
            'wrong_reason_json' => self::encode_json([]),
            'resolution_status' => 'open',
            'resolved_at' => null,
            'feishu_doc_url' => null,
            'timecreated' => $timestamp,
            'timemodified' => $timestamp,
        ];
        $DB->insert_record('local_mathstate_wrong_item', $record);
    }

    private static function batch_result_item_structure(): external_single_structure {
        return new external_single_structure([
            'ref' => new external_value(PARAM_RAW, 'Business reference'),
            'record_id' => new external_value(PARAM_INT, 'Record id'),
            'action' => new external_value(PARAM_RAW, 'created or updated'),
        ]);
    }

    private static function state_item_structure(string $reffield, string $reflabel): external_single_structure {
        return new external_single_structure([
            $reffield => new external_value(PARAM_RAW, $reflabel),
            'mastery_score' => new external_value(PARAM_FLOAT, 'Mastery score'),
            'stage' => new external_value(PARAM_RAW, 'Mastery stage'),
            'review_stage' => new external_value(PARAM_INT, 'Review stage'),
            'correct_count' => new external_value(PARAM_INT, 'Correct count'),
            'wrong_count' => new external_value(PARAM_INT, 'Wrong count'),
            'recent_streak' => new external_value(PARAM_INT, 'Recent streak'),
            'last_result' => new external_value(PARAM_RAW, 'Last result', VALUE_OPTIONAL),
            'last_seen_at' => new external_value(PARAM_INT, 'Last seen timestamp', VALUE_OPTIONAL),
            'next_review_at' => new external_value(PARAM_INT, 'Next review timestamp', VALUE_OPTIONAL),
        ]);
    }

    private static function review_task_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Task id'),
            'target_type' => new external_value(PARAM_RAW, 'Target type'),
            'target_ref' => new external_value(PARAM_RAW, 'Target reference'),
            'title' => new external_value(PARAM_RAW, 'Title'),
            'task_kind' => new external_value(PARAM_RAW, 'Task kind'),
            'priority' => new external_value(PARAM_FLOAT, 'Priority'),
            'source_reason' => new external_value(PARAM_RAW, 'Source reason'),
            'status' => new external_value(PARAM_RAW, 'Status'),
            'due_at' => new external_value(PARAM_INT, 'Due timestamp', VALUE_OPTIONAL),
            'linked_doc_url' => new external_value(PARAM_RAW, 'Linked document URL', VALUE_OPTIONAL),
        ]);
    }

    public static function std_kp_upsert_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'kg_id' => new external_value(PARAM_RAW, 'Knowledge point id'),
                    'subject' => new external_value(PARAM_RAW, 'Subject', VALUE_DEFAULT, 'math'),
                    'chapter' => new external_value(PARAM_RAW, 'Chapter', VALUE_DEFAULT, ''),
                    'section' => new external_value(PARAM_RAW, 'Section', VALUE_DEFAULT, ''),
                    'name' => new external_value(PARAM_RAW, 'Name'),
                    'aliases' => new external_multiple_structure(new external_value(PARAM_RAW, 'Alias'), 'Aliases', VALUE_DEFAULT, []),
                    'tags' => new external_multiple_structure(new external_value(PARAM_RAW, 'Tag'), 'Tags', VALUE_DEFAULT, []),
                    'summary' => new external_value(PARAM_RAW, 'Summary', VALUE_DEFAULT, ''),
                    'importance' => new external_value(PARAM_RAW, 'Importance', VALUE_DEFAULT, ''),
                    'hard_prerequisites' => new external_multiple_structure(new external_value(PARAM_RAW, 'Prerequisite'), 'Prerequisites', VALUE_DEFAULT, []),
                    'related' => new external_multiple_structure(new external_value(PARAM_RAW, 'Related kp'), 'Related knowledge points', VALUE_DEFAULT, []),
                    'related_question_types' => new external_multiple_structure(new external_value(PARAM_RAW, 'Related qtype'), 'Related question types', VALUE_DEFAULT, []),
                    'common_errors' => new external_multiple_structure(new external_value(PARAM_RAW, 'Common error'), 'Common errors', VALUE_DEFAULT, []),
                    'mastery_checkpoints' => new external_multiple_structure(new external_value(PARAM_RAW, 'Mastery checkpoint'), 'Mastery checkpoints', VALUE_DEFAULT, []),
                    'mirror_ref' => new external_value(PARAM_RAW, 'Mirror ref', VALUE_DEFAULT, ''),
                    'source_ref' => new external_value(PARAM_RAW, 'Source ref', VALUE_DEFAULT, ''),
                    'rev' => new external_value(PARAM_INT, 'Revision', VALUE_DEFAULT, 1),
                ]),
                'Knowledge point items'
            ),
        ]);
    }

    public static function std_kp_upsert_batch(array $items): array {
        self::system_context(true);
        $params = self::validate_parameters(self::std_kp_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $timestamp = time();
        $results = [];
        foreach ($params['items'] as $item) {
            $values = [
                'kg_id' => trim((string)$item['kg_id']),
                'subject' => trim((string)$item['subject']) !== '' ? trim((string)$item['subject']) : 'math',
                'chapter' => trim((string)$item['chapter']) !== '' ? trim((string)$item['chapter']) : null,
                'section' => trim((string)$item['section']) !== '' ? trim((string)$item['section']) : null,
                'name' => trim((string)$item['name']),
                'aliases_json' => self::encode_json(self::normalize_string_list($item['aliases'])),
                'tags_json' => self::encode_json(self::normalize_string_list($item['tags'])),
                'summary' => trim((string)$item['summary']),
                'importance' => trim((string)$item['importance']) !== '' ? trim((string)$item['importance']) : null,
                'hard_prerequisites_json' => self::encode_json(self::normalize_string_list($item['hard_prerequisites'])),
                'related_json' => self::encode_json(self::normalize_string_list($item['related'])),
                'related_question_types_json' => self::encode_json(self::normalize_string_list($item['related_question_types'])),
                'common_errors_json' => self::encode_json(self::normalize_string_list($item['common_errors'])),
                'mastery_checkpoints_json' => self::encode_json(self::normalize_string_list($item['mastery_checkpoints'])),
                'mirror_ref' => trim((string)$item['mirror_ref']) !== '' ? trim((string)$item['mirror_ref']) : null,
                'source_ref' => trim((string)$item['source_ref']) !== '' ? trim((string)$item['source_ref']) : null,
                'rev' => (int)$item['rev'],
            ];
            $result = self::upsert_std_record('local_mathstate_std_kp', 'kg_id', $values['kg_id'], $values, $timestamp);
            $results[] = [
                'ref' => $values['kg_id'],
                'record_id' => $result['record_id'],
                'action' => $result['action'],
            ];
        }

        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function std_kp_upsert_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Upserted count'),
            'items' => new external_multiple_structure(self::batch_result_item_structure()),
        ]);
    }

    public static function std_qtype_upsert_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'qg_id' => new external_value(PARAM_RAW, 'Question type id'),
                    'subject' => new external_value(PARAM_RAW, 'Subject', VALUE_DEFAULT, 'math'),
                    'chapter' => new external_value(PARAM_RAW, 'Chapter', VALUE_DEFAULT, ''),
                    'name' => new external_value(PARAM_RAW, 'Name'),
                    'knowledge_points' => new external_multiple_structure(new external_value(PARAM_RAW, 'Knowledge point'), 'Knowledge points', VALUE_DEFAULT, []),
                    'tags' => new external_multiple_structure(new external_value(PARAM_RAW, 'Tag'), 'Tags', VALUE_DEFAULT, []),
                    'summary' => new external_value(PARAM_RAW, 'Summary', VALUE_DEFAULT, ''),
                    'difficulty_min' => new external_value(PARAM_INT, 'Minimum difficulty', VALUE_DEFAULT, 0),
                    'difficulty_max' => new external_value(PARAM_INT, 'Maximum difficulty', VALUE_DEFAULT, 0),
                    'common_traps' => new external_multiple_structure(new external_value(PARAM_RAW, 'Common trap'), 'Common traps', VALUE_DEFAULT, []),
                    'evidence_for_mastery' => new external_multiple_structure(new external_value(PARAM_RAW, 'Evidence for mastery'), 'Evidence for mastery', VALUE_DEFAULT, []),
                    'mirror_ref' => new external_value(PARAM_RAW, 'Mirror ref', VALUE_DEFAULT, ''),
                    'source_ref' => new external_value(PARAM_RAW, 'Source ref', VALUE_DEFAULT, ''),
                    'rev' => new external_value(PARAM_INT, 'Revision', VALUE_DEFAULT, 1),
                ]),
                'Question type items'
            ),
        ]);
    }

    public static function std_qtype_upsert_batch(array $items): array {
        self::system_context(true);
        $params = self::validate_parameters(self::std_qtype_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $timestamp = time();
        $results = [];
        foreach ($params['items'] as $item) {
            $difficultymin = (int)$item['difficulty_min'];
            $difficultymax = (int)$item['difficulty_max'];
            $values = [
                'qg_id' => trim((string)$item['qg_id']),
                'subject' => trim((string)$item['subject']) !== '' ? trim((string)$item['subject']) : 'math',
                'chapter' => trim((string)$item['chapter']) !== '' ? trim((string)$item['chapter']) : null,
                'name' => trim((string)$item['name']),
                'knowledge_points_json' => self::encode_json(self::normalize_string_list($item['knowledge_points'])),
                'tags_json' => self::encode_json(self::normalize_string_list($item['tags'])),
                'summary' => trim((string)$item['summary']),
                'difficulty_min' => $difficultymin > 0 ? $difficultymin : null,
                'difficulty_max' => $difficultymax > 0 ? $difficultymax : null,
                'common_traps_json' => self::encode_json(self::normalize_string_list($item['common_traps'])),
                'evidence_for_mastery_json' => self::encode_json(self::normalize_string_list($item['evidence_for_mastery'])),
                'mirror_ref' => trim((string)$item['mirror_ref']) !== '' ? trim((string)$item['mirror_ref']) : null,
                'source_ref' => trim((string)$item['source_ref']) !== '' ? trim((string)$item['source_ref']) : null,
                'rev' => (int)$item['rev'],
            ];
            $result = self::upsert_std_record('local_mathstate_std_qtype', 'qg_id', $values['qg_id'], $values, $timestamp);
            $results[] = [
                'ref' => $values['qg_id'],
                'record_id' => $result['record_id'],
                'action' => $result['action'],
            ];
        }

        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function std_qtype_upsert_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Upserted count'),
            'items' => new external_multiple_structure(self::batch_result_item_structure()),
        ]);
    }

    public static function question_map_upsert_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT, 'Question id'),
                    'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry id', VALUE_DEFAULT, 0),
                    'qg_id' => new external_value(PARAM_RAW, 'Question type id', VALUE_DEFAULT, ''),
                    'kg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'Knowledge point id'), 'Knowledge point ids', VALUE_DEFAULT, []),
                    'difficulty' => new external_value(PARAM_INT, 'Difficulty', VALUE_DEFAULT, 0),
                    'mapping_source' => new external_value(PARAM_RAW, 'Mapping source', VALUE_DEFAULT, 'manual'),
                    'mapping_confidence' => new external_value(PARAM_FLOAT, 'Mapping confidence 0-100', VALUE_DEFAULT, 0.0),
                ]),
                'Question mapping items'
            ),
        ]);
    }

    public static function question_map_upsert_batch(array $items): array {
        global $DB;
        self::system_context(true);
        $params = self::validate_parameters(self::question_map_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $timestamp = time();
        $results = [];
        foreach ($params['items'] as $item) {
            $qgid = trim((string)$item['qg_id']);
            $qtype = $qgid !== '' ? $DB->get_record('local_mathstate_std_qtype', ['qg_id' => $qgid], 'id', IGNORE_MISSING) : null;
            $difficulty = (int)$item['difficulty'];
            $values = [
                'questionid' => (int)$item['questionid'],
                'questionbankentryid' => !empty($item['questionbankentryid']) ? (int)$item['questionbankentryid'] : null,
                'qtype_std_id' => $qtype ? (int)$qtype->id : null,
                'qg_id' => $qgid !== '' ? $qgid : null,
                'kg_ids_json' => self::encode_json(self::normalize_string_list($item['kg_ids'])),
                'difficulty' => $difficulty > 0 ? $difficulty : null,
                'mapping_source' => trim((string)$item['mapping_source']) !== '' ? trim((string)$item['mapping_source']) : 'manual',
                'mapping_confidence' => round((float)$item['mapping_confidence'], 2),
            ];
            $result = self::upsert_std_record('local_mathstate_question_map', 'questionid', (string)$values['questionid'], $values, $timestamp);
            $results[] = [
                'ref' => (string)$values['questionid'],
                'record_id' => $result['record_id'],
                'action' => $result['action'],
            ];
        }

        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function question_map_upsert_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Upserted count'),
            'items' => new external_multiple_structure(self::batch_result_item_structure()),
        ]);
    }

    public static function evidence_ingest_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id, 0 means current user', VALUE_DEFAULT, 0),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_DEFAULT, 0),
                    'quizid' => new external_value(PARAM_INT, 'Quiz id', VALUE_DEFAULT, 0),
                    'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_DEFAULT, 0),
                    'questionid' => new external_value(PARAM_INT, 'Question id', VALUE_DEFAULT, 0),
                    'questionusageid' => new external_value(PARAM_INT, 'Question usage id', VALUE_DEFAULT, 0),
                    'slotno' => new external_value(PARAM_INT, 'Slot number', VALUE_DEFAULT, 0),
                    'qg_id' => new external_value(PARAM_RAW, 'Question type id', VALUE_DEFAULT, ''),
                    'kg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'Knowledge point id'), 'Knowledge point ids', VALUE_DEFAULT, []),
                    'source' => new external_value(PARAM_RAW, 'Evidence source', VALUE_DEFAULT, 'quiz'),
                    'result' => new external_value(PARAM_RAW, 'Result: correct|wrong|partial|skipped'),
                    'score' => new external_value(PARAM_FLOAT, 'Score', VALUE_DEFAULT, 0.0),
                    'maxscore' => new external_value(PARAM_FLOAT, 'Max score', VALUE_DEFAULT, 0.0),
                    'accuracy' => new external_value(PARAM_FLOAT, 'Accuracy percentage', VALUE_DEFAULT, 0.0),
                    'response_time_sec' => new external_value(PARAM_INT, 'Response time in seconds', VALUE_DEFAULT, 0),
                    'difficulty' => new external_value(PARAM_INT, 'Difficulty', VALUE_DEFAULT, 0),
                    'raw_payload_json' => new external_value(PARAM_RAW, 'Raw payload JSON', VALUE_DEFAULT, ''),
                ]),
                'Evidence items'
            ),
        ]);
    }

    public static function evidence_ingest_batch(array $items): array {
        global $DB;
        self::system_context(true);
        $params = self::validate_parameters(self::evidence_ingest_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $results = [];
        foreach ($params['items'] as $item) {
            $transaction = $DB->start_delegated_transaction();
            $timestamp = time();
            $userid = self::resolve_userid((int)$item['userid']);
            $courseid = (int)$item['courseid'];
            $questionid = !empty($item['questionid']) ? (int)$item['questionid'] : 0;
            $qgid = trim((string)$item['qg_id']);
            $kgids = self::normalize_string_list($item['kg_ids']);
            $questionmap = null;

            if ($questionid > 0 && ($qgid === '' || empty($kgids))) {
                $questionmap = self::question_map_record($questionid);
                if ($questionmap) {
                    if ($qgid === '' && !empty($questionmap->qg_id)) {
                        $qgid = (string)$questionmap->qg_id;
                    }
                    if (empty($kgids)) {
                        $kgids = self::normalize_string_list(self::decode_json_list((string)$questionmap->kg_ids_json));
                    }
                }
            }

            if ($qgid !== '' && empty($kgids)) {
                $kgids = self::standard_qtype_kg_ids($qgid);
            }

            $result = trim((string)$item['result']);
            if (!in_array($result, ['correct', 'wrong', 'partial', 'skipped'], true)) {
                throw new moodle_exception('Invalid result value: ' . $result);
            }

            $score = round((float)$item['score'], 2);
            $maxscore = round((float)$item['maxscore'], 2);
            $accuracy = (float)$item['accuracy'];
            if ($accuracy <= 0.0 && $maxscore > 0.0) {
                $accuracy = round(($score / $maxscore) * 100.0, 2);
            }
            $difficulty = (int)$item['difficulty'];

            $evidence = (object)[
                'userid' => $userid,
                'courseid' => $courseid,
                'cmid' => !empty($item['cmid']) ? (int)$item['cmid'] : null,
                'quizid' => !empty($item['quizid']) ? (int)$item['quizid'] : null,
                'attemptid' => !empty($item['attemptid']) ? (int)$item['attemptid'] : null,
                'questionid' => $questionid ?: null,
                'questionusageid' => !empty($item['questionusageid']) ? (int)$item['questionusageid'] : null,
                'slotno' => !empty($item['slotno']) ? (int)$item['slotno'] : null,
                'question_map_id' => $questionmap ? (int)$questionmap->id : null,
                'qg_id' => $qgid !== '' ? $qgid : null,
                'kg_ids_json' => self::encode_json($kgids),
                'source' => trim((string)$item['source']) !== '' ? trim((string)$item['source']) : 'quiz',
                'result' => $result,
                'score' => $score,
                'maxscore' => $maxscore,
                'accuracy' => round($accuracy, 2),
                'response_time_sec' => !empty($item['response_time_sec']) ? (int)$item['response_time_sec'] : null,
                'difficulty' => $difficulty > 0 ? $difficulty : null,
                'raw_payload_json' => trim((string)$item['raw_payload_json']) !== '' ? trim((string)$item['raw_payload_json']) : null,
                'timecreated' => $timestamp,
            ];
            $evidenceid = (int)$DB->insert_record('local_mathstate_attempt_evi', $evidence);

            if ($qgid !== '') {
                $qtypestate = self::save_qtype_state($userid, $courseid, $qgid, $result, $timestamp, $evidenceid);
                if (empty($kgids)) {
                    self::upsert_review_task($userid, $courseid, 'qtype', $qgid, $qtypestate, $result === 'wrong' ? 'wrong_answer' : 'due_review', $timestamp);
                }
            }

            foreach ($kgids as $kgid) {
                $kpstate = self::save_kp_state($userid, $courseid, $kgid, $result, $timestamp, $evidenceid);
                self::upsert_review_task($userid, $courseid, 'kp', $kgid, $kpstate, $result === 'wrong' ? 'wrong_answer' : 'due_review', $timestamp);
            }

            if ($result !== 'correct') {
                self::create_wrong_item($userid, $courseid, $evidenceid, $questionid ?: null, $qgid, $kgids, $timestamp);
            }

            $transaction->allow_commit();
            $results[] = [
                'evidence_id' => $evidenceid,
                'userid' => $userid,
                'courseid' => $courseid,
                'qg_id' => $qgid,
                'kg_count' => count($kgids),
                'result' => $result,
            ];
        }

        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function evidence_ingest_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Evidence count'),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'evidence_id' => new external_value(PARAM_INT, 'Evidence id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'qg_id' => new external_value(PARAM_RAW, 'Question type id', VALUE_OPTIONAL),
                    'kg_count' => new external_value(PARAM_INT, 'Knowledge point count'),
                    'result' => new external_value(PARAM_RAW, 'Result'),
                ])
            ),
        ]);
    }

    public static function student_summary_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current user', VALUE_DEFAULT, 0),
            'include_kp_states' => new external_value(PARAM_BOOL, 'Include knowledge point states', VALUE_DEFAULT, true),
            'include_qtype_states' => new external_value(PARAM_BOOL, 'Include question type states', VALUE_DEFAULT, true),
            'include_due_tasks' => new external_value(PARAM_BOOL, 'Include due tasks', VALUE_DEFAULT, true),
        ]);
    }

    public static function student_summary(int $courseid, int $userid = 0, bool $includekpstates = true, bool $includeqtypestates = true, bool $includeduetasks = true): array {
        global $DB;
        self::system_context(false);
        $params = self::validate_parameters(self::student_summary_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'include_kp_states' => $includekpstates,
            'include_qtype_states' => $includeqtypestates,
            'include_due_tasks' => $includeduetasks,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];

        $kpstates = [];
        if (!empty($params['include_kp_states'])) {
            $records = $DB->get_records('local_mathstate_student_kp', ['userid' => $userid, 'courseid' => $courseid], 'mastery_score DESC, kg_id ASC');
            foreach ($records as $record) {
                $kpstates[] = [
                    'kg_id' => (string)$record->kg_id,
                    'mastery_score' => (float)$record->mastery_score,
                    'stage' => (string)$record->stage,
                    'review_stage' => (int)$record->review_stage,
                    'correct_count' => (int)$record->correct_count,
                    'wrong_count' => (int)$record->wrong_count,
                    'recent_streak' => (int)$record->recent_streak,
                    'last_result' => (string)($record->last_result ?? ''),
                    'last_seen_at' => (int)($record->last_seen_at ?? 0),
                    'next_review_at' => (int)($record->next_review_at ?? 0),
                ];
            }
        }

        $qtypestates = [];
        if (!empty($params['include_qtype_states'])) {
            $records = $DB->get_records('local_mathstate_student_qtype', ['userid' => $userid, 'courseid' => $courseid], 'mastery_score DESC, qg_id ASC');
            foreach ($records as $record) {
                $qtypestates[] = [
                    'qg_id' => (string)$record->qg_id,
                    'mastery_score' => (float)$record->mastery_score,
                    'stage' => (string)$record->stage,
                    'review_stage' => (int)$record->review_stage,
                    'correct_count' => (int)$record->correct_count,
                    'wrong_count' => (int)$record->wrong_count,
                    'recent_streak' => (int)$record->recent_streak,
                    'last_result' => (string)($record->last_result ?? ''),
                    'last_seen_at' => (int)($record->last_seen_at ?? 0),
                    'next_review_at' => (int)($record->next_review_at ?? 0),
                ];
            }
        }

        $duetasks = [];
        if (!empty($params['include_due_tasks'])) {
            $records = $DB->get_records_select(
                'local_mathstate_review_task',
                'userid = :userid AND courseid = :courseid AND status = :status AND due_at > 0 AND due_at <= :duebefore',
                [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'status' => 'todo',
                    'duebefore' => time(),
                ],
                'priority DESC, due_at ASC'
            );
            foreach ($records as $record) {
                $duetasks[] = [
                    'id' => (int)$record->id,
                    'target_type' => (string)$record->target_type,
                    'target_ref' => (string)$record->target_ref,
                    'title' => (string)$record->title,
                    'task_kind' => (string)$record->task_kind,
                    'priority' => (float)$record->priority,
                    'source_reason' => (string)$record->source_reason,
                    'status' => (string)$record->status,
                    'due_at' => (int)($record->due_at ?? 0),
                    'linked_doc_url' => (string)($record->linked_doc_url ?? ''),
                ];
            }
        }

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'kp_states' => $kpstates,
            'qtype_states' => $qtypestates,
            'due_tasks' => $duetasks,
        ];
    }

    public static function student_summary_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'kp_states' => new external_multiple_structure(self::state_item_structure('kg_id', 'Knowledge point id')),
            'qtype_states' => new external_multiple_structure(self::state_item_structure('qg_id', 'Question type id')),
            'due_tasks' => new external_multiple_structure(self::review_task_structure()),
        ]);
    }

    public static function reviews_due_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current user', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum tasks to return', VALUE_DEFAULT, 50),
            'due_before' => new external_value(PARAM_INT, 'Upper bound due timestamp, 0 means now', VALUE_DEFAULT, 0),
        ]);
    }

    public static function reviews_due(int $courseid, int $userid = 0, int $limit = 50, int $duebefore = 0): array {
        global $DB;
        self::system_context(false);
        $params = self::validate_parameters(self::reviews_due_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'limit' => $limit,
            'due_before' => $duebefore,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        $limit = max(1, min(200, (int)$params['limit']));
        $duebefore = !empty($params['due_before']) ? (int)$params['due_before'] : time();

        $records = $DB->get_records_select(
            'local_mathstate_review_task',
            'userid = :userid AND courseid = :courseid AND status = :status AND due_at > 0 AND due_at <= :duebefore',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'status' => 'todo',
                'duebefore' => $duebefore,
            ],
            'priority DESC, due_at ASC',
            '*',
            0,
            $limit
        );

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'id' => (int)$record->id,
                'target_type' => (string)$record->target_type,
                'target_ref' => (string)$record->target_ref,
                'title' => (string)$record->title,
                'task_kind' => (string)$record->task_kind,
                'priority' => (float)$record->priority,
                'source_reason' => (string)$record->source_reason,
                'status' => (string)$record->status,
                'due_at' => (int)($record->due_at ?? 0),
                'linked_doc_url' => (string)($record->linked_doc_url ?? ''),
            ];
        }

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'count' => count($items),
            'items' => $items,
        ];
    }

    public static function reviews_due_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'count' => new external_value(PARAM_INT, 'Task count'),
            'items' => new external_multiple_structure(self::review_task_structure()),
        ]);
    }
}
