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
        $categorizedcourses['teaching']['finished']  = array();

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

            if (!empty($passedcourses)) {
                foreach ($passedcourses as $course) {
                    $passedcourseids[] = $course->id;
                }
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
            $context = get_context_instance(CONTEXT_COURSE, $course->id);
            $roles = extractshortname(get_user_roles($context, $USER->id));

            // See if the user is a teacher in this course, take appropriate action...
            foreach ($roles as $r) {
                if (in_array($r, $studentroles) && count($roles) == 1) {
                    // User is only a student in this course
                    break;
                } else if (!in_array($r, $studentroles) && in_array($r, $teachingroles)) {
                    // User is only a teacher in this course, we can skip all further checks
                    $categorizedcourses['teaching']['ongoing'][$course->id] = $course;
                    continue 2;
                } else if (in_array($r, $teachingroles)) {
                    // User is probably both a teacher and a student, we need to run the student checks as well
                    $categorizedcourses['teaching']['ongoing'][$course->id] = $course;
                    break;
                }
            }

            if ($hasidnumber && in_array($course->idnumber, $passedcourseids)) {
                // This is a passed taken course
                $categorizedcourses['taking']['passed'][$course->id] = $course;

            } else if ($activeoncourse) {
                // This course is currently ongoing (enrolled as student)
                $categorizedcourses['taking']['ongoing'][$course->id] = $course;

            } else if (!$activeoncourse) {
                // This course is upcoming (enrolled as student)
                $categorizedcourses['taking']['upcoming'][$course->id] = $course;
            }
        }

        // Sort passed teaching courses
        foreach ($categorizedcourses['teaching']['ongoing'] as $course) {
            // Get course data from API
            if (!empty($course->idnumber)) {
                $result = array();

                $idnumbers = explode(',', trim($course->idnumber));
                foreach ($idnumbers as $id) {
                    $params = array();
                    $params[] = 'rest';
                    $params[] = 'courseSegment';
                    $params[] = $id;
                    $result[] = $this->api_call($params);
                }

                // Check course endDate, if it's less than time() - the course is finished
                if (!empty($result)) {
                    $bestmatch = current($result);
                    foreach ($result as $r) {
                        if (strtotime($r->endDate) > strtotime($bestmatch->endDate)) {
                            // This is the most current course instance
                            $bestmatch = $r;
                        }
                    }

                    if (strtotime($bestmatch->endDate) < time()) {
                        // This is a passed course
                        $categorizedcourses['teaching']['finished'][$course->id] = $course;
                        unset($categorizedcourses['teaching']['ongoing'][$course->id]);
                    }
                }
            }
        }

        // Print courses
        require_once $CFG->dirroot."/course/lib.php";
        $nocoursesprinted = true;
        $teachingheaderprinted = false;
        if (!empty($categorizedcourses['teaching']['ongoing'])) {
            if (!$teachingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('teaching_header', 'block_my_courses'));
                $teachingheaderprinted = true;
            }
            ob_start();
            print_overview($categorizedcourses['teaching']['ongoing']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();
            $this->content->text.=$this->create_collapsable_list(
                    get_string('ongoingcourses', 'block_my_courses'), implode($content), true, true);

            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['teaching']['finished'])) {
            if (!$teachingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('teaching_header', 'block_my_courses'));
                $teachingheaderprinted = true;
            }
            ob_start();
            print_overview($categorizedcourses['teaching']['finished']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();
            $this->content->text.=$this->create_collapsable_list(
                    get_string('finishedcourses', 'block_my_courses'), implode($content), false, true);

            $nocoursesprinted = false;
        }

        $takingheaderprinted = false;
        if (!empty($categorizedcourses['taking']['upcoming'])) {
            if (!$takingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('taking_header', 'block_my_courses'));
                $takingheaderprinted = true;
            }
            $content = '';
            $content .= $this->print_overview_starttime($categorizedcourses['taking']['upcoming']);

            $this->content->text.=$this->create_collapsable_list(
                    get_string('upcomingcourses', 'block_my_courses'), $content, true);

            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['taking']['ongoing'])) {
            if (!$takingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('taking_header', 'block_my_courses'));
                $takingheaderprinted = true;
            }
            ob_start();
            print_overview($categorizedcourses['taking']['ongoing']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();

            $this->content->text.=$this->create_collapsable_list(
                    get_string('ongoingcourses', 'block_my_courses'), implode($content), true);

            $nocoursesprinted = false;
        }
        if ($hasidnumber && !empty($categorizedcourses['taking']['passed'])) {
            if (!$takingheaderprinted) {
                $this->content->text.=html_writer::tag('h2', get_string('taking_header', 'block_my_courses'));
                $takingheaderprinted = true;
            }
            ob_start();
            print_overview($categorizedcourses['taking']['passed']);
            $content = array();
            $content[] = ob_get_contents();
            ob_end_clean();

            $this->content->text.=$this->create_collapsable_list(
                    get_string('passedcourses', 'block_my_courses'), implode($content));

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

    private function create_collapsable_list($header, $content, $ongoing = false, $teaching = false) {
        global $PAGE;

        $PAGE->requires->js('/blocks/my_courses/collapse.js');
        $javascript = "";
        if ($teaching) {
            $javascript = 'javascript:toggle(\'cc'.str_replace(array('\'', '\"'), '', $header).'\',\'chTeaching'.$header.'\');';
        } else {
            $javascript = 'javascript:toggle(\'cc'.str_replace(array('\'', '\"'), '', $header).'\',\'chTaking'.$header.'\');';
        }

        $contentdisplay = "";
        if ($teaching && $ongoing) {
            $collapsablelist = html_writer::start_tag('div',
                    array('id' => 'chTeaching'.$header, 'class' => 'c_header expanded', 'onclick' => $javascript));
            $contentdisplay = 'display:block;';
        } else if ($teaching) {
            $collapsablelist = html_writer::start_tag('div',
                    array('id' => 'chTeaching'.$header, 'class' => 'c_header collapsed', 'onclick' => $javascript));
            $contentdisplay = 'display:none;';
        } else if ($ongoing) {
            $collapsablelist = html_writer::start_tag('div',
                    array('id' => 'chTaking'.$header, 'class' => 'c_header expanded', 'onclick' => $javascript));
            $contentdisplay = 'display:block;';
        } else {
            $collapsablelist = html_writer::start_tag('div',
                    array('id' => 'chTaking'.$header, 'class' => 'c_header collapsed', 'onclick' => $javascript));
            $contentdisplay = 'display:none;';
        }

        $collapsablelist .= html_writer::start_tag('ul');
        $collapsablelist .= html_writer::start_tag('li');
        $collapsablelist .= html_writer::tag('h3', $header);
        $collapsablelist .= html_writer::end_tag('li');
        $collapsablelist .= html_writer::end_tag('ul');
        $collapsablelist .= html_writer::end_tag('div');

        $collapsablelist .= html_writer::start_tag('div', array('id' => 'cc'.$header, 'class' => 'c_content', 'style' => $contentdisplay));
        $collapsablelist .= $content;
        $collapsablelist .= html_writer::end_tag('div');

        return $collapsablelist;
    }

    public function print_overview_starttime($courses) {
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
        $result = '';
        foreach ($courses as $course) {
            // Format and append time that this course starts
            $formattedstart = get_string('coursestarts', 'block_my_courses').': ';
            $formattedstart .= date('d M Y', $starttimes[$course->id]);

            $result .= $OUTPUT->box_start('coursebox');
            $result .= $OUTPUT->container(html_writer::tag('h3', $course->fullname));
            $result .= $OUTPUT->container(html_writer::tag('div', $formattedstart, array('class' => 'upcoming_course_content')));
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
