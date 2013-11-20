<?php

function block_my_courses_api_call(array $params) {
    global $CFG;

    $apiurl   = get_config('block_my_courses', 'api_url');
    $username = get_config('block_my_courses', 'api_user');
    $password = get_config('block_my_courses', 'api_key');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
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
    $sql = "SELECT userid, courseid, timestart
            FROM mdl_user_enrolments ue
            INNER JOIN mdl_enrol e
            ON e.id = ue.enrolid
            WHERE userid = ? AND courseid IN ( ? )";

    // Get starting times from the database
    $sqlobjects = array();
    $sqlobjects = $DB->get_records_sql($sql, array($USER->id, implode(',', $courseids)));

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
