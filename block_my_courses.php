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
        $allcourses = enrol_get_users_courses($USER->id, false, 'id, shortname, modinfo,
                sectioncache', 'visible DESC,sortorder ASC');

        foreach ($allcourses as $c) {
            if (isset($USER->lastcourseaccess[$c->id])) {
                $allcourses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
            } else {
                $allcourses[$c->id]->lastaccess = 0;
            }
        }

        $categorizedcourses = array();

        $categorizedcourses['teaching'] = array();
        $categorizedcourses['teaching']['ongoing'] = array();
        $categorizedcourses['teaching']['passed']  = array();

        $categorizedcourses['taking'] = array();
        $categorizedcourses['taking']['upcoming'] = array();
        $categorizedcourses['taking']['ongoing']  = array();
        $categorizedcourses['taking']['passed']   = array();

        $passedcourseids = array();

        $hasidnumber = false;

        // Check if user has user->idnumber. If not, do not access daisy API
        if (!empty($USER->idnumber)) {
            $hasidnumber = true;

            // Get passed courses
            $params     = array();
            $params[]   = 'rest';
            $params[]   = 'person';
            $params[]   = $USER->idnumber;
            $params[]   = 'courseSegmentInstances';
            $params[]   = '?onlyPassed=true';

            $passedcourses = $this->api_call($params);

            foreach ($passedcourses as $course) {
                $passedcourseids[] = $course->id;
            }
        }

        // Get the different roles
        $teachingroles = array_merge(get_archetype_roles('teacher'), get_archetype_roles('editingteacher'));
        $studentroles = get_archetype_roles('student');

        function extractshortname($roles) {
            $shortnames = array();
            foreach ($roles as $r) {
                $shortnames[] = $r->shortname;
            }
            return $shortnames;
        }

        $teachingroles = extractshortname($teachingroles);
        $studentroles  = extractshortname($studentroles);

        // Sort courses
        foreach ($allcourses as $course) {
            $instance = context::instance_by_id($course->ctxid);
            $activeoncourse = is_enrolled($instance, NULL, '', true);
            $courseid = $course->idnumber;
            $context = get_context_instance(CONTEXT_COURSE, $course->id);
            $roles = extractshortname(get_user_roles($context, $USER->id));

            // See if the user is a teacher in this course, take appropriate action...

            foreach ($roles as $r) {
                if (in_array($r, $studentroles) && count($roles) == 1) {
                    break;
                } else if (in_array($r, $teachingroles) && count($roles) == 1) {
                    $categorizedcourses['teaching']['ongoing'][$course->id] = $course;
                    continue 2;
                } else if (!in_array($r, $studentroles) && in_array($r, $teachingroles)) {
                    $categorizedcourses['teaching']['ongoing'][$course->id] = $course;
                    continue 2;
                } else if (in_array($r, $teachingroles)) {
                    $categorizedcourses['teaching']['ongoing'][$course->id] = $course;
                    break;
                }
            }

            if ($hasidnumber && in_array($courseid, $passedcourseids)) {
                // This is a passed course
                $categorizedcourses['taking']['passed'][$course->id] = $course;

            } else if ($activeoncourse) {
                // This course is ongoing
                $categorizedcourses['taking']['ongoing'][$course->id] = $course;

            } else if (!$activeoncourse) {
                // This course is upcoming
                $categorizedcourses['taking']['upcoming'][$course->id] = $course;
            }
        }

        // Get passed teaching courses
        foreach ($categorizedcourses['teaching']['ongoing'] as $course) {
            if (!empty($course->idnumber)) {
                $params = array();
                $params[] = 'rest';
                $params[] = 'courseSegment';
                $params[] = $course->idnumber;

                $result = $this->api_call($params);
                if (strtotime($result->endDate) < time()) {
                    // This course is passed
                    $categorizedcourses['teaching']['passed'][$course->id] = $course;
                    unset($categorizedcourses['teaching']['ongoing'][$course->id]);
                }
            }
        }

        // Print courses
        require_once $CFG->dirroot."/course/lib.php";
        $nocoursesprinted = true;
        $teachingheaderprinted = false;
        if (!empty($categorizedcourses['teaching']['ongoing'])) {
            // Teaching courses (ongoing)
            if (!$teachingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('teaching_header', 'block_my_courses'));
                $teachingheaderprinted = true;
            }
            $this->content->text.=html_writer::tag('h3', get_string('ongoingcourses', 'block_my_courses'));
            ob_start();
            print_overview($categorizedcourses['teaching']['ongoing']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();
            $this->content->text.=implode($content);

            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['teaching']['passed'])) {
            // Teaching courses (passed)
            if (!$teachingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('teaching_header', 'block_my_courses'));
                $teachingheaderprinted = true;
            }
            $this->content->text.=html_writer::tag('h3', get_string('passedcourses', 'block_my_courses'));
            ob_start();
            print_overview($categorizedcourses['teaching']['passed']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();
            $this->content->text.=implode($content);

            $nocoursesprinted = false;
        }

        $takingheaderprinted = false;
        if (!empty($categorizedcourses['taking']['upcoming'])) {
            // Upcoming courses
            if (!$takingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('taking_header', 'block_my_courses'));
                $takingheaderprinted = true;
            }
            $this->content->text.=html_writer::tag('h3', get_string('upcomingcourses', 'block_my_courses'));
            $this->content->text.=$this->print_overview_starttime($categorizedcourses['taking']['upcoming']);

            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['taking']['ongoing'])) {
            // Ongoing courses
            if (!$takingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('taking_header', 'block_my_courses'));
                $takingheaderprinted = true;
            }
            ob_start();
            print_overview($categorizedcourses['taking']['ongoing']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();

            $this->content->text.=html_writer::tag('h3', get_string('ongoingcourses', 'block_my_courses'));
            $this->content->text.=implode($content);

            $nocoursesprinted = false;
        }
        if ($hasidnumber && !empty($categorizedcourses['taking']['passed'])) {
            // Passed courses (if user has idnumber)
            if (!$takingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('taking_header', 'block_my_courses'));
                $takingheaderprinted = true;
            }
            ob_start();
            print_overview($categorizedcourses['taking']['passed']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();

            $this->content->text.=html_writer::tag('h3', get_string('passedcourses', 'block_my_courses'));
            $this->content->text.=implode($content);

            $nocoursesprinted = false;
        }
        if ($nocoursesprinted) {
            $this->content->text.=get_string('nocourses', 'block_my_courses');
        }

        return $this->content;
    }

    private function api_call(array $params) {
        $apiurl   = 'https://api.dsv.su.se/';
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

        } else {
            // Create and show an error message
            $error = new stdClass;
            $error->httpcode = $curlheader['http_code'];
            $error->path     = implode('/', $params);
            echo '<pre>';
            echo get_string('servererror', 'block_my_courses')."\n";
            echo $error ."\n";
            echo '</pre>';
        }
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
