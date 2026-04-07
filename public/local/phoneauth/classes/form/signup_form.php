<?php

namespace local_phoneauth\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/user/editlib.php');

class signup_form extends \moodleform {
    public function definition() {
        global $CFG, $PAGE;

        $mform = $this->_form;

        $mform->addElement('text', 'phone', get_string('phone', 'local_phoneauth'), 'maxlength="11" size="12"');
        $mform->setType('phone', PARAM_RAW_TRIMMED);
        $mform->addRule('phone', get_string('required'), 'required', null, 'client');

        $verifygroup = [];
        $verifygroup[] = $mform->createElement('text', 'verifycode', '', 'maxlength="6" size="6"');
        $verifygroup[] = $mform->createElement('button', 'sendcode', get_string('sendcode', 'local_phoneauth'), [
            'type' => 'button',
            'class' => 'btn btn-secondary btn-sm',
            'onclick' => 'phoneAuthSendCode(this); return false;',
        ]);
        $mform->addGroup($verifygroup, 'verify_group', get_string('verifycode', 'local_phoneauth'), [' '], false);
        $mform->setType('verifycode', PARAM_RAW_TRIMMED);
        $mform->addRule('verify_group', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'lastname', get_string('lastname'));
        $mform->setType('lastname', PARAM_NOTAGS);
        $mform->addRule('lastname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'firstname', get_string('firstname'));
        $mform->setType('firstname', PARAM_NOTAGS);
        $mform->addRule('firstname', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'email', get_string('email'));
        $mform->setType('email', PARAM_EMAIL);
        $mform->addRule('email', get_string('required'), 'required', null, 'client');

        $mform->addElement('password', 'password', get_string('password'), [
            'maxlength' => MAX_PASSWORD_CHARACTERS,
            'size' => 12,
            'autocomplete' => 'new-password',
        ]);
        $mform->setType('password', \core_user::get_property_type('password'));
        $mform->addRule('password', get_string('required'), 'required', null, 'client');

        $PAGE->requires->js_init_code("\n            window.phoneAuthSendCode = function(button) {\n                var phone = document.querySelector('input[name=\\\"phone\\\"]').value;\n                if (!phone) {\n                    alert('" . addslashes(get_string('phoneinvalid', 'local_phoneauth')) . "');\n                    return;\n                }\n                var xhr = new XMLHttpRequest();\n                xhr.open('POST', '" . $CFG->wwwroot . "/local/phoneauth/send_sms.php', true);\n                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');\n                xhr.onreadystatechange = function() {\n                    if (xhr.readyState !== 4) {\n                        return;\n                    }\n                    var response;\n                    try {\n                        response = JSON.parse(xhr.responseText);\n                    } catch (e) {\n                        alert('SMS request failed');\n                        return;\n                    }\n                    if (!response.success) {\n                        alert(response.message || 'SMS request failed');\n                        return;\n                    }\n                    var time = 60;\n                    button.disabled = true;\n                    var timer = setInterval(function() {\n                        if (time <= 0) {\n                            clearInterval(timer);\n                            button.disabled = false;\n                            button.innerHTML = '" . addslashes(get_string('sendcode', 'local_phoneauth')) . "';\n                        } else {\n                            button.innerHTML = time + 's';\n                            time--;\n                        }\n                    }, 1000);\n                };\n                xhr.send('phone=' + encodeURIComponent(phone));\n            };\n        ");

        $this->set_display_vertical();
        $this->add_action_buttons(true, get_string('createaccount'));
    }

    public function definition_after_data() {
        $mform = $this->_form;
        foreach (['phone', 'verifycode', 'lastname', 'firstname', 'email'] as $field) {
            $mform->applyFilter($field, 'trim');
        }
    }

    public function validation($data, $files) {
        global $CFG, $DB;

        $errors = parent::validation($data, $files);
        require_once($CFG->dirroot . '/local/phoneauth/classes/manager.php');

        $phone = \local_phoneauth\manager::normalize_phone((string) ($data['phone'] ?? ''));
        if (!\local_phoneauth\manager::is_valid_phone($phone)) {
            $errors['phone'] = get_string('phoneinvalid', 'local_phoneauth');
        } else if ($DB->record_exists('user', ['phone1' => $phone])) {
            $errors['phone'] = get_string('phoneexists', 'local_phoneauth');
        }

        if (empty($data['email']) || !validate_email($data['email'])) {
            $errors['email'] = get_string('invalidemail');
        } else if (empty($CFG->allowaccountssameemail) && $DB->record_exists('user', ['email' => $data['email']])) {
            $errors['email'] = get_string('emailexists');
        }

        if (!\local_phoneauth\manager::verify_code($phone, (string) ($data['verifycode'] ?? ''))) {
            $errors['verify_group'] = get_string('verifycodeinvalid', 'local_phoneauth');
        }

        return $errors;
    }
}
