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


class FakeCLI:
    def __init__(self, responses: dict[tuple[str, str], object]) -> None:
        self.responses = responses
        self.calls: list[tuple[str, dict[str, object]]] = []

    def call(self, wsfunction: str, params: dict[str, object]) -> object:
        self.calls.append((wsfunction, dict(params)))
        key = (wsfunction, json.dumps(params, sort_keys=True, ensure_ascii=False))
        if key not in self.responses:
            raise AssertionError(f"unexpected call: {wsfunction} {params}")
        return self.responses[key]


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

    def test_searchable_values_includes_summary_and_content(self) -> None:
        resource = {
            "title": "第1课",
            "summary": "本课讲测试重点。",
            "content": {
                "summary_html": "<p>总结测试重点</p>",
                "content_html": "<div>这是资源正文内容</div>",
            },
        }
        values = moodle_cli.searchable_values(resource)
        self.assertIn("summary", values)
        self.assertIn("content.summary_html", values)
        self.assertIn("content.content_html", values)

    def test_apply_env_defaults_accepts_format_flag(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["--format", "table", "courses", "list"])
        args = moodle_cli.apply_env_defaults(args)
        self.assertEqual(args.format, "table")

    def test_resolve_offset_limit_rejects_invalid_values(self) -> None:
        with self.assertRaises(moodle_cli.CliError):
            moodle_cli.resolve_offset_limit(-1, 10)
        with self.assertRaises(moodle_cli.CliError):
            moodle_cli.resolve_offset_limit(0, 0)

    def test_paginate_items_reports_has_more_and_next_offset(self) -> None:
        items = [{"id": 1}, {"id": 2}, {"id": 3}]
        page, meta = moodle_cli.paginate_items(items, offset=1, limit=1)
        self.assertEqual(page, [{"id": 2}])
        self.assertTrue(meta["has_more"])
        self.assertEqual(meta["next_offset"], 2)
        self.assertEqual(meta["total_count"], 3)

    def test_resolve_list_offset_limit_rejects_negative_values(self) -> None:
        with self.assertRaises(moodle_cli.CliError):
            moodle_cli.resolve_list_offset_limit(-1, 0)
        with self.assertRaises(moodle_cli.CliError):
            moodle_cli.resolve_list_offset_limit(0, -2)

    def test_paginate_items_optional_limit_supports_unbounded_mode(self) -> None:
        items = [{"id": 1}, {"id": 2}, {"id": 3}]
        page, meta = moodle_cli.paginate_items_optional_limit(items, offset=1, limit=0)
        self.assertEqual(page, [{"id": 2}, {"id": 3}])
        self.assertFalse(meta["has_more"])
        self.assertIsNone(meta["next_offset"])
        self.assertEqual(meta["total_count"], 3)

    def test_resolve_batch_items_accepts_object_items_array(self) -> None:
        with tempfile.NamedTemporaryFile("w+", encoding="utf-8", delete=False) as handle:
            json.dump({"items": [{"kg_id": "kp-1"}]}, handle, ensure_ascii=False)
            path = handle.name
        self.addCleanup(lambda: os.path.exists(path) and os.unlink(path))
        args = argparse.Namespace(input=path, item_json=[])
        items = moodle_cli.resolve_batch_items(args, label="mathstate kp-upsert")
        self.assertEqual(items, [{"kg_id": "kp-1"}])

    def test_command_mathstate_student_summary_calls_ws(self) -> None:
        params = {
            "courseid": 3,
            "userid": 8,
            "include_kp_states": True,
            "include_qtype_states": False,
            "include_due_tasks": True,
        }
        response = {"ok": True, "data": {"userid": 8, "courseid": 3}}
        cli = FakeCLI({
            ("local_mathstate_student_summary", json.dumps(params, sort_keys=True, ensure_ascii=False)): response,
        })
        args = argparse.Namespace(
            course_id=3,
            user_id=8,
            include_kp_states=True,
            include_qtype_states=False,
            include_due_tasks=True,
        )
        result = moodle_cli.command_mathstate_student_summary(cli, args)
        self.assertEqual(result, response)

    def test_build_parser_includes_mathstate_reviews_due(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["mathstate", "reviews-due", "--course-id", "3"])
        self.assertEqual(args.command_path, ["mathstate", "reviews-due"])
        self.assertEqual(args.limit, 50)

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

    def test_transform_output_courses_list_applies_offset_pagination(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["courses", "list", "--limit", "1", "--offset", "1"])
        args = moodle_cli.apply_env_defaults(args)
        payload = {
            "ok": True,
            "data": {
                "courses": [
                    {"id": 1, "shortname": "c1", "fullname": "课程1"},
                    {"id": 2, "shortname": "c2", "fullname": "课程2"},
                    {"id": 3, "shortname": "c3", "fullname": "课程3"},
                ]
            },
        }
        transformed = moodle_cli.transform_output(payload, args)
        self.assertEqual(transformed["meta"]["count"], 1)
        self.assertEqual(transformed["meta"]["total_count"], 3)
        self.assertEqual(transformed["meta"]["offset"], 1)
        self.assertEqual(transformed["meta"]["limit"], 1)
        self.assertTrue(transformed["meta"]["has_more"])
        self.assertEqual(transformed["meta"]["next_offset"], 2)
        self.assertEqual(transformed["data"]["courses"][0]["id"], 2)
        self.assertEqual(len(transformed["data"]["items"]), 1)

    def test_transform_output_assignments_list_unbounded_default_limit(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["assignments", "list", "--course-id", "21"])
        args = moodle_cli.apply_env_defaults(args)
        payload = {
            "ok": True,
            "data": {
                "course": {"id": 21, "shortname": "c21", "fullname": "课程21"},
                "assignments": [
                    {"assignid": 401, "cmid": 301, "name": "作业A", "visible": True},
                    {"assignid": 402, "cmid": 302, "name": "作业B", "visible": True},
                ],
            },
        }
        transformed = moodle_cli.transform_output(payload, args)
        self.assertEqual(transformed["meta"]["count"], 2)
        self.assertEqual(transformed["meta"]["total_count"], 2)
        self.assertEqual(transformed["meta"]["limit"], 0)
        self.assertFalse(transformed["meta"]["has_more"])
        self.assertEqual(len(transformed["data"]["assignments"]), 2)

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
        self.assertEqual(transformed["meta"]["offset"], 0)
        self.assertEqual(transformed["meta"]["limit"], 20)
        self.assertEqual(transformed["meta"]["total_count"], 1)
        self.assertFalse(transformed["meta"]["has_more"])
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
        self.assertEqual(transformed["meta"]["offset"], 0)
        self.assertEqual(transformed["meta"]["limit"], 20)
        self.assertEqual(transformed["meta"]["total_count"], 1)
        self.assertFalse(transformed["meta"]["has_more"])
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
        self.assertEqual(transformed["meta"]["offset"], 0)
        self.assertEqual(transformed["meta"]["limit"], 50)
        self.assertEqual(transformed["meta"]["total_count"], 1)
        self.assertFalse(transformed["meta"]["has_more"])
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

    def test_emit_output_auth_login_no_wait_prints_verification_url(self) -> None:
        args = argparse.Namespace(
            command_path=["auth", "login"],
            json=False,
            plain=False,
            format="",
        )
        payload = {
            "ok": True,
            "profile": "dzexam",
            "device_code": "abc123",
            "user_code": "WXYZ-1234",
            "verification_url": "http://dzexam.cn/local/aiagentapi/device_verify.php?user_code=WXYZ-1234",
            "expires_in": 600,
            "interval": 5,
        }
        stdout = io.StringIO()
        with mock.patch("sys.stdout", stdout):
            moodle_cli.emit_output(payload, args)
        out = stdout.getvalue()
        self.assertIn("Open this URL to approve CLI login:", out)
        self.assertIn("device_verify.php?user_code=WXYZ-1234", out)
        self.assertIn("Resume command: moodle auth login --device-code abc123 --name dzexam", out)

    def test_emit_output_auth_login_success_prints_compact_status(self) -> None:
        args = argparse.Namespace(
            command_path=["auth", "login"],
            json=False,
            plain=False,
            format="",
        )
        payload = {
            "ok": True,
            "profile": {"name": "dzexam"},
            "user": {"username": "wonderhow"},
        }
        stdout = io.StringIO()
        with mock.patch("sys.stdout", stdout):
            moodle_cli.emit_output(payload, args)
        out = stdout.getvalue()
        self.assertIn("Login completed.", out)
        self.assertIn("Profile: dzexam", out)
        self.assertIn("User: wonderhow", out)

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
        self.assertIn("search", names)

    def test_search_schema_contains_global_and_course(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "search"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("global", names)
        self.assertIn("course", names)

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
        self.assertIn("get", names)
        self.assertIn("detail", names)

    def test_assignments_schema_contains_save_draft(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "assignments"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("get", names)
        self.assertIn("save-draft", names)
        self.assertIn("submit-final", names)

    def test_quiz_schema_contains_attempt_lifecycle(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "quiz"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("get", names)
        self.assertIn("start", names)
        self.assertIn("attempt-data", names)
        self.assertIn("attempt-summary", names)
        self.assertIn("save-attempt", names)
        self.assertIn("submit-attempt", names)
        self.assertIn("answer", names)

    def test_courses_schema_contains_get(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "courses"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("get", names)

    def test_resources_schema_contains_get(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["schema", "resources"])
        args = moodle_cli.apply_env_defaults(args)
        cli = moodle_cli.MoodleCLI(parser=parser, args=args)
        doc = moodle_cli.command_schema(cli, args)
        names = [item["name"] for item in doc["command"]["subcommands"]]
        self.assertIn("get", names)

    def test_resources_fetch_alias_maps_to_get_command_path(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["resources", "fetch", "--course-id", "21", "--cmid", "701"])
        args = moodle_cli.apply_env_defaults(args)
        self.assertEqual(args.command_path, ["resources", "get"])

    def test_command_search_course_aggregates_and_ranks_results(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args([
            "search", "course",
            "--course-id", "21",
            "--query", "作业",
            "--kind", "activity",
            "--kind", "assignment",
            "--limit", "10",
        ])
        args = moodle_cli.apply_env_defaults(args)

        responses = {
            (
                "local_aiagentapi_course_get_outline",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "sections": [],
                },
            },
            (
                "local_aiagentapi_activities_list_by_course",
                json.dumps({"courseid": 21, "modname": ""}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "activities": [
                        {
                            "cmid": 501,
                            "instance": 401,
                            "modname": "assign",
                            "name": "作业讲解",
                            "sectionnum": 1,
                            "visible": True,
                            "url": "http://example.test/mod/assign/view.php?id=501",
                        }
                    ],
                },
            },
            (
                "local_aiagentapi_assignments_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                        }
                    ],
                },
            },
        }
        result = moodle_cli.command_search_course(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertEqual(result["data"]["scope"], "course")
        self.assertEqual(result["meta"]["count"], 2)
        self.assertEqual(result["meta"]["total_count"], 2)
        self.assertEqual(result["meta"]["offset"], 0)
        self.assertEqual(result["meta"]["limit"], 10)
        self.assertFalse(result["meta"]["has_more"])
        self.assertIsNone(result["meta"]["next_offset"])
        self.assertEqual(result["data"]["results"][0]["title"], "作业A")
        self.assertIn("matched_fields", result["data"]["results"][0])

    def test_command_search_course_supports_offset_pagination(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args([
            "search", "course",
            "--course-id", "21",
            "--query", "作业",
            "--kind", "activity",
            "--kind", "assignment",
            "--limit", "1",
            "--offset", "1",
        ])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_course_get_outline",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "sections": [],
                },
            },
            (
                "local_aiagentapi_activities_list_by_course",
                json.dumps({"courseid": 21, "modname": ""}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "activities": [
                        {
                            "cmid": 501,
                            "instance": 401,
                            "modname": "assign",
                            "name": "作业讲解",
                            "sectionnum": 1,
                            "visible": True,
                            "url": "http://example.test/mod/assign/view.php?id=501",
                        }
                    ],
                },
            },
            (
                "local_aiagentapi_assignments_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                        }
                    ],
                },
            },
        }
        result = moodle_cli.command_search_course(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertEqual(result["meta"]["count"], 1)
        self.assertEqual(result["meta"]["total_count"], 2)
        self.assertEqual(result["meta"]["offset"], 1)
        self.assertEqual(result["meta"]["limit"], 1)
        self.assertFalse(result["meta"]["has_more"])
        self.assertIsNone(result["meta"]["next_offset"])
        self.assertEqual(result["data"]["results"][0]["title"], "作业讲解")

    def test_command_questions_search_applies_offset_window(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args([
            "questions", "search",
            "--query", "函数",
            "--limit", "1",
            "--offset", "1",
        ])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_questionbank_search",
                json.dumps(
                    {
                        "query": "函数",
                        "courseid": 0,
                        "categoryid": 0,
                        "recurse": False,
                        "qtypes": [],
                        "limit": 2,
                    },
                    sort_keys=True,
                    ensure_ascii=False,
                ),
            ): {
                "ok": True,
                "data": {
                    "questions": [
                        {"id": 101, "name": "函数定义", "qtype": "shortanswer"},
                        {"id": 102, "name": "函数单调性", "qtype": "multichoice"},
                    ]
                },
            },
        }
        result = moodle_cli.command_questions_search(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertEqual(len(result["data"]["questions"]), 1)
        self.assertEqual(result["data"]["questions"][0]["id"], 102)
        self.assertEqual(result["data"]["offset"], 1)
        self.assertEqual(result["data"]["limit"], 1)
        self.assertEqual(result["data"]["totalcount"], 2)
        self.assertFalse(result["data"]["hasmore"])

    def test_command_forum_discussions_applies_offset_window(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args([
            "forum", "discussions",
            "--course-id", "21",
            "--limit", "1",
            "--offset", "1",
        ])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_forum_discussions_list",
                json.dumps({"courseid": 21, "forumid": 0, "limit": 2}, sort_keys=True, ensure_ascii=False),
            ): {
                "ok": True,
                "data": {
                    "courseid": 21,
                    "forumid": 0,
                    "discussions": [
                        {"discussionid": 201, "postid": 301, "subject": "A"},
                        {"discussionid": 202, "postid": 302, "subject": "B"},
                    ],
                },
            },
        }
        result = moodle_cli.command_forum_discussions(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertEqual(len(result["data"]["discussions"]), 1)
        self.assertEqual(result["data"]["discussions"][0]["discussionid"], 202)
        self.assertEqual(result["data"]["offset"], 1)
        self.assertEqual(result["data"]["limit"], 1)
        self.assertEqual(result["data"]["totalcount"], 2)
        self.assertFalse(result["data"]["hasmore"])

    def test_command_notifications_list_prefers_offset_alias(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args([
            "notifications", "list",
            "--limit-from", "3",
            "--offset", "4",
            "--limit", "2",
        ])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_notifications_list_my",
                json.dumps({"limitfrom": 4, "limitnum": 2, "unreadonly": False}, sort_keys=True, ensure_ascii=False),
            ): {
                "ok": True,
                "data": {
                    "notifications": [
                        {"id": 901, "subject": "提醒"},
                    ]
                },
            },
        }
        result = moodle_cli.command_notifications_list(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertEqual(result["data"]["offset"], 4)
        self.assertEqual(result["data"]["limit"], 2)
        self.assertFalse(result["data"]["hasmore"])
        self.assertEqual(result["data"]["totalcount"], 5)

    def test_command_resources_get_returns_single_resource_envelope(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["resources", "get", "--course-id", "21", "--cmid", "701", "--no-content"])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_resources_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "resources": [
                        {
                            "cmid": 701,
                            "instance": 801,
                            "modname": "resource",
                            "name": "第一章讲义",
                            "sectionnum": 1,
                            "visible": True,
                            "url": "http://example.test/mod/resource/view.php?id=701",
                        }
                    ],
                },
            },
        }
        result = moodle_cli.command_resources_get(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertEqual(result["meta"]["count"], 1)
        self.assertEqual(result["data"]["resource"]["id"], 701)
        self.assertFalse(result["meta"]["content_included"])

    def test_command_resources_get_merges_detail_content(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["resources", "get", "--course-id", "21", "--cmid", "701"])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_resources_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "resources": [
                        {
                            "cmid": 701,
                            "instance": 801,
                            "modname": "resource",
                            "name": "第一章讲义",
                            "sectionnum": 1,
                            "visible": True,
                            "url": "http://example.test/mod/resource/view.php?id=701",
                        }
                    ],
                },
            },
            (
                "local_aiagentapi_course_activity_detail",
                json.dumps({"cmid": 701}, sort_keys=True, ensure_ascii=False),
            ): {
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
                        "cmid": 701,
                        "instance": 801,
                        "modname": "resource",
                        "name": "第一章讲义",
                        "sectionnum": 1,
                        "visible": True,
                        "url": "http://example.test/mod/resource/view.php?id=701",
                        "summaryhtml": "<p>章节总结</p>",
                        "contenthtml": "<div>详细内容</div>",
                    },
                },
            },
        }
        result = moodle_cli.command_resources_get(FakeCLI(responses), args)
        self.assertTrue(result["ok"])
        self.assertTrue(result["meta"]["content_included"])
        self.assertEqual(result["data"]["resource"]["content"]["summary_html"], "<p>章节总结</p>")
        self.assertEqual(result["data"]["resource"]["content"]["content_html"], "<div>详细内容</div>")

    def test_command_resources_get_no_content_skips_detail_fetch(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["resources", "get", "--course-id", "21", "--cmid", "701", "--no-content"])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_resources_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                    "resources": [
                        {
                            "cmid": 701,
                            "instance": 801,
                            "modname": "resource",
                            "name": "第一章讲义",
                            "sectionnum": 1,
                            "visible": True,
                            "url": "http://example.test/mod/resource/view.php?id=701",
                        }
                    ],
                },
            },
        }
        fake = FakeCLI(responses)
        result = moodle_cli.command_resources_get(fake, args)
        self.assertTrue(result["ok"])
        self.assertFalse(result["meta"]["content_included"])
        self.assertEqual(len(fake.calls), 1)

    def test_command_assignments_get_merges_status(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["assignments", "get", "--course-id", "21", "--assign-id", "401"])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_assignments_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
                        }
                    ],
                },
            },
            (
                "local_aiagentapi_assignments_my_status",
                json.dumps({"courseid": 21, "assignid": 401}, sort_keys=True, ensure_ascii=False),
            ): {
                "ok": True,
                "data": {
                    "courseid": 21,
                    "assignid": 401,
                    "assignments": [
                        {
                            "assignid": 401,
                            "cmid": 301,
                            "courseid": 21,
                            "courseshortname": "c21",
                            "name": "作业A",
                            "submissionstatus": "submitted",
                            "windowstatus": "open",
                            "isgraded": False,
                            "isoverdue": False,
                        }
                    ],
                },
            },
        }
        result = moodle_cli.command_assignments_get(FakeCLI(responses), args)
        self.assertEqual(result["data"]["assignment"]["submission_status"], "submitted")
        self.assertIn("status", result["data"]["assignment"])

    def test_command_quiz_get_merges_attempts(self) -> None:
        parser = moodle_cli.build_parser()
        args = parser.parse_args(["quiz", "get", "--course-id", "21", "--quiz-id", "901"])
        args = moodle_cli.apply_env_defaults(args)
        responses = {
            (
                "local_aiagentapi_quiz_list_by_course",
                json.dumps({"courseid": 21}, sort_keys=True, ensure_ascii=False),
            ): {
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
            },
            (
                "local_aiagentapi_quiz_attempts_my",
                json.dumps({"courseid": 21, "quizid": 901}, sort_keys=True, ensure_ascii=False),
            ): {
                "ok": True,
                "data": {
                    "courseid": 21,
                    "quizid": 901,
                    "quizzes": [
                        {
                            "courseid": 21,
                            "courseshortname": "c21",
                            "cmid": 902,
                            "quizid": 901,
                            "name": "阶段测验",
                            "attemptsmade": 1,
                            "attemptsleft": 2,
                            "hasunfinished": False,
                            "attempts": [
                                {
                                    "attemptid": 1001,
                                    "attempt": 1,
                                    "state": "finished",
                                }
                            ],
                        }
                    ],
                },
            },
        }
        result = moodle_cli.command_quiz_get(FakeCLI(responses), args)
        self.assertEqual(result["data"]["quiz"]["attempt_count"], 1)
        self.assertEqual(result["data"]["quiz"]["attempts"][0]["attempt_id"], 1001)

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
