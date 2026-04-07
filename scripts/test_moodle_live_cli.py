#!/usr/bin/env python3
"""
Live regression for the Moodle CLI + local_aiagentapi.

This script verifies the seeded demo workflow end-to-end:
- seed demo data
- call CLI read endpoints
- call question helpers
- call quiz random resolution
- dry-run and real calendar write
- confirm the written calendar event can be read back

Usage:
  python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed
"""

from __future__ import annotations

import argparse
import json
import os
import re
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, List, Optional


DEMO_COURSE_ID = 3
DEMO_COURSE_SHORTNAME = "AIAGENT_DEMO_101"
DEMO_CATEGORY_ID = 3
DEMO_ASSIGNMENT_ID = 1
DEMO_QUIZ_ID = 1
DEMO_QUESTION_IDS = [1, 2, 3]


def _eprint(*args: object) -> None:
    print(*args, file=sys.stderr)


def _strip_quotes(s: str) -> str:
    s = s.strip()
    if len(s) >= 2 and ((s[0] == s[-1] == '"') or (s[0] == s[-1] == "'")):
        return s[1:-1]
    return s


def load_env_file(path: str) -> Dict[str, str]:
    env: Dict[str, str] = {}
    if not path:
        return env
    if not os.path.exists(path):
        raise FileNotFoundError(f"Env file not found: {path}")

    line_re = re.compile(r"^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$")
    with open(path, "r", encoding="utf-8") as f:
        for raw in f:
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            m = line_re.match(raw)
            if not m:
                continue
            env[m.group(1)] = _strip_quotes(m.group(2))
    return env


def normalize_base_url(base_url: str) -> str:
    return base_url.rstrip("/")


def check_alive(base_url: str, timeout_s: float = 2.0) -> bool:
    try:
        with urllib.request.urlopen(f"{base_url}/", timeout=timeout_s) as resp:
            return 200 <= resp.status < 500
    except Exception:
        return False


def start_php_server(cwd: str, host: str, port: int, log_path: str) -> subprocess.Popen[bytes]:
    cmd = [
        "php",
        "-d",
        "max_input_vars=5000",
        "-d",
        "display_errors=0",
        "-d",
        "html_errors=0",
        "-d",
        "log_errors=1",
        "-d",
        f"error_log={log_path}",
        "-d",
        "error_reporting=E_ALL & ~E_DEPRECATED",
        "-S",
        f"{host}:{port}",
        "-t",
        "public",
    ]
    return subprocess.Popen(
        cmd,
        cwd=cwd,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        preexec_fn=os.setsid if hasattr(os, "setsid") else None,
    )


def stop_php_server(proc: subprocess.Popen[bytes]) -> None:
    if proc.poll() is not None:
        return
    try:
        if hasattr(os, "getpgid") and hasattr(os, "killpg"):
            os.killpg(os.getpgid(proc.pid), signal.SIGTERM)
        else:
            proc.terminate()
        try:
            proc.wait(timeout=3)
        except subprocess.TimeoutExpired:
            if hasattr(os, "getpgid") and hasattr(os, "killpg"):
                os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
            else:
                proc.kill()
    except Exception:
        pass


@dataclass
class CommandResult:
    code: int
    stdout: str
    stderr: str


def run_cmd(argv: List[str], cwd: str) -> CommandResult:
    proc = subprocess.run(argv, cwd=cwd, capture_output=True, text=True)
    return CommandResult(code=proc.returncode, stdout=proc.stdout, stderr=proc.stderr)


def expect_ok(result: CommandResult, label: str) -> Any:
    if result.code != 0:
        raise RuntimeError(f"{label}: command failed ({result.code}): {result.stderr.strip() or result.stdout.strip()}")
    try:
        payload = json.loads(result.stdout)
    except Exception as e:
        raise RuntimeError(f"{label}: invalid JSON output: {result.stdout[:300]}") from e
    if not isinstance(payload, dict) or payload.get("ok") is not True:
        raise RuntimeError(f"{label}: unexpected payload: {payload}")
    return payload


def cli_json(cwd: str, env_file: str, *args: str) -> Any:
    result = run_cmd(
        ["python3", "scripts/moodle_cli.py", "--env-file", env_file, "--json", *args],
        cwd,
    )
    return expect_ok(result, " ".join(args))


