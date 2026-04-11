#!/usr/bin/env python3
from __future__ import annotations

import argparse
import io
import json
import os
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(__file__))

import moodle_cli  # noqa: E402


class MoodleCliTests(unittest.TestCase):
    def test_rewrite_fields_alias(self) -> None:
        self.assertEqual(
            moodle_cli.rewrite_desire_args(["questions", "pick-random", "--fields=id,name"]),
            ["questions", "pick-random", "--select=id,name"],
        )

    def test_unwrap_primary_prefers_data_envelope(self) -> None:
        payload = {
            "ok": True,
            "audit_id": "a",
            "replayed": False,
            "dry_run": False,
            "data": {"questions": [{"id": 1}, {"id": 2}]},
        }
        self.assertEqual(moodle_cli.unwrap_primary(payload), [{"id": 1}, {"id": 2}])

    def test_select_fields_supports_dot_paths(self) -> None:
        value = {"data": {"plugin": {"component": "local_aiagentapi", "version": 1}}}
        selected = moodle_cli.select_fields(value, ["data.plugin.component"])
        self.assertEqual(selected, {"data.plugin.component": "local_aiagentapi"})

    def test_apply_env_defaults_accepts_format_flag(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["--format", "table", "courses", "list"])
        args = moodle_cli.apply_env_defaults(args)
        self.assertEqual(args.format, "table")

    def test_transform_courses_list_result_builds_lark_style_resource_objects(self) -> None:
        payload = {
            "ok": True,
            "audit_id": "a1",
            "replayed": False,
            "dry_run": False,
            "data": {
                "courses": [
                    {
                        "id": 92,
                        "shortname": "camp-92",
                        "fullname": "提分训练营 语文",
                        "categoryid": 7,
                        "categoryname": "提分训练营",
                        "categorypath": "/1/7",
                        "categorydisplaypath": "题库课程 / 提分训练营",
                        "categorypathnames": ["题库课程", "提分训练营"],
                        "format": "topics",
                        "lang": "zh_cn",
                        "enablecompletion": True,
                        "visible": True,
                        "startdate": 1710000000,
                        "enddate": 0,
                        "semantic": {
                            "course_type": "question_bank",
                            "learning_mode": "practice",
                            "agent_strategy": "practice_first",
                            "confidence": "high",
                            "reasons": ["Category indicates question bank flow."],
                            "module_stats": [
                                {"modname": "quiz", "count": 12},
                                {"modname": "page", "count": 2},
                            ],
                        },
                    }
                ]
            },
        }
        transformed = moodle_cli.transform_courses_list_result(payload)
        self.assertTrue(transformed["ok"])
        self.assertEqual(transformed["meta"]["count"], 1)
        self.assertEqual(len(transformed["data"]["items"]), 1)
        course = transformed["data"]["courses"][0]
        self.assertEqual(course["resource_type"], "course")
        self.assertEqual(course["title"], "提分训练营 语文")
        self.assertEqual(course["category"]["display_path"], "题库课程 / 提分训练营")
        self.assertEqual(course["learning"]["course_type"], "question_bank")
        self.assertEqual(course["module_stats"][0]["type"], "quiz")

    def test_transform_courses_outline_result_builds_outline_summary_and_route(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "course": {
                    "id": 104,
                    "shortname": "video-104",
                    "fullname": "2026单招最新视频课",
                    "categoryid": 9,
                    "categoryname": "2026单招最新视频课",
                    "categorypath": "/1/9",
                    "categorydisplaypath": "视频课程 / 2026单招最新视频课",
                    "categorypathnames": ["视频课程", "2026单招最新视频课"],
                    "format": "topics",
                    "lang": "zh_cn",
                    "enablecompletion": True,
                    "semantic": {
                        "course_type": "video_course",
                        "learning_mode": "watch",
                        "agent_strategy": "watch_first",
                        "confidence": "high",
                        "reasons": ["Category indicates video course."],
                    },
                },
                "sections": [
                    {
                        "id": 1,
                        "sectionnum": 1,
                        "name": "基础导学",
                        "summary": "先看导学视频，再看讲义。",
                        "modules": [
                            {
                                "cmid": 1001,
                                "instance": 201,
                                "modname": "page",
                                "name": "第一节视频课",
                                "uservisible": True,
                                "url": "http://example.test/mod/page/view.php?id=1001",
                            },
                            {
                                "cmid": 1002,
                                "instance": 202,
                                "modname": "resource",
                                "name": "第一节讲义",
                                "uservisible": True,
                                "url": "http://example.test/mod/resource/view.php?id=1002",
                            },
                        ],
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_courses_outline_result(payload)
        self.assertEqual(transformed["meta"]["section_count"], 1)
        self.assertEqual(transformed["meta"]["module_count"], 2)
        self.assertEqual(len(transformed["data"]["items"]), 1)
        route = transformed["data"]["outline"]["route"]
        self.assertEqual(route["strategy"], "watch_first")
        self.assertEqual(route["entry_section"], 1)
        self.assertIn("lesson-style", route["notes"][0])

    def test_transform_activities_list_result_normalizes_activity_resources(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "course": {
                    "id": 10,
                    "shortname": "c10",
                    "fullname": "课程10",
                    "categoryid": 1,
                    "categoryname": "分类",
                    "categorypath": "/1",
                    "categorydisplaypath": "分类",
                    "categorypathnames": ["分类"],
                    "format": "topics",
                    "lang": "zh_cn",
                    "enablecompletion": True,
                    "semantic": {
                        "course_type": "mixed_course",
                        "learning_mode": "mixed",
                        "agent_strategy": "mixed_route",
                        "confidence": "medium",
                        "reasons": [],
                    },
                },
                "activities": [
                    {
                        "cmid": 201,
                        "instance": 301,
                        "modname": "assign",
                        "name": "作业1",
                        "sectionnum": 2,
                        "visible": True,
                        "url": "http://example.test/mod/assign/view.php?id=201",
                        "openfrom": 1711000000,
                        "dueto": 1712000000,
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_activities_list_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        self.assertEqual(len(transformed["data"]["items"]), 1)
        item = transformed["data"]["activities"][0]
        self.assertEqual(item["resource_type"], "activity")
        self.assertEqual(item["kind"], "assign")
        self.assertEqual(item["title"], "作业1")
        self.assertTrue(item["open_at"].startswith("2024-"))

    def test_transform_quiz_list_result_normalizes_quiz_resources(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "course": {
                    "id": 11,
                    "shortname": "c11",
                    "fullname": "课程11",
                    "categoryid": 1,
                    "categoryname": "分类",
                    "categorypath": "/1",
                    "categorydisplaypath": "分类",
                    "categorypathnames": ["分类"],
                    "format": "topics",
                    "lang": "zh_cn",
                    "enablecompletion": True,
                    "semantic": {
                        "course_type": "question_bank",
                        "learning_mode": "practice",
                        "agent_strategy": "practice_first",
                        "confidence": "high",
                        "reasons": [],
                    },
                },
                "quizzes": [
                    {
                        "quizid": 901,
                        "cmid": 902,
                        "name": "阶段测验",
                        "sectionnum": 3,
                        "visible": True,
                        "url": "http://example.test/mod/quiz/view.php?id=902",
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_quiz_list_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        self.assertEqual(len(transformed["data"]["items"]), 1)
        quiz = transformed["data"]["quizzes"][0]
        self.assertEqual(quiz["resource_type"], "quiz")
        self.assertEqual(quiz["id"], 901)
        self.assertEqual(quiz["activity_id"], 902)

    def test_unwrap_primary_supports_feishu_style_envelope(self) -> None:
        payload = {
            "ok": True,
            "identity": "user",
            "data": {
                "activities": [{"id": 1}, {"id": 2}],
                "items": [{"id": 1}, {"id": 2}],
            },
            "meta": {"count": 2},
        }
        self.assertEqual(moodle_cli.unwrap_primary(payload), [{"id": 1}, {"id": 2}])

    def test_extract_items_for_output_prefers_items_alias(self) -> None:
        payload = {
            "ok": True,
            "identity": "user",
            "data": {
                "quizzes": [{"id": 10}],
                "items": [{"id": 10}],
            },
        }
        self.assertEqual(moodle_cli.extract_items_for_output(payload), [{"id": 10}])

    def test_transform_assignments_list_result_normalizes_assignments(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "course": {
                    "id": 21,
                    "shortname": "c21",
                    "fullname": "课程21",
                    "categoryid": 1,
                    "categoryname": "分类",
                    "categorypath": "/1",
                    "categorydisplaypath": "分类",
                    "categorypathnames": ["分类"],
                    "format": "topics",
                    "lang": "zh_cn",
                    "enablecompletion": True,
                    "semantic": {},
                },
                "assignments": [
                    {
                        "cmid": 301,
                        "assignid": 401,
                        "name": "作业A",
                        "sectionnum": 2,
                        "visible": True,
                        "url": "http://example.test/mod/assign/view.php?id=301",
                        "allowsubmissionsfromdate": 1711000000,
                        "duedate": 1712000000,
                        "cutoffdate": 1712600000,
                        "gradingduedate": 1713000000,
                        "alwaysshowdescription": True,
                        "teamsubmission": False,
                        "submissiondrafts": True,
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_assignments_list_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        self.assertEqual(len(transformed["data"]["items"]), 1)
        item = transformed["data"]["assignments"][0]
        self.assertEqual(item["resource_type"], "assignment")
        self.assertEqual(item["id"], 401)
        self.assertEqual(item["activity_id"], 301)
        self.assertEqual(item["title"], "作业A")

    def test_transform_calendar_list_result_normalizes_events(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "timestart": 1711000000,
                "timeend": 1713000000,
                "events": [
                    {
                        "id": 77,
                        "name": "课程直播",
                        "eventtype": "course",
                        "timestart": 1711200000,
                        "timeduration": 3600,
                        "courseid": 21,
                        "groupid": 0,
                        "userid": 2,
                        "visible": True,
                        "url": "http://example.test/calendar/view.php?event=77",
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_calendar_list_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        event = transformed["data"]["events"][0]
        self.assertEqual(event["resource_type"], "calendar_event")
        self.assertEqual(event["id"], 77)
        self.assertEqual(event["event_type"], "course")
        self.assertTrue(event["start_at"].startswith("2024-"))
        self.assertTrue(event["end_at"].startswith("2024-"))

    def test_transform_forum_discussions_result_normalizes_items(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "courseid": 21,
                "forumid": 9,
                "discussions": [
                    {
                        "forumid": 9,
                        "forumname": "答疑区",
                        "courseid": 21,
                        "courseshortname": "c21",
                        "discussionid": 888,
                        "postid": 889,
                        "subject": "第一章讨论",
                        "userfullname": "Alice",
                        "created": 1711200000,
                        "modified": 1711200600,
                        "timemodified": 1711200600,
                        "numreplies": 5,
                        "numunread": 2,
                        "pinned": False,
                        "locked": False,
                        "canreply": True,
                        "url": "http://example.test/mod/forum/discuss.php?d=888",
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_forum_discussions_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        item = transformed["data"]["discussions"][0]
        self.assertEqual(item["resource_type"], "forum_discussion")
        self.assertEqual(item["id"], 888)
        self.assertEqual(item["forum_title"], "答疑区")
        self.assertEqual(item["replies"], 5)

    def test_transform_notifications_list_result_normalizes_items(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "limitfrom": 0,
                "limitnum": 20,
                "notifications": [
                    {
                        "id": 901,
                        "useridfrom": 2,
                        "useridto": 3,
                        "subject": "作业提醒",
                        "fullmessage": "请在今晚提交作业",
                        "smallmessage": "今晚提交作业",
                        "component": "mod_assign",
                        "eventtype": "submission_due",
                        "timecreated": 1711200000,
                        "timeread": 0,
                        "read": False,
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_notifications_list_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        item = transformed["data"]["notifications"][0]
        self.assertEqual(item["resource_type"], "notification")
        self.assertEqual(item["id"], 901)
        self.assertEqual(item["component"], "mod_assign")
        self.assertFalse(item["is_read"])

    def test_transform_questions_search_result_normalizes_questions(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "questions": [
                    {
                        "id": 3001,
                        "name": "函数单调性",
                        "qtype": "multichoice",
                        "categoryid": 91,
                        "categoryname": "数学题库",
                        "defaultmark": 2.0,
                        "timecreated": 1711200000,
                        "timemodified": 1711300000,
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_questions_search_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        question = transformed["data"]["questions"][0]
        self.assertEqual(question["resource_type"], "question")
        self.assertEqual(question["id"], 3001)
        self.assertEqual(question["kind"], "multichoice")
        self.assertEqual(question["category_name"], "数学题库")

    def test_transform_activities_detail_result_normalizes_activity_detail(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "course": {
                    "id": 21,
                    "shortname": "c21",
                    "fullname": "课程21",
                    "categoryid": 1,
                    "categoryname": "分类",
                    "categorypath": "/1",
                    "categorydisplaypath": "分类",
                    "categorypathnames": ["分类"],
                    "format": "topics",
                    "lang": "zh_cn",
                    "enablecompletion": True,
                    "semantic": {},
                },
                "activity": {
                    "cmid": 501,
                    "instance": 601,
                    "modname": "assign",
                    "name": "作业详情",
                    "sectionnum": 2,
                    "visible": True,
                    "url": "http://example.test/mod/assign/view.php?id=501",
                    "summaryhtml": "<p>摘要</p>",
                    "contenthtml": "<div>正文</div>",
                    "externalurl": "",
                    "openfrom": 1711000000,
                    "dueto": 1712000000,
                    "completionexpected": 1712100000,
                },
            },
        }
        transformed = moodle_cli.transform_activities_detail_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        self.assertEqual(len(transformed["data"]["items"]), 1)
        item = transformed["data"]["activity"]
        self.assertEqual(item["resource_type"], "activity")
        self.assertEqual(item["id"], 501)
        self.assertIn("summary_html", item["content"])

    def test_transform_quiz_attempts_result_normalizes_attempt_groups(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "courseid": 21,
                "quizid": 0,
                "quizzes": [
                    {
                        "courseid": 21,
                        "courseshortname": "c21",
                        "cmid": 902,
                        "quizid": 901,
                        "name": "阶段测验",
                        "timeopen": 1711000000,
                        "timeclose": 1712000000,
                        "attemptsallowed": 3,
                        "attemptsmade": 1,
                        "attemptsleft": 2,
                        "bestgrade": 85.0,
                        "grademax": 100.0,
                        "url": "http://example.test/mod/quiz/view.php?id=902",
                        "hasunfinished": False,
                        "attempts": [
                            {
                                "attemptid": 1001,
                                "attempt": 1,
                                "state": "finished",
                                "timestart": 1711100000,
                                "timefinish": 1711103600,
                                "sumgrades": 85.0,
                            }
                        ],
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_quiz_attempts_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        quiz = transformed["data"]["quizzes"][0]
        self.assertEqual(quiz["resource_type"], "quiz_attempt_group")
        self.assertEqual(quiz["id"], 901)
        self.assertEqual(quiz["attempt_count"], 1)
        self.assertEqual(quiz["attempts"][0]["attempt_id"], 1001)

    def test_transform_grades_overview_result_normalizes_courses(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "courseid": 0,
                "courses": [
                    {
                        "courseid": 21,
                        "courseshortname": "c21",
                        "coursefullname": "课程21",
                        "coursegrade": 88.5,
                        "grademin": 0.0,
                        "grademax": 100.0,
                        "items": [
                            {
                                "itemid": 1,
                                "itemtype": "mod",
                                "itemmodule": "assign",
                                "iteminstance": 401,
                                "itemname": "作业A",
                                "grade": 90.0,
                                "grademin": 0.0,
                                "grademax": 100.0,
                                "percentage": 90.0,
                                "feedback": "good",
                                "dategraded": 1711200000,
                            }
                        ],
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_grades_overview_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        course = transformed["data"]["courses"][0]
        self.assertEqual(course["resource_type"], "grade_course")
        self.assertEqual(course["id"], 21)
        self.assertEqual(course["graded_items_count"], 1)
        self.assertEqual(course["items"][0]["resource_type"], "grade_item")

    def test_transform_progress_course_result_normalizes_courses(self) -> None:
        payload = {
            "ok": True,
            "data": {
                "courseid": 0,
                "courses": [
                    {
                        "courseid": 21,
                        "courseshortname": "c21",
                        "coursefullname": "课程21",
                        "completionenabled": True,
                        "progresspercent": 66.7,
                        "completedcount": 2,
                        "totalcount": 3,
                        "statuses": [
                            {
                                "cmid": 501,
                                "modname": "assign",
                                "instance": 401,
                                "state": 1,
                                "timecompleted": 1711200000,
                                "tracking": 2,
                                "isoverallcomplete": True,
                                "uservisible": True,
                            }
                        ],
                    }
                ],
            },
        }
        transformed = moodle_cli.transform_progress_course_result(payload)
        self.assertEqual(transformed["meta"]["count"], 1)
        course = transformed["data"]["courses"][0]
        self.assertEqual(course["resource_type"], "progress_course")
        self.assertEqual(course["id"], 21)
        self.assertEqual(course["completed_count"], 2)
        self.assertEqual(course["statuses"][0]["resource_type"], "progress_status")

    def test_emit_cli_error_outputs_structured_envelope_in_json_mode(self) -> None:
        err = moodle_cli.CliError(
            "missing token",
            moodle_cli.EXIT_CONFIG,
            hint="run: moodle auth login",
        )
        args = argparse.Namespace(token="tok_demo", json=True, plain=False, format="")
        stderr = io.StringIO()
        with mock.patch("sys.stderr", stderr):
            moodle_cli.emit_cli_error(err, args)
        payload = json.loads(stderr.getvalue())
        self.assertFalse(payload["ok"])
        self.assertEqual(payload["identity"], "user")
        self.assertEqual(payload["error"]["type"], "config")
        self.assertEqual(payload["error"]["code"], moodle_cli.EXIT_CONFIG)
        self.assertEqual(payload["error"]["message"], "missing token")
        self.assertEqual(payload["error"]["hint"], "run: moodle auth login")

    def test_emit_cli_error_outputs_plain_message_when_plain_mode(self) -> None:
        err = moodle_cli.CliError("simple error", moodle_cli.EXIT_ERROR)
        args = argparse.Namespace(token="", json=False, plain=True, format="")
        stderr = io.StringIO()
        with mock.patch("sys.stderr", stderr):
            moodle_cli.emit_cli_error(err, args)
        self.assertEqual(stderr.getvalue(), "simple error\n")

    def test_parse_enabled_commands(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["--enable-commands=context", "context", "get"])
        args = moodle_cli.apply_env_defaults(args)
        moodle_cli.enforce_enabled_commands(args)
        blocked = parser.parse_args(["--enable-commands=context", "catalog", "get"])
        blocked = moodle_cli.apply_env_defaults(blocked)
        with self.assertRaises(moodle_cli.CliError):
            moodle_cli.enforce_enabled_commands(blocked)

    def test_schema_contains_expected_groups(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("doctor", names)
        self.assertIn("config", names)
        self.assertIn("auth", names)
        self.assertIn("profile", names)
        self.assertIn("courses", names)
        self.assertIn("activities", names)
        self.assertIn("assignments", names)
        self.assertIn("calendar", names)
        self.assertIn("grades", names)
        self.assertIn("progress", names)
        self.assertIn("notifications", names)
        self.assertIn("questions", names)

    def test_questions_schema_contains_search(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "questions"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("categories", names)
        self.assertIn("search", names)

    def test_resolve_name_value_pairs_supports_single_key_objects(self) -> None:
        pairs = moodle_cli.resolve_name_value_pairs(['{"q1:1_answer":"1"}'], "--response-json")
        self.assertEqual(pairs, [{"name": "q1:1_answer", "value": "1"}])

    def test_resolve_json_objects_accepts_structured_answers(self) -> None:
        answers = moodle_cli.resolve_json_objects(['{"slot":1,"choice_index":0}'], "--answer-json")
        self.assertEqual(answers, [{"slot": 1, "choice_index": 0}])

    def test_quiz_answer_normalizes_choice_indexes(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args([
            "--force",
            "quiz",
            "answer",
            "--idempotency-key",
            "x1",
            "--attempt-id",
            "12",
            "--answer-json",
            '{"slot":2,"choice_indexes":[0,2]}',
        ])
        self.assertEqual(moodle_cli.resolve_json_objects(args.answer_json, "--answer-json")[0]["choice_indexes"], [0, 2])

    def test_activities_schema_contains_due_and_detail(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "activities"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("due", names)
        self.assertIn("detail", names)

    def test_assignments_schema_contains_save_draft(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "assignments"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("save-draft", names)
        self.assertIn("submit-final", names)

    def test_quiz_schema_contains_attempt_lifecycle(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "quiz"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("start", names)
        self.assertIn("attempt-data", names)
        self.assertIn("attempt-summary", names)
        self.assertIn("save-attempt", names)
        self.assertIn("submit-attempt", names)
        self.assertIn("answer", names)

    def test_forum_schema_contains_write_commands(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "forum"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("create-discussion", names)
        self.assertIn("reply", names)
        self.assertIn("update-post", names)
        self.assertIn("delete-post", names)

    def test_apply_env_defaults_reads_current_profile(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            config_path = os.path.join(tmpdir, "config.json")
            with open(config_path, "w", encoding="utf-8") as f:
                json.dump({
                    "version": 1,
                    "current_profile": "demo",
                    "profiles": {
                        "demo": {
                            "base_url": "https://moodle.example.test",
                            "service": "local_aiagentapi",
                            "token": "tok_demo_1234",
                        }
                    },
                }, f)

            parser = moodle_cli.build_parser()
            args = parser.parse_args(["--config-dir", tmpdir, "auth", "status", "--offline"])
            args = moodle_cli.apply_env_defaults(args)
            self.assertEqual(args.profile, "demo")
            self.assertEqual(args.base_url, "https://moodle.example.test")
            self.assertEqual(args.service, "local_aiagentapi")
            self.assertEqual(args.token, "tok_demo_1234")

    def test_config_init_persists_and_activates_profile(self) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            parser = moodle_cli.build_parser()
            args = parser.parse_args([
                "--config-dir", tmpdir,
                "--base-url", "https://school.example.test",
                "config", "init",
                "--name", "prod",
                "--activate",
            ])
            args = moodle_cli.apply_env_defaults(args)
            cli = moodle_cli.MoodleCLI(parser=parser, args=args)
            result = moodle_cli.command_config_init(cli, args)

            self.assertTrue(result["ok"])
            self.assertEqual(result["current_profile"], "prod")
            with open(os.path.join(tmpdir, "config.json"), "r", encoding="utf-8") as f:
                stored = json.load(f)
            self.assertEqual(stored["current_profile"], "prod")
            self.assertEqual(stored["profiles"]["prod"]["base_url"], "https://school.example.test")
            self.assertEqual(stored["profiles"]["prod"]["service"], "local_aiagentapi")

    @mock.patch.object(moodle_cli, "keychain_available", return_value=False)
    @mock.patch.object(moodle_cli, "verify_profile_session")
    @mock.patch.object(moodle_cli, "request_login_token")
    def test_auth_login_persists_token_and_user(self, mock_login: mock.Mock, mock_verify: mock.Mock, _mock_keychain: mock.Mock) -> None:
        mock_login.return_value = {"token": "token-abc-1234"}
        mock_verify.return_value = {
            "ok": True,
            "data": {
                "user": {
                    "userid": 42,
                    "username": "alice",
                    "fullname": "Alice Example",
                },
                "context": {
                    "contextid": 1,
                },
            },
        }
        with tempfile.TemporaryDirectory() as tmpdir:
            parser = moodle_cli.build_parser()
            args = parser.parse_args([
                "--config-dir", tmpdir,
                "--base-url", "https://school.example.test",
                "auth", "login",
                "--name", "alice",
                "--username", "alice",
                "--password", "secret",
            ])
            args = moodle_cli.apply_env_defaults(args)
            cli = moodle_cli.MoodleCLI(parser=parser, args=args)
            result = moodle_cli.command_auth_login(cli, args)

            self.assertTrue(result["ok"])
            self.assertEqual(result["profile"]["name"], "alice")
            self.assertEqual(result["user"]["userid"], 42)
            with open(os.path.join(tmpdir, "config.json"), "r", encoding="utf-8") as f:
                stored = json.load(f)
            self.assertEqual(stored["current_profile"], "alice")
            self.assertEqual(stored["profiles"]["alice"]["token"], "token-abc-1234")
            self.assertEqual(stored["profiles"]["alice"]["username"], "alice")
            self.assertEqual(stored["profiles"]["alice"]["user_id"], 42)

    @mock.patch.object(moodle_cli, "keychain_available", return_value=False)
    def test_profile_rename_moves_file_stored_token(self, _mock_keychain: mock.Mock) -> None:
        with tempfile.TemporaryDirectory() as tmpdir:
            with open(os.path.join(tmpdir, "config.json"), "w", encoding="utf-8") as f:
                json.dump({
                    "version": 1,
                    "current_profile": "old",
                    "profiles": {
                        "old": {
                            "base_url": "https://moodle.example.test",
                            "service": "local_aiagentapi",
                            "token_storage": "file",
                            "token": "tok_rename_1234",
                        }
                    },
                }, f)
            parser = moodle_cli.build_parser()
            args = parser.parse_args(["--config-dir", tmpdir, "profile", "rename", "old", "new"])
            args = moodle_cli.apply_env_defaults(args)
            cli = moodle_cli.MoodleCLI(parser=parser, args=args)
            result = moodle_cli.command_profile_rename(cli, args)

            self.assertTrue(result["ok"])
            self.assertEqual(result["new_name"], "new")
            with open(os.path.join(tmpdir, "config.json"), "r", encoding="utf-8") as f:
                stored = json.load(f)
            self.assertEqual(stored["current_profile"], "new")
            self.assertNotIn("old", stored["profiles"])
            self.assertEqual(stored["profiles"]["new"]["token"], "tok_rename_1234")

    @mock.patch.object(moodle_cli, "request_device_authorization")
    def test_auth_login_no_wait_returns_device_flow_payload(self, mock_start: mock.Mock) -> None:
        mock_start.return_value = {
            "device_code": "device-123",
            "user_code": "ABCD-EFGH",
            "verification_uri_complete": "https://moodle.example.test/local/aiagentapi/device_verify.php?user_code=ABCD-EFGH",
            "expires_in": 600,
            "interval": 5,
        }
        with tempfile.TemporaryDirectory() as tmpdir:
            parser = moodle_cli.build_parser()
            args = parser.parse_args([
                "--config-dir", tmpdir,
                "--base-url", "https://moodle.example.test",
                "auth", "login",
                "--name", "demo",
                "--no-wait",
            ])
            args = moodle_cli.apply_env_defaults(args)
            cli = moodle_cli.MoodleCLI(parser=parser, args=args)
            result = moodle_cli.command_auth_login(cli, args)

            self.assertTrue(result["ok"])
            self.assertEqual(result["device_code"], "device-123")
            self.assertEqual(result["user_code"], "ABCD-EFGH")

    def test_parse_http_status(self) -> None:
        self.assertEqual(moodle_cli.parse_http_status("HTTP 200"), 200)
        self.assertEqual(moodle_cli.parse_http_status("https://x -> HTTP 404"), 404)
        self.assertIsNone(moodle_cli.parse_http_status("urlopen error [Errno 61]"))

    @mock.patch.object(moodle_cli, "verify_profile_session")
    @mock.patch.object(moodle_cli, "probe_url")
    def test_doctor_warns_when_device_verify_is_404(self, mock_probe_url: mock.Mock, mock_verify: mock.Mock) -> None:
        mock_probe_url.side_effect = [
            (True, "HTTP 200"),
            (True, "HTTP 200"),
            (True, "HTTP 404"),
            (True, "HTTP 200"),
        ]
        mock_verify.return_value = {
            "ok": True,
            "data": {"user": {"username": "alice"}, "context": {}},
        }

        with tempfile.TemporaryDirectory() as tmpdir:
            with open(os.path.join(tmpdir, "config.json"), "w", encoding="utf-8") as f:
                json.dump(
                    {
                        "version": 1,
                        "current_profile": "demo",
                        "profiles": {
                            "demo": {
                                "base_url": "http://moodle.example.test",
                                "service": "local_aiagentapi",
                                "token_storage": "file",
                                "token": "tok_demo_1234",
                            }
                        },
                    },
                    f,
                )

            parser = moodle_cli.build_parser()
            args = parser.parse_args(["--config-dir", tmpdir, "--profile", "demo", "doctor"])
            args = moodle_cli.apply_env_defaults(args)
            cli = moodle_cli.MoodleCLI(parser=parser, args=args)
            result = moodle_cli.command_doctor(cli, args)

            checks = {item["name"]: item for item in result["checks"]}
            self.assertEqual(checks["device_verify_page"]["status"], "warn")
            self.assertIn("device/browser login page is missing", checks["device_verify_page"]["hint"])


if __name__ == "__main__":
    unittest.main()
