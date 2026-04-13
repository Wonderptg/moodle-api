<?php
// Register all local_mathstate external functions into an external service.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'service-shortname' => 'local_mathstate',
        'function-prefix' => 'local_mathstate_',
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognized)) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognized));
}

if (!empty($options['help'])) {
    echo <<<TEXT
Register local_mathstate external functions into an external service.

Options:
--service-shortname  External service shortname (default: local_mathstate)
--function-prefix    Function name prefix to include (default: local_mathstate_)
-h, --help           Show this help

TEXT;
    exit(0);
}

\core\session\manager::set_user(get_admin());

$service = $DB->get_record_select(
    'external_services',
    'shortname = :shortname OR name = :name',
    [
        'shortname' => $options['service-shortname'],
        'name' => $options['service-shortname'],
    ],
    '*'
);
if (!$service) {
    $serviceid = $DB->insert_record('external_services', [
        'name' => $options['service-shortname'],
        'shortname' => $options['service-shortname'],
        'enabled' => 1,
        'requiredcapability' => '',
        'restrictedusers' => 0,
        'component' => 'local_mathstate',
        'timecreated' => time(),
        'timemodified' => time(),
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ]);
    $service = $DB->get_record('external_services', ['id' => $serviceid], '*', MUST_EXIST);
}
$functions = $DB->get_records_select(
    'external_functions',
    $DB->sql_like('name', '?'),
    [$options['function-prefix'] . '%'],
    'name ASC'
);

$added = [];
$existing = [];
foreach ($functions as $function) {
    $link = $DB->get_record('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $function->name,
    ]);
    if ($link) {
        $existing[] = $function->name;
        continue;
    }
    $DB->insert_record('external_services_functions', [
        'externalserviceid' => $service->id,
        'functionname' => $function->name,
    ]);
    $added[] = $function->name;
}

echo json_encode([
    'service' => [
        'id' => (int)$service->id,
        'name' => (string)$service->name,
        'shortname' => (string)$service->shortname,
    ],
    'added' => $added,
    'existing' => $existing,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
