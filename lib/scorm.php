<?php

/**
 * writes overview info for course_overview block - displays upcoming scorm objects that have a due date
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @param object $type - type of log(aicc,scorm12,scorm13) used as prefix for filename
 * @param array $htmlarray
 * @return mixed
 */
function block_my_courses_scorm_print_overview($courses, &$htmlarray)
{
    global $USER, $CFG;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$scorms = get_all_instances_in_courses('scorm', $courses)) {
        return;
    }

    $strscorm   = get_string('modulename', 'scorm');
    $strduedate = get_string('duedate', 'scorm');

    foreach ($scorms as $scorm) {
        $time = time();
        $showattemptstatus = false;
        if ($scorm->timeopen) {
            $isopen = ($scorm->timeopen <= $time && $time <= $scorm->timeclose);
        }
        if (
            $scorm->displayattemptstatus == SCORM_DISPLAY_ATTEMPTSTATUS_ALL ||
            $scorm->displayattemptstatus == SCORM_DISPLAY_ATTEMPTSTATUS_MY
        ) {
            $showattemptstatus = true;
        }
        if ($showattemptstatus || !empty($isopen) || !empty($scorm->timeclose)) {
            $str = html_writer::start_div('scorm overview') . html_writer::div($strscorm . ': ' .
                html_writer::link(
                    $CFG->wwwroot . '/mod/scorm/view.php?id=' . $scorm->coursemodule,
                    $scorm->name,
                    array('title' => $strscorm, 'class' => $scorm->visible ? '' : 'dimmed')
                ), 'name');
            if ($scorm->timeclose) {
                $str .= html_writer::div($strduedate . ': ' . userdate($scorm->timeclose), 'info');
            }
            if ($showattemptstatus) {
                require_once($CFG->dirroot . '/mod/scorm/locallib.php');
                $str .= html_writer::div(scorm_get_attempt_status($USER, $scorm), 'details');
            }
            $str .= html_writer::end_div();
            if (empty($htmlarray[$scorm->course]['scorm'])) {
                $htmlarray[$scorm->course]['scorm'] = $str;
            } else {
                $htmlarray[$scorm->course]['scorm'] .= $str;
            }
        }
    }
}
