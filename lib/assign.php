<?php

/**
 * Print an overview of all assignments
 * for the courses.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @param mixed $courses The list of courses to print the overview for
 * @param array $htmlarray The array of html to return
 * @return true
 */
function block_my_courses_assign_print_overview($courses, &$htmlarray)
{
    global $CFG, $DB;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return true;
    }

    if (!$assignments = get_all_instances_in_courses('assign', $courses)) {
        return true;
    }

    $assignmentids = array();

    // Do assignment_base::isopen() here without loading the whole thing for speed.
    foreach ($assignments as $key => $assignment) {
        $time = time();
        $isopen = false;
        if ($assignment->duedate) {
            $duedate = false;
            if ($assignment->cutoffdate) {
                $duedate = $assignment->cutoffdate;
            }
            if ($duedate) {
                $isopen = ($assignment->allowsubmissionsfromdate <= $time && $time <= $duedate);
            } else {
                $isopen = ($assignment->allowsubmissionsfromdate <= $time);
            }
        } else {
            $isopen = true;
        }
        if ($isopen) {
            $assignmentids[] = $assignment->id;
        }
    }

    if (empty($assignmentids)) {
        // No assignments to look at - we're done.
        return true;
    }

    // Definitely something to print, now include the constants we need.
    require_once($CFG->dirroot . '/mod/assign/locallib.php');

    $strduedate = get_string('duedate', 'assign');
    $strcutoffdate = get_string('nosubmissionsacceptedafter', 'assign');
    $strnolatesubmissions = get_string('nolatesubmissions', 'assign');
    $strduedateno = get_string('duedateno', 'assign');
    $strassignment = get_string('modulename', 'assign');

    // We do all possible database work here *outside* of the loop to ensure this scales.
    list($sqlassignmentids, $assignmentidparams) = $DB->get_in_or_equal($assignmentids);

    $mysubmissions = null;
    $unmarkedsubmissions = null;

    foreach ($assignments as $assignment) {

        // Do not show assignments that are not open.
        if (!in_array($assignment->id, $assignmentids)) {
            continue;
        }

        $context = context_module::instance($assignment->coursemodule);

        // Does the submission status of the assignment require notification?
        if (has_capability('mod/assign:submit', $context, null, false)) {
            // Does the submission status of the assignment require notification?
            $submitdetails = block_my_courses_assign_get_mysubmission_details_for_print_overview(
                $mysubmissions,
                $sqlassignmentids,
                $assignmentidparams,
                $assignment
            );
        } else {
            $submitdetails = false;
        }

        if (has_capability('mod/assign:grade', $context, null, false)) {
            // Does the grading status of the assignment require notification ?
            $gradedetails = block_my_courses_assign_get_grade_details_for_print_overview(
                $unmarkedsubmissions,
                $sqlassignmentids,
                $assignmentidparams,
                $assignment,
                $context
            );
        } else {
            $gradedetails = false;
        }

        if (empty($submitdetails) && empty($gradedetails)) {
            // There is no need to display this assignment as there is nothing to notify.
            continue;
        }

        $dimmedclass = '';
        if (!$assignment->visible) {
            $dimmedclass = ' class="dimmed"';
        }
        $href = $CFG->wwwroot . '/mod/assign/view.php?id=' . $assignment->coursemodule;
        $basestr = '<div class="assign overview">' .
            '<div class="name">' .
            $strassignment . ': ' .
            '<a ' . $dimmedclass .
            'title="' . $strassignment . '" ' .
            'href="' . $href . '">' .
            format_string($assignment->name) .
            '</a></div>';
        if ($assignment->duedate) {
            $userdate = userdate($assignment->duedate);
            $basestr .= '<div class="info">' . $strduedate . ': ' . $userdate . '</div>';
        } else {
            $basestr .= '<div class="info">' . $strduedateno . '</div>';
        }
        if ($assignment->cutoffdate) {
            if ($assignment->cutoffdate == $assignment->duedate) {
                $basestr .= '<div class="info">' . $strnolatesubmissions . '</div>';
            } else {
                $userdate = userdate($assignment->cutoffdate);
                $basestr .= '<div class="info">' . $strcutoffdate . ': ' . $userdate . '</div>';
            }
        }

        // Show only relevant information.
        if (!empty($submitdetails)) {
            $basestr .= $submitdetails;
        }

        if (!empty($gradedetails)) {
            $basestr .= $gradedetails;
        }
        $basestr .= '</div>';

        if (empty($htmlarray[$assignment->course]['assign'])) {
            $htmlarray[$assignment->course]['assign'] = $basestr;
        } else {
            $htmlarray[$assignment->course]['assign'] .= $basestr;
        }
    }
    return true;
}

/**
 * This api generates html to be displayed to students in print overview section, related to their submission status of the given
 * assignment.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @param array $mysubmissions list of submissions of current user indexed by assignment id.
 * @param string $sqlassignmentids sql clause used to filter open assignments.
 * @param array $assignmentidparams sql params used to filter open assignments.
 * @param stdClass $assignment current assignment
 *
 * @return bool|string html to display , false if nothing needs to be displayed.
 * @throws coding_exception
 */
