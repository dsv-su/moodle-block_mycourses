<?php

/**
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @global object
 * @global object
 * @global object
 * @param array $courses
 * @param array $htmlarray
 */
function block_my_courses_forum_print_overview($courses,&$htmlarray) {
    global $USER, $CFG, $DB, $SESSION;

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$forums = get_all_instances_in_courses('forum',$courses)) {
        return;
    }

    // Courses to search for new posts
    $coursessqls = array();
    $params = array();
    foreach ($courses as $course) {

        // If the user has never entered into the course all posts are pending
        if ($course->lastaccess == 0) {
            $coursessqls[] = '(d.course = ?)';
            $params[] = $course->id;

        // Only posts created after the course last access
        } else {
            $coursessqls[] = '(d.course = ? AND p.created > ?)';
            $params[] = $course->id;
            $params[] = $course->lastaccess;
        }
    }
    $params[] = $USER->id;
    $coursessql = implode(' OR ', $coursessqls);

    $sql = "SELECT d.id, d.forum, d.course, d.groupid, COUNT(*) as count "
                .'FROM {forum_discussions} d '
                .'JOIN {forum_posts} p ON p.discussion = d.id '
                ."WHERE ($coursessql) "
                .'AND p.deleted <> 1 '
                .'AND p.userid != ? '
                .'AND (d.timestart <= ? AND (d.timeend = 0 OR d.timeend > ?)) '
                .'GROUP BY d.id, d.forum, d.course, d.groupid '
                .'ORDER BY d.course, d.forum';
    $params[] = time();
    $params[] = time();

    // Avoid warnings.
    if (!$discussions = $DB->get_records_sql($sql, $params)) {
        $discussions = array();
    }

    $forumsnewposts = block_my_courses_forum_filter_user_groups_discussions($discussions);

    // also get all forum tracking stuff ONCE.
    $trackingforums = array();
    foreach ($forums as $forum) {
        if (forum_tp_can_track_forums($forum)) {
            $trackingforums[$forum->id] = $forum;
        }
    }

    if (count($trackingforums) > 0) {
        $cutoffdate = isset($CFG->forum_oldpostdays) ? (time() - ($CFG->forum_oldpostdays*24*60*60)) : 0;
        $sql = 'SELECT d.forum,d.course,COUNT(p.id) AS count '.
            ' FROM {forum_posts} p '.
            ' JOIN {forum_discussions} d ON p.discussion = d.id '.
            ' LEFT JOIN {forum_read} r ON r.postid = p.id AND r.userid = ? WHERE p.deleted <> 1 AND (';
        $params = array($USER->id);

        foreach ($trackingforums as $track) {
            $sql .= '(d.forum = ? AND (d.groupid = -1 OR d.groupid = 0 OR d.groupid = ?)) OR ';
            $params[] = $track->id;
            if (isset($SESSION->currentgroup[$track->course])) {
                $groupid =  $SESSION->currentgroup[$track->course];
            } else {
                // get first groupid
                $groupids = groups_get_all_groups($track->course, $USER->id);
                if ($groupids) {
                    reset($groupids);
                    $groupid = key($groupids);
                    $SESSION->currentgroup[$track->course] = $groupid;
                } else {
                    $groupid = 0;
                }
                unset($groupids);
            }
            $params[] = $groupid;
        }
        $sql = substr($sql,0,-3); // take off the last OR
        $sql .= ') AND p.modified >= ? AND r.id is NULL ';
        $sql .= 'AND (d.timestart < ? AND (d.timeend = 0 OR d.timeend > ?)) ';
        $sql .= 'GROUP BY d.forum,d.course';
        $params[] = $cutoffdate;
        $params[] = time();
        $params[] = time();

        if (!$unread = $DB->get_records_sql($sql, $params)) {
            $unread = array();
        }
    } else {
        $unread = array();
    }

    if (empty($unread) and empty($forumsnewposts)) {
        return;
    }

    $strforum = get_string('modulename','forum');

    foreach ($forums as $forum) {
        $str = '';
        $count = 0;
        $thisunread = 0;
        $showunread = false;
        // either we have something from logs, or trackposts, or nothing.
        if (array_key_exists($forum->id, $forumsnewposts) && !empty($forumsnewposts[$forum->id])) {
            $count = $forumsnewposts[$forum->id]->count;
        }
        if (array_key_exists($forum->id,$unread)) {
            $thisunread = $unread[$forum->id]->count;
            $showunread = true;
        }
        if ($count > 0 || $thisunread > 0) {
            $str .= '<div class="overview forum"><div class="name">'.$strforum.': <a title="'.$strforum.'" href="'.$CFG->wwwroot.'/mod/forum/view.php?f='.$forum->id.'">'.
                $forum->name.'</a></div>';
            $str .= '<div class="info"><span class="postsincelogin">';
            $str .= get_string('overviewnumpostssince', 'forum', $count)."</span>";
            if (!empty($showunread)) {
                $str .= '<div class="unreadposts">'.get_string('overviewnumunread', 'forum', $thisunread).'</div>';
            }
            $str .= '</div></div>';
        }
        if (!empty($str)) {
            if (!array_key_exists($forum->course,$htmlarray)) {
                $htmlarray[$forum->course] = array();
            }
            if (!array_key_exists('forum',$htmlarray[$forum->course])) {
                $htmlarray[$forum->course]['forum'] = ''; // initialize, avoid warnings
            }
            $htmlarray[$forum->course]['forum'] .= $str;
        }
    }
}

/**
 * Filters the forum discussions according to groups membership and config.
 *
 * @deprecated since 3.3
 * @todo The final deprecation of this function will take place in Moodle 3.7 - see MDL-57487.
 * @since  Moodle 2.8, 2.7.1, 2.6.4
 * @param  array $discussions Discussions with new posts array
 * @return array Forums with the number of new posts
 */
function block_my_courses_forum_filter_user_groups_discussions($discussions) {

    // Group the remaining discussions posts by their forumid.
    $filteredforums = array();

    // Discard not visible groups.
    foreach ($discussions as $discussion) {

        // Course data is already cached.
        $instances = get_fast_modinfo($discussion->course)->get_instances();
        $forum = $instances['forum'][$discussion->forum];

        // Continue if the user should not see this discussion.
        if (!forum_is_user_group_discussion($forum, $discussion->groupid)) {
            continue;
        }

        // Grouping results by forum.
        if (empty($filteredforums[$forum->instance])) {
            $filteredforums[$forum->instance] = new stdClass();
            $filteredforums[$forum->instance]->id = $forum->id;
            $filteredforums[$forum->instance]->count = 0;
        }
        $filteredforums[$forum->instance]->count += $discussion->count;

    }

    return $filteredforums;
}
