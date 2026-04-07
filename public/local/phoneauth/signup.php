<?php

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
    throw new RuntimeException('Unable to locate config.php for local_phoneauth/signup.php');
}
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/local/phoneauth/classes/manager.php');
require_once($CFG->dirroot . '/local/phoneauth/classes/form/signup_form.php');

global $SESSION;

$PAGE->set_url('/local/phoneauth/signup.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('signupwithphone', 'local_phoneauth'));
$PAGE->set_heading($SITE->fullname);

if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/'));
}

$mform = new \local_phoneauth\form\signup_form();

if ($mform->is_cancelled()) {
    redirect(get_login_url());
} else if ($data = $mform->get_data()) {
    $data = signup_setup_new_user($data);

    $phone = \local_phoneauth\manager::normalize_phone((string) $data->phone);
    if (!\local_phoneauth\manager::verify_code($phone, (string) $data->verifycode)) {
        throw new moodle_exception('verifycodeinvalid', 'local_phoneauth');
    }

    $user = (object) [
        'username' => core_text::strtolower($phone),
        'firstname' => trim((string) $data->firstname),
        'lastname' => trim((string) $data->lastname),
        'email' => trim((string) $data->email),
        'phone1' => $phone,
        'mnethostid' => $CFG->mnet_localhost_id,
        'auth' => 'phone',
        'confirmed' => 1,
        'policyagreed' => 1,
        'password' => (string) $data->password,
    ];

    $userid = user_create_user($user, true, true);
    \local_phoneauth\manager::clear_code();

    $newuser = core_user::get_user($userid);
    complete_user_login($newuser);

    $redirecturl = !empty($SESSION->wantsurl) ? new moodle_url($SESSION->wantsurl) : new moodle_url('/user/profile.php');
    redirect($redirecturl, get_string('welcome', 'moodle', fullname($newuser)), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('signupwithphone', 'local_phoneauth'));
$mform->display();
echo $OUTPUT->footer();
