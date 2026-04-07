<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_phoneauth', get_string('pluginname', 'local_phoneauth'));

    $settings->add(new admin_setting_heading(
        'local_phoneauth/settings',
        get_string('pluginname', 'local_phoneauth'),
        get_string('settings_intro', 'local_phoneauth')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_phoneauth/demo_mode',
        get_string('demo_mode', 'local_phoneauth'),
        get_string('demo_mode_desc', 'local_phoneauth'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_phoneauth/send_interval',
        get_string('send_interval', 'local_phoneauth'),
        get_string('send_interval_desc', 'local_phoneauth'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_phoneauth/code_expiry',
        get_string('code_expiry', 'local_phoneauth'),
        get_string('code_expiry_desc', 'local_phoneauth'),
        300,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_phoneauth/aliyun_accesskeyid',
        get_string('aliyun_accesskeyid', 'local_phoneauth'),
        get_string('aliyun_accesskeyid_desc', 'local_phoneauth'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_phoneauth/aliyun_accesskeysecret',
        get_string('aliyun_accesskeysecret', 'local_phoneauth'),
        get_string('aliyun_accesskeysecret_desc', 'local_phoneauth'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_phoneauth/aliyun_signname',
        get_string('aliyun_signname', 'local_phoneauth'),
        get_string('aliyun_signname_desc', 'local_phoneauth'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_phoneauth/aliyun_templatecode',
        get_string('aliyun_templatecode', 'local_phoneauth'),
        get_string('aliyun_templatecode_desc', 'local_phoneauth'),
        '',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
