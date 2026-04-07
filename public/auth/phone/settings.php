<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings->add(new admin_setting_heading(
        'auth_phone/settings',
        get_string('pluginname', 'auth_phone'),
        get_string('auth_phone_description', 'auth_phone')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'auth_phone/allowphonelogin',
        get_string('allowphonelogin', 'auth_phone'),
        get_string('allowphonelogin_desc', 'auth_phone'),
        1
    ));
}