function block_my_courses_assign_get_mysubmission_details_for_print_overview(
    &$mysubmissions,
    $sqlassignmentids,
    $assignmentidparams,
    $assignment
) {
    global $USER, $DB;

    if ($assignment->nosubmissions) {
        // Offline assignment. No need to display alerts for offline assignments.
        return false;
    }

    $strnotsubmittedyet = get_string('notsubmittedyet', 'assign');

    if (!isset($mysubmissions)) {

        // Get all user submissions, indexed by assignment id.
        $dbparams = array_merge(array($USER->id), $assignmentidparams, array($USER->id));
        $mysubmissions = $DB->get_records_sql('SELECT a.id AS assignment,
                                                      a.nosubmissions AS nosubmissions,
                                                      g.timemodified AS timemarked,
                                                      g.grader AS grader,
                                                      g.grade AS grade,
                                                      s.status AS status
                                                 FROM {assign} a, {assign_submission} s
                                            LEFT JOIN {assign_grades} g ON
                                                      g.assignment = s.assignment AND
                                                      g.userid = ? AND
                                                      g.attemptnumber = s.attemptnumber
                                                WHERE a.id ' . $sqlassignmentids . ' AND
                                                      s.latest = 1 AND
                                                      s.assignment = a.id AND
                                                      s.userid = ?', $dbparams);
    }

    $submitdetails = '';
    $submitdetails .= '<div class="details">';
    $submitdetails .= get_string('mysubmission', 'assign');
    $submission = false;

    if (isset($mysubmissions[$assignment->id])) {
        $submission = $mysubmissions[$assignment->id];
    }

    if ($submission && $submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
        // A valid submission already exists, no need to notify students about this.
        return false;
    }

    // We need to show details only if a valid submission doesn't exist.
    if (
        !$submission ||
        !$submission->status ||
        $submission->status == ASSIGN_SUBMISSION_STATUS_DRAFT ||
        $submission->status == ASSIGN_SUBMISSION_STATUS_NEW
    ) {
        $submitdetails .= $strnotsubmittedyet;
    } else {
        $submitdetails .= get_string('submissionstatus_' . $submission->status, 'assign');
    }
    if ($assignment->markingworkflow) {
        $workflowstate = $DB->get_field('assign_user_flags', 'workflowstate', array('assignment' =>
        $assignment->id, 'userid' => $USER->id));
        if ($workflowstate) {
            $gradingstatus = 'markingworkflowstate' . $workflowstate;
        } else {
            $gradingstatus = 'markingworkflowstate' . ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED;
        }
    } else if (!empty($submission->grade) && $submission->grade !== null && $submission->grade >= 0) {
        $gradingstatus = ASSIGN_GRADING_STATUS_GRADED;
    } else {
        $gradingstatus = ASSIGN_GRADING_STATUS_NOT_GRADED;
    }
    $submitdetails .= ', ' . get_string($gradingstatus, 'assign');
    $submitdetails .= '</div>';
    return $submitdetails;
}

/**
 * This api generates html to be displayed to teachers in print overview section, related to the grading status of the given
 * assignment's submissions.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @param array $unmarkedsubmissions list of submissions of that are currently unmarked indexed by assignment id.
 * @param string $sqlassignmentids sql clause used to filter open assignments.
 * @param array $assignmentidparams sql params used to filter open assignments.
 * @param stdClass $assignment current assignment
 * @param context $context context of the assignment.
 *
 * @return bool|string html to display , false if nothing needs to be displayed.
 * @throws coding_exception
 */
function block_my_courses_assign_get_grade_details_for_print_overview(
    &$unmarkedsubmissions,
    $sqlassignmentids,
    $assignmentidparams,
    $assignment,
    $context
) {
    global $DB;

    if (!isset($unmarkedsubmissions)) {
        // Build up and array of unmarked submissions indexed by assignment id/ userid
        // for use where the user has grading rights on assignment.
        $dbparams = array_merge(array(ASSIGN_SUBMISSION_STATUS_SUBMITTED), $assignmentidparams);
        $rs = $DB->get_recordset_sql('SELECT s.assignment as assignment,
                                             s.userid as userid,
                                             s.id as id,
                                             s.status as status,
                                             g.timemodified as timegraded
                                        FROM {assign_submission} s
                                   LEFT JOIN {assign_grades} g ON
                                             s.userid = g.userid AND
                                             s.assignment = g.assignment AND
                                             g.attemptnumber = s.attemptnumber
                                   LEFT JOIN {assign} a ON
                                             a.id = s.assignment
                                       WHERE
                                             ( g.timemodified is NULL OR
                                             s.timemodified >= g.timemodified OR
                                             g.grade IS NULL OR
                                             (g.grade = -1 AND
                                             a.grade < 0)) AND
                                             s.timemodified IS NOT NULL AND
                                             s.status = ? AND
                                             s.latest = 1 AND
                                             s.assignment ' . $sqlassignmentids, $dbparams);

        $unmarkedsubmissions = array();
        foreach ($rs as $rd) {
            $unmarkedsubmissions[$rd->assignment][$rd->userid] = $rd->id;
        }
        $rs->close();
    }

    $assign = new assign($context, null, null);

    // Count how many people can submit.
    $submissions = 0;
    if ($students = get_enrolled_users($context, 'mod/assign:view', 0, 'u.id')) {
        foreach ($students as $student) {
            if (isset($unmarkedsubmissions[$assignment->id][$student->id])) {
                if ($assignment->teamsubmission && !groups_has_membership($assign->get_course_module(), $student->id)) {
                    continue;
                }
                $submissions++;
            }
        }
    }

    if ($submissions) {
        $urlparams = array('id' => $assignment->coursemodule, 'action' => 'grading');
        $url = new moodle_url('/mod/assign/view.php', $urlparams);
        $gradedetails = '<div class="details">' .
            '<a href="' . $url . '">' .
            get_string('submissionsnotgraded', 'assign', $submissions) .
            '</a></div>';
        return $gradedetails;
    } else {
        return false;
    }
}
