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
 * This page (user.php) displays the all the posts for a chosen user
 * in chronological order (modified date). It is also indicates whether
 * a post is a new discussion or a reply. If it is a reply then a link
 * link is printed for displaying the original post.
 * It can also produce a csv format output file.
 * @package forumngfeature
 * @subpackage userposts
 * @copyright 2011 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../../config.php');
require_once($CFG->dirroot . '/mod/forumng/mod_forumng.php');
require_once($CFG->dirroot . '/mod/forumng/feature/userposts/locallib.php');
require_once($CFG->dirroot.'/mod/forumng/locallib.php');

$cmid = required_param('id', PARAM_INT);
$userid = required_param('user', PARAM_INT);
$cloneid = optional_param('clone', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_TEXT);

$student = false;
$groupid = -1;

$pageparams = array('id' => $cmid, 'user'=>$userid, 'download' => $download);
if ($cloneid) {
    $pageparams['clone'] = $cloneid;
}

$forum = mod_forumng::get_from_cmid($cmid, $cloneid);
$cm = $forum->get_course_module();
$course = $forum->get_course();
$forumngid = $forum->get_id();
$context = $forum->get_context();
if ($forum->is_shared() || $forum->is_clone()) {
    throw new invalid_parameter_exception("Not supported for shared forums.");
}

global $USER;
if (has_capability('forumngfeature/userposts:view', $context)) {
    $student = false;
} else {
    if ($USER->id == $userid) {
        $student = true;
    } else {
        // Throw user access no permissions error.
        throw new moodle_exception('error_nopermission', 'forumng');
    }
}

if ($student) {
    // We need to get active group from forum.
    $groupid = mod_forumng::get_activity_group($cm, true);
} else {
    // Check group mode and get group id.
    if ($forum->get_group_mode()) {
        $groupid = required_param('group', PARAM_INT);
    }
    // Check access.
    $forum->require_view($groupid);
    require_capability('forumngfeature/userposts:view', $context);
    // Check group mode and set up group id.
    if ($forum->get_group_mode()) {
        $pageparams['group'] = $groupid;
        if (!$groupid) {
            $groupid = mod_forumng::ALL_GROUPS;
        }
    } else {
        $groupid = mod_forumng::NO_GROUPS;
    }
}

$posts = $forum->get_all_posts_by_user($userid, $groupid, 'fd.forumngid');

// Set pagename.
$postsfound = true;
if ($posts) {
    $post = reset($posts);
    $user = $post->get_user();
} else {
    $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
    $postsfound = false;
}
$pagename = fullname($user, has_capability('moodle/site:viewfullnames', $context));
$pagename .= $CFG->forumng_showusername ? ' (' . $user->username . ')' : '';

$prevpage = '';
$prevurl = '';
$pageurl = new moodle_url('/mod/forumng/feature/userposts/user.php', $pageparams);
$out = '';

if (!$student) {
    $prevpage = get_string('userposts', 'forumngfeature_userposts');
    $prevurl = new moodle_url('/mod/forumng/feature/userposts/list.php',
        $forum->get_link_params_array());
    $out = $forum->init_page($pageurl, $pagename, array($prevpage=>$prevurl));
} else {
    $out = $forum->init_page($pageurl, $pagename);
}

$table = new forumng_participation_table('mod-forumng-participation');
$table->set_attribute('class', 'flexible generaltable');
$table->set_attribute('width', '100%');
$table->define_columns(array('c1', 'c2', 'c3'));
$table->define_headers(array('In reply to', 'Post subject', 'Post message'));
$table->define_baseurl($pageurl);
$filename = "$course->shortname-" . format_string($forum->get_name(), true) . "-" . $USER->username;
$table->is_downloading($download, $filename, get_string('userposts', 'forumngfeature_userposts'));

if (empty($download)) {
    print $out->header();
    print $table->download_buttons();
}

$data = array();
foreach ($posts as $postid => $post) {
    $deleted = $post->get_deleted();
    $discussion = $post->get_discussion();
    $discussiondeleted = $discussion->is_deleted();

    // Don't display if post or discussion is deleted and user is a student.
    if (! (($deleted || $discussiondeleted) && $student)) {
        if (empty($download)) {
            print "<div class='forumng-userpostheading'>";
            // Get URL to post.
            print '<a href="' . s($post->get_url()) . '">';
        }
        // If this post is a reply, then print a link to the discussion.
        if (!$post->is_root_post()) {
            if (empty($download)) {
                print get_string('replyin', 'forumngfeature_userposts',
                    $discussion->get_subject());
            } else {
                $row[0] = $discussion->get_subject();
            }
        } else {
            if (empty($download)) {
                print get_string('newdiscussion', 'forumng');
            } else {
                $row[0] = '';
            }
        }
        if (empty($download)) {
            print "</a></div>";
        }
        // Display this post.
        $options = array(
            mod_forumng_post::OPTION_NO_COMMANDS => true,
            mod_forumng_post::OPTION_FIRST_UNREAD => false,
            mod_forumng_post::OPTION_UNREAD_NOT_HIGHLIGHTED => true);
        if (empty($download)) {
            print $post->display(true, $options);
        } else {
            $row[1] = $post->get_subject();
            $row[2] = $post->get_formatted_message();
            $data[] = $row;
        }
    }
}

if (!$postsfound) {
    // Should only occur if student because list.php won't let us get here if no posts available.
    $username = fullname($user);
    if (empty($download)) {
        print '<p class="forumng-nopostsby">' .
                get_string('nopostsby', 'forumngfeature_userposts', $username) . '</p>';
    }
}

if ($forum->can_grade() && empty($download)) {
    forumngfeature_userposts_display_user_grade( $cm->id, $forum, $user, $groupid);
}

if (!$student && empty($download)) {
    // Display link to the discussion.
    print link_arrow_left($prevpage, 'list.php?id=' . $cmid);
} else if (empty($download)) {
    // Display link to the forum view.
    $url = '../../view.php?id=' . $cmid;
    print link_arrow_left($forum->get_name(), $url);
}
if (!empty($download)) {
    $table->downloadable = false;
    $table->setup($download);
    foreach ($data as $row) {
        $table->add_data($row);
    }
    $table->finish_output();
}
if (empty($download)) {
    // Display footer.
    print $out->footer();
}
add_to_log($course->id, 'forumng', 'view', 'user.php?' . $pageurl->get_query_string(false));
