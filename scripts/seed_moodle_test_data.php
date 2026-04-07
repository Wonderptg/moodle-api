<?php
// Seed a minimal but realistic Moodle dataset for local AI API testing.

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

set_exception_handler(function(Throwable $e): void {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
});

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'course-shortname' => 'AIAGENT_DEMO_101',
        'course-fullname' => 'AI Agent Demo 101',
    ],
    [
        'h' => 'help',
    ]
);

if (!empty($unrecognized)) {
    cli_error('Unrecognised options: ' . implode(', ', $unrecognized));
}

if (!empty($options['help'])) {
    echo <<<TEXT
Seed local Moodle data for AI agent API testing.

Options:
--course-shortname   Course shortname to create/reuse (default: AIAGENT_DEMO_101)
--course-fullname    Course fullname to create/reuse (default: AI Agent Demo 101)
-h, --help           Show this help

TEXT;
    exit(0);
}

function seed_introeditor(string $text): array {
    $draftid = 0;
    file_prepare_draft_area($draftid, null, null, null, null);
    return [
        'text' => $text,
        'format' => FORMAT_HTML,
        'itemid' => $draftid,
    ];
}

function seed_find_qbank(stdClass $course, string $name): ?stdClass {
    global $DB;
    $sql = "SELECT q.id, q.name, cm.id AS cmid
              FROM {qbank} q
              JOIN {modules} m ON m.name = 'qbank'
              JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
             WHERE cm.course = ? AND q.name = ?";
    $record = $DB->get_record_sql($sql, [$course->id, $name]);
    return $record ?: null;
}

function seed_find_assignment(stdClass $course, string $name): ?stdClass {
    return seed_find_named_module($course, 'assign', $name);
}

function seed_find_page(stdClass $course, string $name): ?stdClass {
    return seed_find_named_module($course, 'page', $name);
}

function seed_find_url(stdClass $course, string $name): ?stdClass {
    return seed_find_named_module($course, 'url', $name);
}

function seed_find_forum(stdClass $course, string $name): ?stdClass {
    return seed_find_named_module($course, 'forum', $name);
}

function seed_find_named_module(stdClass $course, string $modname, string $name): ?stdClass {
    global $DB;
    $sql = "SELECT x.id, x.name, cm.id AS cmid
              FROM {" . $modname . "} x
              JOIN {modules} m ON m.name = ?
              JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = x.id
             WHERE x.course = ? AND x.name = ?";
    $record = $DB->get_record_sql($sql, [$modname, $course->id, $name]);
    return $record ?: null;
}

function seed_find_quiz(stdClass $course, string $name): ?stdClass {
    global $DB;
    $sql = "SELECT q.id, q.name, cm.id AS cmid
              FROM {quiz} q
              JOIN {modules} m ON m.name = 'quiz'
              JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = q.id
             WHERE q.course = ? AND q.name = ?";
    $record = $DB->get_record_sql($sql, [$course->id, $name]);
    return $record ?: null;
}

function seed_find_forum_discussion(int $forumid, string $subject): ?stdClass {
    global $DB;
    return $DB->get_record('forum_discussions', ['forum' => $forumid, 'name' => $subject]) ?: null;
}

function seed_update_course(stdClass $course, array $fields): void {
    global $DB;
    $update = (object)(['id' => $course->id] + $fields);
    $DB->update_record('course', $update);
}

function seed_update_cm(int $cmid, array $fields): void {
    global $DB;
    $update = (object)(['id' => $cmid] + $fields);
    $DB->update_record('course_modules', $update);
}

function seed_find_category(int $contextid, string $name): ?stdClass {
    global $DB;
    return $DB->get_record('question_categories', ['contextid' => $contextid, 'name' => $name]) ?: null;
}

