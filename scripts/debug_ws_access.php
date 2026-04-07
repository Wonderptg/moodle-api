<?php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/webservice/lib.php');

$token = $argv[1] ?? '';
if (!$token) {
    fwrite(STDERR, "Usage: php scripts/debug_ws_access.php <wstoken>\n");
    exit(2);
}

try {
    $ws = new webservice();
    $auth = $ws->authenticate_user($token);
    echo "OK\n";
    echo "user={$auth['user']->username} userid={$auth['user']->id}\n";
    echo "serviceid={$auth['service']->id} servicename={$auth['service']->name}\n";
} catch (Throwable $e) {
    echo "ERROR: " . get_class($e) . "\n";
    if ($e instanceof moodle_exception) {
        echo "errorcode={$e->errorcode}\n";
        echo "message={$e->getMessage()}\n";
        if (!empty($e->debuginfo)) {
            echo "debuginfo={$e->debuginfo}\n";
        }
    } else {
        echo "message={$e->getMessage()}\n";
    }
    exit(1);
}

