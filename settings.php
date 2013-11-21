<?php

defined('MOODLE_INTERNAL') || die;
if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext('block_my_courses/api_url', get_string('apiurl', 'block_my_courses'), '', '', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('block_my_courses/api_user', get_string('apiuser', 'block_my_courses'), '', '', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('block_my_courses/api_key', get_string('apikey', 'block_my_courses'), '', '', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('block_my_courses/defaultmaxcourses', get_string('defaultmaxcourses', 'block_my_courses'),
        get_string('defaultmaxcoursesdesc', 'block_my_courses'), 10, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('block_my_courses/forcedefaultmaxcourses', get_string('forcedefaultmaxcourses', 'block_my_courses'),
        get_string('forcedefaultmaxcoursesdesc', 'block_my_courses'), 0, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('block_my_courses/showchildren', get_string('showchildren', 'block_my_courses'),
        get_string('showchildrendesc', 'block_my_courses'), 1, PARAM_INT));
    $settings->add(new admin_setting_configcheckbox('block_my_courses/showwelcomearea', get_string('showwelcomearea', 'block_my_courses'),
        get_string('showwelcomeareadesc', 'block_my_courses'), 0, PARAM_INT));
}