function seed_ensure_category(int $contextid, string $name): stdClass {
    global $DB;

    $existing = seed_find_category($contextid, $name);
    if ($existing) {
        return $existing;
    }

    $topcategory = question_get_top_category($contextid, true);
    $manager = new \core_question\category_manager();

    $record = (object)[
        'name' => $name,
        'info' => '',
        'infoformat' => FORMAT_HTML,
        'stamp' => make_unique_id_code(),
        'contextid' => $contextid,
        'parent' => $topcategory->id,
        'sortorder' => $manager->get_max_sortorder($topcategory->id) + 1,
        'idnumber' => null,
    ];
    $record->id = $DB->insert_record('question_categories', $record);
    return $record;
}

function seed_find_latest_question(int $categoryid, string $name): ?stdClass {
    global $DB;
    $sql = "SELECT q.id, q.name
              FROM {question} q
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
             WHERE qbe.questioncategoryid = ?
               AND q.name = ?
               AND q.parent = 0
               AND qv.status = 'ready'
               AND NOT EXISTS (
                    SELECT 1
                      FROM {question_versions} qv2
                     WHERE qv2.questionbankentryid = qv.questionbankentryid
                       AND qv2.version > qv.version
               )";
    $record = $DB->get_record_sql($sql, [$categoryid, $name]);
    return $record ?: null;
}

function seed_create_multichoice_question(stdClass $category, array $spec): stdClass {
    global $USER;

    $question = (object)[
        'qtype' => 'multichoice',
        'createdby' => $USER->id,
        'idnumber' => null,
        'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
    ];

    $form = (object)[
        'category' => $category->id . ',' . $category->contextid,
        'name' => $spec['name'],
        'questiontext' => [
            'text' => $spec['questiontext'],
            'format' => FORMAT_HTML,
        ],
        'generalfeedback' => [
            'text' => $spec['generalfeedback'],
            'format' => FORMAT_HTML,
        ],
        'defaultmark' => 1,
        'penalty' => 0.3333333,
        'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        'versionid' => 0,
        'version' => 1,
        'questionbankentryid' => 0,
        'idnumber' => '',
        'noanswers' => count($spec['answers']) + 1,
        'numhints' => 0,
        'shuffleanswers' => 1,
        'answernumbering' => 'abc',
        'showstandardinstruction' => 0,
        'single' => $spec['single'] ? '1' : '0',
        'correctfeedback' => [
            'text' => 'Correct.',
            'format' => FORMAT_HTML,
        ],
        'partiallycorrectfeedback' => [
            'text' => 'Partially correct.',
            'format' => FORMAT_HTML,
        ],
        'shownumcorrect' => 1,
        'incorrectfeedback' => [
            'text' => 'Incorrect.',
            'format' => FORMAT_HTML,
        ],
        'answer' => [],
        'fraction' => [],
        'feedback' => [],
        'hint' => [],
    ];

    foreach ($spec['answers'] as $answer) {
        $form->answer[] = [
            'text' => $answer['text'],
            'format' => FORMAT_PLAIN,
        ];
        $form->fraction[] = (string)$answer['fraction'];
        $form->feedback[] = [
            'text' => $answer['feedback'],
            'format' => FORMAT_HTML,
        ];
    }

    $form->answer[] = ['text' => '', 'format' => FORMAT_PLAIN];
    $form->fraction[] = '0.0';
    $form->feedback[] = ['text' => '', 'format' => FORMAT_HTML];

    return question_bank::get_qtype('multichoice')->save_question($question, $form);
}

\core\session\manager::set_user(get_admin());

$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']) ?: 0;
$aiagent = $DB->get_record('user', ['username' => 'aiagent'], '*', MUST_EXIST);

$courseshortname = trim((string)$options['course-shortname']);
$coursefullname = trim((string)$options['course-fullname']);
$course = $DB->get_record('course', ['shortname' => $courseshortname]);

if (!$course) {
    $course = create_course((object)[
        'fullname' => $coursefullname,
        'shortname' => $courseshortname,
        'summary' => 'Seeded for AI CLI and local_aiagentapi testing.',
        'summaryformat' => FORMAT_HTML,
        'format' => 'topics',
        'numsections' => 3,
        'visible' => 1,
        'enablecompletion' => 1,
    ]);
} else if (empty($course->enablecompletion)) {
    seed_update_course($course, ['enablecompletion' => 1]);
}

