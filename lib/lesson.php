<?php

/**
 * Prints lesson summaries on MyMoodle Page
 *
 * Prints lesson name, due date and attempt information on
 * lessons that have a deadline that has not already passed
 * and it is available for taking.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @global object
 * @global stdClass
 * @global object
 * @uses CONTEXT_MODULE
 * @param array $courses An array of course objects to get lesson instances from
 * @param array $htmlarray Store overview output array( course ID => 'lesson' => HTML output )
 * @return void
 */
function block_my_courses_lesson_print_overview($courses, &$htmlarray) {
    global $USER, $CFG, $DB, $OUTPUT;

    if (!$lessons = get_all_instances_in_courses('lesson', $courses)) {
        return;
    }

    // Get all of the current users attempts on all lessons.
    $params = array($USER->id);
    $sql = 'SELECT lessonid, userid, count(userid) as attempts
              FROM {lesson_grades}
             WHERE userid = ?
          GROUP BY lessonid, userid';
    $allattempts = $DB->get_records_sql($sql, $params);
    $completedattempts = array();
    foreach ($allattempts as $myattempt) {
        $completedattempts[$myattempt->lessonid] = $myattempt->attempts;
    }

    // Get the current course ID.
    $listoflessons = array();
    foreach ($lessons as $lesson) {
        $listoflessons[] = $lesson->id;
    }
    // Get the last page viewed by the current user for every lesson in this course.
    list($insql, $inparams) = $DB->get_in_or_equal($listoflessons, SQL_PARAMS_NAMED);
    $dbparams = array_merge($inparams, array('userid' => $USER->id));

    // Get the lesson attempts for the user that have the maximum 'timeseen' value.
    $select = "SELECT l.id, l.timeseen, l.lessonid, l.userid, l.retry, l.pageid, l.answerid as nextpageid, p.qtype ";
    $from = "FROM {lesson_attempts} l
             JOIN (
                   SELECT idselect.lessonid, idselect.userid, MAX(idselect.id) AS id
                     FROM {lesson_attempts} idselect
                     JOIN (
                           SELECT lessonid, userid, MAX(timeseen) AS timeseen
                             FROM {lesson_attempts}
                            WHERE userid = :userid
                              AND lessonid $insql
                         GROUP BY userid, lessonid
                           ) timeselect
                       ON timeselect.timeseen = idselect.timeseen
                      AND timeselect.userid = idselect.userid
                      AND timeselect.lessonid = idselect.lessonid
                 GROUP BY idselect.userid, idselect.lessonid
                   ) aid
               ON l.id = aid.id
             JOIN {lesson_pages} p
               ON l.pageid = p.id ";
    $lastattempts = $DB->get_records_sql($select . $from, $dbparams);

    // Now, get the lesson branches for the user that have the maximum 'timeseen' value.
    $select = "SELECT l.id, l.timeseen, l.lessonid, l.userid, l.retry, l.pageid, l.nextpageid, p.qtype ";
    $from = str_replace('{lesson_attempts}', '{lesson_branch}', $from);
    $lastbranches = $DB->get_records_sql($select . $from, $dbparams);

    $lastviewed = array();
    foreach ($lastattempts as $lastattempt) {
        $lastviewed[$lastattempt->lessonid] = $lastattempt;
    }

    // Go through the branch times and record the 'timeseen' value if it doesn't exist
    // for the lesson, or replace it if it exceeds the current recorded time.
    foreach ($lastbranches as $lastbranch) {
        if (!isset($lastviewed[$lastbranch->lessonid])) {
            $lastviewed[$lastbranch->lessonid] = $lastbranch;
        } else if ($lastviewed[$lastbranch->lessonid]->timeseen < $lastbranch->timeseen) {
            $lastviewed[$lastbranch->lessonid] = $lastbranch;
        }
    }

    // Since we have lessons in this course, now include the constants we need.
    require_once($CFG->dirroot . '/mod/lesson/locallib.php');

    $now = time();
    foreach ($lessons as $lesson) {
        if ($lesson->deadline != 0                                         // The lesson has a deadline
            and $lesson->deadline >= $now                                  // And it is before the deadline has been met
            and ($lesson->available == 0 or $lesson->available <= $now)) { // And the lesson is available

            // Visibility.
            $class = (!$lesson->visible) ? 'dimmed' : '';

            // Context.
            $context = context_module::instance($lesson->coursemodule);

            // Link to activity.
            $url = new moodle_url('/mod/lesson/view.php', array('id' => $lesson->coursemodule));
            $url = html_writer::link($url, format_string($lesson->name, true, array('context' => $context)), array('class' => $class));
            $str = $OUTPUT->box(get_string('lessonname', 'lesson', $url), 'name');

            // Deadline.
            $str .= $OUTPUT->box(get_string('lessoncloseson', 'lesson', userdate($lesson->deadline)), 'info');

            // Attempt information.
            if (has_capability('mod/lesson:manage', $context)) {
                // This is a teacher, Get the Number of user attempts.
                $attempts = $DB->count_records('lesson_grades', array('lessonid' => $lesson->id));
                $str     .= $OUTPUT->box(get_string('xattempts', 'lesson', $attempts), 'info');
                $str      = $OUTPUT->box($str, 'lesson overview');
            } else {
                // This is a student, See if the user has at least started the lesson.
                if (isset($lastviewed[$lesson->id]->timeseen)) {
                    // See if the user has finished this attempt.
                    if (isset($completedattempts[$lesson->id]) &&
                             ($completedattempts[$lesson->id] == ($lastviewed[$lesson->id]->retry + 1))) {
                        // Are additional attempts allowed?
                        if ($lesson->retake) {
                            // User can retake the lesson.
                            $str .= $OUTPUT->box(get_string('additionalattemptsremaining', 'lesson'), 'info');
                            $str = $OUTPUT->box($str, 'lesson overview');
                        } else {
                            // User has completed the lesson and no retakes are allowed.
                            $str = '';
                        }

                    } else {
                        // The last attempt was not finished or the lesson does not contain questions.
                        // See if the last page viewed was a branchtable.
                        require_once($CFG->dirroot . '/mod/lesson/pagetypes/branchtable.php');
                        if ($lastviewed[$lesson->id]->qtype == LESSON_PAGE_BRANCHTABLE) {
                            // See if the next pageid is the end of lesson.
                            if ($lastviewed[$lesson->id]->nextpageid == LESSON_EOL) {
                                // The last page viewed was the End of Lesson.
                                if ($lesson->retake) {
                                    // User can retake the lesson.
                                    $str .= $OUTPUT->box(get_string('additionalattemptsremaining', 'lesson'), 'info');
                                    $str = $OUTPUT->box($str, 'lesson overview');
                                } else {
                                    // User has completed the lesson and no retakes are allowed.
                                    $str = '';
                                }

                            } else {
                                // The last page viewed was NOT the end of lesson.
                                $str .= $OUTPUT->box(get_string('notyetcompleted', 'lesson'), 'info');
                                $str = $OUTPUT->box($str, 'lesson overview');
                            }

                        } else {
                            // Last page was a question page, so the attempt is not completed yet.
                            $str .= $OUTPUT->box(get_string('notyetcompleted', 'lesson'), 'info');
                            $str = $OUTPUT->box($str, 'lesson overview');
                        }
                    }

                } else {
                    // User has not yet started this lesson.
                    $str .= $OUTPUT->box(get_string('nolessonattempts', 'lesson'), 'info');
                    $str = $OUTPUT->box($str, 'lesson overview');
                }
            }
            if (!empty($str)) {
                if (empty($htmlarray[$lesson->course]['lesson'])) {
                    $htmlarray[$lesson->course]['lesson'] = $str;
                } else {
                    $htmlarray[$lesson->course]['lesson'] .= $str;
                }
            }
        }
    }
}
