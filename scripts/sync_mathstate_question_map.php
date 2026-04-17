<?php
// Sync question_map bridge rows from sidecar json/jsonl into local_mathstate.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(
    [
        'help' => false,
        'input' => null,
        'limit' => 0,
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognised)) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognised));
}

if (!empty($options['help']) || empty($options['input'])) {
    echo <<<TEXT
Sync local_mathstate question_map bridge rows from sidecar JSON/JSONL.

Options:
--input   Path to questions.jsonl or question-lesson-links.jsonl
--limit   Optional limit for smoke runs
-h, --help

TEXT;
    exit(empty($options['input']) ? 1 : 0);
}

\core\session\manager::set_user(get_admin());

$path = $options['input'];
if (!is_file($path)) {
    cli_error('Input file not found: ' . $path);
}

$rows = [];
if (preg_match('/\.json$/i', $path)) {
    $decoded = json_decode(file_get_contents($path), true);
    if (!is_array($decoded)) {
        cli_error('Invalid JSON input.');
    }
    $rows = array_values($decoded);
} else {
    $handle = fopen($path, 'r');
    if ($handle === false) {
        cli_error('Unable to open input file.');
    }
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    fclose($handle);
}

$limit = (int)$options['limit'];
if ($limit > 0) {
    $rows = array_slice($rows, 0, $limit);
}

$result = \local_mathstate\local\storage\question_map_store::sync_sidecar_batch($rows);

echo json_encode([
    'ok' => true,
    'input' => $path,
    'total_rows' => count($rows),
    'synced_count' => count($result['synced']),
    'unresolved_count' => count($result['unresolved']),
    'ambiguous_count' => count($result['ambiguous']),
    'synced' => array_slice($result['synced'], 0, 20),
    'unresolved' => array_slice($result['unresolved'], 0, 20),
    'ambiguous' => array_slice($result['ambiguous'], 0, 20),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
