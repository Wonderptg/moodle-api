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
 * Upgrade script for local_mathstate.
 *
 * @package     local_mathstate
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_mathstate_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026041700) {
        $table = new xmldb_table('local_mathstate_question_map');
        $fields = [
            new xmldb_field('source_id', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'qtype_std_id'),
            new xmldb_field('lesson_key', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'kg_ids_json'),
            new xmldb_field('lesson_match_type', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'lesson_key'),
            new xmldb_field('lesson_confidence', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'lesson_match_type'),
            new xmldb_field('review_status', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'mapping_confidence'),
            new xmldb_field('review_notes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'review_status'),
            new xmldb_field('metadata_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'review_notes'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $indexes = [
            new xmldb_index('idx_source_id', XMLDB_INDEX_NOTUNIQUE, ['source_id']),
            new xmldb_index('idx_lesson_key', XMLDB_INDEX_NOTUNIQUE, ['lesson_key']),
        ];
        foreach ($indexes as $index) {
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }

        $table = new xmldb_table('local_mathstate_learning_event');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('session_key', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('lesson_key', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('questionusageid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('qg_id', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('kg_ids_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('event_type', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'practice');
            $table->add_field('result', XMLDB_TYPE_CHAR, '16', null, null, null, null);
            $table->add_field('score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('maxscore', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('source', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'agent');
            $table->add_field('payload_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('occurred_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('idx_learning_event_user_course_time', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'occurred_at']);
            $table->add_index('idx_learning_event_session', XMLDB_INDEX_NOTUNIQUE, ['session_key']);
            $table->add_index('idx_learning_event_question', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_mathstate_lesson_session');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('session_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('lesson_key', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('progress_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('summary_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('started_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('ended_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('last_event_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('source', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'agent');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uq_lesson_session_key', XMLDB_INDEX_UNIQUE, ['session_key']);
            $table->add_index('idx_lesson_session_user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'status']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_mathstate_doc_job');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('job_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('target_type', XMLDB_TYPE_CHAR, '32', null, null, null, null);
            $table->add_field('target_ref', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('doc_ref', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('provider', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'agent');
            $table->add_field('job_type', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'doc');
            $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'queued');
            $table->add_field('request_payload_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('result_payload_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('error_message', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('queued_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('completed_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('uq_doc_job_key', XMLDB_INDEX_UNIQUE, ['job_key']);
            $table->add_index('idx_doc_job_user_course', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'status']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041700, 'local', 'mathstate');
    }

    return true;
}
