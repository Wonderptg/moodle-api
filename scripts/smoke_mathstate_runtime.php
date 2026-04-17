<?php
// Minimal runtime storage smoke script for local_mathstate.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(
    [
        'help' => false,
        'userid' => 0,
        'courseid' => 0,
        'questionid' => 0,
        'lesson-key' => '',
        'session-key' => '',
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognised)) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help']) || empty($options['userid']) || empty($options['courseid'])) {
    echo <<<TEXT
Write one lesson session, one learning event, one review, and one doc job.

Options:
--userid       Required Moodle user id
--courseid     Required course id
--questionid   Optional question id for learning event enrichment
--lesson-key   Optional lesson key
--session-key  Optional session key
-h, --help

TEXT;
    exit((empty($options['userid']) || empty($options['courseid'])) ? 1 : 0);
}

\core\session\manager::set_user(get_admin());

$userid = (int)$options['userid'];
$courseid = (int)$options['courseid'];
$questionid = (int)$options['questionid'];
$lessonkey = trim((string)$options['lesson-key']);
$sessionkey = trim((string)$options['session-key']);

if ($sessionkey === '') {
    $sessionkey = 'smoke-' . $userid . '-' . $courseid . '-' . time();
}

$sessionresult = \local_mathstate\local\storage\runtime_store::upsert_lesson_sessions([[
    'userid' => $userid,
    'courseid' => $courseid,
    'session_key' => $sessionkey,
    'lesson_key' => $lessonkey,
    'status' => 'active',
    'progress' => ['step' => 'smoke'],
    'summary' => ['note' => 'runtime smoke'],
    'started_at' => time(),
    'source' => 'cli-smoke',
]]);

$eventresult = \local_mathstate\local\storage\runtime_store::record_learning_events([[
    'userid' => $userid,
    'courseid' => $courseid,
    'session_key' => $sessionkey,
    'lesson_key' => $lessonkey,
    'questionid' => $questionid,
    'event_type' => 'practice',
    'result' => 'wrong',
    'score' => 0,
    'maxscore' => 1,
    'source' => 'cli-smoke',
    'payload' => ['note' => 'runtime smoke event'],
    'occurred_at' => time(),
]]);

$reviewresult = \local_mathstate\local\storage\runtime_store::upsert_reviews([[
    'userid' => $userid,
    'courseid' => $courseid,
    'target_type' => 'lesson',
    'target_ref' => $lessonkey !== '' ? $lessonkey : $sessionkey,
    'title' => 'Smoke review task',
    'task_kind' => 'review',
    'priority' => 10,
    'source_reason' => 'smoke',
    'payload' => ['session_key' => $sessionkey],
    'status' => 'todo',
    'due_at' => time() + DAYSECS,
    'linked_doc_url' => '',
]]);

$docjobresult = \local_mathstate\local\storage\runtime_store::upsert_doc_jobs([[
    'job_key' => 'smoke-doc-' . $sessionkey,
    'userid' => $userid,
    'courseid' => $courseid,
    'target_type' => 'lesson',
    'target_ref' => $lessonkey !== '' ? $lessonkey : $sessionkey,
    'doc_ref' => 'smoke-doc',
    'provider' => 'cli',
    'job_type' => 'summary',
    'status' => 'queued',
    'request_payload' => ['session_key' => $sessionkey],
    'result_payload' => [],
    'error_message' => '',
    'queued_at' => time(),
]]);

echo json_encode([
    'ok' => true,
    'session' => $sessionresult,
    'event' => $eventresult,
    'review' => $reviewresult,
    'doc_job' => $docjobresult,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