course_create_sections_if_missing($course->id, [1, 2, 3]);
enrol_try_internal_enrol($course->id, $aiagent->id, $studentroleid);
if (!empty($editingteacherroleid)) {
    enrol_try_internal_enrol($course->id, $aiagent->id, $editingteacherroleid);
}
$course = get_course($course->id);

$qbank = seed_find_qbank($course, 'AI Agent Question Bank');
if (!$qbank) {
    $qbank = create_module((object)[
        'modulename' => 'qbank',
        'course' => $course->id,
        'section' => 0,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Question Bank',
        'introeditor' => seed_introeditor('Question bank for local AI API tests.'),
        'type' => \core_question\local\bank\question_bank_helper::TYPE_STANDARD,
    ]);
}

$qbankcontext = context_module::instance($qbank->cmid);
$category = seed_ensure_category($qbankcontext->id, 'AI Agent Demo Category');

$questionspecs = [
    [
        'name' => 'AI Agent Demo Question 1',
        'questiontext' => 'What is 2 + 2?',
        'generalfeedback' => '2 + 2 equals 4.',
        'single' => true,
        'answers' => [
            ['text' => 'One', 'fraction' => 0.0, 'feedback' => 'No.'],
            ['text' => 'Two', 'fraction' => 0.0, 'feedback' => 'No.'],
            ['text' => 'Three', 'fraction' => 0.0, 'feedback' => 'No.'],
            ['text' => 'Four', 'fraction' => 1.0, 'feedback' => 'Correct.'],
        ],
    ],
    [
        'name' => 'AI Agent Demo Question 2',
        'questiontext' => 'Which are the odd numbers?',
        'generalfeedback' => 'One and Three are odd.',
        'single' => false,
        'answers' => [
            ['text' => 'One', 'fraction' => 0.5, 'feedback' => 'Correct.'],
            ['text' => 'Two', 'fraction' => 0.0, 'feedback' => 'Two is even.'],
            ['text' => 'Three', 'fraction' => 0.5, 'feedback' => 'Correct.'],
            ['text' => 'Four', 'fraction' => 0.0, 'feedback' => 'Four is even.'],
        ],
    ],
    [
        'name' => 'AI Agent Demo Question 3',
        'questiontext' => 'Which number is even?',
        'generalfeedback' => 'Two is the even number.',
        'single' => true,
        'answers' => [
            ['text' => 'One', 'fraction' => 0.0, 'feedback' => 'One is odd.'],
            ['text' => 'Two', 'fraction' => 1.0, 'feedback' => 'Correct.'],
            ['text' => 'Three', 'fraction' => 0.0, 'feedback' => 'Three is odd.'],
            ['text' => 'Four', 'fraction' => 0.0, 'feedback' => 'Not the intended answer here.'],
        ],
    ],
];

$questionids = [];
foreach ($questionspecs as $spec) {
    $existing = seed_find_latest_question($category->id, $spec['name']);
    if ($existing) {
        $questionids[] = (int)$existing->id;
        continue;
    }
    $question = seed_create_multichoice_question($category, $spec);
    $questionids[] = (int)$question->id;
}

