<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

class auth_plugin_phone extends auth_plugin_base {
    public function __construct() {
        $this->authtype = 'phone';
        $this->config = get_config('auth_phone');
    }

    public function is_configured() {
        return true;
    }

    public function can_signup() {
        return true;
    }

    public function signup_form() {
        global $CFG;

        require_once($CFG->dirroot . '/local/phoneauth/classes/form/signup_form.php');
        return new \local_phoneauth\form\signup_form(null, null, 'post', '', ['autocomplete' => 'on']);
    }

    public function user_login($username, $password) {
        global $CFG, $DB;

        if (empty($this->config->allowphonelogin)) {
            return false;
        }

        $username = trim(core_text::strtolower($username));
        $user = null;

        if (self::looks_like_phone($username)) {
            $user = $DB->get_record('user', [
                'phone1' => $username,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
        }

        if (!$user) {
            $user = $DB->get_record('user', [
                'username' => $username,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
        }

        if (!$user) {
            return false;
        }

        return validate_internal_user_password($user, $password);
    }

    public function user_update_password($user, $newpassword) {
        $user = get_complete_user_data('id', $user->id);
        return update_internal_user_password($user, $newpassword);
    }

    public function user_signup($user, $notify = true) {
        global $CFG, $SESSION;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/local/phoneauth/classes/manager.php');

        $phone = \local_phoneauth\manager::normalize_phone((string) ($user->phone ?? ''));
        $verifycode = (string) ($user->verifycode ?? '');

        if (!$phone || !\local_phoneauth\manager::verify_code($phone, $verifycode)) {
            throw new \moodle_exception('verifycodeinvalid', 'local_phoneauth');
        }

        $newuser = (object) [
            'username' => core_text::strtolower($phone),
            'firstname' => trim((string) ($user->firstname ?? '')),
            'lastname' => trim((string) ($user->lastname ?? '')),
            'email' => trim((string) ($user->email ?? '')),
            'phone1' => $phone,
            'mnethostid' => $CFG->mnet_localhost_id,
            'auth' => 'phone',
            'confirmed' => 1,
            'policyagreed' => 1,
            'password' => (string) ($user->password ?? ''),
        ];

        $userid = user_create_user($newuser, true, true);
        \local_phoneauth\manager::clear_code();

        $createduser = core_user::get_user($userid);
        complete_user_login($createduser);

        if ($notify) {
            $redirecturl = !empty($SESSION->wantsurl) ? new \moodle_url($SESSION->wantsurl) : new \moodle_url('/user/profile.php');
            redirect($redirecturl);
        }

        return true;
    }

    public function loginpage_hook() {
        global $CFG, $DB, $frm;

        if (empty($this->config->allowphonelogin) || empty($frm) || !isset($frm->username)) {
            return;
        }

        $candidate = trim(core_text::strtolower((string) $frm->username));
        if (!self::looks_like_phone($candidate)) {
            return;
        }

        $user = $DB->get_record('user', [
            'phone1' => $candidate,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ], 'id,username');

        if ($user && !empty($user->username)) {
            $frm->username = $user->username;
        }
    }

    private static function looks_like_phone(string $value): bool {
        return (bool) preg_match('/^1[3-9][0-9]{9}$/', $value);
    }
}
