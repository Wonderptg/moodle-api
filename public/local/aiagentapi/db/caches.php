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
 * Cache definitions for local_aiagentapi.
 *
 * @package     local_aiagentapi
 * @category    cache
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'student_shell_courses' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 8,
        'ttl' => 300,
        'invalidationevents' => [
            'changesincourse',
            'changesincoursecat',
            'changesincategoryenrolment',
        ],
    ],
    'student_shell_course_outline' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
        'ttl' => 86400,
        'invalidationevents' => [
            'changesincourse',
            'changesincoursecat',
        ],
    ],
    'student_shell_quiz_list' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
        'ttl' => 86400,
        'invalidationevents' => [
            'changesincourse',
        ],
    ],
];
