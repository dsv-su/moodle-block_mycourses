<?php

/**
 * Prints choice summaries on MyMoodle Page
 *
 * Prints choice name, due date and attempt information on
 * choice activities that have a deadline that has not already passed
 * and it is available for completing.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @uses CONTEXT_MODULE
 * @param array $courses An array of course objects to get choice instances from.
 * @param array $htmlarray Store overview output array( course ID => 'choice' => HTML output )
 */
function block_my_courses_choice_print_overview($courses, &$htmlarray) {
    global $USER, $DB, $OUTPUT;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return;
    }
    if (!$choices = get_all_instances_in_courses('choice', $courses)) {
        return;
    }

    $now = time();
    foreach ($choices as $choice) {
        if ($choice->timeclose != 0                                      // If this choice is scheduled.
            and $choice->timeclose >= $now                               // And the deadline has not passed.
            and ($choice->timeopen == 0 or $choice->timeopen <= $now)) { // And the choice is available.

            // Visibility.
            $class = (!$choice->visible) ? 'dimmed' : '';

            // Link to activity.
            $url = new moodle_url('/mod/choice/view.php', array('id' => $choice->coursemodule));
            $url = html_writer::link($url, format_string($choice->name), array('class' => $class));
            $str = $OUTPUT->box(get_string('choiceactivityname', 'choice', $url), 'name');

             // Deadline.
            $str .= $OUTPUT->box(get_string('choicecloseson', 'choice', userdate($choice->timeclose)), 'info');

            // Display relevant info based on permissions.
            if (has_capability('mod/choice:readresponses', context_module::instance($choice->coursemodule))) {
                $attempts = $DB->count_records_sql('SELECT COUNT(DISTINCT userid) FROM {choice_answers} WHERE choiceid = ?',
                    [$choice->id]);
                $url = new moodle_url('/mod/choice/report.php', ['id' => $choice->coursemodule]);
                $str .= $OUTPUT->box(html_writer::link($url, get_string('viewallresponses', 'choice', $attempts)), 'info');

            } else if (has_capability('mod/choice:choose', context_module::instance($choice->coursemodule))) {
                // See if the user has submitted anything.
                $answers = $DB->count_records('choice_answers', array('choiceid' => $choice->id, 'userid' => $USER->id));
                if ($answers > 0) {
                    // User has already selected an answer, nothing to show.
                    $str = '';
                } else {
                    // User has not made a selection yet.
                    $str .= $OUTPUT->box(get_string('notanswered', 'choice'), 'info');
                }
            } else {
                // Does not have permission to do anything on this choice activity.
                $str = '';
            }

            // Make sure we have something to display.
            if (!empty($str)) {
                // Generate the containing div.
                $str = $OUTPUT->box($str, 'choice overview');

                if (empty($htmlarray[$choice->course]['choice'])) {
                    $htmlarray[$choice->course]['choice'] = $str;
                } else {
                    $htmlarray[$choice->course]['choice'] .= $str;
                }
            }
        }
    }
    return;
}