$assignment = seed_find_assignment($course, 'AI Agent Assignment 1');
if (!$assignment) {
    $assignment = create_module((object)[
        'modulename' => 'assign',
        'course' => $course->id,
        'section' => 1,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Assignment 1',
        'introeditor' => seed_introeditor('Seed assignment for CLI testing.'),
        'alwaysshowdescription' => 1,
        'submissiondrafts' => 1,
        'requiresubmissionstatement' => 0,
        'sendnotifications' => 0,
        'sendstudentnotifications' => 1,
        'sendlatenotifications' => 0,
        'duedate' => time() + (7 * DAYSECS),
        'allowsubmissionsfromdate' => time(),
        'cutoffdate' => time() + (9 * DAYSECS),
        'gradingduedate' => time() + (10 * DAYSECS),
        'grade' => 100,
        'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0,
        'teamsubmissiongroupingid' => 0,
        'blindmarking' => 0,
        'markingworkflow' => 0,
        'markingallocation' => 0,
        'assignsubmission_onlinetext_enabled' => 1,
        'assignsubmission_file_enabled' => 0,
        'assignsubmission_comments_enabled' => 0,
        'assignfeedback_comments_enabled' => 1,
        'assignfeedback_offline_enabled' => 0,
        'assignfeedback_file_enabled' => 0,
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (7 * DAYSECS),
        'completionsubmit' => 1,
    ]);
} else {
    seed_update_cm((int)$assignment->cmid, [
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (7 * DAYSECS),
    ]);
    $DB->set_field('assign', 'completionsubmit', 1, ['id' => $assignment->id]);
}

$page = seed_find_page($course, 'AI Agent Course Guide');
if (!$page) {
    $page = create_module((object)[
        'modulename' => 'page',
        'course' => $course->id,
        'section' => 1,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Course Guide',
        'introeditor' => seed_introeditor('Overview page for seeded Moodle CLI tests.'),
        'page' => seed_introeditor('<p>This page is seeded so the AI agent can read course guidance.</p>'),
        'display' => RESOURCELIB_DISPLAY_OPEN,
        'printintro' => 1,
        'printlastmodified' => 1,
        'showdescription' => 1,
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (2 * DAYSECS),
    ]);
} else {
    seed_update_cm((int)$page->cmid, [
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (2 * DAYSECS),
    ]);
}

$url = seed_find_url($course, 'AI Agent Reference Link');
if (!$url) {
    $url = create_module((object)[
        'modulename' => 'url',
        'course' => $course->id,
        'section' => 1,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Reference Link',
        'introeditor' => seed_introeditor('External resource used by seeded tests.'),
        'externalurl' => 'https://moodle.org/',
        'display' => RESOURCELIB_DISPLAY_OPEN,
        'printintro' => 1,
        'showdescription' => 1,
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (3 * DAYSECS),
    ]);
} else {
    seed_update_cm((int)$url->cmid, [
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (3 * DAYSECS),
    ]);
}

$forum = seed_find_forum($course, 'AI Agent Forum');
if (!$forum) {
    $forum = create_module((object)[
        'modulename' => 'forum',
        'course' => $course->id,
        'section' => 3,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Forum',
        'introeditor' => seed_introeditor('Forum for seeded CLI and API discussion tests.'),
        'type' => 'general',
        'forcesubscribe' => FORUM_CHOOSESUBSCRIBE,
        'trackingtype' => FORUM_TRACKING_OPTIONAL,
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionposts' => 1,
        'completiondiscussions' => 1,
        'completionreplies' => 1,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (4 * DAYSECS),
    ]);
} else {
    seed_update_cm((int)$forum->cmid, [
        'completion' => COMPLETION_TRACKING_AUTOMATIC,
        'completionview' => COMPLETION_VIEW_REQUIRED,
        'completionexpected' => time() + (4 * DAYSECS),
    ]);
    $DB->update_record('forum', (object)[
        'id' => $forum->id,
        'completionposts' => 1,
        'completiondiscussions' => 1,
        'completionreplies' => 1,
    ]);
}

