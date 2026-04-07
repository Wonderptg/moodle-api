<?php
declare(strict_types=1);

define('CLI_SCRIPT', true);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php rehearsal_uninstall_plugins.php /absolute/path/to/config.php component [component...]\n");
    exit(1);
}

$configpath = $argv[1];
if (!is_file($configpath)) {
    fwrite(STDERR, "Config file not found: {$configpath}\n");
    exit(1);
}

require($configpath);
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/upgradelib.php');

$components = array_values(array_unique(array_slice($argv, 2)));
$pluginman = core_plugin_manager::instance();
$progress = new text_progress_trace();
$exitcode = 0;

foreach ($components as $component) {
    $plugininfo = $pluginman->get_plugin_info($component);
    if (!$plugininfo) {
        fwrite(STDERR, "[skip] {$component}: plugin not found in current code tree\n");
        $exitcode = max($exitcode, 2);
        continue;
    }
    if (!$pluginman->can_uninstall_plugin($component)) {
        fwrite(STDERR, "[skip] {$component}: Moodle reports it cannot be uninstalled cleanly\n");
        $exitcode = max($exitcode, 3);
        continue;
    }

    echo "== Uninstalling {$component} ==\n";
    $pluginman->uninstall_plugin($component, $progress);
    echo "== Finished {$component} ==\n";
}

$progress->finished();
exit($exitcode);
