<?php

/**
 * Prints quiz summaries on MyMoodle Page
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @param array $courses
 * @param array $htmlarray
 */
function block_my_courses_quiz_print_overview($courses, &$htmlarray)
{
    global $USER, $CFG;

    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$quizzes = get_all_instances_in_courses('quiz', $courses)) {
        return;
    }

    // Get the quizzes attempts.
    $attemptsinfo = [];
    $quizids = [];
    foreach ($quizzes as $quiz) {
        $quizids[] = $quiz->id;
        $attemptsinfo[$quiz->id] = ['count' => 0, 'hasfinished' => false];
    }
    $attempts = quiz_get_user_attempts($quizids, $USER->id);
    foreach ($attempts as $attempt) {
        $attemptsinfo[$attempt->quiz]['count']++;
        $attemptsinfo[$attempt->quiz]['hasfinished'] = true;
    }
    unset($attempts);

    // Fetch some language strings outside the main loop.
    $strquiz = get_string('modulename', 'quiz');
    $strnoattempts = get_string('noattempts', 'quiz');

    // We want to list quizzes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($quizzes as $quiz) {
        if ($quiz->timeclose >= $now && $quiz->timeopen < $now) {
            $str = '';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($quiz->coursemodule);
            if (has_capability('mod/quiz:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $quiz objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                //$str .= '<div class="info">' . quiz_num_attempt_summary($quiz, $quiz, true) . '</div>';
                continue; // We don't want to show this to teachers since it has no point.

            } else if (has_any_capability(array('mod/quiz:reviewmyattempts', 'mod/quiz:attempt'), $context)) { // Student
                // For student-like people, tell them how many attempts they have made.

                if (isset($USER->id)) {
                    if ($attemptsinfo[$quiz->id]['hasfinished']) {
                        // The student's last attempt is finished.
                        continue;
                    }

                    if ($attemptsinfo[$quiz->id]['count'] > 0) {
                        $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'quiz', $attemptsinfo[$quiz->id]['count']) . '</div>';
                    } else {
                        $str .= '<div class="info">' . $strnoattempts . '</div>';
                    }
                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }
            } else {
                // For ayone else, there is no point listing this quiz, so stop processing.
                continue;
            }

            // Give a link to the quiz, and the deadline.
            $html = '<div class="quiz overview">' .
                '<div class="name">' . $strquiz . ': <a ' .
                ($quiz->visible ? '' : ' class="dimmed"') .
                ' href="' . $CFG->wwwroot . '/mod/quiz/view.php?id=' .
                $quiz->coursemodule . '">' .
                $quiz->name . '</a></div>';
            $html .= '<div class="info">' . get_string(
                'quizcloseson',
                'quiz',
                userdate($quiz->timeclose)
            ) . '</div>';
            $html .= $str;
            $html .= '</div>';
            if (empty($htmlarray[$quiz->course]['quiz'])) {
                $htmlarray[$quiz->course]['quiz'] = $html;
            } else {
                $htmlarray[$quiz->course]['quiz'] .= $html;
            }
        }
    }
}