$forumdiscussion = seed_find_forum_discussion((int)$forum->id, 'Welcome to AI Agent Forum');
if (!$forumdiscussion) {
    \core\session\manager::set_user($aiagent);
    $discussion = (object)[
        'course' => $course->id,
        'forum' => $forum->id,
        'message' => '<p>This seeded discussion is here so forum read/write APIs have real content.</p>',
        'messageformat' => FORMAT_HTML,
        'messagetrust' => trusttext_trusted(context_course::instance($course->id)),
        'itemid' => 0,
        'groupid' => -1,
        'mailnow' => 0,
        'subject' => 'Welcome to AI Agent Forum',
        'name' => 'Welcome to AI Agent Forum',
        'timestart' => 0,
        'timeend' => 0,
        'timelocked' => 0,
        'attachments' => null,
        'pinned' => FORUM_DISCUSSION_UNPINNED,
    ];
    $discussionid = forum_add_discussion($discussion, null);
    \core\session\manager::set_user(get_admin());
    $forumdiscussion = $DB->get_record('forum_discussions', ['id' => $discussionid], '*', MUST_EXIST);
}

$assignment = seed_find_assignment($course, 'AI Agent Assignment 1') ?: $assignment;
$page = seed_find_page($course, 'AI Agent Course Guide') ?: $page;
$url = seed_find_url($course, 'AI Agent Reference Link') ?: $url;
$forum = seed_find_forum($course, 'AI Agent Forum') ?: $forum;

$quiz = seed_find_quiz($course, 'AI Agent Quiz 1');
if (!$quiz) {
    $quiz = create_module((object)[
        'modulename' => 'quiz',
        'course' => $course->id,
        'section' => 2,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Quiz 1',
        'introeditor' => seed_introeditor('Seed quiz with one fixed and one random question.'),
        'timeopen' => 0,
        'timeclose' => 0,
        'timelimit' => 0,
        'overduehandling' => 'autosubmit',
        'graceperiod' => 86400,
        'preferredbehaviour' => 'deferredfeedback',
        'canredoquestions' => 0,
        'attempts' => 0,
        'attemptonlast' => 0,
        'grademethod' => QUIZ_GRADEHIGHEST,
        'decimalpoints' => 2,
        'questiondecimalpoints' => -1,
        'reviewattempt' => 1,
        'reviewcorrectness' => 1,
        'reviewmarks' => 1,
        'reviewspecificfeedback' => 1,
        'reviewgeneralfeedback' => 1,
        'reviewrightanswer' => 1,
        'reviewoverallfeedback' => 0,
        'questionsperpage' => 2,
        'navmethod' => QUIZ_NAVMETHOD_FREE,
        'shuffleanswers' => 1,
        'sumgrades' => 0,
        'grade' => 2,
        'quizpassword' => '',
        'subnet' => '',
        'browsersecurity' => '',
        'delay1' => 0,
        'delay2' => 0,
        'showuserpicture' => 0,
        'showblocks' => 0,
        'completion' => COMPLETION_TRACKING_MANUAL,
    ]);
    quiz_add_quiz_question($questionids[0], $quiz, 0, 1.0);
} else {
    seed_update_cm((int)$quiz->cmid, [
        'completion' => COMPLETION_TRACKING_MANUAL,
    ]);
}
$hasrandomslot = $DB->record_exists_sql(
    "SELECT 1
       FROM {question_set_references} qsr
       JOIN {quiz_slots} qs ON qs.id = qsr.itemid
      WHERE qsr.component = ?
        AND qsr.questionarea = ?
        AND qs.quizid = ?",
    ['mod_quiz', 'slot', $quiz->id]
);
if (!$hasrandomslot) {
    $filtercondition = [
        'filter' => [
            'category' => [
                'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                'values' => [(int)$category->id],
                'filteroptions' => ['includesubcategories' => false],
            ],
        ],
    ];
    \mod_quiz\quiz_settings::create($quiz->id)->get_structure()->add_random_questions(0, 1, $filtercondition);
}
$quizrecord = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
\mod_quiz\quiz_settings::create($quizrecord->id)->get_grade_calculator()->recompute_quiz_sumgrades();

