<?php
// Cleanup utility for local_mathstate smoke-test rows.
//
// Usage examples:
//   php scripts/cleanup_mathstate_smoke.php --source-id=w2m-math-smoke-1776415387 --force
//   php scripts/cleanup_mathstate_smoke.php --session-key=sess-smoke-1776415387 --force
//   php scripts/cleanup_mathstate_smoke.php --job-key=docjob-smoke-1776415387 --force
//   php scripts/cleanup_mathstate_smoke.php --target-ref=KG-SMOKE-1776415387 --force
//   php scripts/cleanup_mathstate_smoke.php --source-prefix=w2m-math-smoke- --session-prefix=sess-smoke- --job-prefix=docjob-smoke- --target-prefix=KG-SMOKE- --force
//
// Default is dry-run. Use --force to execute deletes.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source-id' => '',
        'session-key' => '',
        'job-key' => '',
        'target-ref' => '',
        'source-prefix' => '',
        'session-prefix' => '',
        'job-prefix' => '',
        'target-prefix' => '',
        'force' => false,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognized)) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = <<<HELP
Cleanup local_mathstate smoke-test rows by identifiers.

Options:
  --source-id=ID         Exact source_id for question_map
  --session-key=KEY      Exact session_key for lesson_session + learning_event
  --job-key=KEY          Exact job_key for doc_job
  --target-ref=REF       Exact target_ref for review_task/doc_job

  --source-prefix=PFX    source_id LIKE "PFX%"
  --session-prefix=PFX   session_key LIKE "PFX%"
  --job-prefix=PFX       job_key LIKE "PFX%"
  --target-prefix=PFX    target_ref LIKE "PFX%"

  --force                Execute deletion (without this: dry-run)
  -h, --help             Show help

Examples:
  php scripts/cleanup_mathstate_smoke.php --source-id=w2m-math-smoke-123 --force
  php scripts/cleanup_mathstate_smoke.php --session-key=sess-smoke-123 --force
  php scripts/cleanup_mathstate_smoke.php --job-key=docjob-smoke-123 --force
  php scripts/cleanup_mathstate_smoke.php --target-ref=KG-SMOKE-123 --force
  php scripts/cleanup_mathstate_smoke.php --source-prefix=w2m-math-smoke- --session-prefix=sess-smoke- --job-prefix=docjob-smoke- --target-prefix=KG-SMOKE- --force
HELP;

if (!empty($options['help'])) {
    echo $help . PHP_EOL;
    exit(0);
}

$sourceid = trim((string)$options['source-id']);
$sessionkey = trim((string)$options['session-key']);
$jobkey = trim((string)$options['job-key']);
$targetref = trim((string)$options['target-ref']);
$sourceprefix = trim((string)$options['source-prefix']);
$sessionprefix = trim((string)$options['session-prefix']);
$jobprefix = trim((string)$options['job-prefix']);
$targetprefix = trim((string)$options['target-prefix']);
$force = !empty($options['force']);

$hasfilter = ($sourceid !== '' || $sessionkey !== '' || $jobkey !== '' || $targetref !== ''
    || $sourceprefix !== '' || $sessionprefix !== '' || $jobprefix !== '' || $targetprefix !== '');

if (!$hasfilter) {
    cli_error("No filter provided. Use --source-id/--session-key/--job-key/--target-ref or prefix filters. Use --help for usage.");
}

/**
 * @return array{0:string,1:array}
 */
function make_where(string $field, string $exact, string $prefix, string $parambase): array {
    $parts = [];
    $params = [];
    if ($exact !== '') {
        $parts[] = "{$field} = :{$parambase}exact";
        $params[$parambase . 'exact'] = $exact;
    }
    if ($prefix !== '') {
        $parts[] = "{$field} LIKE :{$parambase}pfx";
        $params[$parambase . 'pfx'] = $prefix . '%';
    }
    if (empty($parts)) {
        return ['', []];
    }
    return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * @return array{where:string, params:array}
 */
function combine_filters(array $chunks): array {
    $whereparts = [];
    $params = [];
    foreach ($chunks as $chunk) {
        if (!is_array($chunk) || count($chunk) !== 2) {
            continue;
        }
        [$where, $chunkparams] = $chunk;
        if ($where !== '') {
            $whereparts[] = $where;
            $params = array_merge($params, $chunkparams);
        }
    }
    return [
        'where' => implode(' AND ', $whereparts),
        'params' => $params,
    ];
}

$plans = [];

// question_map by source_id
$mapfilter = combine_filters([
    make_where('source_id', $sourceid, $sourceprefix, 'mapsrc'),
]);
if ($mapfilter['where'] !== '') {
    $plans[] = [
        'table' => 'local_mathstate_question_map',
        'where' => $mapfilter['where'],
        'params' => $mapfilter['params'],
    ];
}

// learning_event + lesson_session by session_key
$sessionfilter = combine_filters([
    make_where('session_key', $sessionkey, $sessionprefix, 'sess'),
]);
if ($sessionfilter['where'] !== '') {
    $plans[] = [
        'table' => 'local_mathstate_learning_event',
        'where' => $sessionfilter['where'],
        'params' => $sessionfilter['params'],
    ];
    $plans[] = [
        'table' => 'local_mathstate_lesson_session',
        'where' => $sessionfilter['where'],
        'params' => $sessionfilter['params'],
    ];
}

// review_task by target_ref
$reviewfilter = combine_filters([
    make_where('target_ref', $targetref, $targetprefix, 'reviewref'),
]);
if ($reviewfilter['where'] !== '') {
    $plans[] = [
        'table' => 'local_mathstate_review_task',
        'where' => $reviewfilter['where'],
        'params' => $reviewfilter['params'],
    ];
}

// doc_job by job_key and/or target_ref
$docfilter = combine_filters([
    make_where('job_key', $jobkey, $jobprefix, 'docjob'),
    make_where('target_ref', $targetref, $targetprefix, 'doctarget'),
]);
if ($docfilter['where'] !== '') {
    $plans[] = [
        'table' => 'local_mathstate_doc_job',
        'where' => $docfilter['where'],
        'params' => $docfilter['params'],
    ];
}

if (empty($plans)) {
    cli_error('No actionable filters matched any target tables.');
}

$summary = [
    'dry_run' => !$force,
    'filters' => [
        'source_id' => $sourceid,
        'session_key' => $sessionkey,
        'job_key' => $jobkey,
        'target_ref' => $targetref,
        'source_prefix' => $sourceprefix,
        'session_prefix' => $sessionprefix,
        'job_prefix' => $jobprefix,
        'target_prefix' => $targetprefix,
    ],
    'tables' => [],
];

foreach ($plans as $plan) {
    $table = $plan['table'];
    $where = $plan['where'];
    $params = $plan['params'];

    $count = $DB->count_records_select($table, $where, $params);
    $entry = [
        'table' => $table,
        'where' => $where,
        'count' => (int)$count,
        'deleted' => 0,
    ];

    if ($force && $count > 0) {
        $records = $DB->get_records_select($table, $where, $params, '', 'id');
        $deleted = 0;
        foreach ($records as $record) {
            $DB->delete_records($table, ['id' => $record->id]);
            $deleted++;
        }
        $entry['deleted'] = $deleted;
    }

    $summary['tables'][] = $entry;
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

