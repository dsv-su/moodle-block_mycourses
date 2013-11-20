<?php

defined('MOODLE_INTERNAL') || die;
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext('block_my_courses/api_url', get_string('apiurl', 'block_my_courses'), '', '', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('block_my_courses/api_user', get_string('apiuser', 'block_my_courses'), '', '', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('block_my_courses/api_key', get_string('apikey', 'block_my_courses'), '', '', PARAM_TEXT));
    $settings->add(new admin_setting_configcheckbox('block_my_courses/showwelcomearea', get_string('showwelcomearea', 'block_my_courses'),
        get_string('showwelcomeareadesc', 'block_my_courses'), 1, PARAM_INT));
}