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
 * External API implementations for local_aiagentapi.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/calendar/externallib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/message/lib.php');
require_once($CFG->dirroot . '/message/externallib.php');
require_once($CFG->dirroot . '/completion/classes/external.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/externallib.php');
require_once($CFG->dirroot . '/mod/forum/externallib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/classes/external.php');

/**
 * External functions for agent-friendly API wrappers.
 */
class local_aiagentapi_external extends external_api {

    /** Max events allowed in a single publish call. */
    private const MAX_EVENTS_PER_CALL = 50;
    /** Max questions allowed in a single render call. */
    private const MAX_QUESTIONS_PER_RENDER = 50;
    /** Max random questions allowed in a single pick call. */
    private const MAX_RANDOM_PICK = 100;
    /** Max copies allowed when resolving random quiz questions. */
    private const MAX_QUIZ_COPIES = 10;
    /** Max questions allowed when creating a practice quiz. */
    private const MAX_PRACTICE_QUIZ_QUESTIONS = 120;

    /**
     * Resolve a safe context for WS calls restricted by token context.
     *
     * @return \context
     */
    private static function restricted_context(): \context {
        $context = \context_course::instance(SITEID);
        self::validate_context($context);
        return $context;
    }

    /**
     * Collect course-related question bank context ids.
     *
     * Moodle 5.1 commonly stores question categories in qbank module contexts,
     * not directly in the course context. For course-scoped APIs we need to
     * search both the course context and any qbank module contexts in the course.
     *
     * @param \stdClass $course
     * @return array<int>
     */
    private static function course_question_context_ids(\stdClass $course): array {
        $contextids = [(int)\context_course::instance($course->id)->id];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_instances_of('qbank') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $contextids[] = (int)\context_module::instance($cm->id)->id;
        }
        return array_values(array_unique($contextids));
    }

    /**
     * Return true when a database table exists.
     *
     * @param string $tablename
     * @return bool
     */
    private static function table_exists(string $tablename): bool {
        global $DB;

        try {
            return $DB->get_manager()->table_exists(new \xmldb_table($tablename));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return cached category metadata for a course.
     *
     * @param int $categoryid
     * @return array
     */
    private static function course_category_metadata(int $categoryid): array {
        global $DB;

        static $cache = [];

        if (array_key_exists($categoryid, $cache)) {
            return $cache[$categoryid];
        }

        $metadata = [
            'id' => $categoryid,
            'name' => '',
            'path' => '',
            'displaypath' => '',
            'pathnames' => [],
        ];

        if ($categoryid > 0) {
            $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id,name,path', IGNORE_MISSING);
            if ($category) {
                $metadata['name'] = (string)$category->name;
                $metadata['path'] = (string)$category->path;

                $pathids = array_map('intval', preg_split('|/|', (string)$category->path, 0, PREG_SPLIT_NO_EMPTY));
                if (!empty($pathids)) {
                    $categories = $DB->get_records_list('course_categories', 'id', $pathids, '', 'id,name');
                    foreach ($pathids as $pathid) {
                        if (!empty($categories[$pathid])) {
                            $metadata['pathnames'][] = (string)$categories[$pathid]->name;
                        }
                    }
                }

                $metadata['displaypath'] = implode(' / ', $metadata['pathnames']);
            }
        }

        $cache[$categoryid] = $metadata;
        return $metadata;
    }

    /**
     * Count visible modules by module type for a course.
     *
     * @param \course_modinfo $modinfo
     * @return array
     */
    private static function course_module_counts(\course_modinfo $modinfo): array {
        $counts = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $modname = (string)$cm->modname;
            $counts[$modname] = (int)($counts[$modname] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Normalize module count map for API output.
     *
     * @param array $counts
     * @return array
     */
    private static function course_module_stats(array $counts): array {
        $stats = [];
        foreach ($counts as $modname => $count) {
            $stats[] = [
                'modname' => (string)$modname,
                'count' => (int)$count,
            ];
        }

        usort($stats, static function(array $a, array $b): int {
            return [$b['count'], $a['modname']] <=> [$a['count'], $b['modname']];
        });

        return $stats;
    }

    /**
     * Check whether a text contains any of the given phrases.
     *
     * @param string $text
     * @param array $phrases
     * @return bool
     */
    private static function text_contains_any(string $text, array $phrases): bool {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            $phrase = trim((string)$phrase);
            if ($phrase === '') {
                continue;
            }
            if (function_exists('mb_stripos')) {
                if (mb_stripos($text, $phrase) !== false) {
                    return true;
                }
            } else if (stripos($text, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build an agent-friendly semantic profile for a course.
     *
     * @param \stdClass $course
     * @param \course_modinfo|null $modinfo
     * @param bool $includemodulestats
     * @return array
     */
    private static function course_semantic_payload(
        \stdClass $course,
        ?\course_modinfo $modinfo = null,
        bool $includemodulestats = false
    ): array {
        $modinfo = $modinfo ?? get_fast_modinfo($course);
        $counts = self::course_module_counts($modinfo);
        $category = self::course_category_metadata((int)($course->category ?? 0));

        $quizcount = (int)($counts['quiz'] ?? 0);
        $offlinequizcount = (int)($counts['offlinequiz'] ?? 0);
        $qbankcount = (int)($counts['qbank'] ?? 0);
        $pagecount = (int)($counts['page'] ?? 0);
        $resourcecount = (int)($counts['resource'] ?? 0);
        $urlcount = (int)($counts['url'] ?? 0);

        $practicecount = $quizcount + $offlinequizcount + $qbankcount;
        $watchcount = $pagecount + $resourcecount + $urlcount;

        $categorytext = trim((string)$category['name'] . ' ' . (string)$category['displaypath']);
        $coursetext = trim((string)($course->fullname ?? '') . ' ' . (string)($course->shortname ?? ''));
        $alltext = trim($categorytext . ' ' . $coursetext);

        $type = 'general_course';
        $learningmode = 'mixed';
        $strategy = 'generic_navigation';
        $confidence = 'low';
        $reasons = [];

        if (self::text_contains_any($categorytext, ['试听课程']) || self::text_contains_any($alltext, ['试听', '试学', '体验课'])) {
            $type = 'trial_course';
            $learningmode = 'watch';
            $strategy = 'trial_preview';
            $confidence = 'high';
            $reasons[] = 'Category or course title marks this as a trial/listening course.';
        } else if (
            self::text_contains_any($categorytext, ['提分训练营']) ||
            (self::text_contains_any($alltext, ['题库', '训练营', '刷题']) && $practicecount >= max(2, $watchcount))
        ) {
            $type = 'question_bank';
            $learningmode = 'practice';
            $strategy = 'practice_first';
            $confidence = 'high';
            $reasons[] = 'Category or course title indicates a question-bank / training-camp flow.';
        } else if (
            self::text_contains_any($categorytext, ['视频课', '最新视频课']) ||
            ($pagecount >= 3 && $watchcount >= max(4, $practicecount * 2))
        ) {
            $type = 'video_course';
            $learningmode = 'watch';
            $strategy = 'watch_first';
            $confidence = self::text_contains_any($categorytext, ['视频课', '最新视频课']) ? 'high' : 'medium';
            $reasons[] = 'Category or visible module mix matches a video-course structure.';
        } else if ($practicecount >= 2 && $pagecount >= 2) {
            $type = 'mixed_course';
            $learningmode = 'mixed';
            $strategy = 'mixed_route';
            $confidence = 'medium';
            $reasons[] = 'Visible module mix contains both practice-heavy and watch-heavy content.';
        } else if ($practicecount >= 3) {
            $type = 'question_bank';
            $learningmode = 'practice';
            $strategy = 'practice_first';
            $confidence = 'medium';
            $reasons[] = 'Visible modules are dominated by quizzes/question-bank activities.';
        } else if ($pagecount >= 2 && $resourcecount >= 1) {
            $type = 'video_course';
            $learningmode = 'watch';
            $strategy = 'watch_first';
            $confidence = 'medium';
            $reasons[] = 'Visible modules are dominated by page/resource lesson content.';
        } else {
            $reasons[] = 'No strong category or module signal was found; keep a generic navigation strategy.';
        }

        if ($practicecount > 0 || $watchcount > 0) {
            $reasons[] = sprintf(
                'Visible module mix: quiz=%d, offlinequiz=%d, qbank=%d, page=%d, resource=%d, url=%d.',
                $quizcount,
                $offlinequizcount,
                $qbankcount,
                $pagecount,
                $resourcecount,
                $urlcount
            );
        }

        $payload = [
            'course_type' => $type,
            'learning_mode' => $learningmode,
            'agent_strategy' => $strategy,
            'confidence' => $confidence,
            'reasons' => array_values($reasons),
        ];

        if ($includemodulestats) {
            $payload['module_stats'] = self::course_module_stats($counts);
        }

        return $payload;
    }

    /**
     * Standard semantic structure for course payloads.
     *
     * @param bool $includemodulestats
     * @return external_single_structure
     */
    private static function course_semantic_structure(bool $includemodulestats = false): external_single_structure {
        $fields = [
            'course_type' => new external_value(PARAM_ALPHANUMEXT, 'Semantic course type'),
            'learning_mode' => new external_value(PARAM_ALPHANUMEXT, 'Primary learning mode'),
            'agent_strategy' => new external_value(PARAM_ALPHANUMEXT, 'Recommended agent strategy'),
            'confidence' => new external_value(PARAM_ALPHANUMEXT, 'Inference confidence'),
            'reasons' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Inference reason'),
                'Human-readable inference reasons'
            ),
        ];

        if ($includemodulestats) {
            $fields['module_stats'] = new external_multiple_structure(
                new external_single_structure([
                    'modname' => new external_value(PARAM_TEXT, 'Module type'),
                    'count' => new external_value(PARAM_INT, 'Visible module count'),
                ]),
                'Visible module counts'
            );
        }

        return new external_single_structure($fields);
    }

    /**
     * Shared course structure for agent-facing API responses.
     *
     * @param bool $includevisibilitydates
     * @param bool $includemodulestats
     * @return external_single_structure
     */
    private static function course_structure(
        bool $includevisibilitydates = false,
        bool $includemodulestats = false
    ): external_single_structure {
        $fields = [
            'id' => new external_value(PARAM_INT, 'Course id'),
            'shortname' => new external_value(PARAM_RAW, 'Short name'),
            'fullname' => new external_value(PARAM_RAW, 'Full name'),
            'categoryid' => new external_value(PARAM_INT, 'Category id'),
            'categoryname' => new external_value(PARAM_RAW, 'Category name'),
            'categorypath' => new external_value(PARAM_RAW, 'Category id path'),
            'categorydisplaypath' => new external_value(PARAM_RAW, 'Category display path'),
            'categorypathnames' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Category path segment'),
                'Category path names'
            ),
            'format' => new external_value(PARAM_TEXT, 'Course format'),
            'lang' => new external_value(PARAM_RAW, 'Course language'),
            'enablecompletion' => new external_value(PARAM_BOOL, 'Completion enabled'),
            'semantic' => self::course_semantic_structure($includemodulestats),
        ];

        if ($includevisibilitydates) {
            $fields['visible'] = new external_value(PARAM_BOOL, 'Visible');
            $fields['startdate'] = new external_value(PARAM_INT, 'Start date');
            $fields['enddate'] = new external_value(PARAM_INT, 'End date');
        }

        return new external_single_structure($fields);
    }

    /**
     * Return agent-friendly course metadata.
     *
     * @param \stdClass $course
     * @param \course_modinfo|null $modinfo
     * @param bool $includevisibilitydates
     * @param bool $includemodulestats
     * @return array
     */
    private static function course_payload(
        \stdClass $course,
        ?\course_modinfo $modinfo = null,
        bool $includevisibilitydates = false,
        bool $includemodulestats = false
    ): array {
        $category = self::course_category_metadata((int)($course->category ?? 0));

        $payload = [
            'id' => (int)($course->id ?? 0),
            'shortname' => (string)($course->shortname ?? ''),
            'fullname' => (string)($course->fullname ?? ''),
            'categoryid' => (int)($course->category ?? 0),
            'categoryname' => (string)$category['name'],
            'categorypath' => (string)$category['path'],
            'categorydisplaypath' => (string)$category['displaypath'],
            'categorypathnames' => array_values($category['pathnames']),
            'format' => (string)($course->format ?? ''),
            'lang' => (string)($course->lang ?? ''),
            'enablecompletion' => !empty($course->enablecompletion) ? 1 : 0,
            'semantic' => self::course_semantic_payload($course, $modinfo, $includemodulestats),
        ];

        if ($includevisibilitydates) {
            $payload['visible'] = !empty($course->visible) ? 1 : 0;
            $payload['startdate'] = (int)($course->startdate ?? 0);
            $payload['enddate'] = (int)($course->enddate ?? 0);
        }

        return $payload;
    }

    /**
     * Return an empty course payload that still matches the public schema.
     *
     * @param int $courseid
     * @param bool $includevisibilitydates
     * @param bool $includemodulestats
     * @return array
     */
    private static function empty_course_payload(
        int $courseid = 0,
        bool $includevisibilitydates = false,
        bool $includemodulestats = false
    ): array {
        $payload = [
            'id' => $courseid,
            'shortname' => '',
            'fullname' => '',
            'categoryid' => 0,
            'categoryname' => '',
            'categorypath' => '',
            'categorydisplaypath' => '',
            'categorypathnames' => [],
            'format' => '',
            'lang' => '',
            'enablecompletion' => 0,
            'semantic' => [
                'course_type' => 'general_course',
                'learning_mode' => 'mixed',
                'agent_strategy' => 'generic_navigation',
                'confidence' => 'low',
                'reasons' => [],
            ],
        ];

        if ($includemodulestats) {
            $payload['semantic']['module_stats'] = [];
        }

        if ($includevisibilitydates) {
            $payload['visible'] = 0;
            $payload['startdate'] = 0;
            $payload['enddate'] = 0;
        }

        return $payload;
    }

    /**
     * Return compact quiz attempt metadata.
     *
     * @param \stdClass $attempt
     * @return array
     */
    private static function quiz_attempt_payload(\stdClass $attempt): array {
        return [
            'id' => (int)$attempt->id,
            'quizid' => (int)$attempt->quiz,
            'userid' => (int)$attempt->userid,
            'attempt' => (int)$attempt->attempt,
            'state' => (string)$attempt->state,
            'currentpage' => (int)$attempt->currentpage,
            'preview' => !empty($attempt->preview) ? 1 : 0,
            'timestart' => (int)$attempt->timestart,
            'timefinish' => (int)$attempt->timefinish,
            'timemodified' => (int)$attempt->timemodified,
            'timecheckstate' => (int)$attempt->timecheckstate,
            'sumgrades' => isset($attempt->sumgrades) ? (string)$attempt->sumgrades : '',
        ];
    }

    /**
     * Normalize quiz question payload from core external response.
     *
     * @param array $question
     * @return array
     */
    private static function quiz_question_payload(array $question): array {
        return [
            'slot' => (int)($question['slot'] ?? 0),
            'type' => (string)($question['type'] ?? ''),
            'page' => (int)($question['page'] ?? 0),
            'questionnumber' => (string)($question['questionnumber'] ?? ''),
            'html' => (string)($question['html'] ?? ''),
            'sequencecheck' => (int)($question['sequencecheck'] ?? 0),
            'flagged' => !empty($question['flagged']) ? 1 : 0,
            'state' => (string)($question['state'] ?? ''),
            'status' => (string)($question['status'] ?? ''),
            'blockedbyprevious' => !empty($question['blockedbyprevious']) ? 1 : 0,
            'mark' => isset($question['mark']) ? (string)$question['mark'] : '',
            'maxmark' => isset($question['maxmark']) ? (float)$question['maxmark'] : 0.0,
            'settings' => (string)($question['settings'] ?? ''),
            'responseschema' => '',
            'responsesummary' => '',
        ];
    }

    /**
     * Common external description for normalized quiz questions.
     *
     * @return external_single_structure
     */
    private static function quiz_question_structure(): external_single_structure {
        return new external_single_structure([
            'slot' => new external_value(PARAM_INT, 'Slot'),
            'type' => new external_value(PARAM_RAW, 'Question type'),
            'page' => new external_value(PARAM_INT, 'Page'),
            'questionnumber' => new external_value(PARAM_RAW, 'Question number'),
            'html' => new external_value(PARAM_RAW, 'Rendered HTML'),
            'sequencecheck' => new external_value(PARAM_INT, 'Sequence check'),
            'flagged' => new external_value(PARAM_BOOL, 'Flagged'),
            'state' => new external_value(PARAM_RAW, 'Question state'),
            'status' => new external_value(PARAM_RAW, 'Question status'),
            'blockedbyprevious' => new external_value(PARAM_BOOL, 'Blocked by previous'),
            'mark' => new external_value(PARAM_RAW, 'Current mark'),
            'maxmark' => new external_value(PARAM_FLOAT, 'Max mark'),
            'settings' => new external_value(PARAM_RAW, 'Question settings JSON'),
            'responseschema' => new external_value(PARAM_RAW, 'Agent-friendly response schema JSON'),
            'responsesummary' => new external_value(PARAM_RAW, 'Current response summary text'),
        ]);
    }

    /**
     * Build an agent-friendly response schema for a question attempt.
     *
     * @param \question_attempt $qa
     * @return array
     */
    private static function quiz_question_response_schema(\question_attempt $qa): array {
        $question = $qa->get_question();
        $responsetype = '';
        if (isset($question->qtype) && is_string($question->qtype)) {
            $responsetype = $question->qtype;
        } else if (isset($question->qtype) && is_object($question->qtype) && method_exists($question->qtype, 'name')) {
            $responsetype = (string)$question->qtype->name();
        } else {
            $responsetype = (string)get_class($question);
        }

        if ($question instanceof \qtype_multichoice_single_question) {
            $choices = [];
            $response = $question->get_response($qa);
            foreach ($question->get_order($qa) as $choiceindex => $answerid) {
                $answer = $question->answers[$answerid];
                $choices[] = [
                    'choice_index' => (int)$choiceindex,
                    'answer_id' => (int)$answerid,
                    'text' => trim((string)html_to_text($answer->answer, 0)),
                    'selected' => $question->is_choice_selected($response, $choiceindex) ? 1 : 0,
                ];
            }
            return [
                'supported' => true,
                'response_type' => 'choice_single',
                'input_hint' => 'Use {"slot":<slot>,"choice_index":<index>}.',
                'field_prefix' => $qa->get_field_prefix(),
                'sequencecheck_field' => $qa->get_control_field_name('sequencecheck'),
                'flag_field' => $qa->get_control_field_name('flagged'),
                'answer_field' => $qa->get_qt_field_name('answer'),
                'choices' => $choices,
            ];
        }

        if ($question instanceof \qtype_multichoice_multi_question) {
            $choices = [];
            $choicefields = [];
            $response = $question->get_response($qa);
            foreach ($question->get_order($qa) as $choiceindex => $answerid) {
                $answer = $question->answers[$answerid];
                $choices[] = [
                    'choice_index' => (int)$choiceindex,
                    'answer_id' => (int)$answerid,
                    'text' => trim((string)html_to_text($answer->answer, 0)),
                    'selected' => $question->is_choice_selected($response, $choiceindex) ? 1 : 0,
                ];
                $choicefields[(int)$choiceindex] = $qa->get_qt_field_name('choice' . $choiceindex);
            }
            return [
                'supported' => true,
                'response_type' => 'choice_multi',
                'input_hint' => 'Use {"slot":<slot>,"choice_indexes":[<index>, ...]} or send choice_indexes_csv at the WS layer.',
                'field_prefix' => $qa->get_field_prefix(),
                'sequencecheck_field' => $qa->get_control_field_name('sequencecheck'),
                'flag_field' => $qa->get_control_field_name('flagged'),
                'choice_fields' => $choicefields,
                'choices' => $choices,
            ];
        }

        return [
            'supported' => false,
            'response_type' => $responsetype,
            'input_hint' => 'Use the low-level response-json fields for this question type.',
            'choices' => [],
        ];
    }

    /**
     * Normalize quiz question payloads and enrich them with response schema.
     *
     * @param array $questions
     * @param \mod_quiz\quiz_attempt|null $attemptobj
     * @return array
     */
    private static function quiz_question_payloads(array $questions, ?\mod_quiz\quiz_attempt $attemptobj = null): array {
        $payloads = [];
        foreach ($questions as $question) {
            $payload = self::quiz_question_payload($question);
            if ($attemptobj && !empty($payload['slot'])) {
                $qa = $attemptobj->get_question_attempt((int)$payload['slot']);
                $payload['state'] = (string)$qa->get_state();
                $payload['status'] = (string)$qa->get_state_string(false);
                $payload['responseschema'] = json_encode(
                    self::quiz_question_response_schema($qa),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) ?: '';
                $payload['responsesummary'] = (string)($qa->get_response_summary() ?? '');
            }
            $payloads[] = $payload;
        }
        return $payloads;
    }

    /**
     * Build raw Moodle response pairs from structured agent answers.
     *
     * @param \question_attempt $qa
     * @param array $answer
     * @return array
     */
    private static function quiz_structured_answer_to_pairs(\question_attempt $qa, array $answer): array {
        $question = $qa->get_question();
        $pairs = [
            ['name' => $qa->get_control_field_name('sequencecheck'), 'value' => (string)$qa->get_sequence_check_count()],
        ];

        if (array_key_exists('flagged', $answer)) {
            $pairs[] = [
                'name' => $qa->get_control_field_name('flagged'),
                'value' => !empty($answer['flagged']) ? '1' : '0',
            ];
        }

        if ($question instanceof \qtype_multichoice_single_question) {
            if (!array_key_exists('choice_index', $answer)) {
                throw new \moodle_exception('choice_index is required for single-choice questions.');
            }
            $pairs[] = [
                'name' => $qa->get_qt_field_name('answer'),
                'value' => (string)((int)$answer['choice_index']),
            ];
            return $pairs;
        }

        if ($question instanceof \qtype_multichoice_multi_question) {
            $selected = [];
            $csv = trim((string)($answer['choice_indexes_csv'] ?? ''));
            $choiceindexes = $csv === '' ? [] : array_map('intval', explode(',', $csv));
            foreach ($choiceindexes as $choiceindex) {
                $selected[(int)$choiceindex] = true;
            }
            foreach ($question->get_order($qa) as $choiceindex => $unusedanswerid) {
                $pairs[] = [
                    'name' => $qa->get_qt_field_name('choice' . $choiceindex),
                    'value' => isset($selected[(int)$choiceindex]) ? '1' : '0',
                ];
            }
            return $pairs;
        }

        $qtypename = isset($question->qtype) && is_object($question->qtype) && method_exists($question->qtype, 'name')
            ? (string)$question->qtype->name()
            : (is_string($question->qtype ?? null) ? $question->qtype : (string)get_class($question));
        throw new \moodle_exception('Unsupported question type for structured answering: ' . $qtypename);
    }

    /**
     * Get enrolled course records for the current user, optionally filtered.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    private static function enrolled_courses_for_user(int $userid, int $courseid = 0): array {
        global $DB;

        $courses = enrol_get_users_courses($userid, true, 'id,shortname,fullname,enablecompletion');
        if (!empty($courseid)) {
            if (!isset($courses[$courseid])) {
                throw new \moodle_exception('Course not enrolled or not visible to current user.');
            }
            return [$courses[$courseid]];
        }

        usort($courses, static function(\stdClass $a, \stdClass $b): int {
            return [$a->fullname, $a->id] <=> [$b->fullname, $b->id];
        });

        return array_values($courses);
    }

    /**
     * Fetch the backing record for a course module instance.
     *
     * @param \cm_info $cm
     * @return \stdClass|null
     */
    private static function module_record(\cm_info $cm): ?\stdClass {
        global $DB;

        if (!$DB->get_manager()->table_exists($cm->modname)) {
            return null;
        }

        return $DB->get_record($cm->modname, ['id' => $cm->instance]) ?: null;
    }

    /**
     * Render activity summary HTML.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $record
     * @param \context $context
     * @return string
     */
    private static function module_summary_html(\cm_info $cm, ?\stdClass $record, \context $context): string {
        if (!$record) {
            return '';
        }

        if (property_exists($record, 'intro') && $record->intro !== null && $record->intro !== '') {
            $format = property_exists($record, 'introformat') ? (int)$record->introformat : FORMAT_HTML;
            return format_text(
                (string)$record->intro,
                $format,
                [
                    'context' => $context,
                    'noclean' => true,
                    'para' => false,
                ]
            );
        }

        return '';
    }

    /**
     * Render activity content HTML for content-style modules.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $record
     * @param \context $context
     * @return string
     */
    private static function module_content_html(\cm_info $cm, ?\stdClass $record, \context $context): string {
        if (!$record) {
            return '';
        }

        if ($cm->modname === 'page' && property_exists($record, 'content') && $record->content !== null) {
            $format = property_exists($record, 'contentformat') ? (int)$record->contentformat : FORMAT_HTML;
            return format_text(
                (string)$record->content,
                $format,
                [
                    'context' => $context,
                    'noclean' => true,
                    'para' => false,
                ]
            );
        }

        return '';
    }

    /**
     * Extract external URL style target if available.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $record
     * @return string
     */
    private static function module_external_url(\cm_info $cm, ?\stdClass $record): string {
        if ($record && property_exists($record, 'externalurl') && !empty($record->externalurl)) {
            return (string)$record->externalurl;
        }
        if ($cm->modname === 'url' && $record && property_exists($record, 'externalurl')) {
            return (string)$record->externalurl;
        }
        return '';
    }

    /**
     * Return due/open style timestamps for a module.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $record
     * @return array
     */
    private static function module_due_entries(\cm_info $cm, ?\stdClass $record): array {
        $entries = [];
        if (!$record) {
            return $entries;
        }

        $mappings = [];
        switch ($cm->modname) {
            case 'assign':
                $mappings = [
                    'allowsubmissionsfromdate' => 'open',
                    'duedate' => 'due',
                    'cutoffdate' => 'cutoff',
                    'gradingduedate' => 'gradingdue',
                ];
                break;
            case 'quiz':
                $mappings = [
                    'timeopen' => 'open',
                    'timeclose' => 'close',
                ];
                break;
            case 'forum':
                $mappings = [
                    'duedate' => 'due',
                    'cutoffdate' => 'cutoff',
                ];
                break;
            case 'workshop':
                $mappings = [
                    'submissionstart' => 'submissionopen',
                    'submissionend' => 'submissiondue',
                    'assessmentstart' => 'assessmentopen',
                    'assessmentend' => 'assessmentdue',
                ];
                break;
            case 'lesson':
                $mappings = [
                    'deadline' => 'due',
                ];
                break;
            case 'choice':
            case 'feedback':
                $mappings = [
                    'timeopen' => 'open',
                    'timeclose' => 'close',
                ];
                break;
            case 'data':
                $mappings = [
                    'timeavailablefrom' => 'open',
                    'timeavailableto' => 'close',
                ];
                break;
        }

        foreach ($mappings as $field => $duetype) {
            if (property_exists($record, $field) && !empty($record->{$field})) {
                $entries[] = [
                    'duetype' => $duetype,
                    'duetime' => (int)$record->{$field},
                ];
            }
        }

        if (!empty($cm->completionexpected)) {
            $entries[] = [
                'duetype' => 'completionexpected',
                'duetime' => (int)$cm->completionexpected,
            ];
        }

        usort($entries, static function(array $a, array $b): int {
            return [$a['duetime'], $a['duetype']] <=> [$b['duetime'], $b['duetype']];
        });

        return $entries;
    }

    /**
     * Pick primary open/due timestamps for module detail views.
     *
     * @param \cm_info $cm
     * @param \stdClass|null $record
     * @return array
     */
    private static function module_primary_times(\cm_info $cm, ?\stdClass $record): array {
        $openfrom = 0;
        $dueto = 0;
        foreach (self::module_due_entries($cm, $record) as $entry) {
            if ($openfrom === 0 && in_array($entry['duetype'], ['open', 'submissionopen', 'assessmentopen'], true)) {
                $openfrom = (int)$entry['duetime'];
            }
            if ($dueto === 0 && in_array($entry['duetype'], ['due', 'cutoff', 'close', 'submissiondue', 'assessmentdue', 'completionexpected'], true)) {
                $dueto = (int)$entry['duetime'];
            }
        }
        return [
            'openfrom' => $openfrom,
            'dueto' => $dueto,
        ];
    }

    /**
     * Hidden marker for AI-managed calendar plan events.
     *
     * @param string $plankey
     * @param string $itemkey
     * @return string
     */
    private static function calendar_plan_marker(string $plankey, string $itemkey): string {
        return '<!-- local_aiagentapi:plan=' . $plankey . ';item=' . $itemkey . ' -->';
    }

    /**
     * Remove hidden AI plan marker from description.
     *
     * @param string|null $description
     * @return string
     */
    private static function calendar_strip_plan_marker(?string $description): string {
        $description = (string)($description ?? '');
        $description = preg_replace('/\s*<!-- local_aiagentapi:plan=[^>]+ -->\s*/', '', $description);
        return trim((string)$description);
    }

    /**
     * Stable request hash for idempotency checks.
     *
     * @param array $params
     * @return string
     */
    private static function request_hash(array $params): string {
        return hash('sha256', json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Resolve a prior idempotent response, or return an error envelope on key reuse.
     *
     * @param int $userid
     * @param string $action
     * @param string $idempotencykey
     * @param string $requesthash
     * @param bool $dryrun
     * @param array $params
     * @return array|null
     */
    private static function idempotency_replay_or_error(
        int $userid,
        string $action,
        string $idempotencykey,
        string $requesthash,
        bool $dryrun,
        array $params
    ): ?array {
        global $DB;

        $existing = $DB->get_record('local_aiagentapi_idemp', [
            'userid' => $userid,
            'action' => $action,
            'idempotencykey' => $idempotencykey,
        ]);
        if (!$existing) {
            return null;
        }

        if (!empty($existing->requesthash) && $existing->requesthash !== $requesthash) {
            $auditid = self::uuid_v4();
            $response = self::response_error(
                $auditid,
                'idempotency_key_reuse',
                'Idempotency key already used with different parameters.',
                $dryrun
            );
            self::audit($userid, $action, false, $auditid, $params, $response);
            return $response;
        }

        $decoded = json_decode((string)$existing->responsejson, true);
        if (!is_array($decoded)) {
            $decoded = self::response_error(
                self::uuid_v4(),
                'bad_stored_response',
                'Invalid stored response.',
                $dryrun
            );
        }
        $decoded['replayed'] = true;
        return $decoded;
    }

    /**
     * Persist a successful idempotent write response.
     *
     * @param int $userid
     * @param string $action
     * @param string $idempotencykey
     * @param string $requesthash
     * @param array $response
     * @return void
     */
    private static function store_idempotent_response(
        int $userid,
        string $action,
        string $idempotencykey,
        string $requesthash,
        array $response
    ): void {
        global $DB;

        $DB->insert_record('local_aiagentapi_idemp', [
            'userid' => $userid,
            'action' => $action,
            'idempotencykey' => $idempotencykey,
            'requesthash' => $requesthash,
            'responsejson' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Standard response envelope schema.
     *
     * @param \core_external\external_description $data
     * @return \core_external\external_description
     */
    private static function envelope_returns(\core_external\external_description $data): \core_external\external_description {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Success'),
            'audit_id' => new external_value(PARAM_TEXT, 'Audit id'),
            'replayed' => new external_value(PARAM_BOOL, 'True if idempotency replay'),
            'dry_run' => new external_value(PARAM_BOOL, 'True if dry-run'),
            'data' => $data,
            'error' => new external_single_structure([
                'code' => new external_value(PARAM_ALPHANUMEXT, 'Error code'),
                'message' => new external_value(PARAM_TEXT, 'Error message'),
            ], 'Error details', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Build a success envelope.
     *
     * @param string $auditid
     * @param array $data
     * @param bool $dryrun
     * @param bool $replayed
     * @return array
     */
    private static function response_ok(string $auditid, array $data, bool $dryrun = false, bool $replayed = false): array {
        return [
            'ok' => true,
            'audit_id' => $auditid,
            'replayed' => $replayed,
            'dry_run' => $dryrun,
            'data' => $data,
        ];
    }

    /**
     * Build an error envelope.
     *
     * @param string $auditid
     * @param string $code
     * @param string $message
     * @param bool $dryrun
     * @param bool $replayed
     * @param array $data
     * @return array
     */
    private static function response_error(
        string $auditid,
        string $code,
        string $message,
        bool $dryrun = false,
        bool $replayed = false,
        array $data = []
    ): array {
        return [
            'ok' => false,
            'audit_id' => $auditid,
            'replayed' => $replayed,
            'dry_run' => $dryrun,
            'data' => $data,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * Persist an audit record. Errors are ignored to avoid masking the main response.
     *
     * @param int $userid
     * @param string $action
     * @param bool $ok
     * @param string $auditid
     * @param array $params
     * @param array $response
     * @return void
     */
    private static function audit(int $userid, string $action, bool $ok, string $auditid, array $params, array $response): void {
        global $DB;
        try {
            $DB->insert_record('local_aiagentapi_audit', [
                'userid' => $userid,
                'action' => $action,
                'ok' => $ok ? 1 : 0,
                'auditid' => $auditid,
                'requestjson' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'responsejson' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Ignore audit failures.
        }
    }

    /**
     * Convert a zero-based index to a label (A, B, ..., Z, AA, AB, ...).
     *
     * @param int $index
     * @return string
     */
    private static function answer_label(int $index): string {
        $label = '';
        $n = $index;
        do {
            $label = chr(ord('A') + ($n % 26)) . $label;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return $label;
    }

    /**
     * Replace common blank placeholders with a styled underline.
     *
     * @param string $html
     * @return string
     */
    private static function replace_blank_placeholders(string $html): string {
        $spacepattern = '(?:\\s|&nbsp;|&ensp;|&emsp;|&thinsp;|&zwnj;|&zwj;|&rlm;|&lrm;|&#160;|&#xA0;|&#x200B;|&#x200C;|&#x200D;|&#xFEFF;|&#x3000;)+';
        $blankpattern = '(?:' . $spacepattern . '|[＿_\\.·．•])+';
        $html = preg_replace('/[\\(\\x{FF08}]' . $blankpattern . '?[\\)\\x{FF09}]/iu', '<span class="cloze-blank"></span>', $html);
        return $html;
    }

    /**
     * Render question text to HTML.
     *
     * @param \stdClass $question
     * @param \context $questioncontext
     * @return string
     */
    private static function render_question_text(\stdClass $question, \context $questioncontext): string {
        $questiontext = question_rewrite_question_preview_urls(
            $question->questiontext ?? '',
            $question->id,
            $question->contextid,
            'question',
            'questiontext',
            $question->id,
            $questioncontext->id,
            'core_question'
        );

        if (!empty($question->qtype) && $question->qtype === 'multianswer') {
            $questiontext = preg_replace('/\{[^}]+\}/', '<span class="cloze-blank">______</span>', $questiontext);
        }

        $questiontext = format_text(
            $questiontext,
            $question->questiontextformat ?? FORMAT_HTML,
            [
                'context' => $questioncontext,
                'noclean' => true,
                'para' => false,
            ]
        );

        return self::replace_blank_placeholders($questiontext);
    }

    /**
     * Render answer text to HTML.
     *
     * @param \stdClass $question
     * @param \stdClass $answerrecord
     * @param \context $questioncontext
     * @return string
     */
    private static function render_answer_text(\stdClass $question, \stdClass $answerrecord, \context $questioncontext): string {
        $answertext = $answerrecord->answer ?? '';
        $answertext = question_rewrite_question_preview_urls(
            $answertext,
            $question->id,
            $question->contextid,
            'question',
            'answer',
            $answerrecord->id ?? $question->id,
            $questioncontext->id,
            'core_question'
        );

        $answertext = format_text(
            $answertext,
            $answerrecord->answerformat ?? FORMAT_HTML,
            [
                'context' => $questioncontext,
                'noclean' => true,
                'para' => false,
            ]
        );

        return self::replace_blank_placeholders($answertext);
    }

    /**
     * Static API catalog for agent bootstrapping.
     *
     * @return array
     */
    private static function api_catalog(): array {
        return [
            [
                'name' => 'local_aiagentapi_get_user_context',
                'description' => 'Return a minimal user + context payload for agent bootstrapping.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [],
                'data_fields' => [
                    'user.userid',
                    'user.username',
                    'user.fullname',
                    'user.lang',
                    'user.timezone',
                    'context.contextid',
                    'context.contextlevel',
                    'context.instanceid',
                ],
                'notes' => 'Uses the site course context to satisfy restricted WS token context.',
            ],
            [
                'name' => 'local_aiagentapi_courses_list_my',
                'description' => 'List current user courses with category and semantic metadata for agent routing.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [],
                'data_fields' => [
                    'courses[].categoryname',
                    'courses[].semantic.course_type',
                    'courses[].semantic.learning_mode',
                    'courses[].semantic.agent_strategy',
                ],
                'notes' => 'Returns active enrolled courses plus agent-friendly semantics inferred from category and visible module mix.',
            ],
            [
                'name' => 'local_aiagentapi_course_get_outline',
                'description' => 'Return course sections and visible modules.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Course id.',
                    ],
                ],
                'data_fields' => [
                    'course.semantic.course_type',
                    'sections[]',
                ],
                'notes' => 'Only user-visible modules are returned, and the course header includes semantic classification for agent planning.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_list_by_course',
                'description' => 'List visible quizzes in a course.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Course id.',
                    ],
                ],
                'data_fields' => [
                    'course',
                    'quizzes[]',
                ],
                'notes' => 'Uses visible quiz course modules from modinfo.',
            ],
            [
                'name' => 'local_aiagentapi_activities_list_by_course',
                'description' => 'List visible activities in a course.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Course id.',
                    ],
                    [
                        'name' => 'modname',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional activity module type filter.',
                    ],
                ],
                'data_fields' => [
                    'course',
                    'activities[]',
                ],
                'notes' => 'Returns user-visible course modules; modname can narrow the result set.',
            ],
            [
                'name' => 'local_aiagentapi_assignments_list_by_course',
                'description' => 'List visible assignments in a course.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Course id.',
                    ],
                ],
                'data_fields' => [
                    'course',
                    'assignments[]',
                ],
                'notes' => 'Includes due-date style metadata from mod_assign when available.',
            ],
            [
                'name' => 'local_aiagentapi_calendar_list',
                'description' => 'List calendar events for current user and enrolled courses.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'timestart',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Range start unix timestamp.',
                    ],
                    [
                        'name' => 'timeend',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Range end unix timestamp.',
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Maximum events to return.',
                    ],
                ],
                'data_fields' => [
                    'events[]',
                ],
                'notes' => 'Includes current user events plus enrolled course events in the selected time window.',
            ],
            [
                'name' => 'local_aiagentapi_activities_due_list',
                'description' => 'List upcoming due/open/completion-expected activity timestamps for the current user.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id filter.',
                    ],
                    [
                        'name' => 'timestart',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional lower timestamp bound.',
                    ],
                    [
                        'name' => 'timeend',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional upper timestamp bound.',
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Maximum rows to return.',
                    ],
                ],
                'data_fields' => [
                    'items[]',
                ],
                'notes' => 'Normalises due-like timestamps from assign/quiz/forum/workshop and completion expected dates.',
            ],
            [
                'name' => 'local_aiagentapi_assignments_my_status',
                'description' => 'List the current user assignment submission status across one course or all enrolled courses.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id filter.',
                    ],
                    [
                        'name' => 'assignid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional assignment id filter.',
                    ],
                ],
                'data_fields' => [
                    'assignments[]',
                ],
                'notes' => 'Returns a simplified student-oriented status view: submission state, due window, overdue, and grade.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_attempts_my',
                'description' => 'List the current user quiz attempts across one course or one quiz.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id filter.',
                    ],
                    [
                        'name' => 'quizid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional quiz id filter.',
                    ],
                ],
                'data_fields' => [
                    'quizzes[]',
                ],
                'notes' => 'Includes attempts made, attempts left, best grade, and attempt state timeline.',
            ],
            [
                'name' => 'local_aiagentapi_grades_overview_my',
                'description' => 'Return current user grade overview by course.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id filter.',
                    ],
                ],
                'data_fields' => [
                    'courses[]',
                ],
                'notes' => 'Includes course total and activity/module grade items for the current user.',
            ],
            [
                'name' => 'local_aiagentapi_course_progress_my',
                'description' => 'Return current user activity completion and course progress by course.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id filter.',
                    ],
                ],
                'data_fields' => [
                    'courses[]',
                ],
                'notes' => 'Wraps Moodle completion APIs into a compact student progress summary.',
            ],
            [
                'name' => 'local_aiagentapi_course_activity_detail',
                'description' => 'Return a normalized detail view for a visible course activity.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'cmid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Course module id.',
                    ],
                ],
                'data_fields' => [
                    'course',
                    'activity',
                ],
                'notes' => 'Normalizes intro/content/url/due metadata for student-facing activity lookup.',
            ],
            [
                'name' => 'local_aiagentapi_resources_list_by_course',
                'description' => 'List resource-style learning materials in a course.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:view',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Course id.',
                    ],
                ],
                'data_fields' => [
                    'course',
                    'resources[]',
                ],
                'notes' => 'Includes page/resource/url/book/folder/label modules.',
            ],
            [
                'name' => 'local_aiagentapi_forum_discussions_list',
                'description' => 'List recent forum discussions visible to the current user.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id filter.',
                    ],
                    [
                        'name' => 'forumid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional single forum id filter.',
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Maximum flattened discussions to return.',
                    ],
                ],
                'data_fields' => [
                    'discussions[]',
                ],
                'notes' => 'Wraps core forum external APIs and flattens discussions for agent use.',
            ],
            [
                'name' => 'local_aiagentapi_notifications_list_my',
                'description' => 'List notifications/messages addressed to the current user.',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'limitfrom',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Offset for pagination.',
                    ],
                    [
                        'name' => 'limitnum',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Maximum notifications to return.',
                    ],
                    [
                        'name' => 'unreadonly',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'If true, return unread notifications only.',
                    ],
                ],
                'data_fields' => [
                    'notifications[]',
                ],
                'notes' => 'Uses Moodle message storage directly to avoid UI-specific noise.',
            ],
            [
                'name' => 'local_aiagentapi_question_categories_list',
                'description' => 'List question categories for a course or context.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/question:useall',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id.',
                    ],
                    [
                        'name' => 'contextid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional context id.',
                    ],
                    [
                        'name' => 'parentid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional parent category id filter.',
                    ],
                ],
                'data_fields' => [
                    'categories[]',
                ],
                'notes' => 'Returns categories in the selected context ordered by parent and sort order.',
            ],
            [
                'name' => 'local_aiagentapi_questionbank_search',
                'description' => 'Search latest ready questions by text, category, or course.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/question:useall',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'query',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional search text.',
                    ],
                    [
                        'name' => 'courseid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional course id.',
                    ],
                    [
                        'name' => 'categoryid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional category id.',
                    ],
                    [
                        'name' => 'recurse',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Include subcategories when categoryid is set.',
                    ],
                    [
                        'name' => 'qtypes',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Question types to include.',
                    ],
                    [
                        'name' => 'limit',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Maximum rows to return.',
                    ],
                ],
                'data_fields' => [
                    'questions[]',
                ],
                'notes' => 'Searches latest ready versions only.',
            ],
            [
                'name' => 'local_aiagentapi_questionbank_pick_random',
                'description' => 'Pick random questions from a category.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/question:useall',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'categoryid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Question category id.',
                    ],
                    [
                        'name' => 'count',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Number of questions to pick.',
                    ],
                    [
                        'name' => 'recurse',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Include subcategories.',
                    ],
                    [
                        'name' => 'seed',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional seed for deterministic ordering.',
                    ],
                    [
                        'name' => 'qtypes',
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Question types to include.',
                    ],
                ],
                'data_fields' => [
                    'available',
                    'picked[]',
                ],
                'notes' => 'Defaults to multichoice/multichoiceset.',
            ],
            [
                'name' => 'local_aiagentapi_questions_render_html',
                'description' => 'Render questions to HTML + plain text.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/question:useall',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'question_ids',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'Question ids in desired order.',
                    ],
                    [
                        'name' => 'shuffle_answers',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Shuffle answer order per question.',
                    ],
                    [
                        'name' => 'seed',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional seed for deterministic shuffling.',
                    ],
                    [
                        'name' => 'show_correction',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Include correct-answer markers in HTML.',
                    ],
                ],
                'data_fields' => [
                    'questions[]',
                ],
                'notes' => 'Supports multichoice/multichoiceset/truefalse answers.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_resolve_random',
                'description' => 'Resolve random questions in a quiz (creates temporary attempts).',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:preview',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    [
                        'name' => 'quizid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Quiz id.',
                    ],
                    [
                        'name' => 'copies',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Number of copies (attempts).',
                    ],
                    [
                        'name' => 'seed',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional seed for deterministic attempts.',
                    ],
                    [
                        'name' => 'allowduplicates',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Placeholder; currently not enforced.',
                    ],
                ],
                'data_fields' => [
                    'copies[]',
                ],
                'notes' => 'Deletes temporary attempts after resolution.',
            ],
            [
                'name' => 'local_aiagentapi_practice_quiz_create_from_resource',
                'description' => 'Create a post-lesson practice quiz from existing mapped question-bank questions.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'moodle/course:manageactivities',
                    'mod/quiz:addinstance',
                    'moodle/question:useall',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    ['name' => 'idempotency_key', 'type' => 'string', 'required' => true, 'description' => 'Client-provided idempotency key.'],
                    ['name' => 'courseid', 'type' => 'int', 'required' => true, 'description' => 'Target Moodle course id.'],
                    ['name' => 'cmid', 'type' => 'int', 'required' => false, 'description' => 'Optional lesson/resource cmid used to read resource_map facts.'],
                    ['name' => 'lesson_key', 'type' => 'string', 'required' => false, 'description' => 'Optional lesson key used to read resource_map facts.'],
                    ['name' => 'title', 'type' => 'string', 'required' => false, 'description' => 'Optional quiz title.'],
                    ['name' => 'count', 'type' => 'int', 'required' => false, 'description' => 'Number of questions to add, max 120.'],
                    ['name' => 'section', 'type' => 'int', 'required' => false, 'description' => 'Course section number, 0 means infer/default.'],
                    ['name' => 'categoryid', 'type' => 'int', 'required' => false, 'description' => 'Optional question category id.'],
                    ['name' => 'categoryids', 'type' => 'array', 'required' => false, 'description' => 'Optional extra question category ids for mixed-category quizzes.'],
                    ['name' => 'kg_ids', 'type' => 'array', 'required' => false, 'description' => 'Additional KG ids.'],
                    ['name' => 'qg_ids', 'type' => 'array', 'required' => false, 'description' => 'Additional QG ids.'],
                    ['name' => 'tags', 'type' => 'array', 'required' => false, 'description' => 'Teaching tag filters. Explicit tags are matched against Moodle question tags and title/text.'],
                    ['name' => 'seed', 'type' => 'int', 'required' => false, 'description' => 'Optional random seed.'],
                    ['name' => 'allow_partial', 'type' => 'bool', 'required' => false, 'description' => 'Create with fewer than count questions when necessary.'],
                    ['name' => 'selection_mode', 'type' => 'string', 'required' => false, 'description' => 'fixed or random_category.'],
                    ['name' => 'visible', 'type' => 'bool', 'required' => false, 'description' => 'Make the created quiz visible to students; default is hidden.'],
                    ['name' => 'dry_run', 'type' => 'bool', 'required' => false, 'description' => 'Validate and preview without writing.'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Optional audit reason.'],
                ],
                'data_fields' => ['quizid', 'quiz_cmid', 'url', 'selection_mode', 'visible', 'questions[]', 'resource_map', 'tags[]', 'tag_ids[]'],
                'notes' => 'Uses Moodle native course, quiz, and question capabilities. Explicit tags such as 第一章 + 1.3 are strict teaching filters; random_category mode preserves resolved Moodle tag ids in random slots.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_start_attempt',
                'description' => 'Start a new quiz attempt and return the first page payload.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:attempt',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    ['name' => 'idempotency_key', 'type' => 'string', 'required' => true, 'description' => 'Client-provided idempotency key.'],
                    ['name' => 'quizid', 'type' => 'int', 'required' => true, 'description' => 'Quiz id.'],
                    ['name' => 'forcenew', 'type' => 'bool', 'required' => false, 'description' => 'Force a fresh attempt when possible.'],
                    ['name' => 'preflightdata', 'type' => 'array', 'required' => false, 'description' => 'Optional preflight pairs like password.'],
                    ['name' => 'dry_run', 'type' => 'bool', 'required' => false, 'description' => 'Validate request without writing.'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Optional audit reason.'],
                ],
                'data_fields' => ['course', 'quiz', 'attempt', 'questions[]'],
                'notes' => 'Wraps core mod_quiz start_attempt and immediately returns page 0 attempt data.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_get_attempt_data',
                'description' => 'Return one page of quiz attempt data for an in-progress attempt.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:attempt',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    ['name' => 'attemptid', 'type' => 'int', 'required' => true, 'description' => 'Attempt id.'],
                    ['name' => 'page', 'type' => 'int', 'required' => true, 'description' => 'Page number.'],
                    ['name' => 'preflightdata', 'type' => 'array', 'required' => false, 'description' => 'Optional preflight pairs like password.'],
                ],
                'data_fields' => ['attempt', 'questions[]', 'messages[]'],
                'notes' => 'Returns rendered question HTML and metadata for the requested page.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_get_attempt_summary',
                'description' => 'Return a compact summary of a quiz attempt before submission.',
                'type' => 'read',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:attempt',
                ],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [
                    ['name' => 'attemptid', 'type' => 'int', 'required' => true, 'description' => 'Attempt id.'],
                    ['name' => 'preflightdata', 'type' => 'array', 'required' => false, 'description' => 'Optional preflight pairs like password.'],
                ],
                'data_fields' => ['questions[]', 'totalunanswered'],
                'notes' => 'Useful before final submission or for agent checkpointing.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_save_attempt',
                'description' => 'Autosave quiz attempt response data.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:attempt',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    ['name' => 'idempotency_key', 'type' => 'string', 'required' => true, 'description' => 'Client-provided idempotency key.'],
                    ['name' => 'attemptid', 'type' => 'int', 'required' => true, 'description' => 'Attempt id.'],
                    ['name' => 'responses', 'type' => 'array', 'required' => false, 'description' => 'Form field name/value pairs.'],
                    ['name' => 'preflightdata', 'type' => 'array', 'required' => false, 'description' => 'Optional preflight pairs like password.'],
                    ['name' => 'dry_run', 'type' => 'bool', 'required' => false, 'description' => 'Validate request without writing.'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Optional audit reason.'],
                ],
                'data_fields' => ['attempt', 'saved_response_count'],
                'notes' => 'Wraps core mod_quiz save_attempt. Response data remains low-level name/value pairs.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_submit_attempt',
                'description' => 'Submit a quiz attempt, optionally processing final response data first.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:attempt',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    ['name' => 'idempotency_key', 'type' => 'string', 'required' => true, 'description' => 'Client-provided idempotency key.'],
                    ['name' => 'attemptid', 'type' => 'int', 'required' => true, 'description' => 'Attempt id.'],
                    ['name' => 'responses', 'type' => 'array', 'required' => false, 'description' => 'Optional final form field name/value pairs.'],
                    ['name' => 'timeup', 'type' => 'bool', 'required' => false, 'description' => 'Whether submission is due to timer expiry.'],
                    ['name' => 'preflightdata', 'type' => 'array', 'required' => false, 'description' => 'Optional preflight pairs like password.'],
                    ['name' => 'dry_run', 'type' => 'bool', 'required' => false, 'description' => 'Validate request without writing.'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Optional audit reason.'],
                ],
                'data_fields' => ['attempt', 'state'],
                'notes' => 'Wraps core mod_quiz process_attempt with finishattempt=true.',
            ],
            [
                'name' => 'local_aiagentapi_quiz_answer_questions',
                'description' => 'Apply structured answers to supported quiz question types.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/quiz:attempt',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    ['name' => 'idempotency_key', 'type' => 'string', 'required' => true, 'description' => 'Client-provided idempotency key.'],
                    ['name' => 'attemptid', 'type' => 'int', 'required' => true, 'description' => 'Attempt id.'],
                    ['name' => 'answers', 'type' => 'array', 'required' => true, 'description' => 'Structured answers like {"slot":1,"choice_index":0}.'],
                    ['name' => 'submit', 'type' => 'bool', 'required' => false, 'description' => 'Submit attempt after applying answers.'],
                    ['name' => 'timeup', 'type' => 'bool', 'required' => false, 'description' => 'Whether submission is due to timer expiry.'],
                    ['name' => 'preflightdata', 'type' => 'array', 'required' => false, 'description' => 'Optional preflight pairs like password.'],
                    ['name' => 'dry_run', 'type' => 'bool', 'required' => false, 'description' => 'Validate request without writing.'],
                    ['name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Optional audit reason.'],
                ],
                'data_fields' => ['attempt', 'state', 'applied_answers[]', 'questions[]'],
                'notes' => 'Currently supports multichoice single-select and multi-select question types.',
            ],
            [
                'name' => 'local_aiagentapi_calendar_publish_plan',
                'description' => 'Publish plan items to the current user calendar with idempotency.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'local/aiagentapi:calendarwrite',
                    'moodle/calendar:manageownentries',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'If true, validate and return preview without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                    [
                        'name' => 'items',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'List of items: name, description, timestart, timeduration.',
                    ],
                ],
                'data_fields' => [
                    'created_events[]',
                    'preview_events[]',
                ],
                'notes' => 'Max 50 items per call. Dry-run does not persist idempotency record.',
            ],
            [
                'name' => 'local_aiagentapi_calendar_plan_upsert',
                'description' => 'Create/update/delete AI-managed user calendar plan events by stable item keys.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'local/aiagentapi:calendarwrite',
                    'moodle/calendar:manageownentries',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'plan_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Stable plan identifier grouping related events.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'If true, preview create/update/delete actions.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                    [
                        'name' => 'items',
                        'type' => 'array',
                        'required' => true,
                        'description' => 'List of keyed plan items with create/update/delete intent.',
                    ],
                ],
                'data_fields' => [
                    'created_events[]',
                    'updated_events[]',
                    'deleted_event_ids[]',
                    'preview_actions[]',
                ],
                'notes' => 'Uses a hidden description marker to track AI-managed events without adding extra DB schema.',
            ],
            [
                'name' => 'local_aiagentapi_assignment_save_draft',
                'description' => 'Save online-text assignment draft content for the current user.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/assign:submit',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'assignid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Assignment id.',
                    ],
                    [
                        'name' => 'text',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Online text draft body.',
                    ],
                    [
                        'name' => 'format',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Moodle format constant, defaults to HTML.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Validate request without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                ],
                'data_fields' => [
                    'assignment',
                    'submission',
                    'warnings[]',
                ],
                'notes' => 'Wraps core mod_assign save_submission with a narrower online-text contract.',
            ],
            [
                'name' => 'local_aiagentapi_assignment_submit_final',
                'description' => 'Submit the current user assignment for grading.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/assign:submit',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'assignid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Assignment id.',
                    ],
                    [
                        'name' => 'accept_submission_statement',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Accept submission statement when required.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Validate request without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                ],
                'data_fields' => [
                    'assignment',
                    'submission',
                    'warnings[]',
                ],
                'notes' => 'Wraps core mod_assign submit_for_grading for agent-safe final handoff.',
            ],
            [
                'name' => 'local_aiagentapi_forum_create_discussion',
                'description' => 'Create a new forum discussion for the current user.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/forum:startdiscussion',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'forumid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Forum id.',
                    ],
                    [
                        'name' => 'subject',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Discussion subject.',
                    ],
                    [
                        'name' => 'message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Discussion message HTML/plain text.',
                    ],
                    [
                        'name' => 'groupid',
                        'type' => 'int',
                        'required' => false,
                        'description' => 'Optional group id.',
                    ],
                    [
                        'name' => 'subscribe',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Subscribe to the discussion.',
                    ],
                    [
                        'name' => 'pinned',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Pin discussion when capability allows it.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Validate request without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                ],
                'data_fields' => [
                    'forum',
                    'discussion',
                    'warnings[]',
                ],
                'notes' => 'Wraps core mod_forum add_discussion and returns a compact discussion payload.',
            ],
            [
                'name' => 'local_aiagentapi_forum_reply_post',
                'description' => 'Reply to an existing forum post for the current user.',
                'type' => 'write',
                'capabilities' => [
                    'local/aiagentapi:use',
                    'mod/forum:replypost',
                ],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'postid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Parent post id.',
                    ],
                    [
                        'name' => 'subject',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Reply subject.',
                    ],
                    [
                        'name' => 'message',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Reply message HTML/plain text.',
                    ],
                    [
                        'name' => 'subscribe',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Subscribe to the discussion.',
                    ],
                    [
                        'name' => 'private_reply',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Make the reply private when supported.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Validate request without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                ],
                'data_fields' => [
                    'forum',
                    'reply',
                    'warnings[]',
                ],
                'notes' => 'Wraps core mod_forum add_discussion_post and returns a compact post payload.',
            ],
            [
                'name' => 'local_aiagentapi_forum_update_post',
                'description' => 'Update an editable forum discussion or reply post for the current user.',
                'type' => 'write',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'postid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Forum post id.',
                    ],
                    [
                        'name' => 'subject',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Updated subject when provided.',
                    ],
                    [
                        'name' => 'message',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Updated message when provided.',
                    ],
                    [
                        'name' => 'subscribe',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Subscribe/unsubscribe discussion.',
                    ],
                    [
                        'name' => 'pinned',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Pin discussion when capability allows.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Validate request without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                ],
                'data_fields' => [
                    'forum',
                    'post',
                    'warnings[]',
                ],
                'notes' => 'Wraps core mod_forum update_discussion_post and returns compact post metadata.',
            ],
            [
                'name' => 'local_aiagentapi_forum_delete_post',
                'description' => 'Delete an editable forum post or discussion for the current user.',
                'type' => 'write',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => true,
                'params' => [
                    [
                        'name' => 'idempotency_key',
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Client-provided idempotency key.',
                    ],
                    [
                        'name' => 'postid',
                        'type' => 'int',
                        'required' => true,
                        'description' => 'Forum post id to delete.',
                    ],
                    [
                        'name' => 'dry_run',
                        'type' => 'bool',
                        'required' => false,
                        'description' => 'Validate request without writing.',
                    ],
                    [
                        'name' => 'reason',
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Optional audit reason.',
                    ],
                ],
                'data_fields' => [
                    'forum',
                    'target',
                    'warnings[]',
                ],
                'notes' => 'Wraps core mod_forum delete_post. First-post deletion removes the whole discussion.',
            ],
            [
                'name' => 'local_aiagentapi_get_api_catalog',
                'description' => 'Return API catalog (endpoints and constraints).',
                'type' => 'read',
                'capabilities' => ['local/aiagentapi:use'],
                'idempotent' => true,
                'supports_dry_run' => false,
                'params' => [],
                'data_fields' => ['plugin', 'server_time', 'endpoints[]'],
                'notes' => 'Catalog is static and for agent bootstrapping.',
            ],
        ];
    }

    /**
     * Parameters for get_user_context.
     *
     * @return external_function_parameters
     */
    public static function get_user_context_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Minimal bootstrap info for agents.
     *
     * @return array
     */
    public static function get_user_context(): array {
        global $USER;

        $context = self::restricted_context();
        $auditid = self::uuid_v4();

        $response = self::response_ok($auditid, [
            'user' => [
                'userid' => $USER->id,
                'username' => $USER->username,
                'fullname' => fullname($USER),
                'lang' => current_language(),
                'timezone' => \core_date::get_user_timezone($USER),
            ],
            'context' => [
                'contextid' => $context->id,
                'contextlevel' => $context->contextlevel,
                'instanceid' => $context->instanceid,
            ],
        ]);

        self::audit($USER->id, 'get_user_context', true, $auditid, [], $response);

        return $response;
    }

    /**
     * Returns for get_user_context.
     *
     * @return \core_external\external_description
     */
    public static function get_user_context_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'user' => new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'username' => new external_value(PARAM_RAW, 'Username'),
                    'fullname' => new external_value(PARAM_RAW, 'Full name'),
                    'lang' => new external_value(PARAM_LANG, 'Current language'),
                    'timezone' => new external_value(PARAM_RAW, 'User timezone'),
                ]),
                'context' => new external_single_structure([
                    'contextid' => new external_value(PARAM_INT, 'Context id'),
                    'contextlevel' => new external_value(PARAM_INT, 'Context level'),
                    'instanceid' => new external_value(PARAM_INT, 'Instance id'),
                ]),
            ])
        );
    }

    /**
     * Parameters for get_api_catalog.
     *
     * @return external_function_parameters
     */
    public static function get_api_catalog_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Return API catalog and constraints for agent bootstrapping.
     *
     * @return array
     */
    public static function get_api_catalog(): array {
        global $USER;

        self::restricted_context();

        $auditid = self::uuid_v4();

        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_aiagentapi');
        $version = $plugininfo ? (int)$plugininfo->versiondisk : 0;
        $release = $plugininfo && !empty($plugininfo->release) ? (string)$plugininfo->release : '';

        $response = self::response_ok($auditid, [
            'plugin' => [
                'component' => 'local_aiagentapi',
                'version' => $version,
                'release' => $release,
            ],
            'server_time' => time(),
            'endpoints' => self::api_catalog(),
        ]);

        self::audit($USER->id, 'get_api_catalog', true, $auditid, [], $response);

        return $response;
    }

    /**
     * Returns for get_api_catalog.
     *
     * @return \core_external\external_description
     */
    public static function get_api_catalog_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'plugin' => new external_single_structure([
                    'component' => new external_value(PARAM_TEXT, 'Plugin component name'),
                    'version' => new external_value(PARAM_INT, 'Plugin version'),
                    'release' => new external_value(PARAM_TEXT, 'Plugin release string'),
                ]),
                'server_time' => new external_value(PARAM_INT, 'Server time (unix timestamp)'),
                'endpoints' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_TEXT, 'WS function name'),
                        'description' => new external_value(PARAM_TEXT, 'Description'),
                        'type' => new external_value(PARAM_TEXT, 'read/write'),
                        'capabilities' => new external_multiple_structure(
                            new external_value(PARAM_TEXT, 'Capability')
                        ),
                        'idempotent' => new external_value(PARAM_BOOL, 'Idempotent'),
                        'supports_dry_run' => new external_value(PARAM_BOOL, 'Supports dry_run'),
                        'params' => new external_multiple_structure(
                            new external_single_structure([
                                'name' => new external_value(PARAM_TEXT, 'Parameter name'),
                                'type' => new external_value(PARAM_TEXT, 'Parameter type'),
                                'required' => new external_value(PARAM_BOOL, 'Required'),
                                'description' => new external_value(PARAM_TEXT, 'Description'),
                            ])
                        ),
                        'data_fields' => new external_multiple_structure(
                            new external_value(PARAM_TEXT, 'Data fields')
                        ),
                        'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for courses_list_my.
     *
     * @return external_function_parameters
     */
    public static function courses_list_my_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * List current user courses.
     *
     * @return array
     */
    public static function courses_list_my(): array {
        global $USER;

        self::restricted_context();

        $auditid = self::uuid_v4();

        try {
            $courses = enrol_get_users_courses(
                $USER->id,
                true,
                'id,shortname,fullname,category,visible,startdate,enddate,format,lang,enablecompletion'
            );
            $payload = [];
            foreach ($courses as $course) {
                $payload[] = self::course_payload($course, null, true, true);
            }

            usort($payload, static function(array $a, array $b): int {
                return [$a['fullname'], $a['id']] <=> [$b['fullname'], $b['id']];
            });

            $response = self::response_ok($auditid, ['courses' => $payload]);
            self::audit($USER->id, 'courses_list_my', true, $auditid, [], $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'courses_list_failed', $e->getMessage());
            self::audit($USER->id, 'courses_list_my', false, $auditid, [], $response);
            return $response;
        }
    }

    /**
     * Returns for courses_list_my.
     *
     * @return \core_external\external_description
     */
    public static function courses_list_my_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'courses' => new external_multiple_structure(
                    self::course_structure(true, true)
                ),
            ])
        );
    }

    /**
     * Parameters for course_get_outline.
     *
     * @return external_function_parameters
     */
    public static function course_get_outline_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Return course outline.
     *
     * @param int $courseid
     * @return array
     */
    public static function course_get_outline(int $courseid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::course_get_outline_parameters(), [
            'courseid' => $courseid,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        try {
            $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);

            $modinfo = get_fast_modinfo($course);
            $sections = [];
            foreach ($modinfo->get_section_info_all() as $sectioninfo) {
                if (!$sectioninfo || ((int)$sectioninfo->section === 0 && empty($sectioninfo->name) && empty($sectioninfo->summary))) {
                    continue;
                }

                $modules = [];
                foreach ($modinfo->get_cms() as $cm) {
                    if ((int)$cm->sectionnum !== (int)$sectioninfo->section) {
                        continue;
                    }
                    if (!$cm->uservisible) {
                        continue;
                    }
                    $modules[] = [
                        'cmid' => (int)$cm->id,
                        'instance' => (int)$cm->instance,
                        'modname' => (string)$cm->modname,
                        'name' => (string)$cm->name,
                        'uservisible' => !empty($cm->uservisible) ? 1 : 0,
                        'url' => $cm->url ? $cm->url->out(false) : '',
                    ];
                }

                $sections[] = [
                    'id' => (int)$sectioninfo->id,
                    'sectionnum' => (int)$sectioninfo->section,
                    'name' => (string)($sectioninfo->name ?? ''),
                    'summary' => (string)html_to_text(
                        format_text(
                            $sectioninfo->summary ?? '',
                            $sectioninfo->summaryformat ?? FORMAT_HTML,
                            ['context' => $coursecontext, 'noclean' => true, 'para' => false]
                        ),
                        0
                    ),
                    'modules' => $modules,
                ];
            }

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course, $modinfo),
                'sections' => $sections,
            ]);
            self::audit($USER->id, 'course_get_outline', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'course_outline_failed', $e->getMessage());
            self::audit($USER->id, 'course_get_outline', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for course_get_outline.
     *
     * @return \core_external\external_description
     */
    public static function course_get_outline_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'sections' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Section id'),
                        'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                        'name' => new external_value(PARAM_RAW, 'Section name'),
                        'summary' => new external_value(PARAM_RAW, 'Section summary'),
                        'modules' => new external_multiple_structure(
                            new external_single_structure([
                                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                                'instance' => new external_value(PARAM_INT, 'Instance id'),
                                'modname' => new external_value(PARAM_TEXT, 'Module name'),
                                'name' => new external_value(PARAM_RAW, 'Module name'),
                                'uservisible' => new external_value(PARAM_BOOL, 'User visible'),
                                'url' => new external_value(PARAM_RAW, 'Module URL'),
                            ])
                        ),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_list_by_course.
     *
     * @return external_function_parameters
     */
    public static function quiz_list_by_course_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
        ]);
    }

    /**
     * List quizzes in a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function quiz_list_by_course(int $courseid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::quiz_list_by_course_parameters(), [
            'courseid' => $courseid,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        try {
            $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);

            $modinfo = get_fast_modinfo($course);
            $quizzes = [];
            foreach ($modinfo->get_instances_of('quiz') as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $quizzes[] = [
                    'cmid' => (int)$cm->id,
                    'quizid' => (int)$cm->instance,
                    'name' => (string)$cm->name,
                    'sectionnum' => (int)$cm->sectionnum,
                    'visible' => !empty($cm->uservisible) ? 1 : 0,
                    'url' => $cm->url ? $cm->url->out(false) : '',
                ];
            }

            usort($quizzes, static function(array $a, array $b): int {
                return [$a['sectionnum'], $a['cmid']] <=> [$b['sectionnum'], $b['cmid']];
            });

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course, $modinfo),
                'quizzes' => $quizzes,
            ]);
            self::audit($USER->id, 'quiz_list_by_course', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_list_failed', $e->getMessage(), false, false, [
                'course' => self::empty_course_payload((int)$params['courseid']),
                'quizzes' => [],
            ]);
            self::audit($USER->id, 'quiz_list_by_course', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_list_by_course.
     *
     * @return \core_external\external_description
     */
    public static function quiz_list_by_course_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'quizzes' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                        'name' => new external_value(PARAM_RAW, 'Quiz name'),
                        'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                        'visible' => new external_value(PARAM_BOOL, 'Visible'),
                        'url' => new external_value(PARAM_RAW, 'Quiz URL'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for activities_list_by_course.
     *
     * @return external_function_parameters
     */
    public static function activities_list_by_course_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Optional activity module type filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * List visible activities in a course.
     *
     * @param int $courseid
     * @param string $modname
     * @return array
     */
    public static function activities_list_by_course(int $courseid, string $modname): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::activities_list_by_course_parameters(), [
            'courseid' => $courseid,
            'modname' => $modname,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        try {
            $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);

            $modinfo = get_fast_modinfo($course);
            $filtermodname = trim(core_text::strtolower($params['modname']));
            $activities = [];
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                if ($filtermodname !== '' && core_text::strtolower($cm->modname) !== $filtermodname) {
                    continue;
                }
                $activities[] = [
                    'cmid' => (int)$cm->id,
                    'instance' => (int)$cm->instance,
                    'modname' => (string)$cm->modname,
                    'name' => (string)$cm->name,
                    'sectionnum' => (int)$cm->sectionnum,
                    'visible' => !empty($cm->uservisible) ? 1 : 0,
                    'url' => $cm->url ? $cm->url->out(false) : '',
                ];
            }

            usort($activities, static function(array $a, array $b): int {
                return [$a['sectionnum'], $a['modname'], $a['cmid']] <=> [$b['sectionnum'], $b['modname'], $b['cmid']];
            });

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course, $modinfo),
                'activities' => $activities,
            ]);
            self::audit($USER->id, 'activities_list_by_course', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'activities_list_failed', $e->getMessage(), false, false, [
                'course' => self::empty_course_payload((int)$params['courseid']),
                'activities' => [],
            ]);
            self::audit($USER->id, 'activities_list_by_course', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for activities_list_by_course.
     *
     * @return \core_external\external_description
     */
    public static function activities_list_by_course_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'instance' => new external_value(PARAM_INT, 'Activity instance id'),
                        'modname' => new external_value(PARAM_TEXT, 'Module type'),
                        'name' => new external_value(PARAM_RAW, 'Activity name'),
                        'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                        'visible' => new external_value(PARAM_BOOL, 'Visible'),
                        'url' => new external_value(PARAM_RAW, 'Activity URL'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for assignments_list_by_course.
     *
     * @return external_function_parameters
     */
    public static function assignments_list_by_course_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
        ]);
    }

    /**
     * List visible assignments in a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function assignments_list_by_course(int $courseid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::assignments_list_by_course_parameters(), [
            'courseid' => $courseid,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        try {
            $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);

            $modinfo = get_fast_modinfo($course);
            $assignrecords = $DB->get_records(
                'assign',
                ['course' => $course->id],
                '',
                'id,duedate,allowsubmissionsfromdate,cutoffdate,gradingduedate,alwaysshowdescription,teamsubmission,submissiondrafts'
            );

            $assignments = [];
            foreach ($modinfo->get_instances_of('assign') as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $assign = $assignrecords[$cm->instance] ?? null;
                $assignments[] = [
                    'cmid' => (int)$cm->id,
                    'assignid' => (int)$cm->instance,
                    'name' => (string)$cm->name,
                    'sectionnum' => (int)$cm->sectionnum,
                    'visible' => !empty($cm->uservisible) ? 1 : 0,
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'allowsubmissionsfromdate' => (int)($assign->allowsubmissionsfromdate ?? 0),
                    'duedate' => (int)($assign->duedate ?? 0),
                    'cutoffdate' => (int)($assign->cutoffdate ?? 0),
                    'gradingduedate' => (int)($assign->gradingduedate ?? 0),
                    'alwaysshowdescription' => !empty($assign->alwaysshowdescription) ? 1 : 0,
                    'teamsubmission' => !empty($assign->teamsubmission) ? 1 : 0,
                    'submissiondrafts' => !empty($assign->submissiondrafts) ? 1 : 0,
                ];
            }

            usort($assignments, static function(array $a, array $b): int {
                return [$a['sectionnum'], $a['cmid']] <=> [$b['sectionnum'], $b['cmid']];
            });

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course, $modinfo),
                'assignments' => $assignments,
            ]);
            self::audit($USER->id, 'assignments_list_by_course', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'assignments_list_failed', $e->getMessage(), false, false, [
                'course' => self::empty_course_payload((int)$params['courseid']),
                'assignments' => [],
            ]);
            self::audit($USER->id, 'assignments_list_by_course', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for assignments_list_by_course.
     *
     * @return \core_external\external_description
     */
    public static function assignments_list_by_course_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'assignments' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'assignid' => new external_value(PARAM_INT, 'Assignment id'),
                        'name' => new external_value(PARAM_RAW, 'Assignment name'),
                        'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                        'visible' => new external_value(PARAM_BOOL, 'Visible'),
                        'url' => new external_value(PARAM_RAW, 'Assignment URL'),
                        'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Submission open time'),
                        'duedate' => new external_value(PARAM_INT, 'Due date'),
                        'cutoffdate' => new external_value(PARAM_INT, 'Cutoff date'),
                        'gradingduedate' => new external_value(PARAM_INT, 'Grading due date'),
                        'alwaysshowdescription' => new external_value(PARAM_BOOL, 'Description visibility'),
                        'teamsubmission' => new external_value(PARAM_BOOL, 'Team submission enabled'),
                        'submissiondrafts' => new external_value(PARAM_BOOL, 'Drafts enabled'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for calendar_list.
     *
     * @return external_function_parameters
     */
    public static function calendar_list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'timestart' => new external_value(PARAM_INT, 'Start unix timestamp', VALUE_DEFAULT, 0),
            'timeend' => new external_value(PARAM_INT, 'End unix timestamp', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Max events to return', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * List calendar events for current user and enrolled courses.
     *
     * @param int $timestart
     * @param int $timeend
     * @param int $limit
     * @return array
     */
    public static function calendar_list(int $timestart, int $timeend, int $limit): array {
        global $USER;

        $params = self::validate_parameters(self::calendar_list_parameters(), [
            'timestart' => $timestart,
            'timeend' => $timeend,
            'limit' => $limit,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        try {
            $now = time();
            $rangestart = !empty($params['timestart']) ? (int)$params['timestart'] : $now - DAYSECS;
            $rangeend = !empty($params['timeend']) ? (int)$params['timeend'] : $rangestart + (30 * DAYSECS);
            $limitnum = (int)$params['limit'];

            if ($limitnum < 1 || $limitnum > 500) {
                throw new \invalid_parameter_exception('Limit must be between 1 and 500.');
            }
            if ($rangeend < $rangestart) {
                throw new \invalid_parameter_exception('timeend must be greater than or equal to timestart.');
            }

            $courses = enrol_get_users_courses($USER->id, true, 'id');
            $courseids = array_map(static fn($course) => (int)$course->id, array_values($courses));

            $events = calendar_get_legacy_events(
                $rangestart,
                $rangeend,
                $USER->id,
                true,
                $courseids,
                true,
                true,
                [],
                $limitnum
            );

            $payload = [];
            foreach ($events as $event) {
                $payload[] = [
                    'id' => (int)$event->id,
                    'name' => (string)$event->name,
                    'eventtype' => (string)($event->eventtype ?? ''),
                    'timestart' => (int)($event->timestart ?? 0),
                    'timeduration' => (int)($event->timeduration ?? 0),
                    'courseid' => (int)($event->courseid ?? 0),
                    'groupid' => (int)($event->groupid ?? 0),
                    'userid' => (int)($event->userid ?? 0),
                    'visible' => !empty($event->visible) ? 1 : 0,
                    'url' => !empty($event->viewurl) ? (string)$event->viewurl->out(false) : '',
                ];
            }

            usort($payload, static function(array $a, array $b): int {
                return [$a['timestart'], $a['id']] <=> [$b['timestart'], $b['id']];
            });

            $response = self::response_ok($auditid, [
                'timestart' => $rangestart,
                'timeend' => $rangeend,
                'events' => $payload,
            ]);
            self::audit($USER->id, 'calendar_list', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'calendar_list_failed', $e->getMessage());
            self::audit($USER->id, 'calendar_list', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for calendar_list.
     *
     * @return \core_external\external_description
     */
    public static function calendar_list_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'timestart' => new external_value(PARAM_INT, 'Start timestamp'),
                'timeend' => new external_value(PARAM_INT, 'End timestamp'),
                'events' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Event id'),
                        'name' => new external_value(PARAM_RAW, 'Event name'),
                        'eventtype' => new external_value(PARAM_TEXT, 'Event type'),
                        'timestart' => new external_value(PARAM_INT, 'Start time'),
                        'timeduration' => new external_value(PARAM_INT, 'Duration'),
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'groupid' => new external_value(PARAM_INT, 'Group id'),
                        'userid' => new external_value(PARAM_INT, 'User id'),
                        'visible' => new external_value(PARAM_BOOL, 'Visible'),
                        'url' => new external_value(PARAM_RAW, 'Event URL'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for activities_due_list.
     *
     * @return external_function_parameters
     */
    public static function activities_due_list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id filter', VALUE_DEFAULT, 0),
            'timestart' => new external_value(PARAM_INT, 'Optional lower timestamp bound', VALUE_DEFAULT, 0),
            'timeend' => new external_value(PARAM_INT, 'Optional upper timestamp bound', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum rows to return', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * List due/open/completion-expected timestamps for visible activities.
     *
     * @param int $courseid
     * @param int $timestart
     * @param int $timeend
     * @param int $limit
     * @return array
     */
    public static function activities_due_list(int $courseid, int $timestart, int $timeend, int $limit): array {
        global $USER;

        $params = self::validate_parameters(self::activities_due_list_parameters(), [
            'courseid' => $courseid,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'limit' => $limit,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $limitnum = (int)$params['limit'];
            if ($limitnum < 1 || $limitnum > 500) {
                throw new \invalid_parameter_exception('Limit must be between 1 and 500.');
            }

            $courses = self::enrolled_courses_for_user($USER->id, (int)$params['courseid']);
            $items = [];
            $now = time();
            foreach ($courses as $course) {
                $coursecontext = \context_course::instance($course->id);
                self::validate_context($coursecontext);
                require_capability('moodle/course:view', $coursecontext);

                $modinfo = get_fast_modinfo($course);
                foreach ($modinfo->get_cms() as $cm) {
                    if (!$cm->uservisible) {
                        continue;
                    }
                    $record = self::module_record($cm);
                    foreach (self::module_due_entries($cm, $record) as $entry) {
                        $duetime = (int)$entry['duetime'];
                        if (!empty($params['timestart']) && $duetime < (int)$params['timestart']) {
                            continue;
                        }
                        if (!empty($params['timeend']) && $duetime > (int)$params['timeend']) {
                            continue;
                        }
                        $items[] = [
                            'courseid' => (int)$course->id,
                            'courseshortname' => (string)$course->shortname,
                            'coursefullname' => (string)$course->fullname,
                            'cmid' => (int)$cm->id,
                            'instance' => (int)$cm->instance,
                            'modname' => (string)$cm->modname,
                            'name' => (string)$cm->name,
                            'duetype' => (string)$entry['duetype'],
                            'duetime' => $duetime,
                            'overdue' => $duetime > 0
                                && $duetime < $now
                                && !in_array($entry['duetype'], ['open', 'submissionopen', 'assessmentopen'], true) ? 1 : 0,
                            'url' => $cm->url ? $cm->url->out(false) : '',
                        ];
                    }
                }
            }

            usort($items, static function(array $a, array $b): int {
                return [$a['duetime'], $a['courseid'], $a['cmid'], $a['duetype']] <=> [$b['duetime'], $b['courseid'], $b['cmid'], $b['duetype']];
            });

            $items = array_slice($items, 0, $limitnum);

            $response = self::response_ok($auditid, [
                'timestart' => (int)$params['timestart'],
                'timeend' => (int)$params['timeend'],
                'items' => $items,
            ]);
            self::audit($USER->id, 'activities_due_list', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'activities_due_list_failed', $e->getMessage(), false, false, [
                'timestart' => (int)$params['timestart'],
                'timeend' => (int)$params['timeend'],
                'items' => [],
            ]);
            self::audit($USER->id, 'activities_due_list', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for activities_due_list.
     *
     * @return \core_external\external_description
     */
    public static function activities_due_list_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'timestart' => new external_value(PARAM_INT, 'Filter lower bound'),
                'timeend' => new external_value(PARAM_INT, 'Filter upper bound'),
                'items' => new external_multiple_structure(
                    new external_single_structure([
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'courseshortname' => new external_value(PARAM_RAW, 'Course shortname'),
                        'coursefullname' => new external_value(PARAM_RAW, 'Course fullname'),
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'instance' => new external_value(PARAM_INT, 'Module instance id'),
                        'modname' => new external_value(PARAM_TEXT, 'Module type'),
                        'name' => new external_value(PARAM_RAW, 'Activity name'),
                        'duetype' => new external_value(PARAM_TEXT, 'Due/open/completion kind'),
                        'duetime' => new external_value(PARAM_INT, 'Timestamp'),
                        'overdue' => new external_value(PARAM_BOOL, 'Whether due time is in the past'),
                        'url' => new external_value(PARAM_RAW, 'Activity URL'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for assignments_my_status.
     *
     * @return external_function_parameters
     */
    public static function assignments_my_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id filter', VALUE_DEFAULT, 0),
            'assignid' => new external_value(PARAM_INT, 'Optional assignment id filter', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List current user assignment submission status.
     *
     * @param int $courseid
     * @param int $assignid
     * @return array
     */
    public static function assignments_my_status(int $courseid, int $assignid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::assignments_my_status_parameters(), [
            'courseid' => $courseid,
            'assignid' => $assignid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $courses = self::enrolled_courses_for_user($USER->id, (int)$params['courseid']);
            $items = [];
            $now = time();
            foreach ($courses as $course) {
                $coursecontext = \context_course::instance($course->id);
                self::validate_context($coursecontext);
                require_capability('moodle/course:view', $coursecontext);

                $modinfo = get_fast_modinfo($course);
                $assignrecords = $DB->get_records('assign', ['course' => $course->id]);
                $cmbyinstance = [];
                foreach ($modinfo->get_instances_of('assign') as $cm) {
                    if ($cm->uservisible) {
                        $cmbyinstance[(int)$cm->instance] = $cm;
                    }
                }

                foreach ($assignrecords as $assign) {
                    if (!isset($cmbyinstance[(int)$assign->id])) {
                        continue;
                    }
                    if (!empty($params['assignid']) && (int)$assign->id !== (int)$params['assignid']) {
                        continue;
                    }
                    $cm = $cmbyinstance[(int)$assign->id];
                    $submission = $DB->get_record_sql(
                        "SELECT *
                           FROM {assign_submission}
                          WHERE assignment = ?
                            AND userid = ?
                       ORDER BY latest DESC, attemptnumber DESC, id DESC",
                        [$assign->id, $USER->id]
                    );
                    $gradeitem = $DB->get_record('grade_items', [
                        'courseid' => $course->id,
                        'itemtype' => 'mod',
                        'itemmodule' => 'assign',
                        'iteminstance' => $assign->id,
                        'itemnumber' => 0,
                    ]);
                    $grade = null;
                    if ($gradeitem) {
                        $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $USER->id]);
                    }

                    $windowstatus = 'open';
                    if (!empty($assign->allowsubmissionsfromdate) && $assign->allowsubmissionsfromdate > $now) {
                        $windowstatus = 'not_open_yet';
                    } else if (!empty($assign->cutoffdate) && $assign->cutoffdate < $now) {
                        $windowstatus = 'closed';
                    }

                    $submissionstatus = $submission ? (string)$submission->status : 'none';
                    $submittedat = 0;
                    if ($submission && $submission->status === 'submitted') {
                        $submittedat = (int)$submission->timemodified;
                    }

                    $items[] = [
                        'courseid' => (int)$course->id,
                        'courseshortname' => (string)$course->shortname,
                        'cmid' => (int)$cm->id,
                        'assignid' => (int)$assign->id,
                        'name' => (string)$assign->name,
                        'windowstatus' => $windowstatus,
                        'submissionstatus' => $submissionstatus,
                        'submittedat' => $submittedat,
                        'timemodified' => (int)($submission->timemodified ?? 0),
                        'attemptnumber' => (int)($submission->attemptnumber ?? 0),
                        'allowsubmissionsfromdate' => (int)($assign->allowsubmissionsfromdate ?? 0),
                        'duedate' => (int)($assign->duedate ?? 0),
                        'cutoffdate' => (int)($assign->cutoffdate ?? 0),
                        'gradingduedate' => (int)($assign->gradingduedate ?? 0),
                        'isoverdue' => !empty($assign->duedate) && $assign->duedate < $now && $submissionstatus !== 'submitted' ? 1 : 0,
                        'isgraded' => !empty($grade) && $grade->finalgrade !== null ? 1 : 0,
                        'grade' => $grade && $grade->finalgrade !== null ? (float)$grade->finalgrade : -1.0,
                        'maxgrade' => $gradeitem ? (float)$gradeitem->grademax : 0.0,
                        'url' => $cm->url ? $cm->url->out(false) : '',
                    ];
                }
            }

            usort($items, static function(array $a, array $b): int {
                return [$a['courseid'], $a['duedate'], $a['cmid']] <=> [$b['courseid'], $b['duedate'], $b['cmid']];
            });

            $response = self::response_ok($auditid, [
                'courseid' => (int)$params['courseid'],
                'assignid' => (int)$params['assignid'],
                'assignments' => $items,
            ]);
            self::audit($USER->id, 'assignments_my_status', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'assignments_my_status_failed', $e->getMessage(), false, false, [
                'courseid' => (int)$params['courseid'],
                'assignid' => (int)$params['assignid'],
                'assignments' => [],
            ]);
            self::audit($USER->id, 'assignments_my_status', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for assignments_my_status.
     *
     * @return \core_external\external_description
     */
    public static function assignments_my_status_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Requested course filter'),
                'assignid' => new external_value(PARAM_INT, 'Requested assignment filter'),
                'assignments' => new external_multiple_structure(
                    new external_single_structure([
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'courseshortname' => new external_value(PARAM_RAW, 'Course shortname'),
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'assignid' => new external_value(PARAM_INT, 'Assignment id'),
                        'name' => new external_value(PARAM_RAW, 'Assignment name'),
                        'windowstatus' => new external_value(PARAM_TEXT, 'Submission window status'),
                        'submissionstatus' => new external_value(PARAM_TEXT, 'Submission status'),
                        'submittedat' => new external_value(PARAM_INT, 'Submission timestamp'),
                        'timemodified' => new external_value(PARAM_INT, 'Last modified timestamp'),
                        'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
                        'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Open time'),
                        'duedate' => new external_value(PARAM_INT, 'Due date'),
                        'cutoffdate' => new external_value(PARAM_INT, 'Cutoff date'),
                        'gradingduedate' => new external_value(PARAM_INT, 'Grading due date'),
                        'isoverdue' => new external_value(PARAM_BOOL, 'Overdue flag'),
                        'isgraded' => new external_value(PARAM_BOOL, 'Whether graded'),
                        'grade' => new external_value(PARAM_FLOAT, 'Current grade or -1 when unavailable'),
                        'maxgrade' => new external_value(PARAM_FLOAT, 'Max grade'),
                        'url' => new external_value(PARAM_RAW, 'Assignment URL'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_attempts_my.
     *
     * @return external_function_parameters
     */
    public static function quiz_attempts_my_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id filter', VALUE_DEFAULT, 0),
            'quizid' => new external_value(PARAM_INT, 'Optional quiz id filter', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List current user quiz attempts.
     *
     * @param int $courseid
     * @param int $quizid
     * @return array
     */
    public static function quiz_attempts_my(int $courseid, int $quizid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::quiz_attempts_my_parameters(), [
            'courseid' => $courseid,
            'quizid' => $quizid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $courses = self::enrolled_courses_for_user($USER->id, (int)$params['courseid']);
            $payload = [];
            foreach ($courses as $course) {
                $coursecontext = \context_course::instance($course->id);
                self::validate_context($coursecontext);
                require_capability('moodle/course:view', $coursecontext);

                $modinfo = get_fast_modinfo($course);
                $quizzes = $DB->get_records('quiz', ['course' => $course->id]);
                $cmbyinstance = [];
                foreach ($modinfo->get_instances_of('quiz') as $cm) {
                    if ($cm->uservisible) {
                        $cmbyinstance[(int)$cm->instance] = $cm;
                    }
                }

                foreach ($quizzes as $quiz) {
                    if (!isset($cmbyinstance[(int)$quiz->id])) {
                        continue;
                    }
                    if (!empty($params['quizid']) && (int)$quiz->id !== (int)$params['quizid']) {
                        continue;
                    }
                    $cm = $cmbyinstance[(int)$quiz->id];
                    $attempts = $DB->get_records('quiz_attempts', [
                        'quiz' => $quiz->id,
                        'userid' => $USER->id,
                        'preview' => 0,
                    ], 'attempt ASC');

                    $bestgrade = mod_quiz_external::get_user_best_grade((int)$quiz->id, (int)$USER->id);
                    $best = null;
                    if (is_array($bestgrade) && array_key_exists('grade', $bestgrade)) {
                        $best = $bestgrade['grade'];
                    }

                    $attemptpayload = [];
                    $unfinished = false;
                    foreach ($attempts as $attempt) {
                        if ((string)$attempt->state !== 'finished') {
                            $unfinished = true;
                        }
                        $attemptpayload[] = [
                            'attemptid' => (int)$attempt->id,
                            'attempt' => (int)$attempt->attempt,
                            'state' => (string)$attempt->state,
                            'timestart' => (int)$attempt->timestart,
                            'timefinish' => (int)$attempt->timefinish,
                            'sumgrades' => $attempt->sumgrades !== null ? (float)$attempt->sumgrades : -1.0,
                        ];
                    }

                    $attemptsmade = count($attemptpayload);
                    $attemptsallowed = (int)$quiz->attempts;
                    $attemptsleft = $attemptsallowed === 0 ? -1 : max($attemptsallowed - $attemptsmade, 0);

                    $payload[] = [
                        'courseid' => (int)$course->id,
                        'courseshortname' => (string)$course->shortname,
                        'cmid' => (int)$cm->id,
                        'quizid' => (int)$quiz->id,
                        'name' => (string)$quiz->name,
                        'timeopen' => (int)($quiz->timeopen ?? 0),
                        'timeclose' => (int)($quiz->timeclose ?? 0),
                        'attemptsallowed' => $attemptsallowed,
                        'attemptsmade' => $attemptsmade,
                        'attemptsleft' => $attemptsleft,
                        'bestgrade' => $best !== null ? (float)$best : -1.0,
                        'grademax' => (float)($quiz->grade ?? 0),
                        'url' => $cm->url ? $cm->url->out(false) : '',
                        'hasunfinished' => $unfinished ? 1 : 0,
                        'attempts' => $attemptpayload,
                    ];
                }
            }

            usort($payload, static function(array $a, array $b): int {
                return [$a['courseid'], $a['quizid']] <=> [$b['courseid'], $b['quizid']];
            });

            $response = self::response_ok($auditid, [
                'courseid' => (int)$params['courseid'],
                'quizid' => (int)$params['quizid'],
                'quizzes' => $payload,
            ]);
            self::audit($USER->id, 'quiz_attempts_my', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_attempts_my_failed', $e->getMessage(), false, false, [
                'courseid' => (int)$params['courseid'],
                'quizid' => (int)$params['quizid'],
                'quizzes' => [],
            ]);
            self::audit($USER->id, 'quiz_attempts_my', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_attempts_my.
     *
     * @return \core_external\external_description
     */
    public static function quiz_attempts_my_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Requested course filter'),
                'quizid' => new external_value(PARAM_INT, 'Requested quiz filter'),
                'quizzes' => new external_multiple_structure(
                    new external_single_structure([
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'courseshortname' => new external_value(PARAM_RAW, 'Course shortname'),
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                        'name' => new external_value(PARAM_RAW, 'Quiz name'),
                        'timeopen' => new external_value(PARAM_INT, 'Open time'),
                        'timeclose' => new external_value(PARAM_INT, 'Close time'),
                        'attemptsallowed' => new external_value(PARAM_INT, 'Allowed attempts (0 = unlimited)'),
                        'attemptsmade' => new external_value(PARAM_INT, 'Attempts made'),
                        'attemptsleft' => new external_value(PARAM_INT, 'Attempts left (-1 = unlimited)'),
                        'bestgrade' => new external_value(PARAM_FLOAT, 'Best grade or -1'),
                        'grademax' => new external_value(PARAM_FLOAT, 'Max quiz grade'),
                        'url' => new external_value(PARAM_RAW, 'Quiz URL'),
                        'hasunfinished' => new external_value(PARAM_BOOL, 'Whether unfinished attempts exist'),
                        'attempts' => new external_multiple_structure(
                            new external_single_structure([
                                'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
                                'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                                'state' => new external_value(PARAM_TEXT, 'Attempt state'),
                                'timestart' => new external_value(PARAM_INT, 'Start time'),
                                'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                                'sumgrades' => new external_value(PARAM_FLOAT, 'Raw sumgrades or -1'),
                            ])
                        ),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for grades_overview_my.
     *
     * @return external_function_parameters
     */
    public static function grades_overview_my_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id filter', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return current user grade overview by course.
     *
     * @param int $courseid
     * @return array
     */
    public static function grades_overview_my(int $courseid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::grades_overview_my_parameters(), [
            'courseid' => $courseid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $courses = self::enrolled_courses_for_user($USER->id, (int)$params['courseid']);
            $payload = [];
            foreach ($courses as $course) {
                $coursecontext = \context_course::instance($course->id);
                self::validate_context($coursecontext);
                require_capability('moodle/course:view', $coursecontext);

                $sql = "SELECT gi.id, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemname, gi.grademin, gi.grademax,
                               gg.finalgrade, gg.feedback, gg.timemodified AS dategraded
                          FROM {grade_items} gi
                     LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                         WHERE gi.courseid = :courseid
                           AND gi.itemtype IN ('course', 'mod')
                      ORDER BY CASE WHEN gi.itemtype = 'course' THEN 0 ELSE 1 END, gi.sortorder, gi.id";
                $records = $DB->get_records_sql($sql, ['userid' => $USER->id, 'courseid' => $course->id]);

                $coursegrade = null;
                $coursemin = 0.0;
                $coursemax = 0.0;
                $items = [];
                foreach ($records as $record) {
                    if ($record->itemtype === 'course') {
                        $coursegrade = $record->finalgrade !== null ? (float)$record->finalgrade : -1.0;
                        $coursemin = (float)$record->grademin;
                        $coursemax = (float)$record->grademax;
                        continue;
                    }
                    $percentage = -1.0;
                    if ($record->finalgrade !== null && (float)$record->grademax > (float)$record->grademin) {
                        $percentage = ((float)$record->finalgrade - (float)$record->grademin) / ((float)$record->grademax - (float)$record->grademin) * 100.0;
                    }
                    $items[] = [
                        'itemid' => (int)$record->id,
                        'itemtype' => (string)$record->itemtype,
                        'itemmodule' => (string)($record->itemmodule ?? ''),
                        'iteminstance' => (int)($record->iteminstance ?? 0),
                        'itemname' => (string)($record->itemname ?? ''),
                        'grade' => $record->finalgrade !== null ? (float)$record->finalgrade : -1.0,
                        'grademin' => (float)$record->grademin,
                        'grademax' => (float)$record->grademax,
                        'percentage' => $percentage,
                        'feedback' => (string)($record->feedback ?? ''),
                        'dategraded' => (int)($record->dategraded ?? 0),
                    ];
                }

                $payload[] = [
                    'courseid' => (int)$course->id,
                    'courseshortname' => (string)$course->shortname,
                    'coursefullname' => (string)$course->fullname,
                    'coursegrade' => $coursegrade !== null ? (float)$coursegrade : -1.0,
                    'grademin' => $coursemin,
                    'grademax' => $coursemax,
                    'items' => $items,
                ];
            }

            $response = self::response_ok($auditid, [
                'courseid' => (int)$params['courseid'],
                'courses' => $payload,
            ]);
            self::audit($USER->id, 'grades_overview_my', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'grades_overview_my_failed', $e->getMessage(), false, false, [
                'courseid' => (int)$params['courseid'],
                'courses' => [],
            ]);
            self::audit($USER->id, 'grades_overview_my', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for grades_overview_my.
     *
     * @return \core_external\external_description
     */
    public static function grades_overview_my_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Requested course filter'),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'courseshortname' => new external_value(PARAM_RAW, 'Course shortname'),
                        'coursefullname' => new external_value(PARAM_RAW, 'Course fullname'),
                        'coursegrade' => new external_value(PARAM_FLOAT, 'Course total or -1'),
                        'grademin' => new external_value(PARAM_FLOAT, 'Course grade minimum'),
                        'grademax' => new external_value(PARAM_FLOAT, 'Course grade maximum'),
                        'items' => new external_multiple_structure(
                            new external_single_structure([
                                'itemid' => new external_value(PARAM_INT, 'Grade item id'),
                                'itemtype' => new external_value(PARAM_TEXT, 'Grade item type'),
                                'itemmodule' => new external_value(PARAM_TEXT, 'Activity module'),
                                'iteminstance' => new external_value(PARAM_INT, 'Activity instance'),
                                'itemname' => new external_value(PARAM_RAW, 'Grade item name'),
                                'grade' => new external_value(PARAM_FLOAT, 'Grade or -1'),
                                'grademin' => new external_value(PARAM_FLOAT, 'Min grade'),
                                'grademax' => new external_value(PARAM_FLOAT, 'Max grade'),
                                'percentage' => new external_value(PARAM_FLOAT, 'Percentage or -1'),
                                'feedback' => new external_value(PARAM_RAW, 'Feedback text'),
                                'dategraded' => new external_value(PARAM_INT, 'Date graded'),
                            ])
                        ),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for course_progress_my.
     *
     * @return external_function_parameters
     */
    public static function course_progress_my_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id filter', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return completion and progress summary for current user.
     *
     * @param int $courseid
     * @return array
     */
    public static function course_progress_my(int $courseid): array {
        global $USER;

        $params = self::validate_parameters(self::course_progress_my_parameters(), [
            'courseid' => $courseid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $courses = self::enrolled_courses_for_user($USER->id, (int)$params['courseid']);
            $payload = [];
            foreach ($courses as $course) {
                $coursecontext = \context_course::instance($course->id);
                self::validate_context($coursecontext);
                require_capability('moodle/course:view', $coursecontext);

                $completionenabled = !empty($course->enablecompletion);
                $statuses = [];
                $completedcount = 0;
                $totalcount = 0;
                if ($completionenabled) {
                    $completiondata = core_completion_external::get_activities_completion_status((int)$course->id, (int)$USER->id);
                    $statuses = [];
                    foreach (($completiondata['statuses'] ?? []) as $status) {
                        $statuses[] = [
                            'cmid' => (int)$status['cmid'],
                            'modname' => (string)$status['modname'],
                            'instance' => (int)$status['instance'],
                            'state' => (int)$status['state'],
                            'timecompleted' => (int)$status['timecompleted'],
                            'tracking' => (int)$status['tracking'],
                            'isoverallcomplete' => !empty($status['isoverallcomplete']) ? 1 : 0,
                            'uservisible' => array_key_exists('uservisible', $status) ? (!empty($status['uservisible']) ? 1 : 0) : 1,
                        ];
                    }
                    $totalcount = count($statuses);
                    foreach ($statuses as $status) {
                        if ((int)$status['state'] > 0) {
                            $completedcount++;
                        }
                    }
                }

                $progresspercent = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
                if ($progresspercent === null) {
                    $progresspercent = -1.0;
                }

                $payload[] = [
                    'courseid' => (int)$course->id,
                    'courseshortname' => (string)$course->shortname,
                    'coursefullname' => (string)$course->fullname,
                    'completionenabled' => $completionenabled ? 1 : 0,
                    'progresspercent' => (float)$progresspercent,
                    'completedcount' => $completedcount,
                    'totalcount' => $totalcount,
                    'statuses' => $statuses,
                ];
            }

            $response = self::response_ok($auditid, [
                'courseid' => (int)$params['courseid'],
                'courses' => $payload,
            ]);
            self::audit($USER->id, 'course_progress_my', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'course_progress_my_failed', $e->getMessage(), false, false, [
                'courseid' => (int)$params['courseid'],
                'courses' => [],
            ]);
            self::audit($USER->id, 'course_progress_my', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for course_progress_my.
     *
     * @return \core_external\external_description
     */
    public static function course_progress_my_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Requested course filter'),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'courseshortname' => new external_value(PARAM_RAW, 'Course shortname'),
                        'coursefullname' => new external_value(PARAM_RAW, 'Course fullname'),
                        'completionenabled' => new external_value(PARAM_BOOL, 'Whether course completion is enabled'),
                        'progresspercent' => new external_value(PARAM_FLOAT, 'Course progress percentage or -1'),
                        'completedcount' => new external_value(PARAM_INT, 'Completed activities count'),
                        'totalcount' => new external_value(PARAM_INT, 'Tracked activities count'),
                        'statuses' => new external_multiple_structure(
                            new external_single_structure([
                                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                                'modname' => new external_value(PARAM_TEXT, 'Module name'),
                                'instance' => new external_value(PARAM_INT, 'Module instance'),
                                'state' => new external_value(PARAM_INT, 'Completion state'),
                                'timecompleted' => new external_value(PARAM_INT, 'Completion timestamp'),
                                'tracking' => new external_value(PARAM_INT, 'Tracking mode'),
                                'isoverallcomplete' => new external_value(PARAM_BOOL, 'Overall completion flag'),
                                'uservisible' => new external_value(PARAM_BOOL, 'Visible to user'),
                            ])
                        ),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for course_activity_detail.
     *
     * @return external_function_parameters
     */
    public static function course_activity_detail_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Return normalized detail for a visible activity.
     *
     * @param int $cmid
     * @return array
     */
    public static function course_activity_detail(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::course_activity_detail_parameters(), [
            'cmid' => $cmid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $cm = get_coursemodule_from_id('', (int)$params['cmid'], 0, false, MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);

            $modinfo = get_fast_modinfo($course);
            $cminfo = $modinfo->get_cm($cm->id);
            if (!$cminfo->uservisible) {
                throw new \moodle_exception('Activity not visible to current user.');
            }
            $context = \context_module::instance($cm->id);
            $record = self::module_record($cminfo);
            $times = self::module_primary_times($cminfo, $record);

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'activity' => [
                    'cmid' => (int)$cminfo->id,
                    'instance' => (int)$cminfo->instance,
                    'modname' => (string)$cminfo->modname,
                    'name' => (string)$cminfo->name,
                    'sectionnum' => (int)$cminfo->sectionnum,
                    'visible' => !empty($cminfo->uservisible) ? 1 : 0,
                    'url' => $cminfo->url ? $cminfo->url->out(false) : '',
                    'summaryhtml' => self::module_summary_html($cminfo, $record, $context),
                    'contenthtml' => self::module_content_html($cminfo, $record, $context),
                    'externalurl' => self::module_external_url($cminfo, $record),
                    'openfrom' => (int)$times['openfrom'],
                    'dueto' => (int)$times['dueto'],
                    'completionexpected' => (int)($cminfo->completionexpected ?? 0),
                ],
            ]);
            self::audit($USER->id, 'course_activity_detail', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'course_activity_detail_failed', $e->getMessage(), false, false, [
                'course' => self::empty_course_payload(),
                'activity' => [
                    'cmid' => (int)$params['cmid'],
                    'instance' => 0,
                    'modname' => '',
                    'name' => '',
                    'sectionnum' => 0,
                    'visible' => 0,
                    'url' => '',
                    'summaryhtml' => '',
                    'contenthtml' => '',
                    'externalurl' => '',
                    'openfrom' => 0,
                    'dueto' => 0,
                    'completionexpected' => 0,
                ],
            ]);
            self::audit($USER->id, 'course_activity_detail', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for course_activity_detail.
     *
     * @return \core_external\external_description
     */
    public static function course_activity_detail_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'activity' => new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'instance' => new external_value(PARAM_INT, 'Module instance'),
                    'modname' => new external_value(PARAM_TEXT, 'Module type'),
                    'name' => new external_value(PARAM_RAW, 'Name'),
                    'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                    'visible' => new external_value(PARAM_BOOL, 'Visible'),
                    'url' => new external_value(PARAM_RAW, 'Module URL'),
                    'summaryhtml' => new external_value(PARAM_RAW, 'Summary HTML'),
                    'contenthtml' => new external_value(PARAM_RAW, 'Content HTML'),
                    'externalurl' => new external_value(PARAM_RAW, 'External target URL'),
                    'openfrom' => new external_value(PARAM_INT, 'Primary open timestamp'),
                    'dueto' => new external_value(PARAM_INT, 'Primary due/close timestamp'),
                    'completionexpected' => new external_value(PARAM_INT, 'Completion expected timestamp'),
                ]),
            ])
        );
    }

    /**
     * Parameters for resources_list_by_course.
     *
     * @return external_function_parameters
     */
    public static function resources_list_by_course_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
        ]);
    }

    /**
     * List resource-style modules in a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function resources_list_by_course(int $courseid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::resources_list_by_course_parameters(), [
            'courseid' => $courseid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
            $coursecontext = \context_course::instance($course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);

            $modinfo = get_fast_modinfo($course);
            $allowed = ['resource', 'page', 'url', 'book', 'folder', 'label'];
            $items = [];
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible || !in_array($cm->modname, $allowed, true)) {
                    continue;
                }
                $context = \context_module::instance($cm->id);
                $record = self::module_record($cm);
                $items[] = [
                    'cmid' => (int)$cm->id,
                    'instance' => (int)$cm->instance,
                    'modname' => (string)$cm->modname,
                    'name' => (string)$cm->name,
                    'sectionnum' => (int)$cm->sectionnum,
                    'visible' => !empty($cm->uservisible) ? 1 : 0,
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'summaryhtml' => self::module_summary_html($cm, $record, $context),
                    'externalurl' => self::module_external_url($cm, $record),
                    'completionexpected' => (int)($cm->completionexpected ?? 0),
                ];
            }

            usort($items, static function(array $a, array $b): int {
                return [$a['sectionnum'], $a['modname'], $a['cmid']] <=> [$b['sectionnum'], $b['modname'], $b['cmid']];
            });

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'resources' => $items,
            ]);
            self::audit($USER->id, 'resources_list_by_course', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'resources_list_by_course_failed', $e->getMessage(), false, false, [
                'course' => self::empty_course_payload((int)$params['courseid']),
                'resources' => [],
            ]);
            self::audit($USER->id, 'resources_list_by_course', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for resources_list_by_course.
     *
     * @return \core_external\external_description
     */
    public static function resources_list_by_course_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'resources' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'instance' => new external_value(PARAM_INT, 'Module instance id'),
                        'modname' => new external_value(PARAM_TEXT, 'Module type'),
                        'name' => new external_value(PARAM_RAW, 'Module name'),
                        'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                        'visible' => new external_value(PARAM_BOOL, 'Visible'),
                        'url' => new external_value(PARAM_RAW, 'Module URL'),
                        'summaryhtml' => new external_value(PARAM_RAW, 'Summary HTML'),
                        'externalurl' => new external_value(PARAM_RAW, 'External target URL'),
                        'completionexpected' => new external_value(PARAM_INT, 'Completion expected timestamp'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for forum_discussions_list.
     *
     * @return external_function_parameters
     */
    public static function forum_discussions_list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id filter', VALUE_DEFAULT, 0),
            'forumid' => new external_value(PARAM_INT, 'Optional forum id filter', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum flattened discussions to return', VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * List recent visible forum discussions.
     *
     * @param int $courseid
     * @param int $forumid
     * @param int $limit
     * @return array
     */
    public static function forum_discussions_list(int $courseid, int $forumid, int $limit): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::forum_discussions_list_parameters(), [
            'courseid' => $courseid,
            'forumid' => $forumid,
            'limit' => $limit,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $limitnum = (int)$params['limit'];
            if ($limitnum < 1 || $limitnum > 200) {
                throw new \invalid_parameter_exception('Limit must be between 1 and 200.');
            }

            $forumrecords = [];
            if (!empty($params['forumid'])) {
                $forum = $DB->get_record('forum', ['id' => $params['forumid']], '*', MUST_EXIST);
                $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
                $coursecontext = \context_course::instance($course->id);
                self::validate_context($coursecontext);
                require_capability('moodle/course:view', $coursecontext);
                $forumrecords[] = $forum;
            } else {
                $courses = self::enrolled_courses_for_user($USER->id, (int)$params['courseid']);
                foreach ($courses as $course) {
                    $coursecontext = \context_course::instance($course->id);
                    self::validate_context($coursecontext);
                    require_capability('moodle/course:view', $coursecontext);
                    $modinfo = get_fast_modinfo($course);
                    $visible = [];
                    foreach ($modinfo->get_instances_of('forum') as $cm) {
                        if ($cm->uservisible) {
                            $visible[(int)$cm->instance] = true;
                        }
                    }
                    foreach ($DB->get_records('forum', ['course' => $course->id]) as $forum) {
                        if (isset($visible[(int)$forum->id])) {
                            $forumrecords[] = $forum;
                        }
                    }
                }
            }

            $items = [];
            foreach ($forumrecords as $forum) {
                $course = $DB->get_record('course', ['id' => $forum->course], '*', MUST_EXIST);
                $result = mod_forum_external::get_forum_discussions((int)$forum->id, -1, 0, min($limitnum, 20), 0);
                foreach (($result['discussions'] ?? []) as $discussion) {
                    $items[] = [
                        'forumid' => (int)$forum->id,
                        'forumname' => (string)$forum->name,
                        'courseid' => (int)$course->id,
                        'courseshortname' => (string)$course->shortname,
                        'discussionid' => (int)$discussion['discussion'],
                        'postid' => (int)$discussion['id'],
                        'subject' => (string)$discussion['subject'],
                        'userfullname' => (string)$discussion['userfullname'],
                        'created' => (int)$discussion['created'],
                        'modified' => (int)$discussion['modified'],
                        'timemodified' => (int)$discussion['timemodified'],
                        'numreplies' => (int)$discussion['numreplies'],
                        'numunread' => (int)$discussion['numunread'],
                        'pinned' => !empty($discussion['pinned']) ? 1 : 0,
                        'locked' => !empty($discussion['locked']) ? 1 : 0,
                        'canreply' => !empty($discussion['canreply']) ? 1 : 0,
                        'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$discussion['discussion']]))->out(false),
                    ];
                }
            }

            usort($items, static function(array $a, array $b): int {
                return [$b['timemodified'], $a['discussionid']] <=> [$a['timemodified'], $b['discussionid']];
            });
            $items = array_slice($items, 0, $limitnum);

            $response = self::response_ok($auditid, [
                'courseid' => (int)$params['courseid'],
                'forumid' => (int)$params['forumid'],
                'discussions' => $items,
            ]);
            self::audit($USER->id, 'forum_discussions_list', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'forum_discussions_list_failed', $e->getMessage(), false, false, [
                'courseid' => (int)$params['courseid'],
                'forumid' => (int)$params['forumid'],
                'discussions' => [],
            ]);
            self::audit($USER->id, 'forum_discussions_list', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for forum_discussions_list.
     *
     * @return \core_external\external_description
     */
    public static function forum_discussions_list_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Requested course filter'),
                'forumid' => new external_value(PARAM_INT, 'Requested forum filter'),
                'discussions' => new external_multiple_structure(
                    new external_single_structure([
                        'forumid' => new external_value(PARAM_INT, 'Forum id'),
                        'forumname' => new external_value(PARAM_RAW, 'Forum name'),
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'courseshortname' => new external_value(PARAM_RAW, 'Course shortname'),
                        'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                        'postid' => new external_value(PARAM_INT, 'First post id'),
                        'subject' => new external_value(PARAM_RAW, 'Discussion subject'),
                        'userfullname' => new external_value(PARAM_TEXT, 'Author name'),
                        'created' => new external_value(PARAM_INT, 'Created time'),
                        'modified' => new external_value(PARAM_INT, 'Modified time'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                        'numreplies' => new external_value(PARAM_INT, 'Reply count'),
                        'numunread' => new external_value(PARAM_INT, 'Unread count'),
                        'pinned' => new external_value(PARAM_BOOL, 'Pinned flag'),
                        'locked' => new external_value(PARAM_BOOL, 'Locked flag'),
                        'canreply' => new external_value(PARAM_BOOL, 'Can reply'),
                        'url' => new external_value(PARAM_RAW, 'Discussion URL'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for notifications_list_my.
     *
     * @return external_function_parameters
     */
    public static function notifications_list_my_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limitfrom' => new external_value(PARAM_INT, 'Offset for pagination', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'Maximum notifications to return', VALUE_DEFAULT, 50),
            'unreadonly' => new external_value(PARAM_BOOL, 'Return unread only', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * List notifications/messages for current user.
     *
     * @param int $limitfrom
     * @param int $limitnum
     * @param bool $unreadonly
     * @return array
     */
    public static function notifications_list_my(int $limitfrom, int $limitnum, bool $unreadonly): array {
        global $USER;

        $params = self::validate_parameters(self::notifications_list_my_parameters(), [
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
            'unreadonly' => $unreadonly,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            if ((int)$params['limitnum'] < 1 || (int)$params['limitnum'] > 200) {
                throw new \invalid_parameter_exception('limitnum must be between 1 and 200.');
            }

            $readmode = $params['unreadonly'] ? MESSAGE_GET_UNREAD : MESSAGE_GET_READ_AND_UNREAD;
            $messages = message_get_messages((int)$USER->id, 0, 1, $readmode, 'mr.timecreated DESC', (int)$params['limitfrom'], (int)$params['limitnum']);
            $payload = [];
            foreach ($messages ?: [] as $message) {
                $payload[] = [
                    'id' => (int)$message->id,
                    'useridfrom' => (int)$message->useridfrom,
                    'useridto' => (int)$message->useridto,
                    'subject' => (string)($message->subject ?? ''),
                    'fullmessage' => (string)($message->fullmessage ?? ''),
                    'smallmessage' => (string)($message->smallmessage ?? ''),
                    'component' => (string)($message->component ?? ''),
                    'eventtype' => (string)($message->eventtype ?? ''),
                    'timecreated' => (int)($message->timecreated ?? 0),
                    'timeread' => (int)($message->timeread ?? 0),
                    'read' => !empty($message->timeread) ? 1 : 0,
                ];
            }

            $response = self::response_ok($auditid, [
                'limitfrom' => (int)$params['limitfrom'],
                'limitnum' => (int)$params['limitnum'],
                'notifications' => $payload,
            ]);
            self::audit($USER->id, 'notifications_list_my', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'notifications_list_my_failed', $e->getMessage(), false, false, [
                'limitfrom' => (int)$params['limitfrom'],
                'limitnum' => (int)$params['limitnum'],
                'notifications' => [],
            ]);
            self::audit($USER->id, 'notifications_list_my', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for notifications_list_my.
     *
     * @return \core_external\external_description
     */
    public static function notifications_list_my_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'limitfrom' => new external_value(PARAM_INT, 'Pagination offset'),
                'limitnum' => new external_value(PARAM_INT, 'Page size'),
                'notifications' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Notification id'),
                        'useridfrom' => new external_value(PARAM_INT, 'Sender user id'),
                        'useridto' => new external_value(PARAM_INT, 'Recipient user id'),
                        'subject' => new external_value(PARAM_RAW, 'Subject'),
                        'fullmessage' => new external_value(PARAM_RAW, 'Full message'),
                        'smallmessage' => new external_value(PARAM_RAW, 'Small message'),
                        'component' => new external_value(PARAM_TEXT, 'Component'),
                        'eventtype' => new external_value(PARAM_TEXT, 'Event type'),
                        'timecreated' => new external_value(PARAM_INT, 'Created time'),
                        'timeread' => new external_value(PARAM_INT, 'Read time'),
                        'read' => new external_value(PARAM_BOOL, 'Read flag'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for question_categories_list.
     *
     * @return external_function_parameters
     */
    public static function question_categories_list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Optional course id', VALUE_DEFAULT, 0),
            'contextid' => new external_value(PARAM_INT, 'Optional context id', VALUE_DEFAULT, 0),
            'parentid' => new external_value(PARAM_INT, 'Optional parent category id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List question categories in a course/context.
     *
     * @param int $courseid
     * @param int $contextid
     * @param int $parentid
     * @return array
     */
    public static function question_categories_list(int $courseid, int $contextid, int $parentid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::question_categories_list_parameters(), [
            'courseid' => $courseid,
            'contextid' => $contextid,
            'parentid' => $parentid,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            if (!empty($params['courseid']) && !empty($params['contextid'])) {
                throw new \invalid_parameter_exception('Use either courseid or contextid, not both.');
            }

            if (!empty($params['courseid'])) {
                $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
                $targetcontext = \context_course::instance($course->id, MUST_EXIST);
                $contextids = self::course_question_context_ids($course);
            } else if (!empty($params['contextid'])) {
                $targetcontext = \context::instance_by_id($params['contextid'], MUST_EXIST);
                $contextids = [(int)$targetcontext->id];
            } else {
                $targetcontext = self::restricted_context();
                $contextids = [(int)$targetcontext->id];
            }

            self::validate_context($targetcontext);
            require_capability('moodle/question:useall', $targetcontext);

            list($contextsql, $contextparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_QM);
            $where = ["contextid $contextsql"];
            $sqlparams = $contextparams;
            if (!empty($params['parentid'])) {
                $where[] = 'parent = ?';
                $sqlparams[] = (int)$params['parentid'];
            }

            $categories = $DB->get_records_select(
                'question_categories',
                implode(' AND ', $where),
                $sqlparams,
                'contextid ASC, parent ASC, sortorder ASC, id ASC'
            );
            $payload = [];
            foreach ($categories as $category) {
                $payload[] = [
                    'id' => (int)$category->id,
                    'name' => (string)$category->name,
                    'contextid' => (int)$category->contextid,
                    'parentid' => (int)$category->parent,
                    'sortorder' => (int)$category->sortorder,
                    'idnumber' => (string)($category->idnumber ?? ''),
                ];
            }

            $response = self::response_ok($auditid, [
                'contextid' => (int)$targetcontext->id,
                'categories' => $payload,
            ]);
            self::audit($USER->id, 'question_categories_list', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'question_categories_failed', $e->getMessage());
            self::audit($USER->id, 'question_categories_list', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for question_categories_list.
     *
     * @return \core_external\external_description
     */
    public static function question_categories_list_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'contextid' => new external_value(PARAM_INT, 'Context id'),
                'categories' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Category id'),
                        'name' => new external_value(PARAM_RAW, 'Category name'),
                        'contextid' => new external_value(PARAM_INT, 'Context id'),
                        'parentid' => new external_value(PARAM_INT, 'Parent category id'),
                        'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                        'idnumber' => new external_value(PARAM_RAW, 'Category idnumber'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for questionbank_search.
     *
     * @return external_function_parameters
     */
    public static function questionbank_search_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Optional search text', VALUE_DEFAULT, ''),
            'courseid' => new external_value(PARAM_INT, 'Optional course id', VALUE_DEFAULT, 0),
            'categoryid' => new external_value(PARAM_INT, 'Optional category id', VALUE_DEFAULT, 0),
            'recurse' => new external_value(PARAM_BOOL, 'Include subcategories', VALUE_DEFAULT, false),
            'qtypes' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Question type'),
                'Question types to include',
                VALUE_DEFAULT,
                []
            ),
            'limit' => new external_value(PARAM_INT, 'Maximum rows to return', VALUE_DEFAULT, 50),
        ]);
    }

    /**
     * Search questions by text/category/course.
     *
     * @param string $query
     * @param int $courseid
     * @param int $categoryid
     * @param bool $recurse
     * @param array $qtypes
     * @param int $limit
     * @return array
     */
    public static function questionbank_search(
        string $query,
        int $courseid,
        int $categoryid,
        bool $recurse,
        array $qtypes,
        int $limit
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::questionbank_search_parameters(), [
            'query' => $query,
            'courseid' => $courseid,
            'categoryid' => $categoryid,
            'recurse' => $recurse,
            'qtypes' => $qtypes,
            'limit' => $limit,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            if (!empty($params['courseid']) && !empty($params['categoryid'])) {
                throw new \invalid_parameter_exception('Use either courseid or categoryid, not both.');
            }

            $limitnum = (int)$params['limit'];
            if ($limitnum < 1 || $limitnum > 200) {
                throw new \invalid_parameter_exception('Limit must be between 1 and 200.');
            }

            $categoryids = [];
            $targetcontext = null;
            if (!empty($params['categoryid'])) {
                $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
                $targetcontext = \context::instance_by_id($category->contextid, MUST_EXIST);
                $categoryids = $params['recurse'] ? question_categorylist($category->id) : [$category->id];
            } else if (!empty($params['courseid'])) {
                $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
                $targetcontext = \context_course::instance($course->id, MUST_EXIST);
                $contextids = self::course_question_context_ids($course);
                list($contextsql, $contextparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_QM);
                $categoryids = $DB->get_fieldset_select('question_categories', 'id', "contextid $contextsql", $contextparams);
            } else {
                $targetcontext = self::restricted_context();
                $categoryids = array_keys($DB->get_records('question_categories', ['contextid' => $targetcontext->id], '', 'id'));
            }

            self::validate_context($targetcontext);
            require_capability('moodle/question:useall', $targetcontext);

            if (empty($categoryids)) {
                $response = self::response_ok($auditid, ['questions' => []]);
                self::audit($USER->id, 'questionbank_search', true, $auditid, $params, $response);
                return $response;
            }

            $sql = "SELECT q.id, q.name, q.qtype, q.defaultmark, q.timecreated, q.timemodified,
                           qbe.questioncategoryid, qc.name AS categoryname
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid";
            $where = [
                'q.parent = 0',
                "qv.status = 'ready'",
                'qbe.questioncategoryid IN (' . implode(',', array_fill(0, count($categoryids), '?')) . ')',
                'NOT EXISTS (
                    SELECT 1
                      FROM {question_versions} qv2
                     WHERE qv2.questionbankentryid = qv.questionbankentryid
                       AND qv.version < qv2.version
                )',
            ];
            $sqlparams = $categoryids;

            $querytext = trim($params['query']);
            if ($querytext !== '') {
                $where[] = $DB->sql_like('q.name', '?', false, false) . ' OR ' .
                    $DB->sql_like('q.questiontext', '?', false, false);
                $like = '%' . $querytext . '%';
                $sqlparams[] = $like;
                $sqlparams[] = $like;
            }

            $types = $params['qtypes'];
            if (!empty($types)) {
                list($qtsql, $qtparams) = $DB->get_in_or_equal($types, SQL_PARAMS_QM);
                $where[] = "q.qtype $qtsql";
                $sqlparams = array_merge($sqlparams, $qtparams);
            }

            $sql .= ' WHERE (' . implode(') AND (', $where) . ') ORDER BY q.timemodified DESC, q.id DESC';
            $records = $DB->get_records_sql($sql, $sqlparams, 0, $limitnum);

            $payload = [];
            foreach ($records as $record) {
                $payload[] = [
                    'id' => (int)$record->id,
                    'name' => (string)$record->name,
                    'qtype' => (string)$record->qtype,
                    'categoryid' => (int)$record->questioncategoryid,
                    'categoryname' => (string)$record->categoryname,
                    'defaultmark' => (float)$record->defaultmark,
                    'timecreated' => (int)$record->timecreated,
                    'timemodified' => (int)$record->timemodified,
                ];
            }

            $response = self::response_ok($auditid, ['questions' => $payload]);
            self::audit($USER->id, 'questionbank_search', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'questionbank_search_failed', $e->getMessage());
            self::audit($USER->id, 'questionbank_search', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for questionbank_search.
     *
     * @return \core_external\external_description
     */
    public static function questionbank_search_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'questions' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Question id'),
                        'name' => new external_value(PARAM_RAW, 'Question name'),
                        'qtype' => new external_value(PARAM_TEXT, 'Question type'),
                        'categoryid' => new external_value(PARAM_INT, 'Category id'),
                        'categoryname' => new external_value(PARAM_RAW, 'Category name'),
                        'defaultmark' => new external_value(PARAM_FLOAT, 'Default mark'),
                        'timecreated' => new external_value(PARAM_INT, 'Created time'),
                        'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for questionbank_pick_random.
     *
     * @return external_function_parameters
     */
    public static function questionbank_pick_random_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Question category id', VALUE_REQUIRED),
            'count' => new external_value(PARAM_INT, 'Number of questions to pick', VALUE_REQUIRED),
            'recurse' => new external_value(PARAM_BOOL, 'Include subcategories', VALUE_DEFAULT, false),
            'seed' => new external_value(PARAM_INT, 'Optional random seed', VALUE_DEFAULT, 0),
            'qtypes' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Question type'),
                'Question types to include',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Pick random questions from a category.
     *
     * @param int $categoryid
     * @param int $count
     * @param bool $recurse
     * @param int $seed
     * @param array $qtypes
     * @return array
     */
    public static function questionbank_pick_random(int $categoryid, int $count, bool $recurse, int $seed, array $qtypes): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::questionbank_pick_random_parameters(), [
            'categoryid' => $categoryid,
            'count' => $count,
            'recurse' => $recurse,
            'seed' => $seed,
            'qtypes' => $qtypes,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        if ($params['count'] < 1 || $params['count'] > self::MAX_RANDOM_PICK) {
            $response = self::response_error(
                $auditid,
                'invalid_count',
                'Count must be between 1 and ' . self::MAX_RANDOM_PICK . '.',
                false,
                false,
                ['available' => 0, 'picked' => []]
            );
            self::audit($USER->id, 'questionbank_pick_random', false, $auditid, $params, $response);
            return $response;
        }

        try {
            $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
            $catcontext = \context::instance_by_id($category->contextid, MUST_EXIST);
            self::validate_context($catcontext);
            require_capability('moodle/question:useall', $catcontext);

            $categoryids = $params['recurse'] ? question_categorylist($category->id) : [$category->id];
            $types = $params['qtypes'];
            if (empty($types)) {
                $types = ['multichoice', 'multichoiceset'];
            }

            list($qcsql, $qcparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'qc');
            list($qtsql, $qtparams) = $DB->get_in_or_equal($types, SQL_PARAMS_NAMED, 'qt');
            $sql = "SELECT q.id, q.name, q.qtype, qbe.questioncategoryid
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                     WHERE qbe.questioncategoryid $qcsql
                       AND q.parent = 0
                       AND qv.status = 'ready'
                       AND q.qtype $qtsql
                       AND NOT EXISTS (SELECT 1
                                         FROM {question_versions} qv2
                                        WHERE qv2.questionbankentryid = qv.questionbankentryid
                                          AND qv.version < qv2.version)";
            $records = $DB->get_records_sql($sql, array_merge($qcparams, $qtparams));

            $items = array_values($records);
            if (!empty($params['seed'])) {
                mt_srand((int)$params['seed']);
            }
            shuffle($items);

            $available = count($items);
            $picked = array_slice($items, 0, min($params['count'], $available));
            $payload = [];
            foreach ($picked as $record) {
                $payload[] = [
                    'id' => (int)$record->id,
                    'name' => (string)$record->name,
                    'qtype' => (string)$record->qtype,
                    'categoryid' => (int)$record->questioncategoryid,
                ];
            }

            $response = self::response_ok($auditid, [
                'available' => $available,
                'picked' => $payload,
            ]);
            self::audit($USER->id, 'questionbank_pick_random', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'pick_random_failed',
                $e->getMessage(),
                false,
                false,
                ['available' => 0, 'picked' => []]
            );
            self::audit($USER->id, 'questionbank_pick_random', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for questionbank_pick_random.
     *
     * @return \core_external\external_description
     */
    public static function questionbank_pick_random_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'available' => new external_value(PARAM_INT, 'Available questions'),
                'picked' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Question id'),
                        'name' => new external_value(PARAM_RAW, 'Question name'),
                        'qtype' => new external_value(PARAM_TEXT, 'Question type'),
                        'categoryid' => new external_value(PARAM_INT, 'Category id'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for questions_render_html.
     *
     * @return external_function_parameters
     */
    public static function questions_render_html_parameters(): external_function_parameters {
        return new external_function_parameters([
            'question_ids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Question id'),
                'Question ids to render'
            ),
            'shuffle_answers' => new external_value(PARAM_BOOL, 'Shuffle answer order', VALUE_DEFAULT, false),
            'seed' => new external_value(PARAM_INT, 'Optional seed for shuffling', VALUE_DEFAULT, 0),
            'show_correction' => new external_value(PARAM_BOOL, 'Include correct-answer markers', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Render questions to HTML.
     *
     * @param array $questionids
     * @param bool $shuffleanswers
     * @param int $seed
     * @param bool $showcorrection
     * @return array
     */
    public static function questions_render_html(array $questionids, bool $shuffleanswers, int $seed, bool $showcorrection): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::questions_render_html_parameters(), [
            'question_ids' => $questionids,
            'shuffle_answers' => $shuffleanswers,
            'seed' => $seed,
            'show_correction' => $showcorrection,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        $ordered = [];
        $seen = [];
        foreach ($params['question_ids'] as $qid) {
            $qid = (int)$qid;
            if ($qid > 0 && empty($seen[$qid])) {
                $ordered[] = $qid;
                $seen[$qid] = true;
            }
        }

        if (count($ordered) > self::MAX_QUESTIONS_PER_RENDER) {
            $response = self::response_error(
                $auditid,
                'too_many_questions',
                'Too many questions. Max ' . self::MAX_QUESTIONS_PER_RENDER . ' per call.',
                false,
                false,
                ['questions' => []]
            );
            self::audit($USER->id, 'questions_render_html', false, $auditid, $params, $response);
            return $response;
        }

        try {
            $questions = $DB->get_records_list('question', 'id', $ordered);
            if (count($questions) !== count($ordered)) {
                $missing = array_diff($ordered, array_keys($questions));
                $response = self::response_error(
                    $auditid,
                    'missing_questions',
                    'Missing questions: ' . implode(',', $missing),
                    false,
                    false,
                    ['questions' => []]
                );
                self::audit($USER->id, 'questions_render_html', false, $auditid, $params, $response);
                return $response;
            }

            $questioncontexts = $DB->get_records_sql_menu(
                "SELECT q.id, qc.contextid
                   FROM {question} q
                   JOIN {question_versions} qv ON qv.questionid = q.id
                   JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                   JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  WHERE q.id IN (" . implode(',', array_fill(0, count($ordered), '?')) . ")",
                $ordered
            );

            if (!get_question_options($questions)) {
                throw new \moodle_exception('Could not load question options');
            }

            $rendered = [];
            $number = 1;
            foreach ($ordered as $qid) {
                $question = $questions[$qid];
                if (empty($questioncontexts[$qid])) {
                    throw new \moodle_exception('Question context missing for question ' . $qid);
                }
                $question->contextid = (int)$questioncontexts[$qid];
                $qcontext = \context::instance_by_id($question->contextid, MUST_EXIST);
                self::validate_context($qcontext);
                question_require_capability_on($question, 'use');

                $questionhtml = self::render_question_text($question, $qcontext);
                $questionplain = trim(html_to_text((string)$questionhtml, 0));

                $answers = [];
                $correctlabels = [];
                if (!empty($question->options->answers) && is_array($question->options->answers)) {
                    $answerids = array_keys($question->options->answers);
                    if ($params['shuffle_answers']) {
                        if (!empty($params['seed'])) {
                            mt_srand((int)$params['seed'] + (int)$question->id);
                        }
                        shuffle($answerids);
                    }
                    $answerindex = 0;
                    foreach ($answerids as $answerid) {
                        $answerrecord = $question->options->answers[$answerid];
                        $answerhtml = self::render_answer_text($question, $answerrecord, $qcontext);
                        $plain = trim(html_to_text((string)$answerhtml, 0));
                        $label = self::answer_label($answerindex);
                        $iscorrect = !empty($answerrecord->fraction) && $answerrecord->fraction > 0;
                        if ($iscorrect) {
                            $correctlabels[] = $label;
                        }
                        $displayhtml = $answerhtml;
                        if ($params['show_correction'] && $iscorrect) {
                            $displayhtml = html_writer::tag('strong', $displayhtml);
                        }
                        $answers[] = [
                            'label' => $label,
                            'html' => $displayhtml,
                            'plain' => $plain,
                            'fraction' => isset($answerrecord->fraction) ? (float)$answerrecord->fraction : 0.0,
                            'is_correct' => $iscorrect ? 1 : 0,
                        ];
                        $answerindex++;
                    }
                }

                $answerhtml = '';
                if (!empty($answers)) {
                    $items = '';
                    foreach ($answers as $answer) {
                        $items .= html_writer::tag(
                            'li',
                            html_writer::span($answer['label'] . '.', 'answer-label') . $answer['html'],
                            ['class' => 'answer-item']
                        );
                    }
                    $answerhtml = html_writer::tag('ol', $items, ['class' => 'answers']);
                }

                $header = html_writer::tag(
                    'div',
                    html_writer::span((string)$number . '.', 'question-number') .
                    html_writer::span($questionhtml, 'question-text'),
                    ['class' => 'question-header']
                );
                $fullhtml = html_writer::tag('div', $header . $answerhtml, ['class' => 'question']);
                $fullplain = trim($questionplain . "\n" . implode("\n", array_map(
                    static fn($a) => $a['label'] . '. ' . $a['plain'],
                    $answers
                )));

                $rendered[] = [
                    'id' => (int)$question->id,
                    'number' => $number,
                    'name' => (string)$question->name,
                    'qtype' => (string)$question->qtype,
                    'text_html' => $questionhtml,
                    'text_plain' => $questionplain,
                    'answers' => $answers,
                    'correct_labels' => $correctlabels,
                    'html' => $fullhtml,
                    'plain' => $fullplain,
                ];

                $number++;
            }

            $response = self::response_ok($auditid, [
                'questions' => $rendered,
            ]);
            self::audit($USER->id, 'questions_render_html', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'render_failed',
                $e->getMessage(),
                false,
                false,
                ['questions' => []]
            );
            self::audit($USER->id, 'questions_render_html', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for questions_render_html.
     *
     * @return \core_external\external_description
     */
    public static function questions_render_html_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'questions' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Question id'),
                        'number' => new external_value(PARAM_INT, 'Display number'),
                        'name' => new external_value(PARAM_RAW, 'Question name'),
                        'qtype' => new external_value(PARAM_TEXT, 'Question type'),
                        'text_html' => new external_value(PARAM_RAW, 'Question text (HTML)'),
                        'text_plain' => new external_value(PARAM_TEXT, 'Question text (plain)'),
                        'answers' => new external_multiple_structure(
                            new external_single_structure([
                                'label' => new external_value(PARAM_TEXT, 'Answer label'),
                                'html' => new external_value(PARAM_RAW, 'Answer HTML'),
                                'plain' => new external_value(PARAM_TEXT, 'Answer plain text'),
                                'fraction' => new external_value(PARAM_FLOAT, 'Fraction'),
                                'is_correct' => new external_value(PARAM_BOOL, 'Correct answer flag'),
                            ])
                        ),
                        'correct_labels' => new external_multiple_structure(
                            new external_value(PARAM_TEXT, 'Correct answer labels')
                        ),
                        'html' => new external_value(PARAM_RAW, 'Rendered question HTML block'),
                        'plain' => new external_value(PARAM_TEXT, 'Rendered question plain text'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_resolve_random.
     *
     * @return external_function_parameters
     */
    public static function quiz_resolve_random_parameters(): external_function_parameters {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'Quiz id', VALUE_REQUIRED),
            'copies' => new external_value(PARAM_INT, 'Number of copies (attempts)', VALUE_DEFAULT, 1),
            'seed' => new external_value(PARAM_INT, 'Optional random seed', VALUE_DEFAULT, 0),
            'allowduplicates' => new external_value(PARAM_BOOL, 'Allow duplicates across copies', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Resolve random questions in a quiz by creating temporary attempts.
     *
     * @param int $quizid
     * @param int $copies
     * @param int $seed
     * @param bool $allowduplicates
     * @return array
     */
    public static function quiz_resolve_random(int $quizid, int $copies, int $seed, bool $allowduplicates): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::quiz_resolve_random_parameters(), [
            'quizid' => $quizid,
            'copies' => $copies,
            'seed' => $seed,
            'allowduplicates' => $allowduplicates,
        ]);

        self::restricted_context();

        $auditid = self::uuid_v4();

        if ($params['copies'] < 1 || $params['copies'] > self::MAX_QUIZ_COPIES) {
            $response = self::response_error(
                $auditid,
                'invalid_copies',
                'Copies must be between 1 and ' . self::MAX_QUIZ_COPIES . '.',
                false,
                false,
                ['quizid' => (int)$params['quizid'], 'copies' => []]
            );
            self::audit($USER->id, 'quiz_resolve_random', false, $auditid, $params, $response);
            return $response;
        }

        try {
            $quizsettings = \mod_quiz\quiz_settings::create((int)$params['quizid']);
            $quizcontext = $quizsettings->get_context();
            self::validate_context($quizcontext);
            require_capability('mod/quiz:preview', $quizcontext);

            $quizrecord = $DB->get_record('quiz', ['id' => (int)$params['quizid']], '*', MUST_EXIST);
            if ((float)$quizrecord->sumgrades <= 0.0) {
                $quizsettings->get_grade_calculator()->recompute_quiz_sumgrades();
            }

            $copiesout = [];
            for ($i = 1; $i <= $params['copies']; $i++) {
                if (!empty($params['seed'])) {
                    mt_srand((int)$params['seed'] + $i);
                }
                $attempt = null;
                try {
                    $attempt = quiz_prepare_and_start_new_attempt($quizsettings, 1, null, true);
                    $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
                    $slots = $quba->get_slots();
                    $questionids = [];
                    foreach ($slots as $slot) {
                        $qa = $quba->get_question_attempt($slot);
                        $question = $qa->get_question(false);
                        if ($question && !empty($question->id)) {
                            $questionids[] = (int)$question->id;
                        }
                    }
                    $copiesout[] = [
                        'index' => $i,
                        'question_ids' => $questionids,
                    ];
                } finally {
                    if ($attempt) {
                        quiz_delete_attempt($attempt, $quizsettings->get_quiz());
                    }
                }
            }

            $response = self::response_ok($auditid, [
                'quizid' => (int)$params['quizid'],
                'copies' => $copiesout,
            ]);
            self::audit($USER->id, 'quiz_resolve_random', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'resolve_random_failed',
                $e->getMessage(),
                false,
                false,
                ['quizid' => (int)$params['quizid'], 'copies' => []]
            );
            self::audit($USER->id, 'quiz_resolve_random', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_resolve_random.
     *
     * @return \core_external\external_description
     */
    public static function quiz_resolve_random_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                'copies' => new external_multiple_structure(
                    new external_single_structure([
                        'index' => new external_value(PARAM_INT, 'Copy index'),
                        'question_ids' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'Question id')
                        ),
                    ])
                ),
            ])
        );
    }

    /**
     * Decode a stored JSON list into strings.
     *
     * @param string|null $json
     * @return array
     */
    private static function decode_string_list(?string $json): array {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $seen = [];
        $values = [];
        foreach ($decoded as $item) {
            $value = trim((string)$item);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $values[] = $value;
        }
        return $values;
    }

    /**
     * Normalize a list of strings.
     *
     * @param array $items
     * @return array
     */
    private static function unique_string_list(array $items): array {
        $seen = [];
        $values = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $values[] = $value;
        }
        return $values;
    }

    /**
     * Return true when a text contains a phrase, with mb support when available.
     *
     * @param string $text
     * @param string $phrase
     * @return bool
     */
    private static function practice_text_contains(string $text, string $phrase): bool {
        $text = trim($text);
        $phrase = trim($phrase);
        if ($text === '' || $phrase === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($text, $phrase) !== false;
        }
        return stripos($text, $phrase) !== false;
    }

    /**
     * Resolve Moodle question tag ids for explicitly requested teaching tags.
     *
     * @param array $tags
     * @return array
     */
    private static function practice_question_tag_ids(array $tags): array {
        global $DB;

        $tags = self::unique_string_list($tags);
        if (empty($tags)) {
            return [];
        }

        $tagids = [];
        foreach ($tags as $index => $tag) {
            $record = $DB->get_record_sql(
                "SELECT t.id, COUNT(ti.id) AS usecount
                   FROM {tag} t
                   JOIN {tag_instance} ti ON ti.tagid = t.id
                  WHERE ti.component = :tagcomponent$index
                    AND ti.itemtype = :tagitemtype$index
                    AND (t.rawname = :tagraw$index OR t.name = :tagname$index)
               GROUP BY t.id
               ORDER BY usecount DESC, t.id ASC",
                [
                    'tagcomponent' . $index => 'core_question',
                    'tagitemtype' . $index => 'question',
                    'tagraw' . $index => $tag,
                    'tagname' . $index => core_text::strtolower($tag),
                ],
                IGNORE_MULTIPLE
            );
            if ($record) {
                $tagids[] = (int)$record->id;
            }
        }

        return array_values(array_unique(array_filter($tagids)));
    }

    /**
     * Add names, aliases, and tags from standard KG/QG tables.
     *
     * @param array $kgids
     * @param array $qgids
     * @return array
     */
    private static function practice_standard_tags(array $kgids, array $qgids): array {
        global $DB;

        $tags = [];
        if (!empty($kgids) && self::table_exists('local_mathstate_std_kp')) {
            [$insql, $params] = $DB->get_in_or_equal(self::unique_string_list($kgids), SQL_PARAMS_NAMED, 'stdkg');
            $records = $DB->get_records_select(
                'local_mathstate_std_kp',
                "kg_id $insql",
                $params,
                '',
                'kg_id,name,aliases_json,tags_json,chapter,section'
            );
            foreach ($records as $record) {
                $tags[] = (string)$record->name;
                $tags[] = (string)$record->chapter;
                $tags[] = (string)$record->section;
                $tags = array_merge($tags, self::decode_string_list((string)$record->aliases_json));
                $tags = array_merge($tags, self::decode_string_list((string)$record->tags_json));
            }
        }

        if (!empty($qgids) && self::table_exists('local_mathstate_std_qtype')) {
            [$insql, $params] = $DB->get_in_or_equal(self::unique_string_list($qgids), SQL_PARAMS_NAMED, 'stdqg');
            $records = $DB->get_records_select(
                'local_mathstate_std_qtype',
                "qg_id $insql",
                $params,
                '',
                'qg_id,name,tags_json,chapter'
            );
            foreach ($records as $record) {
                $tags[] = (string)$record->name;
                $tags[] = (string)$record->chapter;
                $tags = array_merge($tags, self::decode_string_list((string)$record->tags_json));
            }
        }

        return self::unique_string_list($tags);
    }

    /**
     * Derive searchable tags from a Moodle resource or activity title.
     *
     * @param string $title
     * @return array
     */
    private static function practice_title_tags(string $title): array {
        $title = trim(str_replace(['视频课', '课后测试', '课后练习'], '', $title));
        if ($title === '') {
            return [];
        }
        $tags = [$title];
        $parts = preg_split('/[\s;；,，、:：()（）\-]+/u', $title, 0, PREG_SPLIT_NO_EMPTY);
        foreach ($parts ?: [] as $part) {
            $part = trim(preg_replace('/^\d+(\.\d+)?/u', '', (string)$part));
            if ($part !== '') {
                $tags[] = $part;
            }
        }
        return self::unique_string_list($tags);
    }

    /**
     * Load resource-map facts for a course resource.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string $lessonkey
     * @return array
     */
    private static function practice_resource_map_facts(int $courseid, int $cmid, string $lessonkey): array {
        global $DB;

        $facts = [
            'kg_ids' => [],
            'qg_ids' => [],
            'lesson_keys' => [],
            'resources' => [],
        ];

        if (!self::table_exists('local_oc_shell_resource_map')) {
            return $facts;
        }

        $where = ['courseid = :courseid'];
        $params = ['courseid' => $courseid];
        if ($cmid > 0) {
            $where[] = 'cmid = :cmid';
            $params['cmid'] = $cmid;
        }
        if ($lessonkey !== '') {
            $where[] = 'lesson_key = :lessonkey';
            $params['lessonkey'] = $lessonkey;
        }

        $records = $DB->get_records_select(
            'local_oc_shell_resource_map',
            implode(' AND ', $where),
            $params,
            'cmid ASC, id ASC',
            'id,courseid,cmid,title,lesson_key,kg_ids_json,qg_ids_json,review_status'
        );

        foreach ($records as $record) {
            $kgids = self::decode_string_list((string)$record->kg_ids_json);
            $qgids = self::decode_string_list((string)$record->qg_ids_json);
            $facts['kg_ids'] = array_merge($facts['kg_ids'], $kgids);
            $facts['qg_ids'] = array_merge($facts['qg_ids'], $qgids);
            if (!empty($record->lesson_key)) {
                $facts['lesson_keys'][] = (string)$record->lesson_key;
            }
            $facts['resources'][] = [
                'id' => (int)$record->id,
                'courseid' => (int)$record->courseid,
                'cmid' => (int)$record->cmid,
                'title' => (string)$record->title,
                'lesson_key' => (string)$record->lesson_key,
                'kg_ids' => $kgids,
                'qg_ids' => $qgids,
                'review_status' => (string)$record->review_status,
            ];
        }

        $facts['kg_ids'] = self::unique_string_list($facts['kg_ids']);
        $facts['qg_ids'] = self::unique_string_list($facts['qg_ids']);
        $facts['lesson_keys'] = self::unique_string_list($facts['lesson_keys']);
        return $facts;
    }

    /**
     * Pick mapped questions for a practice quiz.
     *
     * @param \stdClass $course
     * @param array $categoryids
     * @param array $kgids
     * @param array $qgids
     * @param array $tags
     * @param int $limit
     * @param int $seed
     * @param array $requiredtags
     * @param array $questiontagids
     * @return array
     */
    private static function practice_pick_questions(
        \stdClass $course,
        array $categoryids,
        array $kgids,
        array $qgids,
        array $tags,
        int $limit,
        int $seed,
        array $requiredtags = [],
        array $questiontagids = []
    ): array {
        global $DB;

        $contextids = self::course_question_context_ids($course);
        if (empty($contextids)) {
            return [];
        }

        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $categorywhere = "qc.contextid $contextsql";
        $params = $contextparams;
        $categoryids = array_values(array_unique(array_filter(array_map('intval', $categoryids), static function($value): bool {
            return $value > 0;
        })));
        if (!empty($categoryids)) {
            [$categorysql, $categoryparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'pcat');
            $categorywhere = "qbe.questioncategoryid $categorysql";
            $params = $categoryparams;
        }

        $where = [
            $categorywhere,
            'q.parent = 0',
            "qv.status = 'ready'",
            'NOT EXISTS (
                SELECT 1
                  FROM {question_versions} qv2
                 WHERE qv2.questionbankentryid = qv.questionbankentryid
                   AND qv.version < qv2.version
            )',
        ];

        $criteria = [];
        $qgids = self::unique_string_list($qgids);
        if (!empty($qgids) && self::table_exists('local_mathstate_question_map')) {
            [$qgsql, $qgparams] = $DB->get_in_or_equal($qgids, SQL_PARAMS_NAMED, 'qg');
            $criteria[] = "qm.qg_id $qgsql";
            $params = array_merge($params, $qgparams);
        }

        $kgids = self::unique_string_list($kgids);
        if (!empty($kgids) && self::table_exists('local_mathstate_question_map')) {
            $kgparts = [];
            foreach ($kgids as $index => $kgid) {
                $key = 'kg' . $index;
                $kgparts[] = $DB->sql_like('qm.kg_ids_json', ':' . $key, false, false);
                $params[$key] = '%' . $kgid . '%';
            }
            if (!empty($kgparts)) {
                $criteria[] = '(' . implode(' OR ', $kgparts) . ')';
            }
        }

        $requiredtags = self::unique_string_list($requiredtags);
        $questiontagids = array_values(array_unique(array_filter(array_map('intval', $questiontagids), static function($value): bool {
            return $value > 0;
        })));
        if (!empty($requiredtags)) {
            $requiredparts = [];
            foreach ($requiredtags as $index => $tag) {
                $key = 'reqtag' . $index;
                $requiredparts[] = '(' . $DB->sql_like('q.name', ':' . $key, false, false) . ' OR ' .
                    $DB->sql_like('q.questiontext', ':' . $key . 't', false, false) . ')';
                $params[$key] = '%' . $tag . '%';
                $params[$key . 't'] = '%' . $tag . '%';
            }
            $textcondition = '(' . implode(' AND ', $requiredparts) . ')';

            if (!empty($questiontagids)) {
                [$tagidsql, $tagidparams] = $DB->get_in_or_equal($questiontagids, SQL_PARAMS_NAMED, 'ptag');
                $tagcondition = "q.id IN (
                    SELECT ti.itemid
                      FROM {tag_instance} ti
                     WHERE ti.itemtype = :ptagitemtype
                       AND ti.component = :ptagcomponent
                       AND ti.tagid $tagidsql
                  GROUP BY ti.itemid
                    HAVING COUNT(DISTINCT ti.tagid) = :ptagcount
                )";
                $params = array_merge($params, [
                    'ptagitemtype' => 'question',
                    'ptagcomponent' => 'core_question',
                    'ptagcount' => count($questiontagids),
                ], $tagidparams);
                $criteria[] = '(' . $tagcondition . ' OR ' . $textcondition . ')';
            } else {
                $criteria[] = $textcondition;
            }
        }

        $tags = self::unique_string_list(array_diff($tags, $requiredtags));
        if (!empty($tags)) {
            $tagparts = [];
            foreach ($tags as $index => $tag) {
                $key = 'tag' . $index;
                $tagparts[] = $DB->sql_like('q.name', ':' . $key, false, false);
                $tagparts[] = $DB->sql_like('q.questiontext', ':' . $key . 't', false, false);
                $params[$key] = '%' . $tag . '%';
                $params[$key . 't'] = '%' . $tag . '%';
            }
            $criteria[] = '(' . implode(' OR ', $tagparts) . ')';
        }

        if (!empty($criteria)) {
            $where[] = '(' . implode(' OR ', $criteria) . ')';
        }

        $hasquestionmap = self::table_exists('local_mathstate_question_map');
        $mapsql = $hasquestionmap
            ? 'LEFT JOIN {local_mathstate_question_map} qm ON qm.questionid = q.id'
            : '';
        $mapselect = $hasquestionmap ? 'qm.qg_id, qm.kg_ids_json' : "'' AS qg_id, '' AS kg_ids_json";
        $sql = "SELECT q.id, q.name, q.questiontext, q.qtype, q.defaultmark, qbe.questioncategoryid, qc.contextid, $mapselect
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             $mapsql
                 WHERE (" . implode(') AND (', $where) . ')
              ORDER BY q.timemodified DESC, q.id DESC';

        $records = array_values($DB->get_records_sql($sql, $params, 0, max($limit * 4, 50)));
        $tagmatchcounts = [];
        if (!empty($records) && !empty($questiontagids)) {
            $questionids = array_values(array_unique(array_map(static function($record): int {
                return (int)$record->id;
            }, $records)));
            [$qidsql, $qidparams] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'pmq');
            [$tagmatchsql, $tagmatchparams] = $DB->get_in_or_equal($questiontagids, SQL_PARAMS_NAMED, 'pmt');
            $tagmatchrecords = $DB->get_records_sql(
                "SELECT ti.itemid, COUNT(DISTINCT ti.tagid) AS tagcount
                   FROM {tag_instance} ti
                  WHERE ti.itemtype = :pmitemtype
                    AND ti.component = :pmcomponent
                    AND ti.itemid $qidsql
                    AND ti.tagid $tagmatchsql
               GROUP BY ti.itemid",
                array_merge([
                    'pmitemtype' => 'question',
                    'pmcomponent' => 'core_question',
                ], $qidparams, $tagmatchparams)
            );
            foreach ($tagmatchrecords as $itemid => $tagmatchrecord) {
                $tagmatchcounts[(int)$itemid] = (int)$tagmatchrecord->tagcount;
            }
        }
        if ($seed > 0) {
            mt_srand($seed);
            shuffle($records);
        }

        $scored = [];
        foreach ($records as $record) {
            $score = 0;
            $recordqgid = (string)($record->qg_id ?? '');
            if ($recordqgid !== '' && in_array($recordqgid, $qgids, true)) {
                $score += 100;
            }
            $recordkgids = self::decode_string_list((string)($record->kg_ids_json ?? ''));
            if (!empty(array_intersect($recordkgids, $kgids))) {
                $score += 80;
            }
            $matchedtagcount = (int)($tagmatchcounts[(int)$record->id] ?? 0);
            if ($matchedtagcount > 0) {
                $score += 40 + ($matchedtagcount * 10);
            }
            foreach ($tags as $tag) {
                if (self::practice_text_contains((string)$record->name, $tag)) {
                    $score += 30;
                } else if (self::practice_text_contains((string)$record->questiontext, $tag)) {
                    $score += 5;
                }
            }
            foreach ($requiredtags as $tag) {
                if (self::practice_text_contains((string)$record->name, $tag)) {
                    $score += 30;
                } else if (self::practice_text_contains((string)$record->questiontext, $tag)) {
                    $score += 5;
                }
            }
            if ($score <= 0 && (!empty($criteria))) {
                continue;
            }
            $record->_practice_score = $score;
            $scored[] = $record;
        }

        usort($scored, static function($a, $b): int {
            return [(int)$b->_practice_score, (int)$b->id] <=> [(int)$a->_practice_score, (int)$a->id];
        });

        $picked = [];
        foreach ($scored as $record) {
            $qcontext = \context::instance_by_id((int)$record->contextid, MUST_EXIST);
            require_capability('moodle/question:useall', $qcontext);
            $picked[] = [
                'id' => (int)$record->id,
                'name' => (string)$record->name,
                'qtype' => (string)$record->qtype,
                'categoryid' => (int)$record->questioncategoryid,
                'defaultmark' => (float)$record->defaultmark,
                'qg_id' => (string)($record->qg_id ?? ''),
                'kg_ids' => self::decode_string_list((string)($record->kg_ids_json ?? '')),
            ];
            if (count($picked) >= $limit) {
                break;
            }
        }
        return $picked;
    }

    /**
     * Empty practice quiz payload used when validation fails before selection.
     *
     * @param array $params
     * @return array
     */
    private static function empty_practice_quiz_payload(array $params): array {
        return [
            'courseid' => (int)($params['courseid'] ?? 0),
            'cmid' => (int)($params['cmid'] ?? 0),
            'lesson_key' => trim((string)($params['lesson_key'] ?? '')),
            'quizid' => 0,
            'quiz_cmid' => 0,
            'title' => trim((string)($params['title'] ?? '')),
            'url' => '',
            'section' => max(0, (int)($params['section'] ?? 0)),
            'selection_mode' => (string)($params['selection_mode'] ?? 'fixed'),
            'requested_count' => (int)($params['count'] ?? 0),
            'created_count' => 0,
            'visible' => !empty($params['visible']),
            'questions' => [],
            'resource_map' => [
                'kg_ids' => [],
                'qg_ids' => [],
                'lesson_keys' => [],
                'resources' => [],
            ],
            'tags' => [],
            'tag_ids' => [],
            'available' => 0,
            'requested' => (int)($params['count'] ?? 0),
        ];
    }

    /**
     * Parameters for practice_quiz_create_from_resource.
     *
     * @return external_function_parameters
     */
    public static function practice_quiz_create_from_resource_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Target Moodle course id', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Optional lesson/resource cmid', VALUE_DEFAULT, 0),
            'lesson_key' => new external_value(PARAM_RAW, 'Optional lesson key', VALUE_DEFAULT, ''),
            'title' => new external_value(PARAM_TEXT, 'Optional quiz title', VALUE_DEFAULT, ''),
            'count' => new external_value(PARAM_INT, 'Number of questions to add, max 120', VALUE_DEFAULT, 5),
            'section' => new external_value(PARAM_INT, 'Course section number, 0 means infer/default', VALUE_DEFAULT, 0),
            'categoryid' => new external_value(PARAM_INT, 'Optional question category id', VALUE_DEFAULT, 0),
            'kg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'KG id'), 'KG ids', VALUE_DEFAULT, []),
            'qg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'QG id'), 'QG ids', VALUE_DEFAULT, []),
            'tags' => new external_multiple_structure(new external_value(PARAM_RAW, 'Teaching tag'), 'Teaching tags matched against Moodle question tags and title/text', VALUE_DEFAULT, []),
            'seed' => new external_value(PARAM_INT, 'Optional random seed', VALUE_DEFAULT, 0),
            'allow_partial' => new external_value(PARAM_BOOL, 'Create with fewer than count questions when necessary', VALUE_DEFAULT, false),
            'selection_mode' => new external_value(PARAM_ALPHANUMEXT, 'fixed or random_category', VALUE_DEFAULT, 'fixed'),
            'visible' => new external_value(PARAM_BOOL, 'Make the created quiz visible to students', VALUE_DEFAULT, false),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
            'categoryids' => new external_multiple_structure(new external_value(PARAM_INT, 'Question category id'), 'Extra question category ids', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Create a post-lesson practice quiz from existing mapped question-bank questions.
     *
     * @return array
     */
    public static function practice_quiz_create_from_resource(
        string $idempotency_key,
        int $courseid,
        int $cmid = 0,
        string $lesson_key = '',
        string $title = '',
        int $count = 5,
        int $section = 0,
        int $categoryid = 0,
        array $kg_ids = [],
        array $qg_ids = [],
        array $tags = [],
        int $seed = 0,
        bool $allow_partial = false,
        string $selection_mode = 'fixed',
        bool $visible = false,
        bool $dry_run = false,
        string $reason = '',
        array $categoryids = []
    ): array {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::practice_quiz_create_from_resource_parameters(), [
            'idempotency_key' => $idempotency_key,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'lesson_key' => $lesson_key,
            'title' => $title,
            'count' => $count,
            'section' => $section,
            'categoryid' => $categoryid,
            'kg_ids' => $kg_ids,
            'qg_ids' => $qg_ids,
            'tags' => $tags,
            'seed' => $seed,
            'allow_partial' => $allow_partial,
            'selection_mode' => $selection_mode,
            'visible' => $visible,
            'dry_run' => $dry_run,
            'reason' => $reason,
            'categoryids' => $categoryids,
        ]);

        $action = 'practice_quiz_create_from_resource';
        $auditid = self::uuid_v4();
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error($USER->id, $action, $params['idempotency_key'], $requesthash, (bool)$params['dry_run'], $params);
        if ($replay !== null) {
            return $replay;
        }
        $errorpayload = self::empty_practice_quiz_payload($params);

        try {
            self::restricted_context();
            $count = (int)$params['count'];
            if ($count < 1 || $count > self::MAX_PRACTICE_QUIZ_QUESTIONS) {
                $response = self::response_error(
                    $auditid,
                    'invalid_count',
                    'count must be between 1 and ' . self::MAX_PRACTICE_QUIZ_QUESTIONS . '.',
                    (bool)$params['dry_run'],
                    false,
                    $errorpayload
                );
                self::audit($USER->id, $action, false, $auditid, $params, $response);
                return $response;
            }
            $selectionmode = (string)$params['selection_mode'];
            if (!in_array($selectionmode, ['fixed', 'random_category'], true)) {
                throw new \invalid_parameter_exception('selection_mode must be fixed or random_category.');
            }
            $questioncategoryids = array_values(array_unique(array_filter(array_map('intval', array_merge(
                [(int)$params['categoryid']],
                (array)($params['categoryids'] ?? [])
            )), static function($value): bool {
                return $value > 0;
            })));

            $course = $DB->get_record('course', ['id' => (int)$params['courseid']], '*', MUST_EXIST);
            $coursecontext = \context_course::instance((int)$course->id);
            self::validate_context($coursecontext);
            require_capability('moodle/course:view', $coursecontext);
            require_capability('moodle/course:manageactivities', $coursecontext);
            require_capability('mod/quiz:addinstance', $coursecontext);

            $targetcm = null;
            if (!empty($params['cmid'])) {
                [$cmcourse, $cm] = get_course_and_cm_from_cmid((int)$params['cmid']);
                if ((int)$cmcourse->id !== (int)$course->id) {
                    throw new \invalid_parameter_exception('cmid does not belong to courseid.');
                }
                $targetcm = $cm;
                if (empty($params['section'])) {
                    $params['section'] = (int)$cm->sectionnum;
                }
            }

            $facts = self::practice_resource_map_facts((int)$course->id, (int)$params['cmid'], trim((string)$params['lesson_key']));
            $kgids = self::unique_string_list(array_merge($facts['kg_ids'], $params['kg_ids']));
            $qgids = self::unique_string_list(array_merge($facts['qg_ids'], $params['qg_ids']));
            $requiredtags = self::unique_string_list($params['tags']);
            $questiontagids = self::practice_question_tag_ids($requiredtags);
            $tags = $requiredtags;
            $tags = self::unique_string_list(array_merge($tags, self::practice_standard_tags($kgids, $qgids)));
            if (empty($tags) && $targetcm) {
                $tags = self::practice_title_tags((string)$targetcm->name);
            }
            if ($selectionmode === 'random_category' && !empty($requiredtags) && empty($questiontagids)) {
                $payload = self::empty_practice_quiz_payload($params);
                $payload['resource_map'] = $facts;
                $payload['tags'] = $tags;
                $payload['tag_ids'] = [];
                $response = self::response_error(
                    $auditid,
                    'random_tags_not_resolved',
                    'Random practice quizzes can only preserve tag filters when the requested tags exist as Moodle question tags. Use fixed mode or sync these labels into Moodle question tags first.',
                    (bool)$params['dry_run'],
                    false,
                    $payload
                );
                self::audit($USER->id, $action, false, $auditid, $params, $response);
                return $response;
            }

            $questions = self::practice_pick_questions(
                $course,
                $questioncategoryids,
                $kgids,
                $qgids,
                $tags,
                $count,
                (int)$params['seed'],
                $requiredtags,
                $questiontagids
            );
            $payload = [
                'courseid' => (int)$course->id,
                'cmid' => (int)$params['cmid'],
                'lesson_key' => trim((string)$params['lesson_key']),
                'quizid' => 0,
                'quiz_cmid' => 0,
                'title' => '',
                'url' => '',
                'section' => max(0, (int)$params['section']),
                'selection_mode' => $selectionmode,
                'requested_count' => $count,
                'created_count' => count($questions),
                'visible' => (bool)$params['visible'],
                'questions' => $questions,
                'resource_map' => $facts,
                'tags' => $tags,
                'tag_ids' => $questiontagids,
                'available' => count($questions),
                'requested' => $count,
            ];
            $errorpayload = $payload;
            if (count($questions) < $count && empty($params['allow_partial'])) {
                $response = self::response_error(
                    $auditid,
                    'not_enough_questions',
                    'Not enough mapped questions found for the requested practice quiz.',
                    (bool)$params['dry_run'],
                    false,
                    $payload
                );
                self::audit($USER->id, $action, false, $auditid, $params, $response);
                return $response;
            }
            if (empty($questions)) {
                $response = self::response_error(
                    $auditid,
                    'no_questions',
                    'No usable questions found for the requested practice quiz.',
                    (bool)$params['dry_run'],
                    false,
                    $payload
                );
                self::audit($USER->id, $action, false, $auditid, $params, $response);
                return $response;
            }

            $quiztitle = trim((string)$params['title']);
            if ($quiztitle === '') {
                $source = $targetcm ? (string)$targetcm->name : (string)$course->fullname;
                $quiztitle = '课后练习 - ' . $source;
            }
            $sectionnum = max(0, (int)$params['section']);
            $payload['title'] = $quiztitle;
            $payload['section'] = $sectionnum;

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, $payload, true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $quiz = create_module((object)[
                'modulename' => 'quiz',
                'course' => (int)$course->id,
                'section' => $sectionnum,
                'visible' => !empty($params['visible']) ? 1 : 0,
                'groupmode' => 0,
                'groupingid' => 0,
                'name' => $quiztitle,
                'introeditor' => [
                    'text' => 'Auto-created post-lesson practice quiz by local_aiagentapi.',
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ],
                'timeopen' => 0,
                'timeclose' => 0,
                'timelimit' => 0,
                'overduehandling' => 'autosubmit',
                'graceperiod' => 0,
                'preferredbehaviour' => 'deferredfeedback',
                'canredoquestions' => 0,
                'attempts' => 0,
                'attemptonlast' => 0,
                'grademethod' => QUIZ_GRADEHIGHEST,
                'decimalpoints' => 2,
                'questiondecimalpoints' => -1,
                'reviewattempt' => 1,
                'reviewcorrectness' => 1,
                'reviewmarks' => 1,
                'reviewspecificfeedback' => 1,
                'reviewgeneralfeedback' => 1,
                'reviewrightanswer' => 1,
                'reviewoverallfeedback' => 0,
                'questionsperpage' => min(5, max(1, count($questions))),
                'navmethod' => QUIZ_NAVMETHOD_FREE,
                'shuffleanswers' => 1,
                'sumgrades' => 0,
                'grade' => max(1, count($questions)),
                'quizpassword' => '',
                'subnet' => '',
                'browsersecurity' => '',
                'delay1' => 0,
                'delay2' => 0,
                'showuserpicture' => 0,
                'showblocks' => 0,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
            $createdcm = get_coursemodule_from_instance('quiz', (int)$quiz->id, (int)$course->id, false, MUST_EXIST);
            $quiz->cmid = (int)$createdcm->id;

            foreach ($questions as $question) {
                if ($selectionmode === 'fixed') {
                    quiz_add_quiz_question((int)$question['id'], $quiz, 0, max(1.0, (float)$question['defaultmark']));
                }
            }
            if ($selectionmode === 'random_category') {
                $categorycounts = [];
                foreach ($questions as $question) {
                    $qid = (int)$question['categoryid'];
                    $categorycounts[$qid] = (int)($categorycounts[$qid] ?? 0) + 1;
                }
                arsort($categorycounts);
                $randomcategoryids = array_values(array_filter(array_map('intval', array_keys($categorycounts))));
                if (empty($randomcategoryids)) {
                    throw new \moodle_exception('No question category available for random practice quiz.');
                }
                $payload['random_categoryid'] = (int)$randomcategoryids[0];
                $payload['random_categoryids'] = $randomcategoryids;
                $payload['random_tagids'] = $questiontagids;
                $quizstructure = \mod_quiz\quiz_settings::create((int)$quiz->id)->get_structure();
                foreach ($categorycounts as $randomcategoryid => $slotcount) {
                    $filtercondition = [
                        'filter' => [
                            'category' => [
                                'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                                'values' => [(int)$randomcategoryid],
                                'filteroptions' => ['includesubcategories' => false],
                            ],
                        ],
                    ];
                    if (!empty($questiontagids)) {
                        $filtercondition['filter']['qtagids'] = [
                            'jointype' => \core\output\datafilter::JOINTYPE_ALL,
                            'values' => $questiontagids,
                        ];
                    }
                    $quizstructure->add_random_questions(0, (int)$slotcount, $filtercondition);
                }
            }
            \mod_quiz\quiz_settings::create((int)$quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

            $payload['quizid'] = (int)$quiz->id;
            $payload['quiz_cmid'] = (int)$createdcm->id;
            $payload['url'] = $CFG->wwwroot . '/mod/quiz/view.php?id=' . (int)$createdcm->id;

            $response = self::response_ok($auditid, $payload);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'practice_quiz_create_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                $errorpayload
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for practice_quiz_create_from_resource.
     *
     * @return \core_external\external_description
     */
    public static function practice_quiz_create_from_resource_returns(): \core_external\external_description {
        $questionstructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Question id'),
            'name' => new external_value(PARAM_RAW, 'Question name'),
            'qtype' => new external_value(PARAM_TEXT, 'Question type'),
            'categoryid' => new external_value(PARAM_INT, 'Question category id'),
            'defaultmark' => new external_value(PARAM_FLOAT, 'Default mark'),
            'qg_id' => new external_value(PARAM_RAW, 'Mapped QG id'),
            'kg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'KG id')),
        ]);
        $resourcestructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Resource map id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'cmid' => new external_value(PARAM_INT, 'Resource cmid'),
            'title' => new external_value(PARAM_RAW, 'Resource title'),
            'lesson_key' => new external_value(PARAM_RAW, 'Lesson key'),
            'kg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'KG id')),
            'qg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'QG id')),
            'review_status' => new external_value(PARAM_RAW, 'Review status'),
        ]);
        return self::envelope_returns(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'cmid' => new external_value(PARAM_INT, 'Source cmid'),
                'lesson_key' => new external_value(PARAM_RAW, 'Lesson key'),
                'quizid' => new external_value(PARAM_INT, 'Created quiz id, 0 for dry-run'),
                'quiz_cmid' => new external_value(PARAM_INT, 'Created quiz cmid, 0 for dry-run'),
                'title' => new external_value(PARAM_RAW, 'Quiz title'),
                'url' => new external_value(PARAM_RAW, 'Quiz URL'),
                'section' => new external_value(PARAM_INT, 'Course section number'),
                'selection_mode' => new external_value(PARAM_ALPHANUMEXT, 'Question selection mode'),
                'requested_count' => new external_value(PARAM_INT, 'Requested question count'),
                'created_count' => new external_value(PARAM_INT, 'Created/selected question count'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the created quiz is visible'),
                'random_categoryid' => new external_value(PARAM_INT, 'Random question category id', VALUE_OPTIONAL),
                'random_categoryids' => new external_multiple_structure(new external_value(PARAM_INT, 'Random question category id'), 'Random question category ids', VALUE_OPTIONAL),
                'random_tagids' => new external_multiple_structure(new external_value(PARAM_INT, 'Random question tag id'), 'Random question tag ids', VALUE_OPTIONAL),
                'questions' => new external_multiple_structure($questionstructure),
                'resource_map' => new external_single_structure([
                    'kg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'KG id')),
                    'qg_ids' => new external_multiple_structure(new external_value(PARAM_RAW, 'QG id')),
                    'lesson_keys' => new external_multiple_structure(new external_value(PARAM_RAW, 'Lesson key')),
                    'resources' => new external_multiple_structure($resourcestructure),
                ]),
                'tags' => new external_multiple_structure(new external_value(PARAM_RAW, 'Resolved teaching/search tag'), 'Resolved teaching/search tags', VALUE_OPTIONAL),
                'tag_ids' => new external_multiple_structure(new external_value(PARAM_INT, 'Resolved Moodle question tag id'), 'Resolved Moodle question tag ids', VALUE_OPTIONAL),
                'available' => new external_value(PARAM_INT, 'Available question count for error responses', VALUE_OPTIONAL),
                'requested' => new external_value(PARAM_INT, 'Requested question count for error responses', VALUE_OPTIONAL),
            ])
        );
    }

    /**
     * Parameters for quiz_start_attempt.
     *
     * @return external_function_parameters
     */
    public static function quiz_start_attempt_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'quizid' => new external_value(PARAM_INT, 'Quiz id', VALUE_REQUIRED),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Data name'),
                    'value' => new external_value(PARAM_RAW, 'Data value'),
                ]),
                'Preflight required data',
                VALUE_DEFAULT,
                []
            ),
            'forcenew' => new external_value(PARAM_BOOL, 'Force a fresh attempt', VALUE_DEFAULT, false),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Start a quiz attempt and return page zero.
     *
     * @param string $idempotency_key
     * @param int $quizid
     * @param array $preflightdata
     * @param bool $forcenew
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function quiz_start_attempt(
        string $idempotency_key,
        int $quizid,
        array $preflightdata,
        bool $forcenew,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::quiz_start_attempt_parameters(), [
            'idempotency_key' => $idempotency_key,
            'quizid' => $quizid,
            'preflightdata' => $preflightdata,
            'forcenew' => $forcenew,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'quiz_start_attempt';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error($USER->id, $action, $params['idempotency_key'], $requesthash, (bool)$params['dry_run'], $params);
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($quiz, 'quiz');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            require_capability('mod/quiz:attempt', $context);

            $payloadquiz = [
                'id' => (int)$quiz->id,
                'cmid' => (int)$cm->id,
                'name' => (string)$quiz->name,
                'attempts' => (int)$quiz->attempts,
                'timeopen' => (int)$quiz->timeopen,
                'timeclose' => (int)$quiz->timeclose,
                'timelimit' => (int)$quiz->timelimit,
            ];

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'quiz' => $payloadquiz,
                    'attempt' => ['id' => 0, 'quizid' => (int)$quiz->id, 'userid' => (int)$USER->id, 'attempt' => 0, 'state' => 'preview', 'currentpage' => 0, 'preview' => 0, 'timestart' => 0, 'timefinish' => 0, 'timemodified' => 0, 'timecheckstate' => 0, 'sumgrades' => ''],
                    'messages' => [],
                    'nextpage' => 0,
                    'questions' => [],
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $started = mod_quiz_external::start_attempt((int)$quiz->id, $params['preflightdata'], (bool)$params['forcenew']);
            $attempt = $started['attempt'];
            $pagezero = mod_quiz_external::get_attempt_data((int)$attempt->id, 0, $params['preflightdata']);
            $attemptobj = \mod_quiz\quiz_attempt::create((int)$attempt->id);
            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'quiz' => $payloadquiz,
                'attempt' => self::quiz_attempt_payload($attempt),
                'messages' => array_values(array_map('strval', $pagezero['messages'] ?? [])),
                'nextpage' => (int)($pagezero['nextpage'] ?? -1),
                'questions' => self::quiz_question_payloads($pagezero['questions'] ?? [], $attemptobj),
                'warnings' => array_merge($started['warnings'] ?? [], $pagezero['warnings'] ?? []),
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_start_attempt_failed', $e->getMessage(), (bool)$params['dry_run'], false, [
                'course' => self::empty_course_payload(),
                'quiz' => ['id' => (int)$params['quizid'], 'cmid' => 0, 'name' => '', 'attempts' => 0, 'timeopen' => 0, 'timeclose' => 0, 'timelimit' => 0],
                'attempt' => ['id' => 0, 'quizid' => (int)$params['quizid'], 'userid' => (int)$USER->id, 'attempt' => 0, 'state' => '', 'currentpage' => 0, 'preview' => 0, 'timestart' => 0, 'timefinish' => 0, 'timemodified' => 0, 'timecheckstate' => 0, 'sumgrades' => ''],
                'messages' => [],
                'nextpage' => -1,
                'questions' => [],
                'warnings' => [],
            ]);
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_start_attempt.
     *
     * @return \core_external\external_description
     */
    public static function quiz_start_attempt_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'quiz' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Quiz id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Quiz name'),
                    'attempts' => new external_value(PARAM_INT, 'Allowed attempts'),
                    'timeopen' => new external_value(PARAM_INT, 'Open time'),
                    'timeclose' => new external_value(PARAM_INT, 'Close time'),
                    'timelimit' => new external_value(PARAM_INT, 'Time limit'),
                ]),
                'attempt' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Attempt id'),
                    'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                    'state' => new external_value(PARAM_TEXT, 'Attempt state'),
                    'currentpage' => new external_value(PARAM_INT, 'Current page'),
                    'preview' => new external_value(PARAM_BOOL, 'Preview flag'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    'timecheckstate' => new external_value(PARAM_INT, 'Time check state'),
                    'sumgrades' => new external_value(PARAM_RAW, 'Sum grades'),
                ]),
                'messages' => new external_multiple_structure(new external_value(PARAM_RAW, 'Access message')),
                'nextpage' => new external_value(PARAM_INT, 'Next page number'),
                'questions' => new external_multiple_structure(self::quiz_question_structure()),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item', VALUE_OPTIONAL),
                        'itemid' => new external_value(PARAM_INT, 'Warning item id', VALUE_OPTIONAL),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code', VALUE_OPTIONAL),
                        'message' => new external_value(PARAM_RAW, 'Warning message', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_get_attempt_data.
     *
     * @return external_function_parameters
     */
    public static function quiz_get_attempt_data_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_REQUIRED),
            'page' => new external_value(PARAM_INT, 'Page number', VALUE_REQUIRED),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Data name'),
                    'value' => new external_value(PARAM_RAW, 'Data value'),
                ]),
                'Preflight required data',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Return one page of attempt data.
     *
     * @param int $attemptid
     * @param int $page
     * @param array $preflightdata
     * @return array
     */
    public static function quiz_get_attempt_data(int $attemptid, int $page, array $preflightdata): array {
        global $USER;

        $params = self::validate_parameters(self::quiz_get_attempt_data_parameters(), [
            'attemptid' => $attemptid,
            'page' => $page,
            'preflightdata' => $preflightdata,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $result = mod_quiz_external::get_attempt_data((int)$params['attemptid'], (int)$params['page'], $params['preflightdata']);
            $attemptobj = \mod_quiz\quiz_attempt::create((int)$params['attemptid']);
            $response = self::response_ok($auditid, [
                'attempt' => self::quiz_attempt_payload($result['attempt']),
                'messages' => array_values(array_map('strval', $result['messages'] ?? [])),
                'nextpage' => (int)($result['nextpage'] ?? -1),
                'questions' => self::quiz_question_payloads($result['questions'] ?? [], $attemptobj),
                'warnings' => $result['warnings'] ?? [],
            ]);
            self::audit($USER->id, 'quiz_get_attempt_data', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_get_attempt_data_failed', $e->getMessage(), false, false, [
                'attempt' => ['id' => (int)$params['attemptid'], 'quizid' => 0, 'userid' => 0, 'attempt' => 0, 'state' => '', 'currentpage' => 0, 'preview' => 0, 'timestart' => 0, 'timefinish' => 0, 'timemodified' => 0, 'timecheckstate' => 0, 'sumgrades' => ''],
                'messages' => [],
                'nextpage' => -1,
                'questions' => [],
                'warnings' => [],
            ]);
            self::audit($USER->id, 'quiz_get_attempt_data', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_get_attempt_data.
     *
     * @return \core_external\external_description
     */
    public static function quiz_get_attempt_data_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'attempt' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Attempt id'),
                    'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                    'state' => new external_value(PARAM_TEXT, 'Attempt state'),
                    'currentpage' => new external_value(PARAM_INT, 'Current page'),
                    'preview' => new external_value(PARAM_BOOL, 'Preview flag'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    'timecheckstate' => new external_value(PARAM_INT, 'Time check state'),
                    'sumgrades' => new external_value(PARAM_RAW, 'Sum grades'),
                ]),
                'messages' => new external_multiple_structure(new external_value(PARAM_RAW, 'Access message')),
                'nextpage' => new external_value(PARAM_INT, 'Next page number'),
                'questions' => new external_multiple_structure(self::quiz_question_structure()),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item', VALUE_OPTIONAL),
                        'itemid' => new external_value(PARAM_INT, 'Warning item id', VALUE_OPTIONAL),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code', VALUE_OPTIONAL),
                        'message' => new external_value(PARAM_RAW, 'Warning message', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_get_attempt_summary.
     *
     * @return external_function_parameters
     */
    public static function quiz_get_attempt_summary_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_REQUIRED),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Data name'),
                    'value' => new external_value(PARAM_RAW, 'Data value'),
                ]),
                'Preflight required data',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Return attempt summary.
     *
     * @param int $attemptid
     * @param array $preflightdata
     * @return array
     */
    public static function quiz_get_attempt_summary(int $attemptid, array $preflightdata): array {
        global $USER;

        $params = self::validate_parameters(self::quiz_get_attempt_summary_parameters(), [
            'attemptid' => $attemptid,
            'preflightdata' => $preflightdata,
        ]);

        self::restricted_context();
        $auditid = self::uuid_v4();

        try {
            $result = mod_quiz_external::get_attempt_summary((int)$params['attemptid'], $params['preflightdata']);
            $attemptobj = \mod_quiz\quiz_attempt::create((int)$params['attemptid']);
            $response = self::response_ok($auditid, [
                'attemptid' => (int)$params['attemptid'],
                'totalunanswered' => (int)($result['totalunanswered'] ?? 0),
                'questions' => self::quiz_question_payloads($result['questions'] ?? [], $attemptobj),
                'warnings' => $result['warnings'] ?? [],
            ]);
            self::audit($USER->id, 'quiz_get_attempt_summary', true, $auditid, $params, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_get_attempt_summary_failed', $e->getMessage(), false, false, [
                'attemptid' => (int)$params['attemptid'],
                'totalunanswered' => 0,
                'questions' => [],
                'warnings' => [],
            ]);
            self::audit($USER->id, 'quiz_get_attempt_summary', false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_get_attempt_summary.
     *
     * @return \core_external\external_description
     */
    public static function quiz_get_attempt_summary_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
                'totalunanswered' => new external_value(PARAM_INT, 'Total unanswered questions'),
                'questions' => new external_multiple_structure(self::quiz_question_structure()),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item', VALUE_OPTIONAL),
                        'itemid' => new external_value(PARAM_INT, 'Warning item id', VALUE_OPTIONAL),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code', VALUE_OPTIONAL),
                        'message' => new external_value(PARAM_RAW, 'Warning message', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_save_attempt.
     *
     * @return external_function_parameters
     */
    public static function quiz_save_attempt_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_REQUIRED),
            'responses' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_RAW, 'Form field name'),
                    'value' => new external_value(PARAM_RAW, 'Form field value'),
                ]),
                'Response field pairs',
                VALUE_DEFAULT,
                []
            ),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Data name'),
                    'value' => new external_value(PARAM_RAW, 'Data value'),
                ]),
                'Preflight required data',
                VALUE_DEFAULT,
                []
            ),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save quiz attempt responses.
     *
     * @param string $idempotency_key
     * @param int $attemptid
     * @param array $responses
     * @param array $preflightdata
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function quiz_save_attempt(
        string $idempotency_key,
        int $attemptid,
        array $responses,
        array $preflightdata,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::quiz_save_attempt_parameters(), [
            'idempotency_key' => $idempotency_key,
            'attemptid' => $attemptid,
            'responses' => $responses,
            'preflightdata' => $preflightdata,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);
        self::restricted_context();

        $action = 'quiz_save_attempt';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error($USER->id, $action, $params['idempotency_key'], $requesthash, (bool)$params['dry_run'], $params);
        if ($replay !== null) {
            return $replay;
        }
        $auditid = self::uuid_v4();

        try {
            $attempt = $DB->get_record('quiz_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'attempt' => self::quiz_attempt_payload($attempt),
                    'saved_response_count' => count($params['responses']),
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $result = mod_quiz_external::save_attempt((int)$attempt->id, $params['responses'], $params['preflightdata']);
            $attempt = $DB->get_record('quiz_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
            $response = self::response_ok($auditid, [
                'attempt' => self::quiz_attempt_payload($attempt),
                'saved_response_count' => count($params['responses']),
                'warnings' => $result['warnings'] ?? [],
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_save_attempt_failed', $e->getMessage(), (bool)$params['dry_run'], false, [
                'attempt' => ['id' => (int)$params['attemptid'], 'quizid' => 0, 'userid' => 0, 'attempt' => 0, 'state' => '', 'currentpage' => 0, 'preview' => 0, 'timestart' => 0, 'timefinish' => 0, 'timemodified' => 0, 'timecheckstate' => 0, 'sumgrades' => ''],
                'saved_response_count' => 0,
                'warnings' => [],
            ]);
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_save_attempt.
     *
     * @return \core_external\external_description
     */
    public static function quiz_save_attempt_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'attempt' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Attempt id'),
                    'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                    'state' => new external_value(PARAM_TEXT, 'Attempt state'),
                    'currentpage' => new external_value(PARAM_INT, 'Current page'),
                    'preview' => new external_value(PARAM_BOOL, 'Preview flag'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    'timecheckstate' => new external_value(PARAM_INT, 'Time check state'),
                    'sumgrades' => new external_value(PARAM_RAW, 'Sum grades'),
                ]),
                'saved_response_count' => new external_value(PARAM_INT, 'How many name/value pairs were saved'),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item', VALUE_OPTIONAL),
                        'itemid' => new external_value(PARAM_INT, 'Warning item id', VALUE_OPTIONAL),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code', VALUE_OPTIONAL),
                        'message' => new external_value(PARAM_RAW, 'Warning message', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_submit_attempt.
     *
     * @return external_function_parameters
     */
    public static function quiz_submit_attempt_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_REQUIRED),
            'responses' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_RAW, 'Form field name'),
                    'value' => new external_value(PARAM_RAW, 'Form field value'),
                ]),
                'Optional final response field pairs',
                VALUE_DEFAULT,
                []
            ),
            'timeup' => new external_value(PARAM_BOOL, 'Whether submit is due to timer expiry', VALUE_DEFAULT, false),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Data name'),
                    'value' => new external_value(PARAM_RAW, 'Data value'),
                ]),
                'Preflight required data',
                VALUE_DEFAULT,
                []
            ),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Submit a quiz attempt.
     *
     * @param string $idempotency_key
     * @param int $attemptid
     * @param array $responses
     * @param bool $timeup
     * @param array $preflightdata
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function quiz_submit_attempt(
        string $idempotency_key,
        int $attemptid,
        array $responses,
        bool $timeup,
        array $preflightdata,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::quiz_submit_attempt_parameters(), [
            'idempotency_key' => $idempotency_key,
            'attemptid' => $attemptid,
            'responses' => $responses,
            'timeup' => $timeup,
            'preflightdata' => $preflightdata,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);
        self::restricted_context();

        $action = 'quiz_submit_attempt';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error($USER->id, $action, $params['idempotency_key'], $requesthash, (bool)$params['dry_run'], $params);
        if ($replay !== null) {
            return $replay;
        }
        $auditid = self::uuid_v4();

        try {
            $attempt = $DB->get_record('quiz_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'attempt' => self::quiz_attempt_payload($attempt),
                    'state' => (string)$attempt->state,
                    'submitted_response_count' => count($params['responses']),
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $result = mod_quiz_external::process_attempt((int)$attempt->id, $params['responses'], true, (bool)$params['timeup'], $params['preflightdata']);
            $attempt = $DB->get_record('quiz_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
            $response = self::response_ok($auditid, [
                'attempt' => self::quiz_attempt_payload($attempt),
                'state' => (string)($result['state'] ?? $attempt->state),
                'submitted_response_count' => count($params['responses']),
                'warnings' => $result['warnings'] ?? [],
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_submit_attempt_failed', $e->getMessage(), (bool)$params['dry_run'], false, [
                'attempt' => ['id' => (int)$params['attemptid'], 'quizid' => 0, 'userid' => 0, 'attempt' => 0, 'state' => '', 'currentpage' => 0, 'preview' => 0, 'timestart' => 0, 'timefinish' => 0, 'timemodified' => 0, 'timecheckstate' => 0, 'sumgrades' => ''],
                'state' => '',
                'submitted_response_count' => 0,
                'warnings' => [],
            ]);
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_submit_attempt.
     *
     * @return \core_external\external_description
     */
    public static function quiz_submit_attempt_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'attempt' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Attempt id'),
                    'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                    'state' => new external_value(PARAM_TEXT, 'Attempt state'),
                    'currentpage' => new external_value(PARAM_INT, 'Current page'),
                    'preview' => new external_value(PARAM_BOOL, 'Preview flag'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    'timecheckstate' => new external_value(PARAM_INT, 'Time check state'),
                    'sumgrades' => new external_value(PARAM_RAW, 'Sum grades'),
                ]),
                'state' => new external_value(PARAM_TEXT, 'Attempt state after submit'),
                'submitted_response_count' => new external_value(PARAM_INT, 'How many name/value pairs were processed'),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item', VALUE_OPTIONAL),
                        'itemid' => new external_value(PARAM_INT, 'Warning item id', VALUE_OPTIONAL),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code', VALUE_OPTIONAL),
                        'message' => new external_value(PARAM_RAW, 'Warning message', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for quiz_answer_questions.
     *
     * @return external_function_parameters
     */
    public static function quiz_answer_questions_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_REQUIRED),
            'answers' => new external_multiple_structure(
                new external_single_structure([
                    'slot' => new external_value(PARAM_INT, 'Quiz slot number'),
                    'choice_index' => new external_value(PARAM_INT, 'Selected choice index for single-choice questions', VALUE_OPTIONAL),
                    'choice_indexes_csv' => new external_value(PARAM_SEQUENCE, 'Comma-separated selected choice indexes for multi-choice questions', VALUE_OPTIONAL),
                    'flagged' => new external_value(PARAM_BOOL, 'Whether to flag this question', VALUE_OPTIONAL),
                ]),
                'Structured answers',
                VALUE_REQUIRED
            ),
            'submit' => new external_value(PARAM_BOOL, 'Submit attempt after applying answers', VALUE_DEFAULT, false),
            'timeup' => new external_value(PARAM_BOOL, 'Whether submission is due to timer expiry', VALUE_DEFAULT, false),
            'preflightdata' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'Data name'),
                    'value' => new external_value(PARAM_RAW, 'Data value'),
                ]),
                'Preflight required data',
                VALUE_DEFAULT,
                []
            ),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Apply structured answers to supported question types.
     *
     * @param string $idempotency_key
     * @param int $attemptid
     * @param array $answers
     * @param bool $submit
     * @param bool $timeup
     * @param array $preflightdata
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function quiz_answer_questions(
        string $idempotency_key,
        int $attemptid,
        array $answers,
        bool $submit,
        bool $timeup,
        array $preflightdata,
        bool $dry_run,
        string $reason
    ): array {
        global $USER;

        $params = self::validate_parameters(self::quiz_answer_questions_parameters(), [
            'idempotency_key' => $idempotency_key,
            'attemptid' => $attemptid,
            'answers' => $answers,
            'submit' => $submit,
            'timeup' => $timeup,
            'preflightdata' => $preflightdata,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);
        self::restricted_context();

        $action = 'quiz_answer_questions';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error($USER->id, $action, $params['idempotency_key'], $requesthash, (bool)$params['dry_run'], $params);
        if ($replay !== null) {
            return $replay;
        }
        $auditid = self::uuid_v4();

        try {
            if (empty($params['answers'])) {
                throw new \moodle_exception('answers must not be empty.');
            }

            $attemptobj = \mod_quiz\quiz_attempt::create((int)$params['attemptid']);
            $attempt = $attemptobj->get_attempt();
            $context = $attemptobj->get_cm()->context;
            self::validate_context($context);
            require_capability('mod/quiz:attempt', $context);
            if ((int)$attempt->userid !== (int)$USER->id && !has_capability('mod/quiz:preview', $context)) {
                throw new \required_capability_exception($context, 'mod/quiz:attempt', 'nopermissions', '');
            }

            $rawresponses = [];
            $appliedanswers = [];
            $slotnumbers = [];
            foreach ($params['answers'] as $answer) {
                $slot = (int)$answer['slot'];
                $slotnumbers[] = $slot;
            }
            $rawresponses[] = ['name' => 'slots', 'value' => implode(',', $slotnumbers)];
            foreach ($params['answers'] as $answer) {
                $slot = (int)$answer['slot'];
                $qa = $attemptobj->get_question_attempt($slot);
                $schema = self::quiz_question_response_schema($qa);
                $rawresponses = array_merge($rawresponses, self::quiz_structured_answer_to_pairs($qa, $answer));
                $appliedanswers[] = [
                    'slot' => $slot,
                    'response_type' => (string)($schema['response_type'] ?? ''),
                    'choice_index' => (int)($answer['choice_index'] ?? -999),
                    'choice_indexes' => isset($answer['choice_indexes_csv']) && trim((string)$answer['choice_indexes_csv']) !== ''
                        ? array_values(array_map('intval', explode(',', (string)$answer['choice_indexes_csv'])))
                        : [],
                    'flagged' => !empty($answer['flagged']) ? 1 : 0,
                ];
            }

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'attempt' => self::quiz_attempt_payload($attempt),
                    'state' => (string)$attempt->state,
                    'applied_answers' => $appliedanswers,
                    'low_level_responses' => $rawresponses,
                    'questions' => [],
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            if ($params['submit']) {
                $result = mod_quiz_external::process_attempt(
                    (int)$attempt->id,
                    $rawresponses,
                    true,
                    (bool)$params['timeup'],
                    $params['preflightdata']
                );
            } else {
                $result = mod_quiz_external::save_attempt((int)$attempt->id, $rawresponses, $params['preflightdata']);
            }

            $attemptobj = \mod_quiz\quiz_attempt::create((int)$attempt->id);
            $updatedattempt = $attemptobj->get_attempt();
            $summary = mod_quiz_external::get_attempt_summary((int)$attempt->id, $params['preflightdata']);
            $response = self::response_ok($auditid, [
                'attempt' => self::quiz_attempt_payload($updatedattempt),
                'state' => (string)($result['state'] ?? $updatedattempt->state),
                'applied_answers' => $appliedanswers,
                'low_level_responses' => $rawresponses,
                'questions' => self::quiz_question_payloads($summary['questions'] ?? [], $attemptobj),
                'warnings' => array_merge($result['warnings'] ?? [], $summary['warnings'] ?? []),
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error($auditid, 'quiz_answer_questions_failed', get_class($e) . ': ' . $e->getMessage(), (bool)$params['dry_run'], false, [
                'attempt' => ['id' => (int)$params['attemptid'], 'quizid' => 0, 'userid' => 0, 'attempt' => 0, 'state' => '', 'currentpage' => 0, 'preview' => 0, 'timestart' => 0, 'timefinish' => 0, 'timemodified' => 0, 'timecheckstate' => 0, 'sumgrades' => ''],
                'state' => '',
                'applied_answers' => [],
                'low_level_responses' => [],
                'questions' => [],
                'warnings' => [],
            ]);
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for quiz_answer_questions.
     *
     * @return \core_external\external_description
     */
    public static function quiz_answer_questions_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'attempt' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Attempt id'),
                    'quizid' => new external_value(PARAM_INT, 'Quiz id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                    'state' => new external_value(PARAM_TEXT, 'Attempt state'),
                    'currentpage' => new external_value(PARAM_INT, 'Current page'),
                    'preview' => new external_value(PARAM_BOOL, 'Preview flag'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timefinish' => new external_value(PARAM_INT, 'Finish time'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    'timecheckstate' => new external_value(PARAM_INT, 'Time check state'),
                    'sumgrades' => new external_value(PARAM_RAW, 'Sum grades'),
                ]),
                'state' => new external_value(PARAM_TEXT, 'Attempt state after applying answers'),
                'applied_answers' => new external_multiple_structure(
                    new external_single_structure([
                        'slot' => new external_value(PARAM_INT, 'Slot'),
                        'response_type' => new external_value(PARAM_RAW, 'Normalized response type'),
                        'choice_index' => new external_value(PARAM_INT, 'Single selected choice index'),
                        'choice_indexes' => new external_multiple_structure(
                            new external_value(PARAM_INT, 'Selected choice index'),
                            'Multiple selected choice indexes'
                        ),
                        'flagged' => new external_value(PARAM_BOOL, 'Flagged state'),
                    ])
                ),
                'low_level_responses' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_RAW, 'Raw Moodle field name'),
                        'value' => new external_value(PARAM_RAW, 'Raw Moodle field value'),
                    ])
                ),
                'questions' => new external_multiple_structure(self::quiz_question_structure()),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item', VALUE_OPTIONAL),
                        'itemid' => new external_value(PARAM_INT, 'Warning item id', VALUE_OPTIONAL),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code', VALUE_OPTIONAL),
                        'message' => new external_value(PARAM_RAW, 'Warning message', VALUE_OPTIONAL),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for calendar_publish_plan.
     *
     * @return external_function_parameters
     */
    public static function calendar_publish_plan_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'dry_run' => new external_value(PARAM_BOOL, 'If true, validate and return a preview without writing', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional reason for audit', VALUE_DEFAULT, ''),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Item title', VALUE_REQUIRED),
                    'description' => new external_value(PARAM_RAW, 'Optional description', VALUE_DEFAULT, null, NULL_ALLOWED),
                    'timestart' => new external_value(PARAM_INT, 'Start time (unix timestamp)', VALUE_REQUIRED),
                    'timeduration' => new external_value(PARAM_INT, 'Duration in seconds', VALUE_DEFAULT, 0),
                ]),
                'Plan items to publish as user calendar events'
            ),
        ]);
    }

    /**
     * Publish plan items into the current user calendar with idempotency.
     *
     * @param string $idempotency_key
     * @param bool $dry_run
     * @param string $reason
     * @param array $items
     * @return array
     */
    public static function calendar_publish_plan(string $idempotency_key, bool $dry_run, string $reason, array $items): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::calendar_publish_plan_parameters(), [
            'idempotency_key' => $idempotency_key,
            'dry_run' => $dry_run,
            'reason' => $reason,
            'items' => $items,
        ]);

        self::restricted_context();

        if (count($params['items']) > self::MAX_EVENTS_PER_CALL) {
            $auditid = self::uuid_v4();
            $response = self::response_error(
                $auditid,
                'too_many_items',
                'Too many items. Max ' . self::MAX_EVENTS_PER_CALL . ' per call.',
                (bool)$params['dry_run']
            );
            self::audit($USER->id, 'calendar_publish_plan', false, $auditid, $params, $response);
            return $response;
        }

        $action = 'calendar_publish_plan';
        $requesthash = self::request_hash($params);

        // Idempotency: return prior response if key already used.
        $existing = $DB->get_record('local_aiagentapi_idemp', [
            'userid' => $USER->id,
            'action' => $action,
            'idempotencykey' => $params['idempotency_key'],
        ]);
        if ($existing) {
            if (!empty($existing->requesthash) && $existing->requesthash !== $requesthash) {
                $auditid = self::uuid_v4();
                $response = self::response_error(
                    $auditid,
                    'idempotency_key_reuse',
                    'Idempotency key already used with different parameters.',
                    (bool)$params['dry_run']
                );
                self::audit($USER->id, $action, false, $auditid, $params, $response);
                return $response;
            }
            $decoded = json_decode($existing->responsejson, true);
            if (!is_array($decoded)) {
                $decoded = self::response_error(
                    self::uuid_v4(),
                    'bad_stored_response',
                    'Invalid stored response.',
                    (bool)$params['dry_run']
                );
            }
            $decoded['replayed'] = true;
            return $decoded;
        }

        // Map plan items -> core calendar events structure.
        $events = [];
        foreach ($params['items'] as $item) {
            $events[] = [
                'name' => $item['name'],
                'description' => $item['description'],
                'format' => FORMAT_HTML,
                'courseid' => 0,
                'groupid' => 0,
                'repeats' => 0,
                'eventtype' => 'user',
                'timestart' => $item['timestart'],
                'timeduration' => $item['timeduration'],
                'visible' => 1,
                'sequence' => 1,
            ];
        }

        $auditid = self::uuid_v4();
        $response = self::response_ok($auditid, [
            'created_events' => [],
            'preview_events' => $events,
        ], (bool)$params['dry_run']);

        try {
            if (!$params['dry_run']) {
                $created = core_calendar_external::create_calendar_events($events);
                $response['data']['created_events'] = $created['events'];
            }
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'calendar_publish_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'created_events' => [],
                    'preview_events' => $events,
                ]
            );
        }

        self::audit($USER->id, $action, (bool)$response['ok'], $auditid, $params, $response);

        // Store idempotency record only for successful writes (not for dry-run).
        if ($response['ok'] && !$params['dry_run']) {
            $DB->insert_record('local_aiagentapi_idemp', [
                'userid' => $USER->id,
                'action' => $action,
                'idempotencykey' => $params['idempotency_key'],
                'requesthash' => $requesthash,
                'responsejson' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return $response;
    }

    /**
     * Returns structure for calendar_publish_plan.
     *
     * @return \core_external\external_description
     */
    public static function calendar_publish_plan_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'created_events' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'event id'),
                        'name' => new external_value(PARAM_RAW, 'event name'),
                        'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
                        'format' => new external_format_value('description'),
                        'courseid' => new external_value(PARAM_INT, 'course id'),
                        'groupid' => new external_value(PARAM_INT, 'group id'),
                        'userid' => new external_value(PARAM_INT, 'user id'),
                        'repeatid' => new external_value(PARAM_INT, 'repeat id', VALUE_OPTIONAL),
                        'modulename' => new external_value(PARAM_TEXT, 'module name', VALUE_OPTIONAL),
                        'instance' => new external_value(PARAM_INT, 'instance id'),
                        'eventtype' => new external_value(PARAM_TEXT, 'Event type'),
                        'timestart' => new external_value(PARAM_INT, 'timestart'),
                        'timeduration' => new external_value(PARAM_INT, 'time duration'),
                        'visible' => new external_value(PARAM_INT, 'visible'),
                        'uuid' => new external_value(PARAM_TEXT, 'unique id of ical events', VALUE_OPTIONAL, '', NULL_NOT_ALLOWED),
                        'sequence' => new external_value(PARAM_INT, 'sequence'),
                        'timemodified' => new external_value(PARAM_INT, 'time modified'),
                        'subscriptionid' => new external_value(PARAM_INT, 'Subscription id', VALUE_OPTIONAL),
                    ])
                ),
                'preview_events' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_TEXT, 'event name'),
                        'description' => new external_value(PARAM_RAW, 'Description', VALUE_OPTIONAL),
                        'format' => new external_value(PARAM_INT, 'Description format'),
                        'courseid' => new external_value(PARAM_INT, 'course id'),
                        'groupid' => new external_value(PARAM_INT, 'group id'),
                        'repeats' => new external_value(PARAM_INT, 'number of repeats'),
                        'eventtype' => new external_value(PARAM_TEXT, 'Event type'),
                        'timestart' => new external_value(PARAM_INT, 'timestart'),
                        'timeduration' => new external_value(PARAM_INT, 'time duration'),
                        'visible' => new external_value(PARAM_INT, 'visible'),
                        'sequence' => new external_value(PARAM_INT, 'sequence'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for calendar_plan_upsert.
     *
     * @return external_function_parameters
     */
    public static function calendar_plan_upsert_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'plan_key' => new external_value(PARAM_ALPHANUMEXT, 'Stable plan key', VALUE_REQUIRED),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'item_key' => new external_value(PARAM_ALPHANUMEXT, 'Stable item key inside the plan', VALUE_REQUIRED),
                    'name' => new external_value(PARAM_TEXT, 'Item title', VALUE_DEFAULT, ''),
                    'description' => new external_value(PARAM_RAW, 'Optional description', VALUE_DEFAULT, null, NULL_ALLOWED),
                    'timestart' => new external_value(PARAM_INT, 'Start time (unix timestamp)', VALUE_DEFAULT, 0),
                    'timeduration' => new external_value(PARAM_INT, 'Duration in seconds', VALUE_DEFAULT, 0),
                    'deleted' => new external_value(PARAM_BOOL, 'Delete existing event for this item key', VALUE_DEFAULT, false),
                ]),
                'Keyed plan items to create/update/delete as user calendar events'
            ),
        ]);
    }

    /**
     * Upsert AI-managed calendar plan events.
     *
     * @param string $idempotency_key
     * @param string $plan_key
     * @param bool $dry_run
     * @param string $reason
     * @param array $items
     * @return array
     */
    public static function calendar_plan_upsert(
        string $idempotency_key,
        string $plan_key,
        bool $dry_run,
        string $reason,
        array $items
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::calendar_plan_upsert_parameters(), [
            'idempotency_key' => $idempotency_key,
            'plan_key' => $plan_key,
            'dry_run' => $dry_run,
            'reason' => $reason,
            'items' => $items,
        ]);

        self::restricted_context();

        if (count($params['items']) > self::MAX_EVENTS_PER_CALL) {
            $auditid = self::uuid_v4();
            $response = self::response_error(
                $auditid,
                'too_many_items',
                'Too many items. Max ' . self::MAX_EVENTS_PER_CALL . ' per call.',
                (bool)$params['dry_run']
            );
            self::audit($USER->id, 'calendar_plan_upsert', false, $auditid, $params, $response);
            return $response;
        }

        $action = 'calendar_plan_upsert';
        $requesthash = self::request_hash($params);
        $existingidemp = $DB->get_record('local_aiagentapi_idemp', [
            'userid' => $USER->id,
            'action' => $action,
            'idempotencykey' => $params['idempotency_key'],
        ]);
        if ($existingidemp) {
            if (!empty($existingidemp->requesthash) && $existingidemp->requesthash !== $requesthash) {
                $auditid = self::uuid_v4();
                $response = self::response_error(
                    $auditid,
                    'idempotency_key_reuse',
                    'Idempotency key already used with different parameters.',
                    (bool)$params['dry_run']
                );
                self::audit($USER->id, $action, false, $auditid, $params, $response);
                return $response;
            }
            $decoded = json_decode($existingidemp->responsejson, true);
            if (!is_array($decoded)) {
                $decoded = self::response_error(
                    self::uuid_v4(),
                    'bad_stored_response',
                    'Invalid stored response.',
                    (bool)$params['dry_run']
                );
            }
            $decoded['replayed'] = true;
            return $decoded;
        }

        $auditid = self::uuid_v4();
        $markerlike = '%local_aiagentapi:plan=' . $params['plan_key'] . ';item=%';
        $existingevents = $DB->get_records_select('event', "userid = ? AND eventtype = ? AND " . $DB->sql_like('description', '?'), [
            $USER->id,
            'user',
            $markerlike,
        ]);
        $existingbyitem = [];
        foreach ($existingevents as $event) {
            if (preg_match('/local_aiagentapi:plan=' . preg_quote($params['plan_key'], '/') . ';item=([A-Za-z0-9_-]+)/', (string)$event->description, $m)) {
                $existingbyitem[$m[1]] = $event;
            }
        }

        $previewactions = [];
        $createdevents = [];
        $updatedevents = [];
        $deletedeventids = [];

        try {
            foreach ($params['items'] as $item) {
                $itemkey = (string)$item['item_key'];
                $existingevent = $existingbyitem[$itemkey] ?? null;
                $marker = self::calendar_plan_marker($params['plan_key'], $itemkey);
                $description = trim((string)($item['description'] ?? ''));
                $descriptionwithmarker = trim($description . "\n" . $marker);

                if (!empty($item['deleted'])) {
                    if ($existingevent) {
                        $previewactions[] = [
                            'action' => 'delete',
                            'item_key' => $itemkey,
                            'eventid' => (int)$existingevent->id,
                            'name' => (string)$existingevent->name,
                            'timestart' => (int)$existingevent->timestart,
                            'timeduration' => (int)$existingevent->timeduration,
                        ];
                        if (!$params['dry_run']) {
                            $eventobj = \calendar_event::load($existingevent->id);
                            $eventobj->delete(false);
                            $deletedeventids[] = (int)$existingevent->id;
                        }
                    }
                    continue;
                }

                if (empty($item['name']) || empty($item['timestart'])) {
                    throw new \invalid_parameter_exception('Active plan items require name and timestart.');
                }

                if ($existingevent) {
                    $previewactions[] = [
                        'action' => 'update',
                        'item_key' => $itemkey,
                        'eventid' => (int)$existingevent->id,
                        'name' => (string)$item['name'],
                        'timestart' => (int)$item['timestart'],
                        'timeduration' => (int)$item['timeduration'],
                    ];
                    if (!$params['dry_run']) {
                        $eventobj = \calendar_event::load($existingevent->id);
                        $eventobj->update((object)[
                            'name' => (string)$item['name'],
                            'description' => $descriptionwithmarker,
                            'format' => FORMAT_HTML,
                            'timestart' => (int)$item['timestart'],
                            'timeduration' => (int)$item['timeduration'],
                        ], false);
                        $updatedevents[] = [
                            'id' => (int)$existingevent->id,
                            'name' => (string)$item['name'],
                            'timestart' => (int)$item['timestart'],
                            'timeduration' => (int)$item['timeduration'],
                        ];
                    }
                    continue;
                }

                $previewactions[] = [
                    'action' => 'create',
                    'item_key' => $itemkey,
                    'eventid' => 0,
                    'name' => (string)$item['name'],
                    'timestart' => (int)$item['timestart'],
                    'timeduration' => (int)$item['timeduration'],
                ];
                if (!$params['dry_run']) {
                    $created = \calendar_event::create((object)[
                        'name' => (string)$item['name'],
                        'description' => $descriptionwithmarker,
                        'format' => FORMAT_HTML,
                        'courseid' => 0,
                        'groupid' => 0,
                        'userid' => $USER->id,
                        'eventtype' => 'user',
                        'timestart' => (int)$item['timestart'],
                        'timeduration' => (int)$item['timeduration'],
                        'visible' => 1,
                        'sequence' => 1,
                    ], false);
                    $createdevents[] = [
                        'id' => (int)$created->id,
                        'name' => (string)$item['name'],
                        'timestart' => (int)$item['timestart'],
                        'timeduration' => (int)$item['timeduration'],
                    ];
                }
            }

            $response = self::response_ok($auditid, [
                'created_events' => $createdevents,
                'updated_events' => $updatedevents,
                'deleted_event_ids' => $deletedeventids,
                'preview_actions' => $previewactions,
            ], (bool)$params['dry_run']);
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'calendar_plan_upsert_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'created_events' => $createdevents,
                    'updated_events' => $updatedevents,
                    'deleted_event_ids' => $deletedeventids,
                    'preview_actions' => $previewactions,
                ]
            );
        }

        self::audit($USER->id, $action, (bool)$response['ok'], $auditid, $params, $response);

        if ($response['ok'] && !$params['dry_run']) {
            $DB->insert_record('local_aiagentapi_idemp', [
                'userid' => $USER->id,
                'action' => $action,
                'idempotencykey' => $params['idempotency_key'],
                'requesthash' => $requesthash,
                'responsejson' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return $response;
    }

    /**
     * Returns for calendar_plan_upsert.
     *
     * @return \core_external\external_description
     */
    public static function calendar_plan_upsert_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'created_events' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Event id'),
                        'name' => new external_value(PARAM_RAW, 'Event name'),
                        'timestart' => new external_value(PARAM_INT, 'Start time'),
                        'timeduration' => new external_value(PARAM_INT, 'Duration'),
                    ])
                ),
                'updated_events' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Event id'),
                        'name' => new external_value(PARAM_RAW, 'Event name'),
                        'timestart' => new external_value(PARAM_INT, 'Start time'),
                        'timeduration' => new external_value(PARAM_INT, 'Duration'),
                    ])
                ),
                'deleted_event_ids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Deleted event id')
                ),
                'preview_actions' => new external_multiple_structure(
                    new external_single_structure([
                        'action' => new external_value(PARAM_TEXT, 'create/update/delete'),
                        'item_key' => new external_value(PARAM_ALPHANUMEXT, 'Stable item key'),
                        'eventid' => new external_value(PARAM_INT, 'Existing event id or 0'),
                        'name' => new external_value(PARAM_RAW, 'Event name'),
                        'timestart' => new external_value(PARAM_INT, 'Start time'),
                        'timeduration' => new external_value(PARAM_INT, 'Duration'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for assignment_save_draft.
     *
     * @return external_function_parameters
     */
    public static function assignment_save_draft_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'assignid' => new external_value(PARAM_INT, 'Assignment id', VALUE_REQUIRED),
            'text' => new external_value(PARAM_RAW, 'Online text draft body', VALUE_REQUIRED),
            'format' => new external_value(PARAM_INT, 'Moodle text format constant', VALUE_DEFAULT, FORMAT_HTML),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Save an online-text assignment draft.
     *
     * @param string $idempotency_key
     * @param int $assignid
     * @param string $text
     * @param int $format
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function assignment_save_draft(
        string $idempotency_key,
        int $assignid,
        string $text,
        int $format,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::assignment_save_draft_parameters(), [
            'idempotency_key' => $idempotency_key,
            'assignid' => $assignid,
            'text' => $text,
            'format' => $format,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'assignment_save_draft';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error(
            $USER->id,
            $action,
            $params['idempotency_key'],
            $requesthash,
            (bool)$params['dry_run'],
            $params
        );
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($assign, 'assign');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            require_capability('mod/assign:submit', $context);

            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $USER->id,
                'latest' => 1,
            ]);
            $draftid = file_get_unused_draft_itemid();
            $preview = [
                'text_preview' => core_text::substr(trim(strip_tags((string)$params['text'])), 0, 240),
                'format' => (int)$params['format'],
                'text_length' => core_text::strlen((string)$params['text']),
            ];

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'assignment' => [
                        'id' => (int)$assign->id,
                        'cmid' => (int)$cm->id,
                        'name' => (string)$assign->name,
                        'duedate' => (int)$assign->duedate,
                        'cutoffdate' => (int)$assign->cutoffdate,
                    ],
                    'submission' => [
                        'id' => $submission ? (int)$submission->id : 0,
                        'status' => $submission ? (string)$submission->status : 'new',
                        'attemptnumber' => $submission ? (int)$submission->attemptnumber : 0,
                        'timemodified' => $submission ? (int)$submission->timemodified : 0,
                        'text' => '',
                        'format' => (int)$params['format'],
                        'wordcount' => count_words(strip_tags((string)$params['text'])),
                    ],
                    'preview' => $preview,
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $warnings = mod_assign_external::save_submission((int)$assign->id, [
                'onlinetext_editor' => [
                    'text' => (string)$params['text'],
                    'format' => (int)$params['format'],
                    'itemid' => $draftid,
                ],
            ]);

            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $USER->id,
                'latest' => 1,
            ], '*', MUST_EXIST);
            $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'assignment' => [
                    'id' => (int)$assign->id,
                    'cmid' => (int)$cm->id,
                    'name' => (string)$assign->name,
                    'duedate' => (int)$assign->duedate,
                    'cutoffdate' => (int)$assign->cutoffdate,
                ],
                'submission' => [
                    'id' => (int)$submission->id,
                    'status' => (string)$submission->status,
                    'attemptnumber' => (int)$submission->attemptnumber,
                    'timemodified' => (int)$submission->timemodified,
                    'text' => (string)($onlinetext->onlinetext ?? ''),
                    'format' => (int)($onlinetext->onlineformat ?? $params['format']),
                    'wordcount' => count_words(strip_tags((string)($onlinetext->onlinetext ?? ''))),
                ],
                'preview' => $preview,
                'warnings' => array_map(static function(array $warning): array {
                    return [
                        'item' => (string)($warning['item'] ?? ''),
                        'warningcode' => (string)($warning['warningcode'] ?? ''),
                        'message' => (string)($warning['message'] ?? ''),
                    ];
                }, $warnings),
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'assignment_save_draft_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'course' => self::empty_course_payload(),
                    'assignment' => ['id' => (int)$params['assignid'], 'cmid' => 0, 'name' => '', 'duedate' => 0, 'cutoffdate' => 0],
                    'submission' => ['id' => 0, 'status' => '', 'attemptnumber' => 0, 'timemodified' => 0, 'text' => '', 'format' => (int)$params['format'], 'wordcount' => 0],
                    'preview' => ['text_preview' => '', 'format' => (int)$params['format'], 'text_length' => 0],
                    'warnings' => [],
                ]
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for assignment_save_draft.
     *
     * @return \core_external\external_description
     */
    public static function assignment_save_draft_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'assignment' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Assignment id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Assignment name'),
                    'duedate' => new external_value(PARAM_INT, 'Due date'),
                    'cutoffdate' => new external_value(PARAM_INT, 'Cutoff date'),
                ]),
                'submission' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Submission id'),
                    'status' => new external_value(PARAM_TEXT, 'Submission status'),
                    'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified timestamp'),
                    'text' => new external_value(PARAM_RAW, 'Saved online text'),
                    'format' => new external_value(PARAM_INT, 'Text format'),
                    'wordcount' => new external_value(PARAM_INT, 'Word count'),
                ]),
                'preview' => new external_single_structure([
                    'text_preview' => new external_value(PARAM_RAW, 'Preview of the submitted text'),
                    'format' => new external_value(PARAM_INT, 'Text format'),
                    'text_length' => new external_value(PARAM_INT, 'Character length'),
                ]),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item'),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code'),
                        'message' => new external_value(PARAM_RAW, 'Warning message'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for assignment_submit_final.
     *
     * @return external_function_parameters
     */
    public static function assignment_submit_final_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'assignid' => new external_value(PARAM_INT, 'Assignment id', VALUE_REQUIRED),
            'accept_submission_statement' => new external_value(PARAM_BOOL, 'Accept submission statement', VALUE_DEFAULT, false),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Submit an assignment for grading.
     *
     * @param string $idempotency_key
     * @param int $assignid
     * @param bool $accept_submission_statement
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function assignment_submit_final(
        string $idempotency_key,
        int $assignid,
        bool $accept_submission_statement,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::assignment_submit_final_parameters(), [
            'idempotency_key' => $idempotency_key,
            'assignid' => $assignid,
            'accept_submission_statement' => $accept_submission_statement,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'assignment_submit_final';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error(
            $USER->id,
            $action,
            $params['idempotency_key'],
            $requesthash,
            (bool)$params['dry_run'],
            $params
        );
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $assign = $DB->get_record('assign', ['id' => $params['assignid']], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($assign, 'assign');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            require_capability('mod/assign:submit', $context);

            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $USER->id,
                'latest' => 1,
            ]);
            $payloadassignment = [
                'id' => (int)$assign->id,
                'cmid' => (int)$cm->id,
                'name' => (string)$assign->name,
                'duedate' => (int)$assign->duedate,
                'cutoffdate' => (int)$assign->cutoffdate,
            ];
            $payloadsubmission = [
                'id' => $submission ? (int)$submission->id : 0,
                'status' => $submission ? (string)$submission->status : 'new',
                'attemptnumber' => $submission ? (int)$submission->attemptnumber : 0,
                'timemodified' => $submission ? (int)$submission->timemodified : 0,
                'submittedforgrading' => $submission ? ((string)$submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED ? 1 : 0) : 0,
            ];

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'assignment' => $payloadassignment,
                    'submission' => $payloadsubmission,
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $warnings = mod_assign_external::submit_for_grading((int)$assign->id, (bool)$params['accept_submission_statement']);
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $USER->id,
                'latest' => 1,
            ], '*', MUST_EXIST);

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'assignment' => $payloadassignment,
                'submission' => [
                    'id' => (int)$submission->id,
                    'status' => (string)$submission->status,
                    'attemptnumber' => (int)$submission->attemptnumber,
                    'timemodified' => (int)$submission->timemodified,
                    'submittedforgrading' => (string)$submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED ? 1 : 0,
                ],
                'warnings' => array_map(static function(array $warning): array {
                    return [
                        'item' => (string)($warning['item'] ?? ''),
                        'warningcode' => (string)($warning['warningcode'] ?? ''),
                        'message' => (string)($warning['message'] ?? ''),
                    ];
                }, $warnings),
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'assignment_submit_final_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'course' => self::empty_course_payload(),
                    'assignment' => ['id' => (int)$params['assignid'], 'cmid' => 0, 'name' => '', 'duedate' => 0, 'cutoffdate' => 0],
                    'submission' => ['id' => 0, 'status' => '', 'attemptnumber' => 0, 'timemodified' => 0, 'submittedforgrading' => 0],
                    'warnings' => [],
                ]
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for assignment_submit_final.
     *
     * @return \core_external\external_description
     */
    public static function assignment_submit_final_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'assignment' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Assignment id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Assignment name'),
                    'duedate' => new external_value(PARAM_INT, 'Due date'),
                    'cutoffdate' => new external_value(PARAM_INT, 'Cutoff date'),
                ]),
                'submission' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Submission id'),
                    'status' => new external_value(PARAM_TEXT, 'Submission status'),
                    'attemptnumber' => new external_value(PARAM_INT, 'Attempt number'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified timestamp'),
                    'submittedforgrading' => new external_value(PARAM_BOOL, 'Whether submission is final'),
                ]),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item'),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code'),
                        'message' => new external_value(PARAM_RAW, 'Warning message'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for forum_create_discussion.
     *
     * @return external_function_parameters
     */
    public static function forum_create_discussion_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'forumid' => new external_value(PARAM_INT, 'Forum id', VALUE_REQUIRED),
            'subject' => new external_value(PARAM_TEXT, 'Discussion subject', VALUE_REQUIRED),
            'message' => new external_value(PARAM_RAW, 'Discussion message', VALUE_REQUIRED),
            'groupid' => new external_value(PARAM_INT, 'Optional group id', VALUE_DEFAULT, 0),
            'subscribe' => new external_value(PARAM_BOOL, 'Subscribe to discussion', VALUE_DEFAULT, true),
            'pinned' => new external_value(PARAM_BOOL, 'Pin discussion when allowed', VALUE_DEFAULT, false),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Create a forum discussion.
     *
     * @param string $idempotency_key
     * @param int $forumid
     * @param string $subject
     * @param string $message
     * @param int $groupid
     * @param bool $subscribe
     * @param bool $pinned
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function forum_create_discussion(
        string $idempotency_key,
        int $forumid,
        string $subject,
        string $message,
        int $groupid,
        bool $subscribe,
        bool $pinned,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::forum_create_discussion_parameters(), [
            'idempotency_key' => $idempotency_key,
            'forumid' => $forumid,
            'subject' => $subject,
            'message' => $message,
            'groupid' => $groupid,
            'subscribe' => $subscribe,
            'pinned' => $pinned,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'forum_create_discussion';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error(
            $USER->id,
            $action,
            $params['idempotency_key'],
            $requesthash,
            (bool)$params['dry_run'],
            $params
        );
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $forum = $DB->get_record('forum', ['id' => $params['forumid']], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($forum, 'forum');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            require_capability('mod/forum:startdiscussion', $context);

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'forum' => [
                        'id' => (int)$forum->id,
                        'cmid' => (int)$cm->id,
                        'name' => (string)$forum->name,
                        'type' => (string)$forum->type,
                    ],
                    'discussion' => [
                        'id' => 0,
                        'postid' => 0,
                        'subject' => (string)$params['subject'],
                        'message_preview' => core_text::substr(trim(strip_tags((string)$params['message'])), 0, 240),
                        'authorid' => (int)$USER->id,
                        'authorname' => fullname($USER),
                        'created' => 0,
                        'url' => '',
                    ],
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $result = mod_forum_external::add_discussion(
                (int)$forum->id,
                (string)$params['subject'],
                (string)$params['message'],
                (int)$params['groupid'],
                [
                    ['name' => 'discussionsubscribe', 'value' => $params['subscribe'] ? '1' : '0'],
                    ['name' => 'discussionpinned', 'value' => $params['pinned'] ? '1' : '0'],
                ]
            );
            $discussion = $DB->get_record('forum_discussions', ['id' => $result['discussionid']], '*', MUST_EXIST);
            $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'forum' => [
                    'id' => (int)$forum->id,
                    'cmid' => (int)$cm->id,
                    'name' => (string)$forum->name,
                    'type' => (string)$forum->type,
                ],
                'discussion' => [
                    'id' => (int)$discussion->id,
                    'postid' => (int)$discussion->firstpost,
                    'subject' => (string)$discussion->name,
                    'message_preview' => core_text::substr(trim(strip_tags((string)$post->message)), 0, 240),
                    'authorid' => (int)$post->userid,
                    'authorname' => fullname(core_user::get_user($post->userid, '*', MUST_EXIST)),
                    'created' => (int)$discussion->timemodified,
                    'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$discussion->id]))->out(false),
                ],
                'warnings' => array_map(static function(array $warning): array {
                    return [
                        'item' => (string)($warning['item'] ?? ''),
                        'warningcode' => (string)($warning['warningcode'] ?? ''),
                        'message' => (string)($warning['message'] ?? ''),
                    ];
                }, $result['warnings'] ?? []),
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'forum_create_discussion_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'course' => self::empty_course_payload(),
                    'forum' => ['id' => (int)$params['forumid'], 'cmid' => 0, 'name' => '', 'type' => ''],
                    'discussion' => ['id' => 0, 'postid' => 0, 'subject' => '', 'message_preview' => '', 'authorid' => 0, 'authorname' => '', 'created' => 0, 'url' => ''],
                    'warnings' => [],
                ]
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for forum_create_discussion.
     *
     * @return \core_external\external_description
     */
    public static function forum_create_discussion_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'forum' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Forum name'),
                    'type' => new external_value(PARAM_RAW, 'Forum type'),
                ]),
                'discussion' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Discussion id'),
                    'postid' => new external_value(PARAM_INT, 'First post id'),
                    'subject' => new external_value(PARAM_RAW, 'Discussion subject'),
                    'message_preview' => new external_value(PARAM_RAW, 'Message preview'),
                    'authorid' => new external_value(PARAM_INT, 'Author user id'),
                    'authorname' => new external_value(PARAM_RAW, 'Author full name'),
                    'created' => new external_value(PARAM_INT, 'Created timestamp'),
                    'url' => new external_value(PARAM_RAW, 'Discussion URL'),
                ]),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item'),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code'),
                        'message' => new external_value(PARAM_RAW, 'Warning message'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for forum_reply_post.
     *
     * @return external_function_parameters
     */
    public static function forum_reply_post_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'postid' => new external_value(PARAM_INT, 'Parent post id', VALUE_REQUIRED),
            'subject' => new external_value(PARAM_TEXT, 'Reply subject', VALUE_REQUIRED),
            'message' => new external_value(PARAM_RAW, 'Reply message', VALUE_REQUIRED),
            'subscribe' => new external_value(PARAM_BOOL, 'Subscribe to the discussion', VALUE_DEFAULT, true),
            'private_reply' => new external_value(PARAM_BOOL, 'Private reply when forum supports it', VALUE_DEFAULT, false),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Reply to a forum post.
     *
     * @param string $idempotency_key
     * @param int $postid
     * @param string $subject
     * @param string $message
     * @param bool $subscribe
     * @param bool $private_reply
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function forum_reply_post(
        string $idempotency_key,
        int $postid,
        string $subject,
        string $message,
        bool $subscribe,
        bool $private_reply,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::forum_reply_post_parameters(), [
            'idempotency_key' => $idempotency_key,
            'postid' => $postid,
            'subject' => $subject,
            'message' => $message,
            'subscribe' => $subscribe,
            'private_reply' => $private_reply,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'forum_reply_post';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error(
            $USER->id,
            $action,
            $params['idempotency_key'],
            $requesthash,
            (bool)$params['dry_run'],
            $params
        );
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $parent = forum_get_post_full((int)$params['postid']);
            if (!$parent) {
                throw new \moodle_exception('invalidparentpostid', 'forum');
            }
            $discussion = $DB->get_record('forum_discussions', ['id' => $parent->discussion], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($forum, 'forum');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            require_capability('mod/forum:replypost', $context);

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'forum' => [
                        'id' => (int)$forum->id,
                        'cmid' => (int)$cm->id,
                        'name' => (string)$forum->name,
                        'discussionid' => (int)$discussion->id,
                    ],
                    'reply' => [
                        'postid' => 0,
                        'parentpostid' => (int)$parent->id,
                        'discussionid' => (int)$discussion->id,
                        'subject' => (string)$params['subject'],
                        'message_preview' => core_text::substr(trim(strip_tags((string)$params['message'])), 0, 240),
                        'authorid' => (int)$USER->id,
                        'authorname' => fullname($USER),
                        'created' => 0,
                        'url' => '',
                    ],
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $result = mod_forum_external::add_discussion_post(
                (int)$parent->id,
                (string)$params['subject'],
                (string)$params['message'],
                [
                    ['name' => 'discussionsubscribe', 'value' => $params['subscribe'] ? '1' : '0'],
                    ['name' => 'private', 'value' => $params['private_reply'] ? '1' : '0'],
                ],
                FORMAT_HTML
            );
            $post = $DB->get_record('forum_posts', ['id' => $result['postid']], '*', MUST_EXIST);

            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'forum' => [
                    'id' => (int)$forum->id,
                    'cmid' => (int)$cm->id,
                    'name' => (string)$forum->name,
                    'discussionid' => (int)$discussion->id,
                ],
                'reply' => [
                    'postid' => (int)$post->id,
                    'parentpostid' => (int)$post->parent,
                    'discussionid' => (int)$post->discussion,
                    'subject' => (string)$post->subject,
                    'message_preview' => core_text::substr(trim(strip_tags((string)$post->message)), 0, 240),
                    'authorid' => (int)$post->userid,
                    'authorname' => fullname(core_user::get_user($post->userid, '*', MUST_EXIST)),
                    'created' => (int)$post->created,
                    'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$post->discussion]))->out(false) . '#p' . (int)$post->id,
                ],
                'warnings' => array_map(static function(array $warning): array {
                    return [
                        'item' => (string)($warning['item'] ?? ''),
                        'warningcode' => (string)($warning['warningcode'] ?? ''),
                        'message' => (string)($warning['message'] ?? ''),
                    ];
                }, $result['warnings'] ?? []),
            ]);
            self::audit($USER->id, $action, true, $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'forum_reply_post_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'course' => self::empty_course_payload(),
                    'forum' => ['id' => 0, 'cmid' => 0, 'name' => '', 'discussionid' => 0],
                    'reply' => ['postid' => 0, 'parentpostid' => (int)$params['postid'], 'discussionid' => 0, 'subject' => '', 'message_preview' => '', 'authorid' => 0, 'authorname' => '', 'created' => 0, 'url' => ''],
                    'warnings' => [],
                ]
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for forum_reply_post.
     *
     * @return \core_external\external_description
     */
    public static function forum_reply_post_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'forum' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Forum name'),
                    'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                ]),
                'reply' => new external_single_structure([
                    'postid' => new external_value(PARAM_INT, 'Post id'),
                    'parentpostid' => new external_value(PARAM_INT, 'Parent post id'),
                    'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                    'subject' => new external_value(PARAM_RAW, 'Reply subject'),
                    'message_preview' => new external_value(PARAM_RAW, 'Message preview'),
                    'authorid' => new external_value(PARAM_INT, 'Author user id'),
                    'authorname' => new external_value(PARAM_RAW, 'Author full name'),
                    'created' => new external_value(PARAM_INT, 'Created timestamp'),
                    'url' => new external_value(PARAM_RAW, 'Reply URL'),
                ]),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item'),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code'),
                        'message' => new external_value(PARAM_RAW, 'Warning message'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for forum_update_post.
     *
     * @return external_function_parameters
     */
    public static function forum_update_post_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'postid' => new external_value(PARAM_INT, 'Forum post id', VALUE_REQUIRED),
            'subject' => new external_value(PARAM_TEXT, 'Updated subject', VALUE_DEFAULT, ''),
            'message' => new external_value(PARAM_RAW, 'Updated message', VALUE_DEFAULT, ''),
            'subscribe' => new external_value(PARAM_BOOL, 'Subscribe discussion', VALUE_DEFAULT, true),
            'pinned' => new external_value(PARAM_BOOL, 'Pin discussion when allowed', VALUE_DEFAULT, false),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Update a forum post or discussion topic.
     *
     * @param string $idempotency_key
     * @param int $postid
     * @param string $subject
     * @param string $message
     * @param bool $subscribe
     * @param bool $pinned
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function forum_update_post(
        string $idempotency_key,
        int $postid,
        string $subject,
        string $message,
        bool $subscribe,
        bool $pinned,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::forum_update_post_parameters(), [
            'idempotency_key' => $idempotency_key,
            'postid' => $postid,
            'subject' => $subject,
            'message' => $message,
            'subscribe' => $subscribe,
            'pinned' => $pinned,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'forum_update_post';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error(
            $USER->id,
            $action,
            $params['idempotency_key'],
            $requesthash,
            (bool)$params['dry_run'],
            $params
        );
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $post = forum_get_post_full((int)$params['postid']);
            if (!$post) {
                throw new \moodle_exception('invalidpostid', 'forum');
            }
            $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($forum, 'forum');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);

            $payloadforum = [
                'id' => (int)$forum->id,
                'cmid' => (int)$cm->id,
                'name' => (string)$forum->name,
                'discussionid' => (int)$discussion->id,
            ];
            $payloadpost = [
                'postid' => (int)$post->id,
                'parentpostid' => (int)$post->parent,
                'discussionid' => (int)$post->discussion,
                'subject' => $params['subject'] !== '' ? (string)$params['subject'] : (string)$post->subject,
                'message_preview' => core_text::substr(trim(strip_tags($params['message'] !== '' ? (string)$params['message'] : (string)$post->message)), 0, 240),
                'authorid' => (int)$post->userid,
                'authorname' => fullname(core_user::get_user($post->userid, '*', MUST_EXIST)),
                'created' => (int)$post->created,
                'updated' => (int)$post->modified,
                'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$post->discussion]))->out(false) . '#p' . (int)$post->id,
            ];

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'forum' => $payloadforum,
                    'post' => $payloadpost,
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $result = mod_forum_external::update_discussion_post(
                (int)$post->id,
                (string)$params['subject'],
                (string)$params['message'],
                FORMAT_HTML,
                [
                    ['name' => 'discussionsubscribe', 'value' => $params['subscribe'] ? '1' : '0'],
                    ['name' => 'pinned', 'value' => $params['pinned'] ? '1' : '0'],
                ]
            );
            $post = forum_get_post_full((int)$post->id);
            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'forum' => $payloadforum,
                'post' => [
                    'postid' => (int)$post->id,
                    'parentpostid' => (int)$post->parent,
                    'discussionid' => (int)$post->discussion,
                    'subject' => (string)$post->subject,
                    'message_preview' => core_text::substr(trim(strip_tags((string)$post->message)), 0, 240),
                    'authorid' => (int)$post->userid,
                    'authorname' => fullname(core_user::get_user($post->userid, '*', MUST_EXIST)),
                    'created' => (int)$post->created,
                    'updated' => (int)$post->modified,
                    'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$post->discussion]))->out(false) . '#p' . (int)$post->id,
                ],
                'warnings' => array_map(static function(array $warning): array {
                    return [
                        'item' => (string)($warning['item'] ?? ''),
                        'warningcode' => (string)($warning['warningcode'] ?? ''),
                        'message' => (string)($warning['message'] ?? ''),
                    ];
                }, $result['warnings'] ?? []),
            ]);
            self::audit($USER->id, $action, (bool)($result['status'] ?? false), $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'forum_update_post_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'course' => self::empty_course_payload(),
                    'forum' => ['id' => 0, 'cmid' => 0, 'name' => '', 'discussionid' => 0],
                    'post' => ['postid' => (int)$params['postid'], 'parentpostid' => 0, 'discussionid' => 0, 'subject' => '', 'message_preview' => '', 'authorid' => 0, 'authorname' => '', 'created' => 0, 'updated' => 0, 'url' => ''],
                    'warnings' => [],
                ]
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for forum_update_post.
     *
     * @return \core_external\external_description
     */
    public static function forum_update_post_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'forum' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Forum name'),
                    'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                ]),
                'post' => new external_single_structure([
                    'postid' => new external_value(PARAM_INT, 'Post id'),
                    'parentpostid' => new external_value(PARAM_INT, 'Parent post id'),
                    'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                    'subject' => new external_value(PARAM_RAW, 'Subject'),
                    'message_preview' => new external_value(PARAM_RAW, 'Message preview'),
                    'authorid' => new external_value(PARAM_INT, 'Author user id'),
                    'authorname' => new external_value(PARAM_RAW, 'Author full name'),
                    'created' => new external_value(PARAM_INT, 'Created timestamp'),
                    'updated' => new external_value(PARAM_INT, 'Updated timestamp'),
                    'url' => new external_value(PARAM_RAW, 'Post URL'),
                ]),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item'),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code'),
                        'message' => new external_value(PARAM_RAW, 'Warning message'),
                    ])
                ),
            ])
        );
    }

    /**
     * Parameters for forum_delete_post.
     *
     * @return external_function_parameters
     */
    public static function forum_delete_post_parameters(): external_function_parameters {
        return new external_function_parameters([
            'idempotency_key' => new external_value(PARAM_ALPHANUMEXT, 'Client-provided idempotency key', VALUE_REQUIRED),
            'postid' => new external_value(PARAM_INT, 'Forum post id', VALUE_REQUIRED),
            'dry_run' => new external_value(PARAM_BOOL, 'Preview only', VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT, 'Optional audit reason', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Delete a forum post or whole discussion.
     *
     * @param string $idempotency_key
     * @param int $postid
     * @param bool $dry_run
     * @param string $reason
     * @return array
     */
    public static function forum_delete_post(
        string $idempotency_key,
        int $postid,
        bool $dry_run,
        string $reason
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::forum_delete_post_parameters(), [
            'idempotency_key' => $idempotency_key,
            'postid' => $postid,
            'dry_run' => $dry_run,
            'reason' => $reason,
        ]);

        self::restricted_context();

        $action = 'forum_delete_post';
        $requesthash = self::request_hash($params);
        $replay = self::idempotency_replay_or_error(
            $USER->id,
            $action,
            $params['idempotency_key'],
            $requesthash,
            (bool)$params['dry_run'],
            $params
        );
        if ($replay !== null) {
            return $replay;
        }

        $auditid = self::uuid_v4();

        try {
            $post = forum_get_post_full((int)$params['postid']);
            if (!$post) {
                throw new \moodle_exception('invalidpostid', 'forum');
            }
            $discussion = $DB->get_record('forum_discussions', ['id' => $post->discussion], '*', MUST_EXIST);
            $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
            [$course, $cm] = get_course_and_cm_from_instance($forum, 'forum');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);

            $target = [
                'postid' => (int)$post->id,
                'discussionid' => (int)$post->discussion,
                'kind' => (int)$post->parent === 0 ? 'discussion' : 'reply',
                'subject' => (string)$post->subject,
                'authorid' => (int)$post->userid,
                'authorname' => fullname(core_user::get_user($post->userid, '*', MUST_EXIST)),
                'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int)$post->discussion]))->out(false) . '#p' . (int)$post->id,
            ];

            if ($params['dry_run']) {
                $response = self::response_ok($auditid, [
                    'course' => self::course_payload($course),
                    'forum' => [
                        'id' => (int)$forum->id,
                        'cmid' => (int)$cm->id,
                        'name' => (string)$forum->name,
                    ],
                    'target' => $target,
                    'warnings' => [],
                ], true);
                self::audit($USER->id, $action, true, $auditid, $params, $response);
                return $response;
            }

            $result = mod_forum_external::delete_post((int)$post->id);
            $response = self::response_ok($auditid, [
                'course' => self::course_payload($course),
                'forum' => [
                    'id' => (int)$forum->id,
                    'cmid' => (int)$cm->id,
                    'name' => (string)$forum->name,
                ],
                'target' => $target,
                'warnings' => array_map(static function(array $warning): array {
                    return [
                        'item' => (string)($warning['item'] ?? ''),
                        'warningcode' => (string)($warning['warningcode'] ?? ''),
                        'message' => (string)($warning['message'] ?? ''),
                    ];
                }, $result['warnings'] ?? []),
            ]);
            self::audit($USER->id, $action, (bool)($result['status'] ?? false), $auditid, $params, $response);
            self::store_idempotent_response($USER->id, $action, $params['idempotency_key'], $requesthash, $response);
            return $response;
        } catch (\Throwable $e) {
            $response = self::response_error(
                $auditid,
                'forum_delete_post_failed',
                $e->getMessage(),
                (bool)$params['dry_run'],
                false,
                [
                    'course' => self::empty_course_payload(),
                    'forum' => ['id' => 0, 'cmid' => 0, 'name' => ''],
                    'target' => ['postid' => (int)$params['postid'], 'discussionid' => 0, 'kind' => '', 'subject' => '', 'authorid' => 0, 'authorname' => '', 'url' => ''],
                    'warnings' => [],
                ]
            );
            self::audit($USER->id, $action, false, $auditid, $params, $response);
            return $response;
        }
    }

    /**
     * Returns for forum_delete_post.
     *
     * @return \core_external\external_description
     */
    public static function forum_delete_post_returns(): \core_external\external_description {
        return self::envelope_returns(
            new external_single_structure([
                'course' => self::course_structure(),
                'forum' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'name' => new external_value(PARAM_RAW, 'Forum name'),
                ]),
                'target' => new external_single_structure([
                    'postid' => new external_value(PARAM_INT, 'Deleted post id'),
                    'discussionid' => new external_value(PARAM_INT, 'Discussion id'),
                    'kind' => new external_value(PARAM_TEXT, 'discussion or reply'),
                    'subject' => new external_value(PARAM_RAW, 'Post subject'),
                    'authorid' => new external_value(PARAM_INT, 'Author user id'),
                    'authorname' => new external_value(PARAM_RAW, 'Author full name'),
                    'url' => new external_value(PARAM_RAW, 'Original post URL'),
                ]),
                'warnings' => new external_multiple_structure(
                    new external_single_structure([
                        'item' => new external_value(PARAM_RAW, 'Warning item'),
                        'warningcode' => new external_value(PARAM_RAW, 'Warning code'),
                        'message' => new external_value(PARAM_RAW, 'Warning message'),
                    ])
                ),
            ])
        );
    }

    /**
     * Generate a UUIDv4 without external deps.
     *
     * @return string
     */
    private static function uuid_v4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
