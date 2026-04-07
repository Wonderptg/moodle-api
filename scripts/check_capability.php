<?php
define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/accesslib.php');

$userid = (int)($argv[1] ?? 0);
$cap = $argv[2] ?? '';
$contextid = (int)($argv[3] ?? 0);

if (!$userid || !$cap || !$contextid) {
    fwrite(STDERR, "Usage: php scripts/check_capability.php <userid> <capability> <contextid>\n");
    exit(2);
}

// Ensure we are not using stale cached access data when roles/capabilities were changed recently.
accesslib_clear_all_caches(true);

$context = context::instance_by_id($contextid, MUST_EXIST);
echo "userid={$userid} cap={$cap} contextid={$contextid} has=" . (has_capability($cap, $context, $userid) ? '1' : '0') . "\n";
