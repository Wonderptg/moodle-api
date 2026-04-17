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
 * Storage helpers for question map sync.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_map_store {
    public static function get_question_map(int $questionid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_mathstate_question_map', ['questionid' => $questionid]) ?: null;
    }

    public static function upsert_mapping(array $values, int $timestamp): array {
        return standard_store::upsert_record(
            'local_mathstate_question_map',
            'questionid',
            (string)$values['questionid'],
            $values,
            $timestamp
        );
    }

    public static function build_mapping_values(array $item): array {
        global $DB;

        $qgid = trim((string)($item['qg_id'] ?? ''));
        $qtype = $qgid !== ''
            ? $DB->get_record('local_mathstate_std_qtype', ['qg_id' => $qgid], 'id', IGNORE_MISSING)
            : null;
        $difficulty = (int)($item['difficulty'] ?? 0);

        return [
            'questionid' => (int)$item['questionid'],
            'questionbankentryid' => !empty($item['questionbankentryid']) ? (int)$item['questionbankentryid'] : null,
            'qtype_std_id' => $qtype ? (int)$qtype->id : null,
            'source_id' => normalizer::optional_text((string)($item['source_id'] ?? '')),
            'qg_id' => normalizer::optional_text($qgid),
            'kg_ids_json' => normalizer::encode_json(
                normalizer::normalize_string_list($item['kg_ids'] ?? [])
            ),
            'lesson_key' => normalizer::optional_text((string)($item['lesson_key'] ?? '')),
            'lesson_match_type' => normalizer::optional_text((string)($item['lesson_match_type'] ?? '')),
            'lesson_confidence' => normalizer::optional_text((string)($item['lesson_confidence'] ?? '')),
            'difficulty' => $difficulty > 0 ? $difficulty : null,
            'mapping_source' => trim((string)($item['mapping_source'] ?? '')) !== ''
                ? trim((string)$item['mapping_source'])
                : 'manual',
            'mapping_confidence' => round((float)($item['mapping_confidence'] ?? 0.0), 2),
            'review_status' => normalizer::optional_text((string)($item['review_status'] ?? '')),
            'review_notes' => normalizer::optional_text((string)($item['review_notes'] ?? '')),
            'metadata_json' => normalizer::encode_json($item['metadata'] ?? []),
        ];
    }

    public static function resolve_source_id(string $sourceid): array {
        global $DB;

        $sourceid = trim($sourceid);
        if ($sourceid === '') {
            return [];
        }

        $tagname = 'srcid:' . $sourceid;
        $sql = "SELECT DISTINCT q.id AS questionid,
                               qv.questionbankentryid,
                               qbe.idnumber
                  FROM {question} q
                  JOIN {question_versions} qv
                    ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe
                    ON qbe.id = qv.questionbankentryid
             LEFT JOIN {tag_instance} ti
                    ON ti.itemid = q.id
                   AND ti.component = :tagcomponent
                   AND ti.itemtype = :tagitemtype
             LEFT JOIN {tag} t
                    ON t.id = ti.tagid
                 WHERE q.parent = 0
                   AND (
                        qbe.idnumber = :sourceid
                     OR t.rawname = :tagrawname
                     OR t.name = :tagname
                   )
              ORDER BY q.id DESC";

        return array_values($DB->get_records_sql($sql, [
            'tagcomponent' => 'core_question',
            'tagitemtype' => 'question',
            'sourceid' => $sourceid,
            'tagrawname' => $tagname,
            'tagname' => $tagname,
        ]));
    }

    public static function sync_sidecar_batch(array $items): array {
        $timestamp = time();
        $synced = [];
        $unresolved = [];
        $ambiguous = [];

        foreach ($items as $item) {
            $sourceid = trim((string)($item['source_id'] ?? ''));
            $matches = [];
            if (!empty($item['questionid'])) {
                $matches[] = (object)[
                    'questionid' => (int)$item['questionid'],
                    'questionbankentryid' => !empty($item['questionbankentryid']) ? (int)$item['questionbankentryid'] : null,
                ];
            } else if ($sourceid !== '') {
                $matches = self::resolve_source_id($sourceid);
            }

            if (count($matches) === 0) {
                $unresolved[] = [
                    'source_id' => $sourceid,
                    'status' => 'unresolved',
                ];
                continue;
            }

            if (count($matches) > 1) {
                $ambiguous[] = [
                    'source_id' => $sourceid,
                    'status' => 'ambiguous',
                    'question_ids' => array_map(static fn($match) => (int)$match->questionid, $matches),
                ];
                continue;
            }

            $match = reset($matches);
            $metadata = [
                'source_question_name' => (string)($item['source_question_name'] ?? ''),
                'normalized_chapter_key' => (string)($item['normalized_chapter_key'] ?? ''),
                'normalized_section_key' => (string)($item['normalized_section_key'] ?? ''),
                'lesson_source' => (string)($item['lesson_source'] ?? ''),
                'lesson_candidate_keys' => $item['lesson_candidate_keys'] ?? [],
                'evidence_excerpt' => (string)($item['evidence_excerpt'] ?? ''),
                'mapping_source' => (string)($item['mapping_source'] ?? ''),
            ];
            $values = self::build_mapping_values([
                'questionid' => (int)$match->questionid,
                'questionbankentryid' => !empty($match->questionbankentryid) ? (int)$match->questionbankentryid : 0,
                'source_id' => $sourceid,
                'qg_id' => (string)($item['qg_id'] ?? ''),
                'kg_ids' => $item['kg_ids'] ?? [],
                'lesson_key' => (string)($item['lesson_key'] ?? ''),
                'lesson_match_type' => (string)($item['lesson_match_type'] ?? ''),
                'lesson_confidence' => (string)($item['lesson_confidence'] ?? ''),
                'difficulty' => 0,
                'mapping_source' => (string)($item['mapping_source'] ?? 'sidecar'),
                'mapping_confidence' => self::confidence_to_number((string)($item['mapping_confidence'] ?? '')),
                'review_status' => (string)($item['review_status'] ?? ''),
                'review_notes' => (string)($item['review_notes'] ?? ''),
                'metadata' => $metadata,
            ]);
            $result = self::upsert_mapping($values, $timestamp);
            $synced[] = [
                'source_id' => $sourceid,
                'questionid' => (int)$values['questionid'],
                'questionbankentryid' => (int)($values['questionbankentryid'] ?? 0),
                'record_id' => (int)$result['record_id'],
                'action' => $result['action'],
            ];
        }

        return [
            'ok' => true,
            'synced' => $synced,
            'unresolved' => $unresolved,
            'ambiguous' => $ambiguous,
        ];
    }

    public static function lookup(array $filters, int $limit = 20): array {
        global $DB;

        $where = [];
        $params = [];

        if (!empty($filters['questionid'])) {
            $where[] = 'qm.questionid = :questionid';
            $params['questionid'] = (int)$filters['questionid'];
        }

        if (!empty($filters['questionbankentryid'])) {
            $where[] = 'qm.questionbankentryid = :questionbankentryid';
            $params['questionbankentryid'] = (int)$filters['questionbankentryid'];
        }

        if (!empty($filters['source_id'])) {
            $where[] = 'qm.source_id = :sourceid';
            $params['sourceid'] = trim((string)$filters['source_id']);
        }

        if (!empty($filters['qg_id'])) {
            $where[] = 'qm.qg_id = :qgid';
            $params['qgid'] = trim((string)$filters['qg_id']);
        }

        if (!empty($filters['lesson_key'])) {
            $where[] = 'qm.lesson_key = :lessonkey';
            $params['lessonkey'] = trim((string)$filters['lesson_key']);
        }

        $wheresql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT qm.*,
                       q.name AS questionname,
                       q.qtype AS moodleqtype,
                       qbe.idnumber AS questionidnumber
                  FROM {local_mathstate_question_map} qm
             LEFT JOIN {question} q
                    ON q.id = qm.questionid
             LEFT JOIN {question_bank_entries} qbe
                    ON qbe.id = qm.questionbankentryid
                       {$wheresql}
              ORDER BY qm.timemodified DESC, qm.id DESC";

        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }

    public static function confidence_to_number(string $confidence): float {
        $value = trim($confidence);
        if ($value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return round((float)$value, 2);
        }

        $map = [
            'high' => 90.0,
            'medium' => 70.0,
            'low' => 50.0,
        ];
        return $map[strtolower($value)] ?? 0.0;
    }
}
