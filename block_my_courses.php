<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * My courses block
 *
 * @package   blocks
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/lib/weblib.php');
require_once($CFG->dirroot . '/lib/formslib.php');

class block_my_courses extends block_base {
    /**
     * block initializations
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_my_courses');
    }

    /**
     * block contents
     *
     * @return object
     */
    public function get_content() {
        global $USER, $CFG;
        if($this->content !== NULL) {
            return $this->content;
        }
	
        $this->content = new stdClass();
        $this->content->text = '';

        // Get courses
        $ongoingcourses = enrol_get_my_courses('id, shortname, modinfo, sectioncache',
                'visible DESC,sortorder ASC', 20);
        $allcourses = enrol_get_users_courses($USER->id, false, 'id, shortname, modinfo,
                sectioncache', 'visible DESC,sortorder ASC');

        $categorizedcourses = array();
        $categorizedcourses['passed']   = array();
        $categorizedcourses['upcoming'] = array();

        $passedcourseids = array();

        $hasidnumber = false;

        // Check if user has user->idnumber. If not, do not access daisy API
        if (!empty($USER->idnumber)) {
            $hasidnumber = true;

            // Get passed courses
            $apiurl     = 'https://api.dsv.su.se/';
            $username   = get_config('block_my_courses', 'api_user');
            $password   = get_config('block_my_courses', 'api_key');
            $params     = array();
            $params[]   = 'rest';
            $params[]   = 'person';
            $params[]   = $USER->idnumber;
            $params[]   = 'courseSegmentInstances';
            $params[]   = '?onlyPassed=true';
            $ch         = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
            curl_setopt($ch, CURLOPT_URL, $apiurl.implode('/', $params));
            $curlcontents = curl_exec($ch);
            $curlheader  = curl_getinfo($ch);
            curl_close($ch);

            if ($curlheader['http_code'] == 200) {
                // Do something with the received data here
                $passedcourses = json_decode($curlcontents);

                foreach ($passedcourses as $passedcourse) {
                    $passedcourseids[] = $passedcourse->id;
                }

            } else {
                // Create and show an error message
                $error = new stdClass;
                $error->httpcode = $curlheader['http_code'];
                $error->path     = implode('/', $params);
                echo get_string('servererror', 'block_my_courses', $error)."\n";
            }
        }
       
        foreach ($allcourses as $c) {
            if (isset($USER->lastcourseaccess[$c->id])) {
                $allcourses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
            } else {
                $allcourses[$c->id]->lastaccess = 0;
            }
        }

        // Sort courses
        foreach ($allcourses as $course) {
            $instance = context::instance_by_id($course->ctxid);
            $activeoncourse = is_enrolled($instance, NULL, '', true);
            $courseid = $course->idnumber;

            if ($hasidnumber && in_array($courseid, $passedcourseids)) {
                // This is a passed course. Remove it from $ongoingcourses
                $categorizedcourses['passed'][] = $course;
                unset($ongoingcourses[$course->id]);

            } else if (!$activeoncourse) {
                // This course is upcoming. Remove it from $ongoingcourses
                $categorizedcourses['upcoming'][] = $course;
                unset($ongoingcourses[$course->id]);
            }
        }

        foreach ($ongoingcourses as $c) {
            if (isset($USER->lastcourseaccess[$c->id])) {
                $ongoingcourses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
            } else {
                $ongoingcourses[$c->id]->lastaccess = 0;
            }
        }

        // Print courses
        $nocoursesprinted = true;
        if (!empty($categorizedcourses['upcoming'])) {
            // Upcoming courses
            $this->content->text.=html_writer::tag('h2', get_string('upcomingcourses', 'block_my_courses'));
            $this->content->text.=$this->print_overview_starttime($categorizedcourses['upcoming']);

            $nocoursesprinted = false;
        }
        if (!empty($ongoingcourses)) {
            // Ongoing courses
            ob_start();
            require_once $CFG->dirroot."/course/lib.php";
            print_overview($ongoingcourses);
            $ongoingcontent[] = ob_get_contents();
            ob_end_clean();

            $this->content->text.=html_writer::tag('h2', get_string('ongoingcourses', 'block_my_courses'));
            $this->content->text.=implode($ongoingcontent);

            $nocoursesprinted = false;
        }
        if ($hasidnumber && !empty($categorizedcourses['passed'])) {
            // Passed courses (if user has idnumber)
            ob_start();
            print_overview($categorizedcourses['passed']);
            $passedcontent[] = ob_get_contents();
            ob_end_clean();

            $this->content->text.=html_writer::tag('h2', get_string('passedcourses', 'block_my_courses'));
            $this->content->text.=implode($passedcontent);

            $nocoursesprinted = false;        
        }
        if ($nocoursesprinted) {
            $this->content->text.=get_string('nocourses', 'block_my_courses');
        }

        return $this->content;
    }

    public function print_overview_starttime($courses) {
        global $USER, $DB, $OUTPUT;

        // Collect course id's
        $courseids = array();

        foreach ($courses as $course) {
            $courseids[] = $course->id;
        }

        // Super awesome SQL. You may touch my shoulder.
        $sql = "SELECT userid, courseid, timestart
                FROM mdl_user_enrolments ue
                INNER JOIN mdl_enrol e
                ON e.id = ue.enrolid
                WHERE userid = ? AND courseid IN ( ? )";

        // Get starting times from the database
        $sqlobject = array();
        $sqlobject = $DB->get_records_sql($sql, array($USER->id, implode(',', $courseids)));

        // Collect starting times from data gathered from database
        $starttimes = array();
        foreach ($sqlobject as $course) {
            $starttimes[$course->courseid] = $course->timestart;
        }

        // Loop over each course, create html code and append to 'result'
        $result = '';
        foreach ($courses as $course) {
            // Get course start time
            $coursestart = $starttimes[$course->id];

            // Format and append time that this course starts
            $formattedstart = get_string('coursestarts', 'block_my_courses').': ';
            $formattedstart .= date('d M Y', $coursestart);

            $result .= $OUTPUT->box_start('coursebox');
            $result .= $OUTPUT->container(html_writer::tag('h3', $course->fullname));
            $result .= $OUTPUT->container($formattedstart);
            $result .= $OUTPUT->box_end();
        }

        return $result;
    }

    /**
     * allow the block to have a configuration page
     *
     * @return boolean
     */
    public function has_config() {
        return false;
    }

    /**
     * locations where block can be displayed
     *
     * @return array
     */
    public function applicable_formats() {
        return array('my-index'=>true);
    }	
}
