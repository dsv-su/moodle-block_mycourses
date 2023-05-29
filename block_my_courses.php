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
require_once($CFG->dirroot.'/lib/formslib.php');
require_once($CFG->dirroot.'/blocks/my_courses/locallib.php');

class block_my_courses extends block_base {
    /**
     * block initializations
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_my_courses');
    }

    private function ifPassedDaisy($course) {
        $passedcourse = false;
        $result = array();

        $idnumbers = explode(',', trim($course->idnumber));
        foreach ($idnumbers as $id) {
            $params = array();
            $params[] = 'rest';
            $params[] = 'courseSegment';
            $params[] = trim($id);
            $result[] = block_my_courses_api_call($params);
        }

        // Check course endDate, if it's less than time() - the course is finished
        if (!empty($result)) {
            foreach ($result as $r) {
                if (!is_numeric($r->endDate)) {
                    $r->endDate = strtotime($r->endDate);
                } else {
                    $r->endDate = $r->endDate/1000;
                }
            }
            $bestmatch = current($result);
            // Look if there's a better match
            foreach ($result as $r) {
                if (($r->endDate) > ($bestmatch->endDate)) {
                    // This is the most current course instance, update $bestmatch
                    $bestmatch = $r;
                }
            }
            // Compare best match's enddate to current time
            if ($bestmatch->endDate+86400 < time()) {
                // This is a passed course
                $passedcourse = true;
            }
        }
            return $passedcourse;
    }

    /**
     * block contents
     *
     * @return object
     */
    public function get_content() {
        global $USER, $CFG;
        require_once($CFG->dirroot.'/user/profile/lib.php');

        if($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $config = get_config('block_my_courses');

        profile_load_custom_fields($USER);

        // Load renderer and display nice little welcome area
        $renderer = $this->page->get_renderer('block_my_courses');
        if (!empty($config->showwelcomearea)) {
            require_once($CFG->dirroot.'/message/lib.php');
            $msgcount = message_count_unread_messages();
            $this->content->text = $renderer->welcome_area($msgcount);
        }

        // GET EVERYTHINGZ
        $sitecourses = block_my_courses_get_site_courses();
        $overviews = block_my_courses_get_overviews($sitecourses);
        $allcourses = block_my_courses_get_all_courses();

        // Create course categories
        $categorizedcourses = array();

        $categorizedcourses['teaching'] = array();
        $categorizedcourses['teaching']['upcoming']  = array();
        $categorizedcourses['teaching']['ongoing'] = array();
        $categorizedcourses['teaching']['finished']  = array();
        $categorizedcourses['teaching']['programs'] = array();

        $categorizedcourses['taking'] = array();
        $categorizedcourses['taking']['upcoming'] = array();
        $categorizedcourses['taking']['ongoing']  = array();
        $categorizedcourses['taking']['passed']   = array();
        $categorizedcourses['taking']['programs'] = array();
        $categorizedcourses['taking']['conferences'] = array();
        $categorizedcourses['taking']['courseswithoutid'] = array();

        $passedcourseids = array();
        $passedsegmentsids = array();

        $hasidnumber = false;

        // Check if user has user->idnumber. If not, do not access Daisy API
        if (!empty($USER->idnumber)) {
            $hasidnumber = true;

            // Get passed courses
            $params     = array();
            $params[]   = 'rest';
            $params[]   = 'person';
            $params[]   = $USER->idnumber;
            $params[]   = 'courseSegmentInstances';
            $params[]   = '?onlyPassed=true';

            $passedcourses = block_my_courses_api_call($params);

            if (!empty($passedcourses)) {
                foreach ($passedcourses as $course) {
                    $passedcourseids[] = $course->id;
                    $passedsegmentsids[] = $course->courseSegment->id;
                }
            }

            if ($passedcourses === 'daisydown') {
                \core\notification::warning('Daisy is currently not responding. Your courses may not be displayed correctly (future/ongoing/passed). This does not affect your performance or any study records.');
            }
        }

        // Get the different roles in the system
        $teachingroles = array_merge(get_archetype_roles('manager'), get_archetype_roles('teacher'), get_archetype_roles('editingteacher'));
        $studentroles = get_archetype_roles('student');

        function extractshortname($roles) {
            $shortnames = array();
            foreach ($roles as $r) {
                $shortnames[] = $r->shortname;
            }
            return $shortnames;
        }

        function append_content($sectionname, $edulevel, $renderer, $categorizedcourses, $overviews, $headerprinted, $collapsed = false) {
            if (!$headerprinted) {
                $header = html_writer::tag('h2', get_string($edulevel.'_header', 'block_my_courses'));
                $headerprinted = true;
            } else {
                $header = '';
            }

            $heading = html_writer::start_tag('h3');
            $heading .= get_string($sectionname, 'block_my_courses');
            $heading .= html_writer::end_tag('h3');

            if ($sectionname == 'upcoming' && $edulevel == 'taking') {
                $content = block_my_courses_get_overviews_starttime($categorizedcourses['taking']['upcoming']);;
            } else {
                $content = $renderer->course_overview($categorizedcourses[$edulevel][$sectionname], $overviews);
            }

            return array($headerprinted, $header, block_my_courses_create_collapsable_list($edulevel.'_'.$sectionname, $heading, $content, $collapsed));
        }

        // Extract the shortnames for all roles
        $teachingroles = extractshortname($teachingroles);
        $studentroles  = extractshortname($studentroles);

        // Sort courses and clean up this mess...
        // ---------------------------------------

        foreach ($allcourses as $course) {
            $instance = context::instance_by_id($course->ctxid);
            $activeoncourse = is_enrolled($instance, NULL, '', true);
            $context = context_course::instance($course->id, IGNORE_MISSING);
            $roles = extractshortname(get_user_roles($context, $USER->id));

            // See if the user is a teacher in this course, take appropriate action...
            if (count(array_intersect($roles, $teachingroles)) > 0 &&
                !(count(array_intersect($roles, $teachingroles)) == 1 &&
                in_array('teacher', $roles) &&
                strpos($course->idnumber, 'program') !== false
                )) {

                if (strpos($course->idnumber, 'program') !== false) {
                    $categorizedcourses['teaching']['programs'][$course->id] = $course;
                    continue;
                }

                if (strpos($course->idnumber, 'conference') !== false) {
                    $categorizedcourses['teaching']['conferences'][$course->id] = $course;
                    continue;
                }

                $passedcourse = false;

                // Let's get the course enddate
                if ($course->enddate > 0) {
                    if ($course->enddate+86400 < time()) {
                        $passedcourse = true;
                    }
                
                } else if (!empty($course->idnumber)) {
                    if($c->startdate+YEARSECS < time()) {
                        $passedcourse = true;
                    } else {
                        $passedcourse = $this->ifPassedDaisy($course);
                    }
                }

                if ($passedcourse) {
                    $categorizedcourses['teaching']['finished'][$course->id] = $course;
                } else if ($course->startdate > time()) {
                    $categorizedcourses['teaching']['upcoming'][$course->id] = $course;
                } else {
                    $categorizedcourses['teaching']['ongoing'][$course->id] = $course;
                }
            }

            // If the user is a student in the course
            if (count(array_intersect($roles, $studentroles)) > 0) {
                $courseids = preg_split('/,/', $course->idnumber);
                $course_idnumber_in_array = false;
                foreach ($courseids as $courseid) {
                    $courseid = trim($courseid);
                    if (is_numeric($courseid)) {
                        $params = array();
                        $params[] = 'rest';
                        $params[] = 'courseSegment';
                        $params[] = $courseid;
                        $result = block_my_courses_api_call($params);
                        if (in_array($result->courseSegment->id, $passedsegmentsids)) {
                            $course_idnumber_in_array = true;
                        }
                    }
                    if (in_array($courseid, $passedcourseids)) {
                        $course_idnumber_in_array = true;
                    }
                }

                if ($hasidnumber && $course_idnumber_in_array) {
                    // This is a passed taken course
                    $categorizedcourses['taking']['passed'][$course->id] = $course;

                } else if (strpos($course->idnumber, 'program') !== false || $course->category == 16) {
                    $categorizedcourses['taking']['programs'][$course->id] = $course;

                } else if (strpos($course->idnumber, 'conference') !== false) {
                    $categorizedcourses['taking']['conferences'][$course->id] = $course;

                } else if (($course->enddate > 0) && ($course->enddate+86400 < time())) {
                    $categorizedcourses['taking']['courseswithoutid'][$course->id] = $course;

                } else if ($activeoncourse) {
                    // This course is currently ongoing (enrolled as student)
                    $categorizedcourses['taking']['ongoing'][$course->id] = $course;

                } else if (!$activeoncourse) {
                    // This course is upcoming (enrolled as student)
                    $categorizedcourses['taking']['upcoming'][$course->id] = $course;
                }
            }
        }


        // Print courses
        // --------------

        $nocoursesprinted = true;

        // Teaching courses start here.
        $teachingheaderprinted = false;
        if (!empty($categorizedcourses['teaching']['conferences'])) {
            list($teachingheaderprinted, $header, $content) = append_content('conferences', 'teaching', $renderer, $categorizedcourses, $overviews, $teachingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['teaching']['programs'])) {
            list($teachingheaderprinted, $header, $content) = append_content('programs', 'teaching', $renderer, $categorizedcourses, $overviews, $teachingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['teaching']['upcoming'])) {
            list($teachingheaderprinted, $header, $content) = append_content('upcoming', 'teaching', $renderer, $categorizedcourses, $overviews, $teachingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['teaching']['ongoing'])) {
            list($teachingheaderprinted, $header, $content) = append_content('ongoing', 'teaching', $renderer, $categorizedcourses, $overviews, $teachingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['teaching']['finished'])) {
            list($teachingheaderprinted, $header, $content) = append_content('finished', 'teaching', $renderer, $categorizedcourses, $overviews, $teachingheaderprinted, true);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }

        // Taking courses start here.
        $takingheaderprinted = false;
        if (!empty($categorizedcourses['taking']['conferences'])) {
            list($takingheaderprinted, $header, $content) = append_content('conferences', 'taking', $renderer, $categorizedcourses, $overviews, $takingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['taking']['programs'])) {
            list($takingheaderprinted, $header, $content) = append_content('programs', 'taking', $renderer, $categorizedcourses, $overviews, $takingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['taking']['upcoming'])) {
            list($takingheaderprinted, $header, $content) = append_content('upcoming', 'taking', $renderer, $categorizedcourses, $overviews, $takingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['taking']['ongoing'])) {
            list($takingheaderprinted, $header, $content) = append_content('ongoing', 'taking', $renderer, $categorizedcourses, $overviews, $takingheaderprinted);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if (!empty($categorizedcourses['taking']['courseswithoutid'])) {
            list($takingheaderprinted, $header, $content) = append_content('courseswithoutid', 'taking', $renderer, $categorizedcourses, $overviews, $takingheaderprinted, true);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if ($hasidnumber && !empty($categorizedcourses['taking']['passed'])) {
            list($takingheaderprinted, $header, $content) = append_content('passed', 'taking', $renderer, $categorizedcourses, $overviews, $takingheaderprinted, true);
            $this->content->text .= $header.$content;
            $nocoursesprinted = false;
        }
        if ($nocoursesprinted) {
            $this->content->text .= get_string('nocourses', 'block_my_courses');
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
            $result .= $OUTPUT->container(html_writer::tag('h3', $course->fullname, array('class' => 'main')));
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
        return true;
    }

    /**
     * locations where block can be displayed
     *
     * @return array
     */
    public function applicable_formats() {
        return array('my'=>true);
    }
}
