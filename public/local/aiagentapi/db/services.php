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
 * External functions and service definitions for local_aiagentapi.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_aiagentapi_get_user_context' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'get_user_context',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Returns a minimal user context payload for agent bootstrapping.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_get_api_catalog' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'get_api_catalog',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Returns API catalog (endpoints and constraints) for agent bootstrapping.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_courses_list_my' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'courses_list_my',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List current user courses.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_course_get_outline' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'course_get_outline',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Return course sections and visible modules.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_quiz_list_by_course' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_list_by_course',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List visible quizzes in a course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_activities_list_by_course' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'activities_list_by_course',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List visible activities in a course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_assignments_list_by_course' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'assignments_list_by_course',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List visible assignments in a course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_calendar_list' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'calendar_list',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List calendar events for current user and enrolled courses.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_activities_due_list' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'activities_due_list',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List due/open/completion-expected activity timestamps for current user.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_assignments_my_status' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'assignments_my_status',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List current user assignment submission status across enrolled courses.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_quiz_attempts_my' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_attempts_my',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List current user quiz attempts across enrolled courses.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_quiz_start_attempt' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_start_attempt',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Start a quiz attempt and return the first page.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:attempt',
    ],
    'local_aiagentapi_quiz_get_attempt_data' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_get_attempt_data',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Return one page of quiz attempt data.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:attempt',
    ],
    'local_aiagentapi_quiz_get_attempt_summary' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_get_attempt_summary',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Return quiz attempt summary data.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:attempt',
    ],
    'local_aiagentapi_quiz_save_attempt' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_save_attempt',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Save quiz attempt responses.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:attempt',
    ],
    'local_aiagentapi_quiz_submit_attempt' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_submit_attempt',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Submit a quiz attempt for grading.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:attempt',
    ],
    'local_aiagentapi_quiz_answer_questions' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_answer_questions',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Apply structured answers to supported quiz question types.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:attempt',
    ],
    'local_aiagentapi_grades_overview_my' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'grades_overview_my',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Return current user grade overview by course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_course_progress_my' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'course_progress_my',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Return current user completion/progress by course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_course_activity_detail' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'course_activity_detail',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Return a normalized detail view for a visible course activity.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_resources_list_by_course' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'resources_list_by_course',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List resource-style learning materials in a course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/course:view',
    ],
    'local_aiagentapi_forum_discussions_list' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'forum_discussions_list',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List recent forum discussions visible to the current user.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_notifications_list_my' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'notifications_list_my',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List notifications/messages for the current user.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_question_categories_list' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'question_categories_list',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'List question categories for a course or context.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/question:useall',
    ],
    'local_aiagentapi_questionbank_search' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'questionbank_search',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Search latest ready questions by text, category, or course.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/question:useall',
    ],
    'local_aiagentapi_questionbank_pick_random' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'questionbank_pick_random',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Pick random questions from a category.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/question:useall',
    ],
    'local_aiagentapi_questions_render_html' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'questions_render_html',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Render questions to HTML + plain text.',
        'type' => 'read',
        'capabilities' => 'local/aiagentapi:use, moodle/question:useall',
    ],
    'local_aiagentapi_quiz_resolve_random' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'quiz_resolve_random',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Resolve random questions in a quiz via temporary attempts.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/quiz:preview',
    ],
    'local_aiagentapi_practice_quiz_create_from_resource' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'practice_quiz_create_from_resource',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Create a post-lesson practice quiz from existing mapped question-bank questions.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, moodle/course:manageactivities, mod/quiz:addinstance, moodle/question:useall',
    ],
    'local_aiagentapi_calendar_publish_plan' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'calendar_publish_plan',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Publish a plan (list of items) into the current user calendar with idempotency.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, local/aiagentapi:calendarwrite, moodle/calendar:manageownentries',
    ],
    'local_aiagentapi_calendar_plan_upsert' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'calendar_plan_upsert',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Create/update/delete AI-managed user calendar plan events by stable item keys.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, local/aiagentapi:calendarwrite, moodle/calendar:manageownentries',
    ],
    'local_aiagentapi_assignment_save_draft' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'assignment_save_draft',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Save online-text assignment draft content for the current user.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/assign:submit',
    ],
    'local_aiagentapi_assignment_submit_final' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'assignment_submit_final',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Submit the current user assignment for grading.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/assign:submit',
    ],
    'local_aiagentapi_forum_create_discussion' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'forum_create_discussion',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Create a new forum discussion for the current user.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/forum:startdiscussion',
    ],
    'local_aiagentapi_forum_reply_post' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'forum_reply_post',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Reply to an existing forum post for the current user.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use, mod/forum:replypost',
    ],
    'local_aiagentapi_forum_update_post' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'forum_update_post',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Update an editable forum discussion or reply post for the current user.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use',
    ],
    'local_aiagentapi_forum_delete_post' => [
        'classname' => 'local_aiagentapi_external',
        'methodname' => 'forum_delete_post',
        'classpath' => 'local/aiagentapi/externallib.php',
        'description' => 'Delete an editable forum post or discussion for the current user.',
        'type' => 'write',
        'capabilities' => 'local/aiagentapi:use',
    ],
];

$coreviewfunctions = [
    // These Moodle core functions log views and update native activity completion state.
    'mod_page_view_page',
    'mod_resource_view_resource',
    'mod_quiz_view_quiz',
];

$services = [
    'local_aiagentapi' => [
        'functions' => array_merge(array_keys($functions), $coreviewfunctions),
        'requiredcapability' => 'local/aiagentapi:use',
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'local_aiagentapi',
        'downloadfiles' => 1,
        'uploadfiles' => 1,
    ],
];
