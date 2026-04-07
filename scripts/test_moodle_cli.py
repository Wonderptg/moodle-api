#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
import unittest

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


if __name__ == "__main__":
    unittest.main()
