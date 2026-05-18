<?php
// Register all local_aiagentapi external functions into a named external service.

define('CLI_SCRIPT', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'service-shortname' => 'local_aiagentapi',
        'function-prefix' => 'local_aiagentapi_',
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
Register local_aiagentapi external functions into an external service.

Options:
--service-shortname  External service shortname (default: local_aiagentapi)
--function-prefix    Function name prefix to include (default: local_aiagentapi_)
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
    $service = (object) [
        'name' => $options['service-shortname'],
        'enabled' => 1,
        'requiredcapability' => 'local/aiagentapi:use',
        'restrictedusers' => 1,
        'component' => 'local_aiagentapi',
        'timecreated' => time(),
        'timemodified' => null,
        'shortname' => $options['service-shortname'],
        'downloadfiles' => 1,
        'uploadfiles' => 1,
    ];
    $service->id = $DB->insert_record('external_services', $service);
} else {
    $updateservice = false;
    if ((string)$service->requiredcapability !== 'local/aiagentapi:use') {
        $service->requiredcapability = 'local/aiagentapi:use';
        $updateservice = true;
    }
    if ((int)$service->restrictedusers !== 1) {
        $service->restrictedusers = 1;
        $updateservice = true;
    }
    if ((int)$service->enabled !== 1) {
        $service->enabled = 1;
        $updateservice = true;
    }
    if ($updateservice) {
        $service->timemodified = time();
        $DB->update_record('external_services', $service);
    }
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
