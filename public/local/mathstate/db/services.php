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
 * External functions and service definitions for local_mathstate.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_mathstate_std_kp_upsert_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'std_kp_upsert_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Upsert standard knowledge-point records from math-markdown.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_std_qtype_upsert_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'std_qtype_upsert_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Upsert standard question-type records from math-markdown.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_question_map_upsert_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'question_map_upsert_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Upsert Moodle question to standard qg/kg mappings.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage, moodle/question:useall',
    ],
    'local_mathstate_question_map_sync_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'question_map_sync_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Resolve source_id sidecar rows to Moodle questions and sync question_map bridge rows.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage, moodle/question:useall',
    ],
    'local_mathstate_question_map_lookup' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'question_map_lookup',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Lookup synced question_map bridge rows for frontend or agent debugging.',
        'type' => 'read',
        'capabilities' => 'local/mathstate:manage, moodle/question:useall',
    ],
    'local_mathstate_evidence_ingest_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'evidence_ingest_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Ingest learning evidence and update student math mastery state.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_learning_event_record_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'learning_event_record_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Persist minimal learning events and update simple student runtime state.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_lesson_session_upsert_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'lesson_session_upsert_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Persist minimal lesson session runtime rows.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_review_upsert_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'review_upsert_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Persist minimal review rows.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_doc_job_upsert_batch' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'doc_job_upsert_batch',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Persist minimal doc job rows.',
        'type' => 'write',
        'capabilities' => 'local/mathstate:manage',
    ],
    'local_mathstate_student_summary' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'student_summary',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'Return a student math mastery summary for a course.',
        'type' => 'read',
        'capabilities' => 'local/mathstate:view',
    ],
    'local_mathstate_reviews_due' => [
        'classname' => 'local_mathstate_external',
        'methodname' => 'reviews_due',
        'classpath' => 'local/mathstate/externallib.php',
        'description' => 'List due review tasks for a student.',
        'type' => 'read',
        'capabilities' => 'local/mathstate:view',
    ],
];

$services = [
    'local_mathstate' => [
        'functions' => array_keys($functions),
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'local_mathstate',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
