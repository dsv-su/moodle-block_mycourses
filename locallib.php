<?php

function block_my_courses_api_call(array $params) {
    global $CFG;

    $apiurl   = get_config('block_my_courses', 'api_url');
    $username = get_config('block_my_courses', 'api_user');
    $password = get_config('block_my_courses', 'api_key');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    curl_setopt($ch, CURLOPT_URL, $apiurl.implode('/', $params));
    $curlcontents = curl_exec($ch);
    $curlheader  = curl_getinfo($ch);
    curl_close($ch);

    if ($curlheader['http_code'] == 200) {
        // Return fetched data
        return json_decode($curlcontents);

    } else if ($CFG->debugdisplay == true) {
        // Show error
        echo '<pre>';
        echo get_string('servererror', 'block_my_courses')."\n";
        if (!empty($curlheader['http_code'])) {
            echo get_string('curl_header', 'block_my_courses');
            echo ':';
            echo $curlheader['http_code']."\n";
        }
        echo 'URL: '.$apiurl.implode('/', $params);
        echo '</pre>';
    }

    return 'daisydown';
}

function block_my_courses_get_overviews($courses) {
    $htmlarray = array();
    if ($modules = get_plugin_list_with_function('mod', 'print_overview')) {
        // Split courses list into batches with no more than MAX_MODINFO_CACHE_SIZE courses in one batch.
        // Otherwise we exceed the cache limit in get_fast_modinfo() and rebuild it too often.
        if (defined('MAX_MODINFO_CACHE_SIZE') && MAX_MODINFO_CACHE_SIZE > 0 && count($courses) > MAX_MODINFO_CACHE_SIZE) {
            $batches = array_chunk($courses, MAX_MODINFO_CACHE_SIZE, true);
        } else {
            $batches = array($courses);
        }
        foreach ($batches as $courses) {
            foreach ($modules as $fname) {
                $fname($courses, $htmlarray);
            }
        }
    }
    return $htmlarray;
}

function block_my_courses_get_overviews_starttime($courses) {
    global $USER, $DB, $OUTPUT;

    // Collect course id's
    $courseids = array();
    foreach ($courses as $course) {
        $courseids[] = $course->id;
    }

    // Super awesome SQL
    $sql = "SELECT * FROM {user_enrolments} ue INNER JOIN {enrol} e
	ON e.id = ue.enrolid WHERE ue.userid = ".$USER->id." AND e.courseid IN (".implode(',', $courseids).")";

    // Get starting times from the database
    $sqlobjects = array();
    $sqlobjects = $DB->get_records_sql($sql);

    // Collect starting times from data gathered from database
    $starttimes = array();
    foreach ($sqlobjects as $course) {
        $starttimes[$course->courseid] = $course->timestart;
    }

    // Loop over each course, create html code and append to 'result'
    $htmlresult = '';
    foreach ($courses as $course) {
        // Format and append time that this course starts
        $formattedstart = get_string('coursestarts', 'block_my_courses').': ';
        $formattedstart .= date('d M Y', $starttimes[$course->id]);

        $htmlresult .= $OUTPUT->box_start('coursebox');
        $htmlresult .= $OUTPUT->container(html_writer::tag('h3', $course->fullname, array('class' => 'main')));
        $htmlresult .= $OUTPUT->container(html_writer::tag('div', $formattedstart, array('class' => 'upcoming_course_content')));
        $htmlresult .= $OUTPUT->box_end();
    }

    return $htmlresult;
}

function block_my_courses_create_collapsable_list($id, $heading, $content, $collapsed = true) {
    global $PAGE;

    $PAGE->requires->jquery();
    $PAGE->requires->js('/blocks/my_courses/collapse.js');

    // Create javascript action to take when clicked
    $action = "javascript:toggle('cc_$id', 'ch_$id');";

    // Create collapsable list
    // ------------------------

    // Add header
    $class = 'c_header';
    $class .= $collapsed ? ' collapsed' : ' expanded';
    $collapsablelist  = html_writer::start_tag('div',
            array('id' => 'ch_'.$id,
                  'class' => $class,
                  'onclick' => $action)
            );
    $collapsablelist .= html_writer::start_tag('ul');
    $collapsablelist .= html_writer::start_tag('li');
    $collapsablelist .= $heading;
    $collapsablelist .= html_writer::end_tag('li');
    $collapsablelist .= html_writer::end_tag('ul');
    $collapsablelist .= html_writer::end_tag('div');

    // Add content
    $contentdisplay = $collapsed ? 'display: none;' : 'display: block;' ;
    $collapsablelist .= html_writer::start_tag('div',
            array('id' => 'cc_'.$id,
                  'class' => 'c_content',
                  'style' => $contentdisplay));
    $collapsablelist .= $content;
    $collapsablelist .= html_writer::end_tag('div');

    return $collapsablelist;
}

/**
 * Returns array with site courses
 *
 * @return array with site courses
 */
function block_my_courses_get_site_courses() {
    global $USER;

    $courses = enrol_get_my_courses();
    $site = get_site();

    if (array_key_exists($site->id,$courses)) {
        unset($courses[$site->id]);
    }

    foreach ($courses as $c) {
        if (isset($USER->lastcourseaccess[$c->id])) {
            $courses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
        } else {
            $courses[$c->id]->lastaccess = 0;
        }
    }

    // Get remote courses.
    $remotecourses = array();
    if (is_enabled_auth('mnet')) {
        $remotecourses = get_my_remotecourses();
    }
    // Remote courses will have -ve remoteid as key, so it can be differentiated from normal courses
    foreach ($remotecourses as $id => $val) {
        $remoteid = $val->remoteid * -1;
        $val->id = $remoteid;
        $courses[$remoteid] = $val;
    }

    // From list extract site courses for overview
    $sitecourses = array();
    foreach ($courses as $key => $course) {
        if ($course->id > 0) {
            $sitecourses[$key] = $course;
        }
    }

    return $sitecourses;
}

/**
 * Fetches all user courses (upcoming, ongoing and passed)
 *
 * @return array with all courses associated with the user
 */
function block_my_courses_get_all_courses() {
    global $USER;
    $allcourses = enrol_get_users_courses($USER->id, false, 'id, shortname', 'visible DESC,sortorder ASC');

    foreach ($allcourses as $c) {
        if (isset($USER->lastcourseaccess[$c->id])) {
            $allcourses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
        } else {
            $allcourses[$c->id]->lastaccess = 0;
        }
    }

    return $allcourses;
}
