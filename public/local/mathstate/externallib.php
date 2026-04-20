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

    private static function course_context(int $courseid): \context_course {
        global $DB;
        $DB->get_record('course', ['id' => $courseid], 'id', MUST_EXIST);
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        return $context;
    }

    private static function require_course_user_view(int $courseid, int $userid): \context_course {
        global $USER;
        $context = self::course_context($courseid);
        require_capability('moodle/course:view', $context);
        if ((int)$USER->id !== $userid) {
            self::system_context(false);
        }
        return $context;
    }

    private static function require_course_user_write(int $courseid, int $userid): \context_course {
        global $USER;
        $context = self::course_context($courseid);
        require_capability('moodle/course:view', $context);
        if ((int)$USER->id !== $userid) {
            self::system_context(true);
        }
        return $context;
    }

    private static function build_session_key(int $userid, int $courseid): string {
        return sprintf('session-%d-%d-%d-%s', $userid, $courseid, time(), substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private static function build_job_key(int $userid, int $courseid): string {
        return sprintf('docjob-%d-%d-%d-%s', $userid, $courseid, time(), substr(bin2hex(random_bytes(3)), 0, 6));
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

    private static function recommendation_item_structure(): external_single_structure {
        return new external_single_structure([
            'kind' => new external_value(PARAM_RAW, 'Recommendation kind'),
            'target_type' => new external_value(PARAM_RAW, 'Target type'),
            'target_ref' => new external_value(PARAM_RAW, 'Target reference'),
            'title' => new external_value(PARAM_RAW, 'Recommendation title'),
            'reason' => new external_value(PARAM_RAW, 'Reason text'),
            'priority' => new external_value(PARAM_FLOAT, 'Priority score'),
            'due_at' => new external_value(PARAM_INT, 'Due timestamp, 0 if none'),
            'payload_json' => new external_value(PARAM_RAW, 'Structured recommendation payload'),
        ]);
    }

    private static function video_progress_item_structure(): external_single_structure {
        return new external_single_structure([
            'session_key' => new external_value(PARAM_RAW, 'Session key'),
            'lesson_key' => new external_value(PARAM_RAW, 'Lesson key'),
            'cmid' => new external_value(PARAM_INT, 'Lesson session course module id'),
            'resource_course_id' => new external_value(PARAM_INT, 'Resource source course id'),
            'resource_cmid' => new external_value(PARAM_INT, 'Resource course module id'),
            'duration_sec' => new external_value(PARAM_FLOAT, 'Video duration seconds'),
            'watched_seconds' => new external_value(PARAM_FLOAT, 'Aggregated watched seconds'),
            'coverage_ratio' => new external_value(PARAM_FLOAT, 'Coverage ratio 0..1'),
            'last_position_sec' => new external_value(PARAM_FLOAT, 'Last playback position in seconds'),
            'completed' => new external_value(PARAM_BOOL, 'Whether the video was completed'),
            'status' => new external_value(PARAM_RAW, 'Lesson session status'),
            'started_at' => new external_value(PARAM_INT, 'Session started timestamp'),
            'ended_at' => new external_value(PARAM_INT, 'Session ended timestamp'),
            'last_event_at' => new external_value(PARAM_INT, 'Last event timestamp'),
            'source' => new external_value(PARAM_RAW, 'Source label'),
            'updated_at' => new external_value(PARAM_INT, 'Video aggregate updated timestamp'),
        ]);
    }

    private static function decode_json_object(string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function metadata_string(?string $value, string $key): string {
        $metadata = self::decode_json_object((string)$value);
        return isset($metadata[$key]) ? trim((string)$metadata[$key]) : '';
    }

    private static function normalize_lesson_summary(array $params, $existing = null): array {
        if (!$existing instanceof \stdClass) {
            $existing = null;
        }
        $summary = trim((string)($params['summary_json'] ?? '')) !== ''
            ? self::decode_json_object((string)$params['summary_json'])
            : self::decode_json_object((string)($existing->summary_json ?? ''));

        $summarytext = trim((string)($params['summary_text'] ?? ''));
        if ($summarytext !== '') {
            $summary['summary_text'] = $summarytext;
        }

        $outcome = trim((string)($params['outcome'] ?? ''));
        if ($outcome !== '') {
            $summary['outcome'] = $outcome;
        }

        $durationsec = (int)($params['duration_sec'] ?? 0);
        if ($durationsec > 0) {
            $summary['duration_sec'] = $durationsec;
        }

        $payloadjson = trim((string)($params['payload_json'] ?? ''));
        if ($payloadjson !== '') {
            $payload = self::decode_json_object($payloadjson);
            if (!empty($payload)) {
                $summary['payload'] = $payload;
            }
        }

        return $summary;
    }

    private static function find_review_task_for_completion(
        int $userid,
        int $courseid,
        array $params
    ): array {
        global $DB;

        $reviewid = !empty($params['review_task_id']) ? (int)$params['review_task_id'] : (int)($params['review_id'] ?? 0);
        if ($reviewid > 0) {
            $record = $DB->get_record('local_mathstate_review_task', [
                'id' => $reviewid,
                'userid' => $userid,
                'courseid' => $courseid,
            ], '*', IGNORE_MISSING);
            return [$record, $record ? (string)$record->target_type : '', $record ? (string)$record->target_ref : ''];
        }

        $targettype = trim((string)($params['target_type'] ?? ''));
        $targetref = trim((string)($params['target_ref'] ?? ''));

        $sessionkey = trim((string)($params['session_id'] ?? ''));
        if ($sessionkey === '') {
            $sessionkey = trim((string)($params['session_key'] ?? ''));
        }
        $lessonkey = trim((string)($params['lesson_key'] ?? ''));

        if ($lessonkey === '' && $sessionkey !== '') {
            $session = $DB->get_record('local_mathstate_lesson_session', [
                'session_key' => $sessionkey,
                'userid' => $userid,
                'courseid' => $courseid,
            ], '*', IGNORE_MISSING);
            if ($session && !empty($session->lesson_key)) {
                $lessonkey = (string)$session->lesson_key;
            }
        }

        if ($targetref === '' && $lessonkey !== '') {
            $targettype = $targettype !== '' ? $targettype : 'lesson';
            $targetref = $lessonkey;
        } else if ($targetref === '' && $sessionkey !== '') {
            $targettype = $targettype !== '' ? $targettype : 'session';
            $targetref = $sessionkey;
        }

        if ($targettype === '' || $targetref === '') {
            return [null, $targettype, $targetref];
        }

        $record = $DB->get_record('local_mathstate_review_task', [
            'userid' => $userid,
            'courseid' => $courseid,
            'target_type' => $targettype,
            'target_ref' => $targetref,
        ], '*', IGNORE_MISSING);

        return [$record, $targettype, $targetref];
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
            $result = \local_mathstate\local\storage\standard_store::upsert_record(
                'local_mathstate_std_kp',
                'kg_id',
                $values['kg_id'],
                $values,
                $timestamp
            );
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
            $result = \local_mathstate\local\storage\standard_store::upsert_record(
                'local_mathstate_std_qtype',
                'qg_id',
                $values['qg_id'],
                $values,
                $timestamp
            );
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
        self::system_context(true);
        $params = self::validate_parameters(self::question_map_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $timestamp = time();
        $results = [];
        foreach ($params['items'] as $item) {
            $values = \local_mathstate\local\storage\question_map_store::build_mapping_values([
                'questionid' => (int)$item['questionid'],
                'questionbankentryid' => !empty($item['questionbankentryid']) ? (int)$item['questionbankentryid'] : 0,
                'qg_id' => (string)$item['qg_id'],
                'kg_ids' => $item['kg_ids'],
                'difficulty' => (int)$item['difficulty'],
                'mapping_source' => (string)$item['mapping_source'],
                'mapping_confidence' => (float)$item['mapping_confidence'],
            ]);
            $result = \local_mathstate\local\storage\question_map_store::upsert_mapping($values, $timestamp);
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

    public static function question_map_sync_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'source_id' => new external_value(PARAM_RAW, 'Stable source id from XML sidecar'),
                    'questionid' => new external_value(PARAM_INT, 'Question id override', VALUE_DEFAULT, 0),
                    'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry id override', VALUE_DEFAULT, 0),
                    'source_question_name' => new external_value(PARAM_RAW, 'Source question name', VALUE_DEFAULT, ''),
                    'normalized_chapter_key' => new external_value(PARAM_RAW, 'Normalized chapter key', VALUE_DEFAULT, ''),
                    'normalized_section_key' => new external_value(PARAM_RAW, 'Normalized section key', VALUE_DEFAULT, ''),
                    'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
                    'lesson_match_type' => new external_value(PARAM_RAW, 'Lesson match type', VALUE_DEFAULT, ''),
                    'lesson_confidence' => new external_value(PARAM_RAW, 'Lesson confidence', VALUE_DEFAULT, ''),
                    'lesson_source' => new external_value(PARAM_RAW, 'Lesson source', VALUE_DEFAULT, ''),
                    'lesson_candidate_keys' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'Lesson candidate key'),
                        'Lesson candidates',
                        VALUE_DEFAULT,
                        []
                    ),
                    'qg_id' => new external_value(PARAM_RAW, 'Primary qg id', VALUE_DEFAULT, ''),
                    'kg_ids' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'Knowledge point id'),
                        'Knowledge point ids',
                        VALUE_DEFAULT,
                        []
                    ),
                    'mapping_source' => new external_value(PARAM_RAW, 'Mapping source', VALUE_DEFAULT, 'sidecar'),
                    'mapping_confidence' => new external_value(PARAM_RAW, 'Mapping confidence label or number', VALUE_DEFAULT, ''),
                    'review_status' => new external_value(PARAM_RAW, 'Review status', VALUE_DEFAULT, ''),
                    'review_notes' => new external_value(PARAM_RAW, 'Review notes', VALUE_DEFAULT, ''),
                    'evidence_excerpt' => new external_value(PARAM_RAW, 'Evidence excerpt', VALUE_DEFAULT, ''),
                ]),
                'Question bridge items'
            ),
            'dry_run' => new external_value(PARAM_BOOL, 'Resolve only, do not write rows', VALUE_DEFAULT, false),
        ]);
    }

    public static function question_map_sync_batch(array $items, bool $dryrun = false): array {
        self::system_context(true);
        $params = self::validate_parameters(self::question_map_sync_batch_parameters(), ['items' => $items, 'dry_run' => $dryrun]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        return \local_mathstate\local\storage\question_map_store::sync_sidecar_batch(
            $params['items'],
            !empty($params['dry_run'])
        );
    }

    public static function question_map_sync_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'dry_run' => new external_value(PARAM_BOOL, 'Dry run mode'),
            'synced' => new external_multiple_structure(
                new external_single_structure([
                    'source_id' => new external_value(PARAM_RAW, 'Source id'),
                    'questionid' => new external_value(PARAM_INT, 'Resolved question id'),
                    'questionbankentryid' => new external_value(PARAM_INT, 'Resolved question bank entry id'),
                    'record_id' => new external_value(PARAM_INT, 'Question map row id'),
                    'action' => new external_value(PARAM_RAW, 'created or updated'),
                ])
            ),
            'unresolved' => new external_multiple_structure(
                new external_single_structure([
                    'source_id' => new external_value(PARAM_RAW, 'Source id'),
                    'status' => new external_value(PARAM_RAW, 'Status'),
                ])
            ),
            'ambiguous' => new external_multiple_structure(
                new external_single_structure([
                    'source_id' => new external_value(PARAM_RAW, 'Source id'),
                    'status' => new external_value(PARAM_RAW, 'Status'),
                    'question_ids' => new external_multiple_structure(
                        new external_value(PARAM_INT, 'Question id'),
                        'Conflicting question ids'
                    ),
                ])
            ),
        ]);
    }

    public static function question_map_lookup_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question id', VALUE_DEFAULT, 0),
            'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry id', VALUE_DEFAULT, 0),
            'source_id' => new external_value(PARAM_RAW, 'Stable source id', VALUE_DEFAULT, ''),
            'qg_id' => new external_value(PARAM_RAW, 'QG id', VALUE_DEFAULT, ''),
            'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Maximum rows to return', VALUE_DEFAULT, 20),
        ]);
    }

    public static function question_map_lookup(
        int $questionid = 0,
        int $questionbankentryid = 0,
        string $sourceid = '',
        string $qgid = '',
        string $lessonkey = '',
        int $limit = 20
    ): array {
        self::system_context(true);
        $params = self::validate_parameters(self::question_map_lookup_parameters(), [
            'questionid' => $questionid,
            'questionbankentryid' => $questionbankentryid,
            'source_id' => $sourceid,
            'qg_id' => $qgid,
            'lesson_key' => $lessonkey,
            'limit' => $limit,
        ]);

        $limit = max(1, min(100, (int)$params['limit']));
        $records = \local_mathstate\local\storage\question_map_store::lookup([
            'questionid' => (int)$params['questionid'],
            'questionbankentryid' => (int)$params['questionbankentryid'],
            'source_id' => (string)$params['source_id'],
            'qg_id' => (string)$params['qg_id'],
            'lesson_key' => (string)$params['lesson_key'],
        ], $limit);

        $items = [];
        foreach ($records as $record) {
            $items[] = [
                'id' => (int)$record->id,
                'questionid' => (int)$record->questionid,
                'questionbankentryid' => (int)($record->questionbankentryid ?? 0),
                'source_id' => (string)($record->source_id ?? ''),
                'questionname' => (string)($record->questionname ?? ''),
                'questionidnumber' => (string)($record->questionidnumber ?? ''),
                'moodle_qtype' => (string)($record->moodleqtype ?? ''),
                'qg_id' => (string)($record->qg_id ?? ''),
                'kg_ids' => self::decode_json_list((string)($record->kg_ids_json ?? '')),
                'lesson_key' => (string)($record->lesson_key ?? ''),
                'lesson_match_type' => (string)($record->lesson_match_type ?? ''),
                'lesson_confidence' => (string)($record->lesson_confidence ?? ''),
                'mapping_source' => (string)($record->mapping_source ?? ''),
                'mapping_confidence' => (float)($record->mapping_confidence ?? 0),
                'review_status' => (string)($record->review_status ?? ''),
                'review_notes' => (string)($record->review_notes ?? ''),
                'metadata_json' => (string)($record->metadata_json ?? ''),
                'chapter' => self::metadata_string((string)($record->metadata_json ?? ''), 'normalized_chapter_key'),
                'section' => self::metadata_string((string)($record->metadata_json ?? ''), 'normalized_section_key'),
                'source_question_name' => self::metadata_string((string)($record->metadata_json ?? ''), 'source_question_name'),
                'timemodified' => (int)($record->timemodified ?? 0),
            ];
        }

        return [
            'ok' => true,
            'count' => count($items),
            'items' => $items,
        ];
    }

    public static function question_map_lookup_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Result count'),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Question map row id'),
                    'questionid' => new external_value(PARAM_INT, 'Question id'),
                    'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry id'),
                    'source_id' => new external_value(PARAM_RAW, 'Source id', VALUE_OPTIONAL),
                    'questionname' => new external_value(PARAM_RAW, 'Question name', VALUE_OPTIONAL),
                    'questionidnumber' => new external_value(PARAM_RAW, 'Question idnumber', VALUE_OPTIONAL),
                    'moodle_qtype' => new external_value(PARAM_RAW, 'Moodle qtype', VALUE_OPTIONAL),
                    'qg_id' => new external_value(PARAM_RAW, 'QG id', VALUE_OPTIONAL),
                    'kg_ids' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'KG id'),
                        'Knowledge point ids'
                    ),
                    'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_OPTIONAL),
                    'lesson_match_type' => new external_value(PARAM_RAW, 'Lesson match type', VALUE_OPTIONAL),
                    'lesson_confidence' => new external_value(PARAM_RAW, 'Lesson confidence', VALUE_OPTIONAL),
                    'mapping_source' => new external_value(PARAM_RAW, 'Mapping source'),
                    'mapping_confidence' => new external_value(PARAM_FLOAT, 'Mapping confidence'),
                    'review_status' => new external_value(PARAM_RAW, 'Review status', VALUE_OPTIONAL),
                    'review_notes' => new external_value(PARAM_RAW, 'Review notes', VALUE_OPTIONAL),
                    'metadata_json' => new external_value(PARAM_RAW, 'Metadata JSON', VALUE_OPTIONAL),
                    'chapter' => new external_value(PARAM_RAW, 'Chapter key', VALUE_OPTIONAL),
                    'section' => new external_value(PARAM_RAW, 'Section key', VALUE_OPTIONAL),
                    'source_question_name' => new external_value(PARAM_RAW, 'Source question name', VALUE_OPTIONAL),
                    'timemodified' => new external_value(PARAM_INT, 'Modified timestamp'),
                ])
            ),
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

    public static function learning_event_record_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'session_key' => new external_value(PARAM_RAW, 'Lesson session key', VALUE_DEFAULT, ''),
                    'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
                    'questionid' => new external_value(PARAM_INT, 'Question id', VALUE_DEFAULT, 0),
                    'questionusageid' => new external_value(PARAM_INT, 'Question usage id', VALUE_DEFAULT, 0),
                    'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_DEFAULT, 0),
                    'qg_id' => new external_value(PARAM_RAW, 'Question group id', VALUE_DEFAULT, ''),
                    'kg_ids' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'Knowledge point id'),
                        'Knowledge point ids',
                        VALUE_DEFAULT,
                        []
                    ),
                    'event_type' => new external_value(PARAM_RAW, 'Event type', VALUE_DEFAULT, 'practice'),
                    'result' => new external_value(PARAM_RAW, 'Result', VALUE_DEFAULT, ''),
                    'score' => new external_value(PARAM_FLOAT, 'Score', VALUE_DEFAULT, 0.0),
                    'maxscore' => new external_value(PARAM_FLOAT, 'Max score', VALUE_DEFAULT, 0.0),
                    'source' => new external_value(PARAM_RAW, 'Source', VALUE_DEFAULT, 'agent'),
                    'payload_json' => new external_value(PARAM_RAW, 'Payload JSON', VALUE_DEFAULT, ''),
                    'occurred_at' => new external_value(PARAM_INT, 'Occurred at timestamp', VALUE_DEFAULT, 0),
                ]),
                'Learning events'
            ),
        ]);
    }

    public static function learning_event_record_batch(array $items): array {
        self::system_context(true);
        $params = self::validate_parameters(self::learning_event_record_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $prepared = [];
        foreach ($params['items'] as $item) {
            $prepared[] = [
                'userid' => self::resolve_userid((int)$item['userid']),
                'courseid' => (int)$item['courseid'],
                'session_key' => (string)$item['session_key'],
                'lesson_key' => (string)$item['lesson_key'],
                'questionid' => (int)$item['questionid'],
                'questionusageid' => (int)$item['questionusageid'],
                'cmid' => (int)$item['cmid'],
                'qg_id' => (string)$item['qg_id'],
                'kg_ids' => $item['kg_ids'],
                'event_type' => (string)$item['event_type'],
                'result' => (string)$item['result'],
                'score' => (float)$item['score'],
                'maxscore' => (float)$item['maxscore'],
                'source' => (string)$item['source'],
                'payload' => self::decode_json_object((string)$item['payload_json']),
                'occurred_at' => (int)$item['occurred_at'],
            ];
        }

        $results = \local_mathstate\local\storage\runtime_store::record_learning_events($prepared);
        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function learning_event_record_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Event count'),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'event_id' => new external_value(PARAM_INT, 'Learning event id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'session_key' => new external_value(PARAM_RAW, 'Session key', VALUE_OPTIONAL),
                    'qg_id' => new external_value(PARAM_RAW, 'Resolved qg id', VALUE_OPTIONAL),
                    'kg_count' => new external_value(PARAM_INT, 'Resolved knowledge point count'),
                ])
            ),
        ]);
    }

    public static function lesson_session_upsert_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'session_key' => new external_value(PARAM_RAW, 'Session key'),
                    'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
                    'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_DEFAULT, 0),
                    'status' => new external_value(PARAM_RAW, 'Status', VALUE_DEFAULT, 'active'),
                    'progress_json' => new external_value(PARAM_RAW, 'Progress JSON', VALUE_DEFAULT, ''),
                    'summary_json' => new external_value(PARAM_RAW, 'Summary JSON', VALUE_DEFAULT, ''),
                    'started_at' => new external_value(PARAM_INT, 'Started at timestamp', VALUE_DEFAULT, 0),
                    'ended_at' => new external_value(PARAM_INT, 'Ended at timestamp', VALUE_DEFAULT, 0),
                    'last_event_at' => new external_value(PARAM_INT, 'Last event timestamp', VALUE_DEFAULT, 0),
                    'source' => new external_value(PARAM_RAW, 'Source', VALUE_DEFAULT, 'agent'),
                ]),
                'Lesson sessions'
            ),
        ]);
    }

    public static function lesson_session_upsert_batch(array $items): array {
        self::system_context(true);
        $params = self::validate_parameters(self::lesson_session_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $prepared = [];
        foreach ($params['items'] as $item) {
            $prepared[] = [
                'userid' => self::resolve_userid((int)$item['userid']),
                'courseid' => (int)$item['courseid'],
                'session_key' => (string)$item['session_key'],
                'lesson_key' => (string)$item['lesson_key'],
                'cmid' => (int)$item['cmid'],
                'status' => (string)$item['status'],
                'progress' => self::decode_json_object((string)$item['progress_json']),
                'summary' => self::decode_json_object((string)$item['summary_json']),
                'started_at' => (int)$item['started_at'],
                'ended_at' => (int)$item['ended_at'],
                'last_event_at' => (int)$item['last_event_at'],
                'source' => (string)$item['source'],
            ];
        }

        $results = \local_mathstate\local\storage\runtime_store::upsert_lesson_sessions($prepared);
        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function lesson_session_upsert_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Session count'),
            'items' => new external_multiple_structure(self::batch_result_item_structure()),
        ]);
    }

    public static function review_upsert_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'target_type' => new external_value(PARAM_RAW, 'Target type'),
                    'target_ref' => new external_value(PARAM_RAW, 'Target ref'),
                    'title' => new external_value(PARAM_RAW, 'Title'),
                    'task_kind' => new external_value(PARAM_RAW, 'Task kind', VALUE_DEFAULT, 'review'),
                    'priority' => new external_value(PARAM_FLOAT, 'Priority', VALUE_DEFAULT, 0.0),
                    'source_reason' => new external_value(PARAM_RAW, 'Source reason', VALUE_DEFAULT, 'manual'),
                    'payload_json' => new external_value(PARAM_RAW, 'Payload JSON', VALUE_DEFAULT, ''),
                    'status' => new external_value(PARAM_RAW, 'Status', VALUE_DEFAULT, 'todo'),
                    'due_at' => new external_value(PARAM_INT, 'Due at timestamp', VALUE_DEFAULT, 0),
                    'completed_at' => new external_value(PARAM_INT, 'Completed at timestamp', VALUE_DEFAULT, 0),
                    'linked_doc_url' => new external_value(PARAM_RAW, 'Linked document URL', VALUE_DEFAULT, ''),
                ]),
                'Review items'
            ),
        ]);
    }

    public static function review_upsert_batch(array $items): array {
        self::system_context(true);
        $params = self::validate_parameters(self::review_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $prepared = [];
        foreach ($params['items'] as $item) {
            $prepared[] = [
                'userid' => self::resolve_userid((int)$item['userid']),
                'courseid' => (int)$item['courseid'],
                'target_type' => (string)$item['target_type'],
                'target_ref' => (string)$item['target_ref'],
                'title' => (string)$item['title'],
                'task_kind' => (string)$item['task_kind'],
                'priority' => (float)$item['priority'],
                'source_reason' => (string)$item['source_reason'],
                'payload' => self::decode_json_object((string)$item['payload_json']),
                'status' => (string)$item['status'],
                'due_at' => (int)$item['due_at'],
                'completed_at' => (int)$item['completed_at'],
                'linked_doc_url' => (string)$item['linked_doc_url'],
            ];
        }

        $results = \local_mathstate\local\storage\runtime_store::upsert_reviews($prepared);
        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function review_upsert_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Review count'),
            'items' => new external_multiple_structure(self::batch_result_item_structure()),
        ]);
    }

    public static function doc_job_upsert_batch_parameters(): external_function_parameters {
        return new external_function_parameters([
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'job_key' => new external_value(PARAM_RAW, 'Job key'),
                    'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'target_type' => new external_value(PARAM_RAW, 'Target type', VALUE_DEFAULT, ''),
                    'target_ref' => new external_value(PARAM_RAW, 'Target ref', VALUE_DEFAULT, ''),
                    'doc_ref' => new external_value(PARAM_RAW, 'Doc ref', VALUE_DEFAULT, ''),
                    'provider' => new external_value(PARAM_RAW, 'Provider', VALUE_DEFAULT, 'agent'),
                    'job_type' => new external_value(PARAM_RAW, 'Job type', VALUE_DEFAULT, 'doc'),
                    'status' => new external_value(PARAM_RAW, 'Status', VALUE_DEFAULT, 'queued'),
                    'request_payload_json' => new external_value(PARAM_RAW, 'Request payload JSON', VALUE_DEFAULT, ''),
                    'result_payload_json' => new external_value(PARAM_RAW, 'Result payload JSON', VALUE_DEFAULT, ''),
                    'error_message' => new external_value(PARAM_RAW, 'Error message', VALUE_DEFAULT, ''),
                    'queued_at' => new external_value(PARAM_INT, 'Queued at timestamp', VALUE_DEFAULT, 0),
                    'completed_at' => new external_value(PARAM_INT, 'Completed at timestamp', VALUE_DEFAULT, 0),
                ]),
                'Doc jobs'
            ),
        ]);
    }

    public static function doc_job_upsert_batch(array $items): array {
        self::system_context(true);
        $params = self::validate_parameters(self::doc_job_upsert_batch_parameters(), ['items' => $items]);
        if (count($params['items']) > self::MAX_BATCH_ITEMS) {
            throw new moodle_exception('Too many items in batch.');
        }

        $prepared = [];
        foreach ($params['items'] as $item) {
            $prepared[] = [
                'job_key' => (string)$item['job_key'],
                'userid' => self::resolve_userid((int)$item['userid']),
                'courseid' => (int)$item['courseid'],
                'target_type' => (string)$item['target_type'],
                'target_ref' => (string)$item['target_ref'],
                'doc_ref' => (string)$item['doc_ref'],
                'provider' => (string)$item['provider'],
                'job_type' => (string)$item['job_type'],
                'status' => (string)$item['status'],
                'request_payload' => self::decode_json_object((string)$item['request_payload_json']),
                'result_payload' => self::decode_json_object((string)$item['result_payload_json']),
                'error_message' => (string)$item['error_message'],
                'queued_at' => (int)$item['queued_at'],
                'completed_at' => (int)$item['completed_at'],
            ];
        }

        $results = \local_mathstate\local\storage\runtime_store::upsert_doc_jobs($prepared);
        return [
            'ok' => true,
            'count' => count($results),
            'items' => $results,
        ];
    }

    public static function doc_job_upsert_batch_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'count' => new external_value(PARAM_INT, 'Doc job count'),
            'items' => new external_multiple_structure(self::batch_result_item_structure()),
        ]);
    }

    public static function lesson_start_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current token user', VALUE_DEFAULT, 0),
            'session_key' => new external_value(PARAM_RAW, 'Session key, empty means auto-generate', VALUE_DEFAULT, ''),
            'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_DEFAULT, 0),
            'source' => new external_value(PARAM_RAW, 'Source', VALUE_DEFAULT, 'agent'),
            'progress_json' => new external_value(PARAM_RAW, 'Progress JSON', VALUE_DEFAULT, ''),
            'summary_json' => new external_value(PARAM_RAW, 'Summary JSON', VALUE_DEFAULT, ''),
        ]);
    }

    public static function lesson_start(
        int $courseid,
        int $userid = 0,
        string $sessionkey = '',
        string $lessonkey = '',
        int $cmid = 0,
        string $source = 'agent',
        string $progressjson = '',
        string $summaryjson = ''
    ): array {
        $params = self::validate_parameters(self::lesson_start_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'session_key' => $sessionkey,
            'lesson_key' => $lessonkey,
            'cmid' => $cmid,
            'source' => $source,
            'progress_json' => $progressjson,
            'summary_json' => $summaryjson,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_write($courseid, $userid);

        $timestamp = time();
        $sessionkey = trim((string)$params['session_key']);
        if ($sessionkey === '') {
            $sessionkey = self::build_session_key($userid, $courseid);
        }

        $items = [[
            'userid' => $userid,
            'courseid' => $courseid,
            'session_key' => $sessionkey,
            'lesson_key' => (string)$params['lesson_key'],
            'cmid' => (int)$params['cmid'],
            'status' => 'active',
            'progress' => self::decode_json_object((string)$params['progress_json']),
            'summary' => self::decode_json_object((string)$params['summary_json']),
            'started_at' => $timestamp,
            'ended_at' => 0,
            'last_event_at' => $timestamp,
            'source' => (string)$params['source'],
        ]];
        $results = \local_mathstate\local\storage\runtime_store::upsert_lesson_sessions($items);
        $result = $results[0] ?? ['record_id' => 0, 'action' => 'created'];

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'session_key' => $sessionkey,
            'status' => 'active',
            'started_at' => $timestamp,
            'record_id' => (int)$result['record_id'],
            'action' => (string)$result['action'],
        ];
    }

    public static function lesson_start_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'session_key' => new external_value(PARAM_RAW, 'Session key'),
            'status' => new external_value(PARAM_RAW, 'Session status'),
            'started_at' => new external_value(PARAM_INT, 'Start timestamp'),
            'record_id' => new external_value(PARAM_INT, 'Lesson session row id'),
            'action' => new external_value(PARAM_RAW, 'created or updated'),
        ]);
    }

    public static function lesson_finish_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current token user', VALUE_DEFAULT, 0),
            'session_key' => new external_value(PARAM_RAW, 'Session key', VALUE_DEFAULT, ''),
            'session_id' => new external_value(PARAM_RAW, 'Compatibility alias for session key', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_RAW, 'Final status', VALUE_DEFAULT, 'completed'),
            'ended_at' => new external_value(PARAM_INT, 'End timestamp, 0 means now', VALUE_DEFAULT, 0),
            'finished_at' => new external_value(PARAM_INT, 'Compatibility end timestamp', VALUE_DEFAULT, 0),
            'source' => new external_value(PARAM_RAW, 'Source', VALUE_DEFAULT, 'agent'),
            'progress_json' => new external_value(PARAM_RAW, 'Progress JSON', VALUE_DEFAULT, ''),
            'summary_json' => new external_value(PARAM_RAW, 'Summary JSON', VALUE_DEFAULT, ''),
            'summary_text' => new external_value(PARAM_RAW, 'Compatibility summary text', VALUE_DEFAULT, ''),
            'outcome' => new external_value(PARAM_RAW, 'Compatibility outcome field', VALUE_DEFAULT, ''),
            'duration_sec' => new external_value(PARAM_INT, 'Compatibility duration', VALUE_DEFAULT, 0),
            'payload_json' => new external_value(PARAM_RAW, 'Compatibility payload JSON', VALUE_DEFAULT, ''),
        ]);
    }

    public static function lesson_finish(
        int $courseid,
        int $userid = 0,
        string $sessionkey = '',
        string $sessionid = '',
        string $status = 'completed',
        int $endedat = 0,
        int $finishedat = 0,
        string $source = 'agent',
        string $progressjson = '',
        string $summaryjson = '',
        string $summarytext = '',
        string $outcome = '',
        int $durationsec = 0,
        string $payloadjson = ''
    ): array {
        global $DB;
        $params = self::validate_parameters(self::lesson_finish_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'session_key' => $sessionkey,
            'session_id' => $sessionid,
            'status' => $status,
            'ended_at' => $endedat,
            'finished_at' => $finishedat,
            'source' => $source,
            'progress_json' => $progressjson,
            'summary_json' => $summaryjson,
            'summary_text' => $summarytext,
            'outcome' => $outcome,
            'duration_sec' => $durationsec,
            'payload_json' => $payloadjson,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_write($courseid, $userid);

        $sessionkey = trim((string)$params['session_key']);
        if ($sessionkey === '') {
            $sessionkey = trim((string)$params['session_id']);
        }
        if ($sessionkey === '') {
            throw new invalid_parameter_exception('session_key/session_id is required');
        }
        $timestamp = !empty($params['ended_at'])
            ? (int)$params['ended_at']
            : (!empty($params['finished_at']) ? (int)$params['finished_at'] : time());
        $existing = $DB->get_record('local_mathstate_lesson_session', ['session_key' => $sessionkey], '*', IGNORE_MISSING);
        if ($existing && ((int)$existing->userid !== $userid || (int)$existing->courseid !== $courseid)) {
            throw new moodle_exception('Session key does not match current user/course.');
        }

        $items = [[
            'userid' => $userid,
            'courseid' => $courseid,
            'session_key' => $sessionkey,
            'lesson_key' => $existing ? (string)($existing->lesson_key ?? '') : '',
            'cmid' => $existing ? (int)($existing->cmid ?? 0) : 0,
            'status' => trim((string)$params['status']) !== '' ? trim((string)$params['status']) : 'completed',
            'progress' => trim((string)$params['progress_json']) !== ''
                ? self::decode_json_object((string)$params['progress_json'])
                : self::decode_json_object((string)($existing->progress_json ?? '')),
            'summary' => self::normalize_lesson_summary($params, $existing),
            'started_at' => $existing && !empty($existing->started_at) ? (int)$existing->started_at : $timestamp,
            'ended_at' => $timestamp,
            'last_event_at' => $timestamp,
            'source' => trim((string)$params['source']) !== ''
                ? trim((string)$params['source'])
                : (string)($existing->source ?? 'agent'),
        ]];
        $results = \local_mathstate\local\storage\runtime_store::upsert_lesson_sessions($items);
        $result = $results[0] ?? ['record_id' => 0, 'action' => 'updated'];

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'session_key' => $sessionkey,
            'session_id' => $sessionkey,
            'status' => (string)$items[0]['status'],
            'ended_at' => $timestamp,
            'record_id' => (int)$result['record_id'],
            'action' => (string)$result['action'],
        ];
    }

    public static function lesson_finish_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'session_key' => new external_value(PARAM_RAW, 'Session key'),
            'session_id' => new external_value(PARAM_RAW, 'Compatibility alias for session key'),
            'status' => new external_value(PARAM_RAW, 'Session status'),
            'ended_at' => new external_value(PARAM_INT, 'End timestamp'),
            'record_id' => new external_value(PARAM_INT, 'Lesson session row id'),
            'action' => new external_value(PARAM_RAW, 'created or updated'),
        ]);
    }

    public static function lesson_log_append_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current token user', VALUE_DEFAULT, 0),
            'session_key' => new external_value(PARAM_RAW, 'Session key', VALUE_DEFAULT, ''),
            'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
            'questionid' => new external_value(PARAM_INT, 'Question id', VALUE_DEFAULT, 0),
            'questionusageid' => new external_value(PARAM_INT, 'Question usage id', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_DEFAULT, 0),
            'qg_id' => new external_value(PARAM_RAW, 'QG id', VALUE_DEFAULT, ''),
            'kg_ids' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Knowledge point id'),
                'Knowledge point ids',
                VALUE_DEFAULT,
                []
            ),
            'event_type' => new external_value(PARAM_RAW, 'Event type', VALUE_DEFAULT, 'practice'),
            'result' => new external_value(PARAM_RAW, 'Result', VALUE_DEFAULT, ''),
            'score' => new external_value(PARAM_FLOAT, 'Score', VALUE_DEFAULT, 0.0),
            'maxscore' => new external_value(PARAM_FLOAT, 'Max score', VALUE_DEFAULT, 0.0),
            'source' => new external_value(PARAM_RAW, 'Source', VALUE_DEFAULT, 'agent'),
            'payload_json' => new external_value(PARAM_RAW, 'Payload JSON', VALUE_DEFAULT, ''),
            'occurred_at' => new external_value(PARAM_INT, 'Occurred at timestamp, 0 means now', VALUE_DEFAULT, 0),
        ]);
    }

    public static function lesson_log_append(
        int $courseid,
        int $userid = 0,
        string $sessionkey = '',
        string $lessonkey = '',
        int $questionid = 0,
        int $questionusageid = 0,
        int $cmid = 0,
        string $qgid = '',
        array $kgids = [],
        string $eventtype = 'practice',
        string $result = '',
        float $score = 0.0,
        float $maxscore = 0.0,
        string $source = 'agent',
        string $payloadjson = '',
        int $occurredat = 0
    ): array {
        $params = self::validate_parameters(self::lesson_log_append_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'session_key' => $sessionkey,
            'lesson_key' => $lessonkey,
            'questionid' => $questionid,
            'questionusageid' => $questionusageid,
            'cmid' => $cmid,
            'qg_id' => $qgid,
            'kg_ids' => $kgids,
            'event_type' => $eventtype,
            'result' => $result,
            'score' => $score,
            'maxscore' => $maxscore,
            'source' => $source,
            'payload_json' => $payloadjson,
            'occurred_at' => $occurredat,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_write($courseid, $userid);

        $items = [[
            'userid' => $userid,
            'courseid' => $courseid,
            'session_key' => (string)$params['session_key'],
            'lesson_key' => (string)$params['lesson_key'],
            'questionid' => (int)$params['questionid'],
            'questionusageid' => (int)$params['questionusageid'],
            'cmid' => (int)$params['cmid'],
            'qg_id' => (string)$params['qg_id'],
            'kg_ids' => $params['kg_ids'],
            'event_type' => (string)$params['event_type'],
            'result' => (string)$params['result'],
            'score' => (float)$params['score'],
            'maxscore' => (float)$params['maxscore'],
            'source' => (string)$params['source'],
            'payload' => self::decode_json_object((string)$params['payload_json']),
            'occurred_at' => (int)$params['occurred_at'],
        ]];
        $results = \local_mathstate\local\storage\runtime_store::record_learning_events($items);
        $event = $results[0] ?? ['event_id' => 0, 'kg_count' => 0, 'qg_id' => ''];

        return [
            'ok' => true,
            'event_id' => (int)$event['event_id'],
            'userid' => $userid,
            'courseid' => $courseid,
            'session_key' => (string)($event['session_key'] ?? ''),
            'qg_id' => (string)($event['qg_id'] ?? ''),
            'kg_count' => (int)($event['kg_count'] ?? 0),
        ];
    }

    public static function lesson_log_append_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'event_id' => new external_value(PARAM_INT, 'Learning event id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'session_key' => new external_value(PARAM_RAW, 'Session key', VALUE_OPTIONAL),
            'qg_id' => new external_value(PARAM_RAW, 'Resolved qg id', VALUE_OPTIONAL),
            'kg_count' => new external_value(PARAM_INT, 'Resolved knowledge point count'),
        ]);
    }

    public static function review_complete_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current token user', VALUE_DEFAULT, 0),
            'review_id' => new external_value(PARAM_INT, 'Review task id', VALUE_DEFAULT, 0),
            'review_task_id' => new external_value(PARAM_INT, 'Compatibility review task id', VALUE_DEFAULT, 0),
            'target_type' => new external_value(PARAM_RAW, 'Target type fallback', VALUE_DEFAULT, ''),
            'target_ref' => new external_value(PARAM_RAW, 'Target ref fallback', VALUE_DEFAULT, ''),
            'session_id' => new external_value(PARAM_RAW, 'Compatibility session id/session key', VALUE_DEFAULT, ''),
            'session_key' => new external_value(PARAM_RAW, 'Session key alias', VALUE_DEFAULT, ''),
            'lesson_key' => new external_value(PARAM_RAW, 'Lesson key', VALUE_DEFAULT, ''),
            'status' => new external_value(PARAM_RAW, 'Completion status', VALUE_DEFAULT, 'done'),
            'completed_at' => new external_value(PARAM_INT, 'Completed timestamp, 0 means now', VALUE_DEFAULT, 0),
            'linked_doc_url' => new external_value(PARAM_RAW, 'Linked doc URL', VALUE_DEFAULT, ''),
            'note' => new external_value(PARAM_RAW, 'Completion note', VALUE_DEFAULT, ''),
            'completion_note' => new external_value(PARAM_RAW, 'Compatibility completion note', VALUE_DEFAULT, ''),
        ]);
    }

    public static function review_complete(
        int $courseid,
        int $userid = 0,
        int $reviewid = 0,
        int $reviewtaskid = 0,
        string $targettype = '',
        string $targetref = '',
        string $sessionid = '',
        string $sessionkey = '',
        string $lessonkey = '',
        string $status = 'done',
        int $completedat = 0,
        string $linkeddocurl = '',
        string $note = '',
        string $completionnote = ''
    ): array {
        $params = self::validate_parameters(self::review_complete_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'review_id' => $reviewid,
            'review_task_id' => $reviewtaskid,
            'target_type' => $targettype,
            'target_ref' => $targetref,
            'session_id' => $sessionid,
            'session_key' => $sessionkey,
            'lesson_key' => $lessonkey,
            'status' => $status,
            'completed_at' => $completedat,
            'linked_doc_url' => $linkeddocurl,
            'note' => $note,
            'completion_note' => $completionnote,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_write($courseid, $userid);

        [$record, $resolvedtype, $resolvedref] = self::find_review_task_for_completion($userid, $courseid, $params);
        if (!$record) {
            if ($resolvedtype === '' || $resolvedref === '') {
                throw new invalid_parameter_exception(
                    'review_task_id/review_id or target/session/lesson information is required'
                );
            }

            $created = \local_mathstate\local\storage\runtime_store::upsert_reviews([[
                'userid' => $userid,
                'courseid' => $courseid,
                'target_type' => $resolvedtype,
                'target_ref' => $resolvedref,
                'title' => 'Review ' . $resolvedref,
                'task_kind' => 'review',
                'priority' => 0.0,
                'source_reason' => 'completion-compat',
                'payload' => [],
                'status' => 'todo',
                'due_at' => 0,
                'completed_at' => 0,
                'linked_doc_url' => '',
            ]]);
            $record = (object)[
                'id' => (int)($created[0]['record_id'] ?? 0),
                'userid' => $userid,
                'courseid' => $courseid,
                'target_type' => $resolvedtype,
                'target_ref' => $resolvedref,
                'title' => 'Review ' . $resolvedref,
                'task_kind' => 'review',
                'priority' => 0.0,
                'source_reason' => 'completion-compat',
                'due_at' => null,
                'linked_doc_url' => '',
                'recommended_payload_json' => '',
            ];
        }

        $payload = self::decode_json_object((string)($record->recommended_payload_json ?? ''));
        $note = trim((string)$params['note']);
        if ($note === '') {
            $note = trim((string)$params['completion_note']);
        }
        if ($note !== '') {
            $payload['completion_note'] = $note;
        }
        $completedat = !empty($params['completed_at']) ? (int)$params['completed_at'] : time();
        $status = trim((string)$params['status']) !== '' ? trim((string)$params['status']) : 'done';
        $results = \local_mathstate\local\storage\runtime_store::upsert_reviews([[
            'userid' => $userid,
            'courseid' => $courseid,
            'target_type' => (string)$record->target_type,
            'target_ref' => (string)$record->target_ref,
            'title' => (string)$record->title,
            'task_kind' => (string)$record->task_kind,
            'priority' => (float)$record->priority,
            'source_reason' => (string)$record->source_reason,
            'payload' => $payload,
            'status' => $status,
            'due_at' => (int)($record->due_at ?? 0),
            'completed_at' => $completedat,
            'linked_doc_url' => trim((string)$params['linked_doc_url']) !== ''
                ? trim((string)$params['linked_doc_url'])
                : (string)($record->linked_doc_url ?? ''),
        ]]);
        $result = $results[0] ?? ['record_id' => (int)$record->id, 'action' => 'updated'];

        return [
            'ok' => true,
            'review_task_id' => (int)$record->id,
            'review_id' => (int)$record->id,
            'userid' => $userid,
            'courseid' => $courseid,
            'target_type' => (string)$record->target_type,
            'target_ref' => (string)$record->target_ref,
            'status' => $status,
            'completed_at' => $completedat,
            'record_id' => (int)$result['record_id'],
            'action' => (string)$result['action'],
        ];
    }

    public static function review_complete_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'review_task_id' => new external_value(PARAM_INT, 'Stable review task id'),
            'review_id' => new external_value(PARAM_INT, 'Review task id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'target_type' => new external_value(PARAM_RAW, 'Target type'),
            'target_ref' => new external_value(PARAM_RAW, 'Target reference'),
            'status' => new external_value(PARAM_RAW, 'Completion status'),
            'completed_at' => new external_value(PARAM_INT, 'Completion timestamp'),
            'record_id' => new external_value(PARAM_INT, 'Review row id'),
            'action' => new external_value(PARAM_RAW, 'created or updated'),
        ]);
    }

    public static function doc_publish_request_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current token user', VALUE_DEFAULT, 0),
            'job_key' => new external_value(PARAM_RAW, 'Job key, empty means auto-generate', VALUE_DEFAULT, ''),
            'target_type' => new external_value(PARAM_RAW, 'Target type', VALUE_DEFAULT, ''),
            'target_ref' => new external_value(PARAM_RAW, 'Target ref', VALUE_DEFAULT, ''),
            'doc_ref' => new external_value(PARAM_RAW, 'Doc ref', VALUE_DEFAULT, ''),
            'provider' => new external_value(PARAM_RAW, 'Provider', VALUE_DEFAULT, 'agent'),
            'job_type' => new external_value(PARAM_RAW, 'Job type', VALUE_DEFAULT, 'doc'),
            'doc_type' => new external_value(PARAM_RAW, 'Compatibility document type', VALUE_DEFAULT, ''),
            'request_payload_json' => new external_value(PARAM_RAW, 'Request payload JSON', VALUE_DEFAULT, ''),
            'queued_at' => new external_value(PARAM_INT, 'Queued timestamp, 0 means now', VALUE_DEFAULT, 0),
        ]);
    }

    public static function doc_publish_request(
        int $courseid,
        int $userid = 0,
        string $jobkey = '',
        string $targettype = '',
        string $targetref = '',
        string $docref = '',
        string $provider = 'agent',
        string $jobtype = 'doc',
        string $doctype = '',
        string $requestpayloadjson = '',
        int $queuedat = 0
    ): array {
        $params = self::validate_parameters(self::doc_publish_request_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'job_key' => $jobkey,
            'target_type' => $targettype,
            'target_ref' => $targetref,
            'doc_ref' => $docref,
            'provider' => $provider,
            'job_type' => $jobtype,
            'doc_type' => $doctype,
            'request_payload_json' => $requestpayloadjson,
            'queued_at' => $queuedat,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_write($courseid, $userid);

        $jobkey = trim((string)$params['job_key']);
        if ($jobkey === '') {
            $jobkey = self::build_job_key($userid, $courseid);
        }
        $queuedat = !empty($params['queued_at']) ? (int)$params['queued_at'] : time();
        $jobtype = trim((string)$params['job_type']);
        if ($jobtype === '') {
            $jobtype = trim((string)$params['doc_type']);
        }
        if ($jobtype === '') {
            $jobtype = 'doc';
        }

        $results = \local_mathstate\local\storage\runtime_store::upsert_doc_jobs([[
            'job_key' => $jobkey,
            'userid' => $userid,
            'courseid' => $courseid,
            'target_type' => (string)$params['target_type'],
            'target_ref' => (string)$params['target_ref'],
            'doc_ref' => (string)$params['doc_ref'],
            'provider' => (string)$params['provider'],
            'job_type' => $jobtype,
            'status' => 'queued',
            'request_payload' => self::decode_json_object((string)$params['request_payload_json']),
            'result_payload' => [],
            'error_message' => '',
            'queued_at' => $queuedat,
            'completed_at' => 0,
        ]]);
        $result = $results[0] ?? ['record_id' => 0, 'action' => 'created'];

        return [
            'ok' => true,
            'job_key' => $jobkey,
            'doc_job_id' => (int)$result['record_id'],
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => 'queued',
            'queued_at' => $queuedat,
            'record_id' => (int)$result['record_id'],
            'action' => (string)$result['action'],
        ];
    }

    public static function doc_publish_request_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'job_key' => new external_value(PARAM_RAW, 'Job key'),
            'doc_job_id' => new external_value(PARAM_INT, 'Stable doc job id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'status' => new external_value(PARAM_RAW, 'Job status'),
            'queued_at' => new external_value(PARAM_INT, 'Queued timestamp'),
            'record_id' => new external_value(PARAM_INT, 'Doc job row id'),
            'action' => new external_value(PARAM_RAW, 'created or updated'),
        ]);
    }

    public static function next_recommendation_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current token user', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Max recommendation count', VALUE_DEFAULT, 5),
            'due_before' => new external_value(PARAM_INT, 'Due upper bound timestamp, 0 means now', VALUE_DEFAULT, 0),
        ]);
    }

    public static function next_recommendation(int $courseid, int $userid = 0, int $limit = 5, int $duebefore = 0): array {
        global $DB;
        $params = self::validate_parameters(self::next_recommendation_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'limit' => $limit,
            'due_before' => $duebefore,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_view($courseid, $userid);

        $limit = max(1, min(50, (int)$params['limit']));
        $duebefore = !empty($params['due_before']) ? (int)$params['due_before'] : time();
        $recommendations = [];

        $duetasks = $DB->get_records_select(
            'local_mathstate_review_task',
            'userid = :userid AND courseid = :courseid AND status IN (:todo, :doing) AND due_at > 0 AND due_at <= :duebefore',
            [
                'userid' => $userid,
                'courseid' => $courseid,
                'todo' => 'todo',
                'doing' => 'doing',
                'duebefore' => $duebefore,
            ],
            'priority DESC, due_at ASC',
            '*',
            0,
            $limit
        );
        foreach ($duetasks as $task) {
            $recommendations[] = [
                'kind' => 'due_review',
                'target_type' => (string)$task->target_type,
                'target_ref' => (string)$task->target_ref,
                'title' => (string)$task->title,
                'reason' => 'review task due',
                'priority' => (float)$task->priority,
                'due_at' => (int)($task->due_at ?? 0),
                'payload_json' => (string)($task->recommended_payload_json ?? '{}'),
            ];
            if (count($recommendations) >= $limit) {
                break;
            }
        }

        if (count($recommendations) < $limit) {
            $remaining = $limit - count($recommendations);
            $weakkp = $DB->get_records_select(
                'local_mathstate_student_kp',
                'userid = :userid AND courseid = :courseid AND stage IN (:weak, :learning)',
                [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'weak' => 'weak',
                    'learning' => 'learning',
                ],
                'mastery_score ASC, timemodified DESC',
                '*',
                0,
                $remaining
            );
            foreach ($weakkp as $state) {
                $recommendations[] = [
                    'kind' => 'focus_kp',
                    'target_type' => 'kp',
                    'target_ref' => (string)$state->kg_id,
                    'title' => '巩固知识点：' . self::standard_name('kp', (string)$state->kg_id),
                    'reason' => 'low mastery',
                    'priority' => max(0.0, 100.0 - (float)$state->mastery_score),
                    'due_at' => (int)($state->next_review_at ?? 0),
                    'payload_json' => self::encode_json([
                        'stage' => (string)$state->stage,
                        'mastery_score' => (float)$state->mastery_score,
                    ]),
                ];
                if (count($recommendations) >= $limit) {
                    break;
                }
            }
        }

        if (count($recommendations) < $limit) {
            $remaining = $limit - count($recommendations);
            $weakqtypes = $DB->get_records_select(
                'local_mathstate_student_qtype',
                'userid = :userid AND courseid = :courseid AND stage IN (:weak, :learning)',
                [
                    'userid' => $userid,
                    'courseid' => $courseid,
                    'weak' => 'weak',
                    'learning' => 'learning',
                ],
                'mastery_score ASC, timemodified DESC',
                '*',
                0,
                $remaining
            );
            foreach ($weakqtypes as $state) {
                $recommendations[] = [
                    'kind' => 'focus_qtype',
                    'target_type' => 'qtype',
                    'target_ref' => (string)$state->qg_id,
                    'title' => '巩固题型：' . self::standard_name('qtype', (string)$state->qg_id),
                    'reason' => 'low mastery',
                    'priority' => max(0.0, 100.0 - (float)$state->mastery_score),
                    'due_at' => (int)($state->next_review_at ?? 0),
                    'payload_json' => self::encode_json([
                        'stage' => (string)$state->stage,
                        'mastery_score' => (float)$state->mastery_score,
                    ]),
                ];
                if (count($recommendations) >= $limit) {
                    break;
                }
            }
        }

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'generated_at' => time(),
            'count' => count($recommendations),
            'items' => $recommendations,
        ];
    }

    public static function next_recommendation_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'generated_at' => new external_value(PARAM_INT, 'Recommendation generated timestamp'),
            'count' => new external_value(PARAM_INT, 'Recommendation count'),
            'items' => new external_multiple_structure(self::recommendation_item_structure()),
        ]);
    }

    public static function video_progress_summary_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current user', VALUE_DEFAULT, 0),
            'session_key' => new external_value(PARAM_RAW, 'Optional session key filter', VALUE_DEFAULT, ''),
            'lesson_key' => new external_value(PARAM_RAW, 'Optional lesson key filter', VALUE_DEFAULT, ''),
            'cmid' => new external_value(PARAM_INT, 'Optional session cmid filter', VALUE_DEFAULT, 0),
            'resource_course_id' => new external_value(PARAM_INT, 'Optional source course id filter', VALUE_DEFAULT, 0),
            'resource_cmid' => new external_value(PARAM_INT, 'Optional source cmid filter', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum summary items to return', VALUE_DEFAULT, 50),
        ]);
    }

    public static function video_progress_summary(
        int $courseid,
        int $userid = 0,
        string $sessionkey = '',
        string $lessonkey = '',
        int $cmid = 0,
        int $resourcecourseid = 0,
        int $resourcecmid = 0,
        int $limit = 50
    ): array {
        $params = self::validate_parameters(self::video_progress_summary_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'session_key' => $sessionkey,
            'lesson_key' => $lessonkey,
            'cmid' => $cmid,
            'resource_course_id' => $resourcecourseid,
            'resource_cmid' => $resourcecmid,
            'limit' => $limit,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_view($courseid, $userid);

        $items = \local_mathstate\local\storage\runtime_store::video_progress_summaries($userid, $courseid, [
            'session_key' => (string)$params['session_key'],
            'lesson_key' => (string)$params['lesson_key'],
            'cmid' => (int)$params['cmid'],
            'resource_course_id' => (int)$params['resource_course_id'],
            'resource_cmid' => (int)$params['resource_cmid'],
            'limit' => (int)$params['limit'],
        ]);

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'count' => count($items),
            'items' => $items,
        ];
    }

    public static function video_progress_summary_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'count' => new external_value(PARAM_INT, 'Summary item count'),
            'items' => new external_multiple_structure(self::video_progress_item_structure()),
        ]);
    }

    public static function student_summary_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userid' => new external_value(PARAM_INT, 'User id, 0 means current user', VALUE_DEFAULT, 0),
            'include_kp_states' => new external_value(PARAM_BOOL, 'Include knowledge point states', VALUE_DEFAULT, true),
            'include_qtype_states' => new external_value(PARAM_BOOL, 'Include question type states', VALUE_DEFAULT, true),
            'include_due_tasks' => new external_value(PARAM_BOOL, 'Include due tasks', VALUE_DEFAULT, true),
            'include_video_progress' => new external_value(PARAM_BOOL, 'Include video progress summary', VALUE_DEFAULT, true),
        ]);
    }

    public static function student_summary(
        int $courseid,
        int $userid = 0,
        bool $includekpstates = true,
        bool $includeqtypestates = true,
        bool $includeduetasks = true,
        bool $includevideoprogress = true
    ): array {
        global $DB;
        $params = self::validate_parameters(self::student_summary_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'include_kp_states' => $includekpstates,
            'include_qtype_states' => $includeqtypestates,
            'include_due_tasks' => $includeduetasks,
            'include_video_progress' => $includevideoprogress,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_view($courseid, $userid);

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

        $videoprogress = [];
        if (!empty($params['include_video_progress'])) {
            $videoprogress = \local_mathstate\local\storage\runtime_store::video_progress_summaries($userid, $courseid, [
                'limit' => 50,
            ]);
        }

        return [
            'ok' => true,
            'userid' => $userid,
            'courseid' => $courseid,
            'kp_states' => $kpstates,
            'qtype_states' => $qtypestates,
            'due_tasks' => $duetasks,
            'video_progress' => $videoprogress,
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
            'video_progress' => new external_multiple_structure(self::video_progress_item_structure()),
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
        $params = self::validate_parameters(self::reviews_due_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
            'limit' => $limit,
            'due_before' => $duebefore,
        ]);

        $userid = self::resolve_userid((int)$params['userid']);
        $courseid = (int)$params['courseid'];
        self::require_course_user_view($courseid, $userid);
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
