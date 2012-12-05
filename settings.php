<?php

defined('MOODLE_INTERNAL') || die;

$settings->add(new admin_setting_configtext('block_my_courses/api_user', get_string('apiuser', 'block_my_courses'), '', '', PARAM_TEXT));
$settings->add(new admin_setting_configtext('block_my_courses/api_key', get_string('apikey', 'block_my_courses'), '', '', PARAM_TEXT));
