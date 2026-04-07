<?php

define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

$configcandidates = [
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../../config.php',
];

foreach ($configcandidates as $configcandidate) {
    if (is_readable($configcandidate)) {
        require($configcandidate);
        break;
    }
}

if (!isset($CFG)) {
    throw new RuntimeException('Unable to locate config.php for local_phoneauth/send_sms.php');
}
require_once($CFG->dirroot . '/local/phoneauth/classes/manager.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$phone = required_param('phone', PARAM_RAW_TRIMMED);
$result = \local_phoneauth\manager::send_code($phone);

$payload = [
    'success' => (bool) ($result['ok'] ?? false),
    'message' => (string) ($result['message'] ?? ''),
];

if (!empty(get_config('local_phoneauth', 'demo_mode')) && !empty($result['code'])) {
    $payload['code'] = $result['code'];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