def ensure(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def find_choice_index(choices: List[Dict[str, Any]], wanted_text: str) -> int:
    for choice in choices:
        if choice.get("text") == wanted_text:
            return int(choice["choice_index"])
    raise RuntimeError(f"could not find choice index for text {wanted_text!r}")


def build_quiz_answers(questions: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    answers: List[Dict[str, Any]] = []
    for question in questions:
        slot = int(question["slot"])
        html = str(question.get("html", ""))
        schema = json.loads(question.get("responseschema") or "{}")
        choices = schema.get("choices", [])
        responsetype = str(schema.get("response_type", ""))

        if "What is 2 + 2?" in html:
            answers.append({
                "slot": slot,
                "choice_index": find_choice_index(choices, "Four"),
            })
            continue

        if "Which are the odd numbers?" in html:
            answers.append({
                "slot": slot,
                "choice_indexes": [
                    find_choice_index(choices, "One"),
                    find_choice_index(choices, "Three"),
                ],
            })
            continue

        if "Which number is even?" in html:
            answers.append({
                "slot": slot,
                "choice_index": find_choice_index(choices, "Two"),
            })
            continue

        if responsetype == "choice_single" and choices:
            answers.append({
                "slot": slot,
                "choice_index": int(choices[0]["choice_index"]),
            })
            continue

        if responsetype == "choice_multi" and choices:
            answers.append({
                "slot": slot,
                "choice_indexes": [int(choices[0]["choice_index"])],
            })
            continue

        raise RuntimeError(f"unsupported seeded question for slot {slot}")
    return answers


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--env-file", default=".env.local", help="Env file path (default: .env.local)")
    ap.add_argument("--base-url", default="", help="Override MOODLE_BASE_URL")
    ap.add_argument("--start-server", action="store_true", help="Start php -S if the local site is not reachable")
    ap.add_argument("--keep-server", action="store_true", help="Leave php -S running after the test")
    ap.add_argument("--seed", action="store_true", help="Run the seed script before regression checks")
    args = ap.parse_args()

    cwd = os.path.dirname(os.path.abspath(__file__)) + "/.."
    env_from_file = load_env_file(args.env_file) if args.env_file else {}
    base_url = args.base_url or env_from_file.get("MOODLE_BASE_URL") or os.environ.get("MOODLE_BASE_URL", "")
    if not base_url:
        _eprint("Missing base url. Set MOODLE_BASE_URL or pass --base-url.")
        return 2
    base_url = normalize_base_url(base_url)

    proc: Optional[subprocess.Popen[bytes]] = None
    if not check_alive(base_url):
        if not args.start_server:
            _eprint(f"Base URL not reachable: {base_url}. Re-run with --start-server.")
            return 3
        proc = start_php_server(cwd, "127.0.0.1", 8000, "/tmp/moodle-php-server.error.log")
        for _ in range(50):
            if check_alive(base_url, timeout_s=0.2):
                break
            time.sleep(0.1)
        else:
            stop_php_server(proc)
            _eprint("Failed to start php server.")
            return 3

    try:
        seed_summary: Dict[str, Any] = {}
        if args.seed:
            seed = run_cmd(["php", "scripts/seed_moodle_test_data.php"], cwd)
            if seed.code != 0:
                raise RuntimeError(f"seed failed: {seed.stderr.strip() or seed.stdout.strip()}")
            seed_summary = json.loads(seed.stdout)

        course_id = int(seed_summary.get("course", {}).get("id", DEMO_COURSE_ID))
        course_shortname = str(seed_summary.get("course", {}).get("shortname", DEMO_COURSE_SHORTNAME))
        category_id = int(seed_summary.get("question_category", {}).get("id", DEMO_CATEGORY_ID))
        assignment_id = int(seed_summary.get("assignment", {}).get("id", DEMO_ASSIGNMENT_ID))
        assignment_cmid = int(seed_summary.get("assignment", {}).get("cmid", 3))
        quiz_id = int(seed_summary.get("quiz_answering", {}).get("id",
                     seed_summary.get("quiz", {}).get("id", DEMO_QUIZ_ID)))
        question_ids = [int(qid) for qid in seed_summary.get("question_ids", DEMO_QUESTION_IDS)]
        forum_id = int(seed_summary.get("forum", {}).get("id", 0))
        forum_post_id = int(seed_summary.get("forum_discussion", {}).get("firstpost", 0))

        context = cli_json(cwd, args.env_file, "context", "get")
        ensure(context["data"]["user"]["username"] == "aiagent", "expected aiagent user")

        catalog = cli_json(cwd, args.env_file, "catalog", "get")
        endpoint_names = {item["name"] for item in catalog["data"]["endpoints"]}
        for name in [
            "local_aiagentapi_courses_list_my",
            "local_aiagentapi_activities_list_by_course",
            "local_aiagentapi_activities_due_list",
            "local_aiagentapi_assignments_list_by_course",
            "local_aiagentapi_assignments_my_status",
            "local_aiagentapi_quiz_attempts_my",
            "local_aiagentapi_quiz_start_attempt",
            "local_aiagentapi_quiz_get_attempt_data",
            "local_aiagentapi_quiz_get_attempt_summary",
            "local_aiagentapi_quiz_save_attempt",
            "local_aiagentapi_quiz_submit_attempt",
            "local_aiagentapi_quiz_answer_questions",
            "local_aiagentapi_grades_overview_my",
            "local_aiagentapi_course_progress_my",
            "local_aiagentapi_course_activity_detail",
            "local_aiagentapi_notifications_list_my",
            "local_aiagentapi_calendar_plan_upsert",
            "local_aiagentapi_assignment_save_draft",
            "local_aiagentapi_assignment_submit_final",
            "local_aiagentapi_forum_create_discussion",
            "local_aiagentapi_forum_reply_post",
            "local_aiagentapi_forum_update_post",
            "local_aiagentapi_forum_delete_post",
            "local_aiagentapi_questionbank_search",
            "local_aiagentapi_quiz_resolve_random",
        ]:
            ensure(name in endpoint_names, f"missing catalog endpoint: {name}")

        courses = cli_json(cwd, args.env_file, "courses", "list")
        ensure(
            any(course["id"] == course_id and course["shortname"] == course_shortname for course in courses["data"]["courses"]),
            "demo course missing from courses list",
        )

        activities = cli_json(cwd, args.env_file, "activities", "list", "--course-id", str(course_id))
        modnames = {item["modname"] for item in activities["data"]["activities"]}
        ensure({"qbank", "assign", "quiz", "page", "url", "forum"}.issubset(modnames), "expected seeded activity mix")

        assignments = cli_json(cwd, args.env_file, "assignments", "list", "--course-id", str(course_id))
        ensure(
            any(item["assignid"] == assignment_id for item in assignments["data"]["assignments"]),
            "demo assignment missing",
        )

        due = cli_json(cwd, args.env_file, "activities", "due", "--course-id", str(course_id), "--limit", "20")
        ensure(
            any(item["cmid"] == assignment_cmid and item["duetype"] == "due" for item in due["data"]["items"]),
            "expected assignment due item in activities due list",
        )

        assignment_status = cli_json(cwd, args.env_file, "assignments", "status", "--course-id", str(course_id))
        ensure(
            any(item["assignid"] == assignment_id for item in assignment_status["data"]["assignments"]),
            "demo assignment missing from assignment status",
        )

        quizzes = cli_json(cwd, args.env_file, "quiz", "list", "--course-id", str(course_id))
        ensure(
            any(item["quizid"] == quiz_id for item in quizzes["data"]["quizzes"]),
            "demo quiz missing",
        )

        attempts = cli_json(cwd, args.env_file, "quiz", "attempts", "--course-id", str(course_id))
        ensure(
            any(item["quizid"] == quiz_id for item in attempts["data"]["quizzes"]),
            "demo quiz missing from quiz attempts view",
        )

        quiz_start = cli_json(
            cwd,
            args.env_file,
            "--force",
            "quiz",
            "start",
            "--idempotency-key",
            f"quiz-start-{int(time.time())}",
            "--quiz-id",
            str(quiz_id),
            "--force-new",
            "--reason",
            "live-regression",
        )
        attempt_id = int(quiz_start["data"]["attempt"]["id"])
        ensure(attempt_id > 0, "quiz start did not return an attempt id")
        ensure(len(quiz_start["data"]["questions"]) >= 1, "quiz start should return first-page questions")

        attempt_data = cli_json(
            cwd,
            args.env_file,
            "quiz",
            "attempt-data",
            "--attempt-id",
            str(attempt_id),
            "--page",
            "0",
        )
        ensure(attempt_data["data"]["attempt"]["id"] == attempt_id, "attempt-data returned wrong attempt id")
        ensure(len(attempt_data["data"]["questions"]) >= 1, "attempt-data should return questions")

        attempt_summary = cli_json(
            cwd,
            args.env_file,
            "quiz",
            "attempt-summary",
            "--attempt-id",
            str(attempt_id),
        )
        ensure(attempt_summary["data"]["attemptid"] == attempt_id, "attempt-summary returned wrong attempt id")

        structured_answers = build_quiz_answers(quiz_start["data"]["questions"])
        ensure(structured_answers, "could not build quiz answers from responseschema")

        answer_argv = [
            "--force",
            "quiz",
            "answer",
            "--idempotency-key",
            f"quiz-answer-{int(time.time())}",
            "--attempt-id",
            str(attempt_id),
        ]
        for answer in structured_answers:
            answer_argv.extend(["--answer-json", json.dumps(answer)])
        answer_argv.extend(["--reason", "live-regression"])
        answer_attempt = cli_json(cwd, args.env_file, *answer_argv)
        ensure(answer_attempt["data"]["attempt"]["id"] == attempt_id, "quiz answer returned wrong attempt id")
        answered_states = {item["slot"]: item["state"] for item in answer_attempt["data"]["questions"]}
        for answer in structured_answers:
            ensure(answered_states.get(int(answer["slot"])) == "complete",
                   f"slot {int(answer['slot'])} should be complete after answering")

        submit_attempt = cli_json(
            cwd,
            args.env_file,
            "--force",
            "quiz",
            "submit-attempt",
            "--idempotency-key",
            f"quiz-submit-{int(time.time())}",
            "--attempt-id",
            str(attempt_id),
            "--reason",
            "live-regression",
        )
        ensure(submit_attempt["data"]["attempt"]["id"] == attempt_id, "quiz submit returned wrong attempt id")
        ensure(submit_attempt["data"]["state"] == "finished", "quiz submit should finish the attempt")

        grades = cli_json(cwd, args.env_file, "grades", "overview", "--course-id", str(course_id))
        ensure(len(grades["data"]["courses"]) == 1, "expected one course in grades overview")
        grade_modules = {item["itemmodule"] for item in grades["data"]["courses"][0]["items"]}
        ensure({"assign", "quiz"}.issubset(grade_modules), "expected assign and quiz grade items")

        progress = cli_json(cwd, args.env_file, "progress", "course", "--course-id", str(course_id))
        ensure(len(progress["data"]["courses"]) == 1, "expected one course in progress view")
        ensure(progress["data"]["courses"][0]["completionenabled"] is True, "expected completion enabled")
        ensure(progress["data"]["courses"][0]["totalcount"] >= 4, "expected tracked completion items")

        detail = cli_json(cwd, args.env_file, "activities", "detail", "--cmid", str(assignment_cmid))
        ensure(detail["data"]["activity"]["modname"] == "assign", "expected assignment detail for cmid 3")

        notifications = cli_json(cwd, args.env_file, "notifications", "list", "--limit", "10")
        ensure(isinstance(notifications["data"]["notifications"], list), "notifications should be a list")

        resources = cli_json(cwd, args.env_file, "resources", "list", "--course-id", str(course_id))
        ensure(len(resources["data"]["resources"]) >= 2, "expected seeded resources")

        discussions = cli_json(cwd, args.env_file, "forum", "discussions", "--course-id", str(course_id), "--limit", "20")
        ensure(len(discussions["data"]["discussions"]) >= 1, "expected seeded forum discussion")
        ensure(any(item["forumid"] == forum_id for item in discussions["data"]["discussions"]), "seeded forum discussion missing")

        categories = cli_json(cwd, args.env_file, "questions", "categories", "--course-id", str(course_id))
        ensure(
            any(item["id"] == category_id for item in categories["data"]["categories"]),
            "demo question category missing",
        )

        search = cli_json(cwd, args.env_file, "questions", "search", "--course-id", str(course_id), "--limit", "20")
        found_ids = [item["id"] for item in search["data"]["questions"]]
        ensure(all(qid in found_ids for qid in question_ids), "not all seeded questions are searchable")

        picked = cli_json(cwd, args.env_file, "questions", "pick-random", "--category-id", str(category_id), "--count", "2")
        ensure(picked["data"]["available"] >= 3, "expected at least 3 available questions")
        ensure(len(picked["data"]["picked"]) == 2, "expected 2 picked questions")

        rendered = cli_json(cwd, args.env_file, "questions", "render-html", "1", "2")
        ensure(len(rendered["data"]["questions"]) == 2, "expected 2 rendered questions")
        ensure(all(item["html"] and item["plain"] for item in rendered["data"]["questions"]), "render output should include html and plain")

        resolved = cli_json(cwd, args.env_file, "quiz", "resolve-random", "--quiz-id", str(quiz_id), "--copies", "1")
        copies = resolved["data"]["copies"]
        ensure(len(copies) == 1, "expected exactly one resolved copy")
        ensure(len(copies[0]["question_ids"]) >= 2, "expected quiz copy to include fixed + random question")

        draft_text = f"<p>Draft from live regression {now if 'now' in locals() else int(time.time())}</p>"
        draft = cli_json(
            cwd,
            args.env_file,
            "--force",
            "assignments",
            "save-draft",
            "--idempotency-key",
            f"assign-draft-{int(time.time())}",
            "--assign-id",
            str(assignment_id),
            "--text",
            draft_text,
            "--reason",
            "live-regression",
        )
        ensure(draft["data"]["assignment"]["id"] == assignment_id, "assignment draft write failed")
        ensure("Draft from live regression" in draft["data"]["submission"]["text"], "saved draft text missing")

        submit_final = cli_json(
            cwd,
            args.env_file,
            "--force",
            "assignments",
            "submit-final",
            "--idempotency-key",
            f"assign-submit-{int(time.time())}",
            "--assign-id",
            str(assignment_id),
            "--reason",
            "live-regression",
        )
        ensure(submit_final["data"]["submission"]["submittedforgrading"] is True, "assignment final submit failed")

        now = int(time.time())
        write_key = f"live-regression-{now}"
        event_name = f"Live Regression Event {now}"

        dryrun = cli_json(
            cwd,
            args.env_file,
            "--dry-run",
            "calendar",
            "publish-plan",
            "--idempotency-key",
            write_key + "-dryrun",
            "--reason",
            "live-regression",
            "--item-json",
            json.dumps(
                {
                    "name": event_name + " Preview",
                    "description": "preview event",
                    "timestart": now + 3600,
                    "timeduration": 1800,
                }
            ),
        )
        ensure(dryrun["dry_run"] is True, "expected dry-run response")

        written = cli_json(
            cwd,
            args.env_file,
            "--force",
            "calendar",
            "publish-plan",
            "--idempotency-key",
            write_key,
            "--reason",
            "live-regression",
            "--item-json",
            json.dumps(
                {
                    "name": event_name,
                    "description": "live regression write",
                    "timestart": now + 5400,
                    "timeduration": 1800,
                }
            ),
        )
        created = written["data"].get("created_events", [])
        ensure(len(created) == 1, "expected one calendar event to be created")

        cal = cli_json(
            cwd,
            args.env_file,
            "calendar",
            "list",
            "--timestart",
            str(now),
            "--timeend",
            str(now + 86400),
            "--limit",
            "100",
        )
        ensure(
            any(event["name"] == event_name for event in cal["data"]["events"]),
            "created calendar event not found in calendar list",
        )

        upsert_key = f"live-upsert-{now}"
        upsert_name = f"Live Upsert Event {now}"
        upsert_preview = cli_json(
            cwd,
            args.env_file,
            "--dry-run",
            "calendar",
            "upsert-plan",
            "--idempotency-key",
            upsert_key + "-preview",
            "--plan-key",
            upsert_key,
            "--reason",
            "live-regression",
            "--item-json",
            json.dumps(
                {
                    "item_key": "study-1",
                    "name": upsert_name,
                    "description": "upsert preview",
                    "timestart": now + 7200,
                    "timeduration": 1500,
                }
            ),
        )
        ensure(upsert_preview["dry_run"] is True, "expected upsert dry-run response")
        ensure(upsert_preview["data"]["preview_actions"][0]["action"] == "create", "expected create preview action")

        upsert_write = cli_json(
            cwd,
            args.env_file,
            "--force",
            "calendar",
            "upsert-plan",
            "--idempotency-key",
            upsert_key,
            "--plan-key",
            upsert_key,
            "--reason",
            "live-regression",
            "--item-json",
            json.dumps(
                {
                    "item_key": "study-1",
                    "name": upsert_name,
                    "description": "upsert write",
                    "timestart": now + 7200,
                    "timeduration": 1500,
                }
            ),
        )
        ensure(len(upsert_write["data"]["created_events"]) == 1, "expected one created upsert event")

        forum_subject = f"Live Discussion {now}"
        forum_create = cli_json(
            cwd,
            args.env_file,
            "--force",
            "forum",
            "create-discussion",
            "--idempotency-key",
            f"forum-create-{now}",
            "--forum-id",
            str(forum_id),
            "--subject",
            forum_subject,
            "--message",
            "<p>Live forum create discussion test.</p>",
            "--reason",
            "live-regression",
        )
        ensure(forum_create["data"]["discussion"]["subject"] == forum_subject, "forum discussion create failed")
        created_discussion_post_id = int(forum_create["data"]["discussion"]["postid"])

        forum_reply = cli_json(
            cwd,
            args.env_file,
            "--force",
            "forum",
            "reply",
            "--idempotency-key",
            f"forum-reply-{now}",
            "--post-id",
            str(forum_post_id),
            "--subject",
            f"Re: seeded discussion {now}",
            "--message",
            "<p>Live forum reply test.</p>",
            "--reason",
            "live-regression",
        )
        ensure(forum_reply["data"]["reply"]["postid"] > 0, "forum reply failed")

        forum_update = cli_json(
            cwd,
            args.env_file,
            "--force",
            "forum",
            "update-post",
            "--idempotency-key",
            f"forum-update-{now}",
            "--post-id",
            str(created_discussion_post_id),
            "--subject",
            f"{forum_subject} Updated",
            "--message",
            "<p>Updated by live regression.</p>",
            "--reason",
            "live-regression",
        )
        ensure(forum_update["data"]["post"]["subject"].endswith("Updated"), "forum update failed")

        forum_delete = cli_json(
            cwd,
            args.env_file,
            "--force",
            "forum",
            "delete-post",
            "--idempotency-key",
            f"forum-delete-{now}",
            "--post-id",
            str(created_discussion_post_id),
            "--reason",
            "live-regression",
        )
        ensure(forum_delete["data"]["target"]["kind"] == "discussion", "forum delete did not target created discussion")

        summary = {
            "context_user": context["data"]["user"]["username"],
            "course_shortname": course_shortname,
            "activity_count": len(activities["data"]["activities"]),
            "due_count": len(due["data"]["items"]),
            "assignment_status_count": len(assignment_status["data"]["assignments"]),
            "quiz_attempt_view_count": len(attempts["data"]["quizzes"]),
            "quiz_started_attempt_id": attempt_id,
            "quiz_start_question_count": len(quiz_start["data"]["questions"]),
            "quiz_summary_unanswered": attempt_summary["data"]["totalunanswered"],
            "quiz_answered_state_count": sum(1 for item in answer_attempt["data"]["questions"] if item["state"] == "complete"),
            "quiz_submitted_state": submit_attempt["data"]["state"],
            "grade_item_count": len(grades["data"]["courses"][0]["items"]),
            "resource_count": len(resources["data"]["resources"]),
            "forum_discussion_count": len(discussions["data"]["discussions"]),
            "search_count": len(search["data"]["questions"]),
            "picked_count": len(picked["data"]["picked"]),
            "resolved_copy_question_count": len(copies[0]["question_ids"]),
            "calendar_event_name": event_name,
            "upsert_event_name": upsert_name,
            "forum_created_subject": forum_subject,
            "assignment_final_status": submit_final["data"]["submission"]["status"],
        }
        print(json.dumps({"ok": True, "summary": summary}, ensure_ascii=False, indent=2))
        return 0
    except Exception as e:
        _eprint(str(e))
        return 1
    finally:
        if proc and not args.keep_server:
            stop_php_server(proc)


if __name__ == "__main__":
    raise SystemExit(main())