$answerquiz = seed_find_quiz($course, 'AI Agent Answer Quiz 1');
if (!$answerquiz) {
    $answerquiz = create_module((object)[
        'modulename' => 'quiz',
        'course' => $course->id,
        'section' => 2,
        'visible' => 1,
        'groupmode' => 0,
        'groupingid' => 0,
        'name' => 'AI Agent Answer Quiz 1',
        'introeditor' => seed_introeditor('Seed quiz with one fixed single-choice and one fixed multi-choice question.'),
        'timeopen' => 0,
        'timeclose' => 0,
        'timelimit' => 0,
        'overduehandling' => 'autosubmit',
        'graceperiod' => 86400,
        'preferredbehaviour' => 'deferredfeedback',
        'canredoquestions' => 0,
        'attempts' => 0,
        'attemptonlast' => 0,
        'grademethod' => QUIZ_GRADEHIGHEST,
        'decimalpoints' => 2,
        'questiondecimalpoints' => -1,
        'reviewattempt' => 1,
        'reviewcorrectness' => 1,
        'reviewmarks' => 1,
        'reviewspecificfeedback' => 1,
        'reviewgeneralfeedback' => 1,
        'reviewrightanswer' => 1,
        'reviewoverallfeedback' => 0,
        'questionsperpage' => 2,
        'navmethod' => QUIZ_NAVMETHOD_FREE,
        'shuffleanswers' => 1,
        'sumgrades' => 0,
        'grade' => 2,
        'quizpassword' => '',
        'subnet' => '',
        'browsersecurity' => '',
        'delay1' => 0,
        'delay2' => 0,
        'showuserpicture' => 0,
        'showblocks' => 0,
        'completion' => COMPLETION_TRACKING_MANUAL,
    ]);
    quiz_add_quiz_question($questionids[0], $answerquiz, 0, 1.0);
    quiz_add_quiz_question($questionids[1], $answerquiz, 0, 1.0);
} else {
    seed_update_cm((int)$answerquiz->cmid, [
        'completion' => COMPLETION_TRACKING_MANUAL,
    ]);
}
$answerquizrecord = $DB->get_record('quiz', ['id' => $answerquiz->id], '*', MUST_EXIST);
\mod_quiz\quiz_settings::create($answerquizrecord->id)->get_grade_calculator()->recompute_quiz_sumgrades();
rebuild_course_cache($course->id, true);

$summary = [
    'course' => [
        'id' => (int)$course->id,
        'shortname' => (string)$course->shortname,
        'fullname' => (string)$course->fullname,
    ],
    'user' => [
        'id' => (int)$aiagent->id,
        'username' => (string)$aiagent->username,
    ],
    'qbank' => [
        'id' => (int)$qbank->id,
        'cmid' => (int)$qbank->cmid,
        'contextid' => (int)$qbankcontext->id,
    ],
    'question_category' => [
        'id' => (int)$category->id,
        'name' => (string)$category->name,
        'contextid' => (int)$category->contextid,
    ],
    'question_ids' => array_values(array_map('intval', $questionids)),
    'assignment' => [
        'id' => (int)$assignment->id,
        'cmid' => (int)$assignment->cmid,
        'name' => (string)$assignment->name,
    ],
    'page' => [
        'id' => (int)$page->id,
        'cmid' => (int)$page->cmid,
        'name' => (string)$page->name,
    ],
    'url' => [
        'id' => (int)$url->id,
        'cmid' => (int)$url->cmid,
        'name' => (string)$url->name,
    ],
    'forum' => [
        'id' => (int)$forum->id,
        'cmid' => (int)$forum->cmid,
        'name' => (string)$forum->name,
    ],
    'forum_discussion' => [
        'id' => (int)$forumdiscussion->id,
        'name' => (string)$forumdiscussion->name,
        'firstpost' => (int)$forumdiscussion->firstpost,
    ],
    'quiz' => [
        'id' => (int)$quiz->id,
        'cmid' => (int)$quiz->cmid,
        'name' => (string)$quiz->name,
    ],
    'quiz_answering' => [
        'id' => (int)$answerquiz->id,
        'cmid' => (int)$answerquiz->cmid,
        'name' => (string)$answerquiz->name,
    ],
];

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
