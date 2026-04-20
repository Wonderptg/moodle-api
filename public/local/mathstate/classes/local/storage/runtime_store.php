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
 * Runtime persistence helpers for local_mathstate.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class runtime_store {
    /** @var array<string, bool> */
    private const VIDEO_EVENT_TYPES = [
        'video_heartbeat' => true,
        'video_paused' => true,
        'video_seek' => true,
        'video_completed' => true,
    ];

    public static function record_learning_events(array $items): array {
        global $DB;

        $results = [];
        foreach ($items as $item) {
            $transaction = $DB->start_delegated_transaction();
            $timestamp = !empty($item['occurred_at']) ? (int)$item['occurred_at'] : time();
            $questionid = !empty($item['questionid']) ? (int)$item['questionid'] : 0;
            $qgid = trim((string)($item['qg_id'] ?? ''));
            $kgids = normalizer::normalize_string_list($item['kg_ids'] ?? []);

            if ($questionid > 0 && ($qgid === '' || empty($kgids))) {
                $map = question_map_store::get_question_map($questionid);
                if ($map) {
                    if ($qgid === '' && !empty($map->qg_id)) {
                        $qgid = (string)$map->qg_id;
                    }
                    if (empty($kgids)) {
                        $kgids = normalizer::normalize_string_list(
                            normalizer::decode_json_list((string)$map->kg_ids_json)
                        );
                    }
                }
            }

            if ($qgid !== '' && empty($kgids)) {
                $kgids = standard_store::qtype_kg_ids($qgid);
            }

            $record = (object)[
                'userid' => (int)$item['userid'],
                'courseid' => (int)$item['courseid'],
                'session_key' => normalizer::optional_text((string)($item['session_key'] ?? '')),
                'lesson_key' => normalizer::optional_text((string)($item['lesson_key'] ?? '')),
                'questionid' => $questionid ?: null,
                'questionusageid' => !empty($item['questionusageid']) ? (int)$item['questionusageid'] : null,
                'cmid' => !empty($item['cmid']) ? (int)$item['cmid'] : null,
                'qg_id' => normalizer::optional_text($qgid),
                'kg_ids_json' => normalizer::encode_json($kgids),
                'event_type' => trim((string)($item['event_type'] ?? 'practice')) ?: 'practice',
                'result' => normalizer::optional_text((string)($item['result'] ?? '')),
                'score' => round((float)($item['score'] ?? 0.0), 2),
                'maxscore' => round((float)($item['maxscore'] ?? 0.0), 2),
                'source' => trim((string)($item['source'] ?? 'agent')) ?: 'agent',
                'payload_json' => normalizer::encode_json($item['payload'] ?? []),
                'occurred_at' => $timestamp,
                'timecreated' => time(),
            ];
            $eventid = (int)$DB->insert_record('local_mathstate_learning_event', $record);

            self::touch_lesson_session($record, $timestamp);
            if ((string)($record->result ?? '') !== '' && ($qgid !== '' || !empty($kgids))) {
                self::apply_state_updates(
                    (int)$record->userid,
                    (int)$record->courseid,
                    (string)$record->result,
                    $qgid,
                    $kgids,
                    $timestamp,
                    $eventid
                );
            }

            $transaction->allow_commit();
            $results[] = [
                'event_id' => $eventid,
                'userid' => (int)$record->userid,
                'courseid' => (int)$record->courseid,
                'session_key' => (string)($record->session_key ?? ''),
                'qg_id' => $qgid,
                'kg_count' => count($kgids),
            ];
        }

        return $results;
    }

    public static function upsert_lesson_sessions(array $items): array {
        global $DB;

        $results = [];
        $timestamp = time();
        foreach ($items as $item) {
            $sessionkey = trim((string)($item['session_key'] ?? ''));
            $record = $DB->get_record('local_mathstate_lesson_session', ['session_key' => $sessionkey], '*', IGNORE_MISSING);
            $data = (object)[
                'userid' => (int)$item['userid'],
                'courseid' => (int)$item['courseid'],
                'session_key' => $sessionkey,
                'lesson_key' => normalizer::optional_text((string)($item['lesson_key'] ?? '')),
                'cmid' => !empty($item['cmid']) ? (int)$item['cmid'] : null,
                'status' => trim((string)($item['status'] ?? 'active')) ?: 'active',
                'progress_json' => normalizer::encode_json($item['progress'] ?? []),
                'summary_json' => normalizer::encode_json($item['summary'] ?? []),
                'started_at' => !empty($item['started_at']) ? (int)$item['started_at'] : $timestamp,
                'ended_at' => !empty($item['ended_at']) ? (int)$item['ended_at'] : null,
                'last_event_at' => !empty($item['last_event_at']) ? (int)$item['last_event_at'] : null,
                'source' => trim((string)($item['source'] ?? 'agent')) ?: 'agent',
                'timemodified' => $timestamp,
            ];

            if ($record) {
                $data->id = $record->id;
                $DB->update_record('local_mathstate_lesson_session', $data);
                $results[] = ['ref' => $sessionkey, 'record_id' => (int)$record->id, 'action' => 'updated'];
                continue;
            }

            $data->timecreated = $timestamp;
            $id = (int)$DB->insert_record('local_mathstate_lesson_session', $data);
            $results[] = ['ref' => $sessionkey, 'record_id' => $id, 'action' => 'created'];
        }

        return $results;
    }

    public static function upsert_reviews(array $items): array {
        global $DB;

        $results = [];
        $timestamp = time();
        foreach ($items as $item) {
            $existing = $DB->get_record_select(
                'local_mathstate_review_task',
                'userid = :userid AND courseid = :courseid AND target_type = :targettype AND target_ref = :targetref',
                [
                    'userid' => (int)$item['userid'],
                    'courseid' => (int)$item['courseid'],
                    'targettype' => trim((string)$item['target_type']),
                    'targetref' => trim((string)$item['target_ref']),
                ],
                '*',
                IGNORE_MISSING
            );

            $record = (object)[
                'userid' => (int)$item['userid'],
                'courseid' => (int)$item['courseid'],
                'target_type' => trim((string)$item['target_type']),
                'target_ref' => trim((string)$item['target_ref']),
                'title' => trim((string)$item['title']),
                'task_kind' => trim((string)($item['task_kind'] ?? 'review')) ?: 'review',
                'priority' => round((float)($item['priority'] ?? 0.0), 2),
                'source_reason' => trim((string)($item['source_reason'] ?? 'manual')) ?: 'manual',
                'recommended_payload_json' => normalizer::encode_json($item['payload'] ?? []),
                'status' => trim((string)($item['status'] ?? 'todo')) ?: 'todo',
                'due_at' => !empty($item['due_at']) ? (int)$item['due_at'] : null,
                'completed_at' => !empty($item['completed_at']) ? (int)$item['completed_at'] : null,
                'linked_doc_url' => normalizer::optional_text((string)($item['linked_doc_url'] ?? '')),
                'timemodified' => $timestamp,
            ];

            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('local_mathstate_review_task', $record);
                $results[] = ['ref' => (string)$record->target_ref, 'record_id' => (int)$existing->id, 'action' => 'updated'];
                continue;
            }

            $record->timecreated = $timestamp;
            $id = (int)$DB->insert_record('local_mathstate_review_task', $record);
            $results[] = ['ref' => (string)$record->target_ref, 'record_id' => $id, 'action' => 'created'];
        }

        return $results;
    }

    public static function upsert_doc_jobs(array $items): array {
        global $DB;

        $results = [];
        $timestamp = time();
        foreach ($items as $item) {
            $jobkey = trim((string)$item['job_key']);
            $existing = $DB->get_record('local_mathstate_doc_job', ['job_key' => $jobkey], '*', IGNORE_MISSING);
            $record = (object)[
                'job_key' => $jobkey,
                'userid' => (int)$item['userid'],
                'courseid' => (int)$item['courseid'],
                'target_type' => trim((string)($item['target_type'] ?? '')),
                'target_ref' => trim((string)($item['target_ref'] ?? '')),
                'doc_ref' => normalizer::optional_text((string)($item['doc_ref'] ?? '')),
                'provider' => trim((string)($item['provider'] ?? 'agent')) ?: 'agent',
                'job_type' => trim((string)($item['job_type'] ?? 'doc')) ?: 'doc',
                'status' => trim((string)($item['status'] ?? 'queued')) ?: 'queued',
                'request_payload_json' => normalizer::encode_json($item['request_payload'] ?? []),
                'result_payload_json' => normalizer::encode_json($item['result_payload'] ?? []),
                'error_message' => normalizer::optional_text((string)($item['error_message'] ?? '')),
                'queued_at' => !empty($item['queued_at']) ? (int)$item['queued_at'] : $timestamp,
                'completed_at' => !empty($item['completed_at']) ? (int)$item['completed_at'] : null,
                'timemodified' => $timestamp,
            ];

            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('local_mathstate_doc_job', $record);
                $results[] = ['ref' => $jobkey, 'record_id' => (int)$existing->id, 'action' => 'updated'];
                continue;
            }

            $record->timecreated = $timestamp;
            $id = (int)$DB->insert_record('local_mathstate_doc_job', $record);
            $results[] = ['ref' => $jobkey, 'record_id' => $id, 'action' => 'created'];
        }

        return $results;
    }

    public static function video_progress_summaries(int $userid, int $courseid, array $filters = []): array {
        global $DB;

        $limit = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $params = [
            'userid' => $userid,
            'courseid' => $courseid,
        ];
        $where = 'userid = :userid AND courseid = :courseid';

        $sessionkey = trim((string)($filters['session_key'] ?? ''));
        if ($sessionkey !== '') {
            $where .= ' AND session_key = :sessionkey';
            $params['sessionkey'] = $sessionkey;
        }

        $lessonkey = trim((string)($filters['lesson_key'] ?? ''));
        if ($lessonkey !== '') {
            $where .= ' AND lesson_key = :lessonkey';
            $params['lessonkey'] = $lessonkey;
        }

        $cmid = (int)($filters['cmid'] ?? 0);
        if ($cmid > 0) {
            $where .= ' AND cmid = :cmid';
            $params['cmid'] = $cmid;
        }

        $records = $DB->get_records_select(
            'local_mathstate_lesson_session',
            $where,
            $params,
            'last_event_at DESC, timemodified DESC, id DESC'
        );

        $resourcecourseid = (int)($filters['resource_course_id'] ?? 0);
        $resourcecmid = (int)($filters['resource_cmid'] ?? 0);
        $items = [];
        foreach ($records as $record) {
            $progress = self::decode_json_object((string)($record->progress_json ?? ''));
            $video = isset($progress['video_progress']) && is_array($progress['video_progress'])
                ? $progress['video_progress']
                : [];
            if (empty($video)) {
                continue;
            }

            $item = self::normalize_video_progress_item($record, $video);
            if ($resourcecourseid > 0 && (int)$item['resource_course_id'] !== $resourcecourseid) {
                continue;
            }
            if ($resourcecmid > 0 && (int)$item['resource_cmid'] !== $resourcecmid) {
                continue;
            }

            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private static function touch_lesson_session(\stdClass $event, int $timestamp): void {
        global $DB;

        if (empty($event->session_key)) {
            return;
        }

        $session = $DB->get_record('local_mathstate_lesson_session', ['session_key' => $event->session_key], '*', IGNORE_MISSING);
        $progress = [];
        if ($session && !empty($session->progress_json)) {
            $progress = self::decode_json_object((string)$session->progress_json);
        }
        if (self::is_video_event_type((string)($event->event_type ?? ''))) {
            $progress = self::merge_video_progress($progress, $event, $timestamp);
        }

        if (!$session) {
            $DB->insert_record('local_mathstate_lesson_session', (object)[
                'userid' => (int)$event->userid,
                'courseid' => (int)$event->courseid,
                'session_key' => (string)$event->session_key,
                'lesson_key' => normalizer::optional_text((string)($event->lesson_key ?? '')),
                'cmid' => !empty($event->cmid) ? (int)$event->cmid : null,
                'status' => 'active',
                'progress_json' => normalizer::encode_json($progress),
                'summary_json' => normalizer::encode_json([]),
                'started_at' => $timestamp,
                'ended_at' => null,
                'last_event_at' => $timestamp,
                'source' => (string)$event->source,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            return;
        }

        $session->last_event_at = $timestamp;
        if (empty($session->lesson_key) && !empty($event->lesson_key)) {
            $session->lesson_key = (string)$event->lesson_key;
        }
        if (empty($session->cmid) && !empty($event->cmid)) {
            $session->cmid = (int)$event->cmid;
        }
        if (!empty($progress)) {
            $session->progress_json = normalizer::encode_json($progress);
        }
        $session->timemodified = time();
        $DB->update_record('local_mathstate_lesson_session', $session);
    }

    private static function is_video_event_type(string $eventtype): bool {
        return isset(self::VIDEO_EVENT_TYPES[strtolower(trim($eventtype))]);
    }

    private static function decode_json_object(string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function float_or_null($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float)$value, 4);
    }

    private static function clamp_ratio(float $ratio): float {
        if ($ratio < 0.0) {
            return 0.0;
        }
        if ($ratio > 1.0) {
            return 1.0;
        }
        return round($ratio, 4);
    }

    private static function merge_video_progress(array $progress, \stdClass $event, int $timestamp): array {
        $payload = self::decode_json_object((string)($event->payload_json ?? ''));
        $video = isset($progress['video_progress']) && is_array($progress['video_progress'])
            ? $progress['video_progress']
            : [];

        $duration = self::float_or_null($payload['duration_sec'] ?? ($video['duration_sec'] ?? null));
        $position = self::float_or_null(
            $payload['current_time_sec']
            ?? $payload['position_sec']
            ?? $payload['last_position_sec']
            ?? ($video['last_position_sec'] ?? null)
        );
        $delta = max(0.0, (float)($payload['watch_seconds_delta'] ?? 0.0));
        $watched = max(0.0, (float)($video['watched_seconds'] ?? 0.0)) + $delta;
        if (array_key_exists('watched_seconds', $payload)) {
            $watched = max($watched, max(0.0, (float)$payload['watched_seconds']));
        }
        if ($duration !== null && $duration > 0.0) {
            $watched = min($watched, $duration);
        }
        $watched = round($watched, 2);

        $incomingcoverage = self::float_or_null($payload['coverage_ratio'] ?? null);
        $computedcoverage = ($duration !== null && $duration > 0.0)
            ? self::clamp_ratio($watched / $duration)
            : null;
        $coverage = max(
            self::clamp_ratio((float)($video['coverage_ratio'] ?? 0.0)),
            $incomingcoverage !== null ? self::clamp_ratio($incomingcoverage) : 0.0,
            $computedcoverage ?? 0.0
        );

        $completed = !empty($video['completed'])
            || !empty($payload['completed'])
            || strtolower((string)($event->event_type ?? '')) === 'video_completed';
        if ($completed && $duration !== null && $duration > 0.0) {
            $coverage = 1.0;
            if ($position === null) {
                $position = $duration;
            }
        }
        $position = $position !== null ? round(max(0.0, $position), 2) : 0.0;

        $progress['video_progress'] = [
            'resource_course_id' => max(0, (int)($payload['resource_course_id'] ?? ($video['resource_course_id'] ?? 0))),
            'resource_cmid' => max(0, (int)($payload['resource_cmid'] ?? ($video['resource_cmid'] ?? 0))),
            'duration_sec' => $duration !== null ? round(max(0.0, $duration), 2) : 0.0,
            'watched_seconds' => $watched,
            'coverage_ratio' => round($coverage, 4),
            'last_position_sec' => $position,
            'completed' => $completed,
            'last_event_type' => strtolower((string)($event->event_type ?? '')),
            'updated_at' => $timestamp,
        ];

        return $progress;
    }

    private static function normalize_video_progress_item(\stdClass $record, array $video): array {
        return [
            'session_key' => (string)$record->session_key,
            'lesson_key' => (string)($record->lesson_key ?? ''),
            'cmid' => (int)($record->cmid ?? 0),
            'resource_course_id' => max(0, (int)($video['resource_course_id'] ?? 0)),
            'resource_cmid' => max(0, (int)($video['resource_cmid'] ?? 0)),
            'duration_sec' => round(max(0.0, (float)($video['duration_sec'] ?? 0.0)), 2),
            'watched_seconds' => round(max(0.0, (float)($video['watched_seconds'] ?? 0.0)), 2),
            'coverage_ratio' => self::clamp_ratio((float)($video['coverage_ratio'] ?? 0.0)),
            'last_position_sec' => round(max(0.0, (float)($video['last_position_sec'] ?? 0.0)), 2),
            'completed' => !empty($video['completed']),
            'status' => (string)($record->status ?? ''),
            'started_at' => (int)($record->started_at ?? 0),
            'ended_at' => (int)($record->ended_at ?? 0),
            'last_event_at' => (int)($record->last_event_at ?? 0),
            'source' => (string)($record->source ?? ''),
            'updated_at' => (int)($video['updated_at'] ?? ($record->timemodified ?? 0)),
        ];
    }

    private static function apply_state_updates(
        int $userid,
        int $courseid,
        string $result,
        string $qgid,
        array $kgids,
        int $timestamp,
        int $eventid
    ): void {
        if ($qgid !== '') {
            self::save_qtype_state($userid, $courseid, $qgid, $result, $timestamp, $eventid);
        }
        foreach ($kgids as $kgid) {
            self::save_kp_state($userid, $courseid, $kgid, $result, $timestamp, $eventid);
        }
    }

    private static function save_kp_state(int $userid, int $courseid, string $kgid, string $result, int $timestamp, int $eventid): void {
        global $DB;

        $record = $DB->get_record('local_mathstate_student_kp', [
            'userid' => $userid,
            'courseid' => $courseid,
            'kg_id' => $kgid,
        ], '*', IGNORE_MISSING);
        if (!$record) {
            $std = $DB->get_record('local_mathstate_std_kp', ['kg_id' => $kgid], 'id', IGNORE_MISSING);
            $record = (object)[
                'userid' => $userid,
                'courseid' => $courseid,
                'kp_std_id' => $std ? (int)$std->id : null,
                'kg_id' => $kgid,
                'mastery_score' => 0,
                'stage' => 'new',
                'review_stage' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'recent_streak' => 0,
                'last_result' => null,
                'first_mastered_at' => null,
                'last_seen_at' => null,
                'last_correct_at' => null,
                'next_review_at' => null,
                'stability_days' => 0,
                'ease_factor' => 2.3,
                'source_last_evidence_id' => null,
                'status_note' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ];
        }

        self::apply_simple_result($record, $result, $timestamp, $eventid);
        if (!empty($record->id)) {
            $DB->update_record('local_mathstate_student_kp', $record);
        } else {
            $DB->insert_record('local_mathstate_student_kp', $record);
        }
    }

    private static function save_qtype_state(int $userid, int $courseid, string $qgid, string $result, int $timestamp, int $eventid): void {
        global $DB;

        $record = $DB->get_record('local_mathstate_student_qtype', [
            'userid' => $userid,
            'courseid' => $courseid,
            'qg_id' => $qgid,
        ], '*', IGNORE_MISSING);
        if (!$record) {
            $std = $DB->get_record('local_mathstate_std_qtype', ['qg_id' => $qgid], 'id', IGNORE_MISSING);
            $record = (object)[
                'userid' => $userid,
                'courseid' => $courseid,
                'qtype_std_id' => $std ? (int)$std->id : null,
                'qg_id' => $qgid,
                'mastery_score' => 0,
                'stage' => 'new',
                'review_stage' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'recent_streak' => 0,
                'last_result' => null,
                'last_seen_at' => null,
                'last_correct_at' => null,
                'next_review_at' => null,
                'source_last_evidence_id' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ];
        }

        self::apply_simple_result($record, $result, $timestamp, $eventid);
        if (!empty($record->id)) {
            $DB->update_record('local_mathstate_student_qtype', $record);
        } else {
            $DB->insert_record('local_mathstate_student_qtype', $record);
        }
    }

    private static function apply_simple_result(\stdClass $record, string $result, int $timestamp, int $eventid): void {
        $score = (float)$record->mastery_score;
        $reviewstage = (int)($record->review_stage ?? 0);

        switch ($result) {
            case 'correct':
                $score += 8.0;
                $record->correct_count = (int)$record->correct_count + 1;
                $record->recent_streak = (int)$record->recent_streak + 1;
                $record->last_correct_at = $timestamp;
                $reviewstage = min(3, $reviewstage + 1);
                $record->next_review_at = $timestamp + (7 * DAYSECS);
                break;
            case 'partial':
                $score += 2.0;
                $record->recent_streak = 0;
                $record->next_review_at = $timestamp + (2 * DAYSECS);
                break;
            case 'wrong':
            default:
                $score -= 8.0;
                $record->wrong_count = (int)$record->wrong_count + 1;
                $record->recent_streak = 0;
                $reviewstage = max(0, $reviewstage - 1);
                $record->next_review_at = $timestamp + DAYSECS;
                break;
        }

        $record->mastery_score = normalizer::clamp_score($score);
        $record->review_stage = $reviewstage;
        $record->last_result = $result;
        $record->last_seen_at = $timestamp;
        $record->source_last_evidence_id = $eventid;
        $record->stage = self::stage_from_score((float)$record->mastery_score);
        if (property_exists($record, 'stability_days')) {
            $record->stability_days = $reviewstage > 0 ? (float)([0, 1, 3, 7][$reviewstage] ?? 7) : 0.0;
        }
        if (property_exists($record, 'first_mastered_at') && (float)$record->mastery_score >= 80.0 && empty($record->first_mastered_at)) {
            $record->first_mastered_at = $timestamp;
        }
        $record->timemodified = time();
    }

    private static function stage_from_score(float $score): string {
        if ($score < 30.0) {
            return 'weak';
        }
        if ($score < 70.0) {
            return 'learning';
        }
        return 'stable';
    }
}
