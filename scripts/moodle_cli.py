#!/usr/bin/env python3
"""
Agent-friendly Moodle CLI wrapper for local_aiagentapi.

Design goals:
- strong root contract
- strict machine-readable mode
- stable exit codes
- thin wrapper over local_aiagentapi
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

VERSION = "0.8.0"

EXIT_OK = 0
EXIT_ERROR = 1
EXIT_USAGE = 2
EXIT_EMPTY_RESULTS = 3
EXIT_AUTH_REQUIRED = 4
EXIT_NOT_FOUND = 5
EXIT_PERMISSION_DENIED = 6
EXIT_RATE_LIMITED = 7
EXIT_RETRYABLE = 8
EXIT_CONFIG = 10
EXIT_CANCELLED = 130

ENVELOPE_META_KEYS = {
    "ok",
    "audit_id",
    "replayed",
    "dry_run",
    "error",
    "nextPageToken",
    "next_cursor",
    "has_more",
    "count",
    "query",
    "note",
    "notes",
}


class CliError(Exception):
    def __init__(self, message: str, exit_code: int = EXIT_ERROR) -> None:
        super().__init__(message)
        self.exit_code = exit_code


def _eprint(*args: object) -> None:
    print(*args, file=sys.stderr)


def env_bool(name: str) -> bool:
    return os.environ.get(name, "").strip().lower() in {"1", "true", "yes", "y", "on"}


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
        return env

    line_re = re.compile(r"^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)\s*$")
    with open(path, "r", encoding="utf-8") as f:
        for raw in f:
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            m = line_re.match(raw)
            if not m:
                continue
            key = m.group(1)
            val = _strip_quotes(m.group(2).strip())
            env[key] = val
    return env


def normalize_base_url(base_url: str) -> str:
    return base_url.rstrip("/")


def rewrite_desire_args(argv: Sequence[str]) -> List[str]:
    out: List[str] = []
    for arg in argv:
        if arg == "--fields":
            out.append("--select")
        elif arg.startswith("--fields="):
            out.append("--select=" + arg.split("=", 1)[1])
        else:
            out.append(arg)
    return out


def flatten_params(prefix: str, value: Any, out: Dict[str, str]) -> None:
    if isinstance(value, dict):
        for key, item in value.items():
            flatten_params(f"{prefix}[{key}]", item, out)
        return
    if isinstance(value, list):
        for index, item in enumerate(value):
            flatten_params(f"{prefix}[{index}]", item, out)
        return
    if isinstance(value, bool):
        out[prefix] = "1" if value else "0"
        return
    if value is None:
        out[prefix] = ""
        return
    out[prefix] = str(value)


def build_payload(wsfunction: str, token: str, params: Dict[str, Any]) -> Dict[str, str]:
    payload: Dict[str, str] = {
        "wstoken": token,
        "wsfunction": wsfunction,
        "moodlewsrestformat": "json",
    }
    for key, value in params.items():
        flatten_params(key, value, payload)
    return payload


def invoke_ws(base_url: str, token: str, wsfunction: str, params: Dict[str, Any], timeout: float) -> Any:
    endpoint = f"{base_url}/webservice/rest/server.php"
    data = urllib.parse.urlencode(build_payload(wsfunction, token, params)).encode("utf-8")
    req = urllib.request.Request(endpoint, method="POST", data=data)
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read()
            status = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        status = e.code
    except urllib.error.URLError as e:
        raise CliError(f"request failed: {e.reason}", EXIT_RETRYABLE) from e
    except Exception as e:
        raise CliError(f"request failed: {e}", EXIT_RETRYABLE) from e

    try:
        decoded = json.loads(raw.decode("utf-8", errors="strict"))
    except Exception as e:
        sample = raw[:300].decode("utf-8", errors="replace")
        raise CliError(f"expected JSON response but got: {sample}", EXIT_RETRYABLE) from e

    handle_moodle_errors(decoded, status)
    return decoded


def handle_moodle_errors(decoded: Any, status: int) -> None:
    if not isinstance(decoded, dict):
        return

    if decoded.get("exception"):
        code = str(decoded.get("errorcode") or decoded.get("exception") or "").lower()
        message = str(decoded.get("message") or decoded.get("debuginfo") or decoded)
        if code in {"invalidtoken", "accessexception", "webservice_access_exception"}:
            raise CliError(message, EXIT_AUTH_REQUIRED)
        if code in {"required_capability_exception", "nopermissions"}:
            raise CliError(message, EXIT_PERMISSION_DENIED)
        if code in {"invalidparameter", "invalid_response_exception"}:
            raise CliError(message, EXIT_USAGE)
        raise CliError(message, EXIT_ERROR if status < 500 else EXIT_RETRYABLE)

    if decoded.get("ok") is False and isinstance(decoded.get("error"), dict):
        message = str(decoded["error"].get("message") or "request failed")
        errcode = str(decoded["error"].get("code") or "").lower()
        if errcode in {"missing_questions"}:
            raise CliError(message, EXIT_NOT_FOUND)
        if errcode in {"invalid_count", "too_many_questions", "invalid_copies", "too_many_items", "idempotency_key_reuse"}:
            raise CliError(message, EXIT_USAGE)
        raise CliError(message, EXIT_ERROR)


def unwrap_primary(value: Any) -> Any:
    if not isinstance(value, dict):
        return value

    if "data" in value and set(value.keys()).issuperset({"ok", "audit_id", "replayed", "dry_run", "data"}):
        value = value["data"]
        if not isinstance(value, dict):
            return value

    if "results" in value:
        return value["results"]

    candidates = [k for k in value.keys() if k not in ENVELOPE_META_KEYS]
    if len(candidates) == 1:
        return value[candidates[0]]
    for key in candidates:
        if isinstance(value.get(key), list):
            return value[key]
    return value


def select_fields(value: Any, fields: Sequence[str]) -> Any:
    if not fields:
        return value
    if isinstance(value, list):
        return [select_fields_from_item(item, fields) for item in value]
    return select_fields_from_item(value, fields)


def select_fields_from_item(value: Any, fields: Sequence[str]) -> Any:
    if not isinstance(value, dict):
        return value
    out: Dict[str, Any] = {}
    for field in fields:
        resolved, ok = get_at_path(value, field)
        if ok:
            out[field] = resolved
    return out


def get_at_path(value: Any, path: str) -> Tuple[Any, bool]:
    cur = value
    for segment in path.split("."):
        segment = segment.strip()
        if not segment:
            return None, False
        if isinstance(cur, dict):
            if segment not in cur:
                return None, False
            cur = cur[segment]
            continue
        if isinstance(cur, list):
            try:
                index = int(segment)
            except ValueError:
                return None, False
            if index < 0 or index >= len(cur):
                return None, False
            cur = cur[index]
            continue
        return None, False
    return cur, True


def is_scalar(value: Any) -> bool:
    return value is None or isinstance(value, (str, int, float, bool))


def emit_plain(value: Any) -> None:
    if isinstance(value, dict) and all(is_scalar(v) for v in value.values()):
        for key, item in value.items():
            print(f"{key}\t{scalar_to_text(item)}")
        return

    if isinstance(value, list) and value and all(isinstance(item, dict) for item in value):
        common_keys = [
            key for key in value[0].keys()
            if all(isinstance(item, dict) and key in item and is_scalar(item[key]) for item in value)
        ]
        if common_keys:
            print("\t".join(common_keys))
            for item in value:
                print("\t".join(scalar_to_text(item[key]) for key in common_keys))
            return

    print(json.dumps(value, ensure_ascii=False, separators=(",", ":")))


def scalar_to_text(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, bool):
        return "true" if value else "false"
    return str(value)


def transform_output(value: Any, args: argparse.Namespace) -> Any:
    if args.results_only:
        value = unwrap_primary(value)
    if args.select:
        fields = [item.strip() for item in args.select.split(",") if item.strip()]
        value = select_fields(value, fields)
    return value


def emit_output(value: Any, args: argparse.Namespace) -> None:
    if args.json:
        print(json.dumps(value, ensure_ascii=False, indent=2))
        return
    if args.plain:
        emit_plain(value)
        return
    if isinstance(value, str):
        print(value)
    else:
        print(json.dumps(value, ensure_ascii=False, indent=2))


def confirm_write(args: argparse.Namespace, action: str) -> None:
    if getattr(args, "dry_run", False):
        return
    if getattr(args, "force", False):
        return
    if getattr(args, "no_input", False) or not sys.stdin.isatty():
        raise CliError(f'refusing to {action} without --force (non-interactive)', EXIT_USAGE)
    answer = input(f"Proceed to {action}? [y/N]: ").strip().lower()
    if answer not in {"y", "yes"}:
        raise CliError("cancelled", EXIT_CANCELLED)


def load_json_file(path: str) -> Any:
    if path == "-":
        return json.load(sys.stdin)
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def resolve_plan_items(args: argparse.Namespace) -> List[Dict[str, Any]]:
    items: List[Dict[str, Any]] = []
    if args.input:
        loaded = load_json_file(args.input)
        if isinstance(loaded, dict) and isinstance(loaded.get("items"), list):
            loaded = loaded["items"]
        if not isinstance(loaded, list):
            raise CliError("--input must contain a JSON array or an object with an 'items' array", EXIT_USAGE)
        items.extend(loaded)
    for raw in args.item_json or []:
        try:
            item = json.loads(raw)
        except json.JSONDecodeError as e:
            raise CliError(f"invalid --item-json payload: {e}", EXIT_USAGE) from e
        if not isinstance(item, dict):
            raise CliError("--item-json must decode to an object", EXIT_USAGE)
        items.append(item)
    if not items:
        raise CliError("publish-plan requires --input or at least one --item-json", EXIT_USAGE)
    return items


def resolve_batch_items(args: argparse.Namespace, *, label: str) -> List[Dict[str, Any]]:
    items: List[Dict[str, Any]] = []
    if getattr(args, "input", ""):
        loaded = load_json_file(args.input)
        if isinstance(loaded, dict) and isinstance(loaded.get("items"), list):
            loaded = loaded["items"]
        if not isinstance(loaded, list):
            raise CliError("--input must contain a JSON array or an object with an 'items' array", EXIT_USAGE)
        for item in loaded:
            if not isinstance(item, dict):
                raise CliError(f"{label} input items must be objects", EXIT_USAGE)
            items.append(item)
    for raw in getattr(args, "item_json", []) or []:
        try:
            item = json.loads(raw)
        except json.JSONDecodeError as e:
            raise CliError(f"invalid --item-json payload: {e}", EXIT_USAGE) from e
        if not isinstance(item, dict):
            raise CliError("--item-json must decode to an object", EXIT_USAGE)
        items.append(item)
    if not items:
        raise CliError(f"{label} requires --input or at least one --item-json", EXIT_USAGE)
    return items


def _pair_from_object(item: Any, flag_name: str) -> Dict[str, Any]:
    if not isinstance(item, dict):
        raise CliError(f"{flag_name} must decode to an object", EXIT_USAGE)
    if "name" in item and "value" in item:
        return {"name": str(item["name"]), "value": item["value"]}
    if len(item) == 1:
        key = next(iter(item.keys()))
        return {"name": str(key), "value": item[key]}
    raise CliError(f"{flag_name} must be an object with name/value or a single key", EXIT_USAGE)


def resolve_name_value_pairs(raw_items: Sequence[str], flag_name: str) -> List[Dict[str, Any]]:
    pairs: List[Dict[str, Any]] = []
    for raw in raw_items or []:
        try:
            item = json.loads(raw)
        except json.JSONDecodeError as e:
            raise CliError(f"invalid {flag_name} payload: {e}", EXIT_USAGE) from e
        pairs.append(_pair_from_object(item, flag_name))
    return pairs


def resolve_json_objects(raw_items: Sequence[str], flag_name: str) -> List[Dict[str, Any]]:
    items: List[Dict[str, Any]] = []
    for raw in raw_items or []:
        try:
            item = json.loads(raw)
        except json.JSONDecodeError as e:
            raise CliError(f"invalid {flag_name} payload: {e}", EXIT_USAGE) from e
        if not isinstance(item, dict):
            raise CliError(f"{flag_name} must decode to an object", EXIT_USAGE)
        items.append(item)
    return items


def command_context_get(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_get_user_context", {})


def command_catalog_get(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_get_api_catalog", {})


def command_courses_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_courses_list_my", {})


def command_courses_outline(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_course_get_outline", {
        "courseid": args.course_id,
    })


def command_activities_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_activities_list_by_course", {
        "courseid": args.course_id,
        "modname": args.modname,
    })


def command_activities_due(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_activities_due_list", {
        "courseid": args.course_id,
        "timestart": args.timestart,
        "timeend": args.timeend,
        "limit": args.limit,
    })


def command_activities_detail(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_course_activity_detail", {
        "cmid": args.cmid,
    })


def command_assignments_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_assignments_list_by_course", {
        "courseid": args.course_id,
    })


def command_assignments_status(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_assignments_my_status", {
        "courseid": args.course_id,
        "assignid": args.assign_id,
    })


def command_assignments_save_draft(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "save assignment draft")
    return cli.call("local_aiagentapi_assignment_save_draft", {
        "idempotency_key": args.idempotency_key,
        "assignid": args.assign_id,
        "text": args.text,
        "format": args.format,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_assignments_submit_final(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "submit assignment for grading")
    return cli.call("local_aiagentapi_assignment_submit_final", {
        "idempotency_key": args.idempotency_key,
        "assignid": args.assign_id,
        "accept_submission_statement": args.accept_submission_statement,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_calendar_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_calendar_list", {
        "timestart": args.timestart,
        "timeend": args.timeend,
        "limit": args.limit,
    })


def command_questions_categories(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_question_categories_list", {
        "courseid": args.course_id,
        "contextid": args.context_id,
        "parentid": args.parent_id,
    })


def command_questions_search(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_questionbank_search", {
        "query": args.query,
        "courseid": args.course_id,
        "categoryid": args.category_id,
        "recurse": args.recurse,
        "qtypes": args.qtype or [],
        "limit": args.limit,
    })


def command_questions_pick_random(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_questionbank_pick_random", {
        "categoryid": args.category_id,
        "count": args.count,
        "recurse": args.recurse,
        "seed": args.seed,
        "qtypes": args.qtype or [],
    })


def command_questions_render_html(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_questions_render_html", {
        "question_ids": args.question_ids,
        "shuffle_answers": args.shuffle_answers,
        "seed": args.seed,
        "show_correction": args.show_correction,
    })


def command_quiz_resolve_random(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_quiz_resolve_random", {
        "quizid": args.quiz_id,
        "copies": args.copies,
        "seed": args.seed,
        "allowduplicates": args.allow_duplicates,
    })


def command_quiz_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_quiz_list_by_course", {
        "courseid": args.course_id,
    })


def command_quiz_attempts(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_quiz_attempts_my", {
        "courseid": args.course_id,
        "quizid": args.quiz_id,
    })


def command_quiz_start(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "start quiz attempt")
    return cli.call("local_aiagentapi_quiz_start_attempt", {
        "idempotency_key": args.idempotency_key,
        "quizid": args.quiz_id,
        "preflightdata": resolve_name_value_pairs(args.preflight_json or [], "--preflight-json"),
        "forcenew": args.force_new,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_quiz_attempt_data(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_quiz_get_attempt_data", {
        "attemptid": args.attempt_id,
        "page": args.page,
        "preflightdata": resolve_name_value_pairs(args.preflight_json or [], "--preflight-json"),
    })


def command_quiz_attempt_summary(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_quiz_get_attempt_summary", {
        "attemptid": args.attempt_id,
        "preflightdata": resolve_name_value_pairs(args.preflight_json or [], "--preflight-json"),
    })


def command_quiz_save_attempt(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "save quiz attempt")
    return cli.call("local_aiagentapi_quiz_save_attempt", {
        "idempotency_key": args.idempotency_key,
        "attemptid": args.attempt_id,
        "responses": resolve_name_value_pairs(args.response_json or [], "--response-json"),
        "preflightdata": resolve_name_value_pairs(args.preflight_json or [], "--preflight-json"),
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_quiz_submit_attempt(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "submit quiz attempt")
    return cli.call("local_aiagentapi_quiz_submit_attempt", {
        "idempotency_key": args.idempotency_key,
        "attemptid": args.attempt_id,
        "responses": resolve_name_value_pairs(args.response_json or [], "--response-json"),
        "timeup": args.timeup,
        "preflightdata": resolve_name_value_pairs(args.preflight_json or [], "--preflight-json"),
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def fetch_quiz_attempt_questions(cli: "MoodleCLI", attempt_id: int, preflightdata: List[Dict[str, Any]]) -> Dict[int, Dict[str, Any]]:
    questions: Dict[int, Dict[str, Any]] = {}
    seen_pages = set()
    page = 0
    while page >= 0 and page not in seen_pages:
        seen_pages.add(page)
        payload = cli.call("local_aiagentapi_quiz_get_attempt_data", {
            "attemptid": attempt_id,
            "page": page,
            "preflightdata": preflightdata,
        })
        for question in payload["data"]["questions"]:
            questions[int(question["slot"])] = question
        nextpage = int(payload["data"].get("nextpage", -1))
        if nextpage < 0:
            break
        page = nextpage
    return questions


def command_quiz_answer(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "apply quiz answers")
    answers = resolve_json_objects(args.answer_json or [], "--answer-json")
    if not answers:
        raise CliError("quiz answer requires at least one --answer-json", EXIT_USAGE)
    preflightdata = resolve_name_value_pairs(args.preflight_json or [], "--preflight-json")
    normalized_answers: List[Dict[str, Any]] = []
    for answer in answers:
        normalized: Dict[str, Any] = {
            "slot": int(answer["slot"]),
            "choice_index": int(answer.get("choice_index", 999999)),
            "choice_indexes_csv": "999999",
            "flagged": bool(answer.get("flagged", False)),
        }
        if isinstance(answer.get("choice_indexes"), list):
            normalized["choice_indexes_csv"] = ",".join(str(int(item)) for item in answer["choice_indexes"])
        elif "choice_indexes_csv" in answer:
            normalized["choice_indexes_csv"] = str(answer["choice_indexes_csv"])
        normalized_answers.append(normalized)
    questions_by_slot = fetch_quiz_attempt_questions(cli, args.attempt_id, preflightdata)
    slot_list = [str(int(answer["slot"])) for answer in normalized_answers]
    rawresponses: List[Dict[str, str]] = [{"name": "slots", "value": ",".join(slot_list)}]
    applied_answers: List[Dict[str, Any]] = []

    for answer in normalized_answers:
        slot = int(answer["slot"])
        question = questions_by_slot.get(slot)
        if not question:
            raise CliError(f"slot {slot} not found in attempt data", EXIT_USAGE)
        try:
            schema = json.loads(question.get("responseschema") or "{}")
        except json.JSONDecodeError as e:
            raise CliError(f"invalid responseschema for slot {slot}: {e}", EXIT_ERROR) from e
        response_type = str(schema.get("response_type") or "")
        rawresponses.append({
            "name": str(schema.get("sequencecheck_field") or ""),
            "value": str(int(question.get("sequencecheck", 0))),
        })
        if answer.get("flagged"):
            rawresponses.append({
                "name": str(schema.get("flag_field") or ""),
                "value": "1",
            })
        if response_type == "choice_single":
            rawresponses.append({
                "name": str(schema.get("answer_field") or ""),
                "value": str(int(answer["choice_index"])),
            })
            applied_answers.append({
                "slot": slot,
                "response_type": response_type,
                "choice_index": int(answer["choice_index"]),
                "choice_indexes": [],
                "flagged": bool(answer.get("flagged", False)),
            })
            continue
        if response_type == "choice_multi":
            selected = {
                int(item) for item in str(answer.get("choice_indexes_csv", "")).split(",")
                if item.strip() and item.strip().isdigit()
            }
            choice_fields = schema.get("choice_fields") or {}
            if isinstance(choice_fields, list):
                choice_field_items = list(enumerate(choice_fields))
            else:
                choice_field_items = list(choice_fields.items())
            for key, field_name in choice_field_items:
                choice_index = int(key)
                rawresponses.append({
                    "name": str(field_name),
                    "value": "1" if choice_index in selected else "0",
                })
            applied_answers.append({
                "slot": slot,
                "response_type": response_type,
                "choice_index": 999999,
                "choice_indexes": sorted(selected),
                "flagged": bool(answer.get("flagged", False)),
            })
            continue
        raise CliError(f"slot {slot} uses unsupported response type {response_type!r}", EXIT_USAGE)

    if args.dry_run:
        return {
            "ok": True,
            "audit_id": "local-cli-dry-run",
            "replayed": False,
            "dry_run": True,
            "data": {
                "attempt": next(iter(questions_by_slot.values()), {}).get("attempt", {"id": args.attempt_id}),
                "state": "inprogress",
                "applied_answers": applied_answers,
                "low_level_responses": rawresponses,
                "questions": list(questions_by_slot.values()),
                "warnings": [],
            },
        }

    wsfunction = "local_aiagentapi_quiz_submit_attempt" if args.submit else "local_aiagentapi_quiz_save_attempt"
    params: Dict[str, Any] = {
        "idempotency_key": args.idempotency_key,
        "attemptid": args.attempt_id,
        "responses": rawresponses,
        "preflightdata": preflightdata,
        "dry_run": False,
        "reason": args.reason,
    }
    if args.submit:
        params["timeup"] = args.timeup
    result = cli.call(wsfunction, params)
    summary = cli.call("local_aiagentapi_quiz_get_attempt_summary", {
        "attemptid": args.attempt_id,
        "preflightdata": preflightdata,
    })
    attempt = result["data"]["attempt"]
    state = result["data"].get("state", attempt.get("state", ""))
    warnings = list(result["data"].get("warnings", [])) + list(summary["data"].get("warnings", []))
    result["data"] = {
        "attempt": attempt,
        "state": state,
        "applied_answers": applied_answers,
        "low_level_responses": rawresponses,
        "questions": summary["data"]["questions"],
        "warnings": warnings,
    }
    return result


def command_calendar_publish_plan(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "publish calendar plan")
    return cli.call("local_aiagentapi_calendar_publish_plan", {
        "idempotency_key": args.idempotency_key,
        "dry_run": args.dry_run,
        "reason": args.reason,
        "items": resolve_plan_items(args),
    })


def command_calendar_upsert_plan(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "upsert calendar plan")
    return cli.call("local_aiagentapi_calendar_plan_upsert", {
        "idempotency_key": args.idempotency_key,
        "plan_key": args.plan_key,
        "dry_run": args.dry_run,
        "reason": args.reason,
        "items": resolve_plan_items(args),
    })


def command_resources_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_resources_list_by_course", {
        "courseid": args.course_id,
    })


def command_forum_discussions(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_forum_discussions_list", {
        "courseid": args.course_id,
        "forumid": args.forum_id,
        "limit": args.limit,
    })


def command_forum_create_discussion(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "create forum discussion")
    return cli.call("local_aiagentapi_forum_create_discussion", {
        "idempotency_key": args.idempotency_key,
        "forumid": args.forum_id,
        "subject": args.subject,
        "message": args.message,
        "groupid": args.group_id,
        "subscribe": args.subscribe,
        "pinned": args.pinned,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_forum_reply(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "reply to forum post")
    return cli.call("local_aiagentapi_forum_reply_post", {
        "idempotency_key": args.idempotency_key,
        "postid": args.post_id,
        "subject": args.subject,
        "message": args.message,
        "subscribe": args.subscribe,
        "private_reply": args.private_reply,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_forum_update_post(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "update forum post")
    return cli.call("local_aiagentapi_forum_update_post", {
        "idempotency_key": args.idempotency_key,
        "postid": args.post_id,
        "subject": args.subject,
        "message": args.message,
        "subscribe": args.subscribe,
        "pinned": args.pinned,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_forum_delete_post(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    confirm_write(args, "delete forum post")
    return cli.call("local_aiagentapi_forum_delete_post", {
        "idempotency_key": args.idempotency_key,
        "postid": args.post_id,
        "dry_run": args.dry_run,
        "reason": args.reason,
    })


def command_notifications_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_notifications_list_my", {
        "limitfrom": args.limit_from,
        "limitnum": args.limit,
        "unreadonly": args.unread_only,
    })


def command_grades_overview(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_grades_overview_my", {
        "courseid": args.course_id,
    })


def command_progress_course(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_aiagentapi_course_progress_my", {
        "courseid": args.course_id,
    })


def command_mathstate_kp_upsert(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_std_kp_upsert_batch", {
        "items": resolve_batch_items(args, label="mathstate kp-upsert"),
    })


def command_mathstate_qtype_upsert(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_std_qtype_upsert_batch", {
        "items": resolve_batch_items(args, label="mathstate qtype-upsert"),
    })


def command_mathstate_question_map_upsert(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_question_map_upsert_batch", {
        "items": resolve_batch_items(args, label="mathstate question-map-upsert"),
    })


def command_mathstate_evidence_ingest(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_evidence_ingest_batch", {
        "items": resolve_batch_items(args, label="mathstate evidence-ingest"),
    })


def command_mathstate_student_summary(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_student_summary", {
        "courseid": args.course_id,
        "userid": args.user_id,
        "include_kp_states": args.include_kp_states,
        "include_qtype_states": args.include_qtype_states,
        "include_due_tasks": args.include_due_tasks,
    })


def command_mathstate_reviews_due(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_reviews_due", {
        "courseid": args.course_id,
        "userid": args.user_id,
        "limit": args.limit,
        "due_before": args.due_before,
    })


def command_exit_codes(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return {
        "exit_codes": {
            "ok": EXIT_OK,
            "error": EXIT_ERROR,
            "usage": EXIT_USAGE,
            "empty_results": EXIT_EMPTY_RESULTS,
            "auth_required": EXIT_AUTH_REQUIRED,
            "not_found": EXIT_NOT_FOUND,
            "permission_denied": EXIT_PERMISSION_DENIED,
            "rate_limited": EXIT_RATE_LIMITED,
            "retryable": EXIT_RETRYABLE,
            "config": EXIT_CONFIG,
            "cancelled": EXIT_CANCELLED,
        }
    }


def _schema_type(action: argparse.Action) -> str:
    if isinstance(action, (argparse._StoreTrueAction, argparse._StoreFalseAction)):
        return "bool"
    if action.type is int:
        return "int"
    if action.type is float:
        return "float"
    return "string"


def _build_parser_schema(parser: argparse.ArgumentParser, path: Optional[List[str]] = None) -> Dict[str, Any]:
    path = path or []
    flags: List[Dict[str, Any]] = []
    positionals: List[Dict[str, Any]] = []
    subcommands: List[Dict[str, Any]] = []

    for action in parser._actions:
        if isinstance(action, argparse._HelpAction):
            continue
        if isinstance(action, argparse._SubParsersAction):
            seen = set()
            for name, subparser in action.choices.items():
                real_name = getattr(subparser, "_command_name", name)
                if real_name in seen:
                    continue
                seen.add(real_name)
                subcommands.append(_build_parser_schema(subparser, path + [real_name]))
            subcommands.sort(key=lambda item: item["name"])
            continue

        entry = {
            "name": action.dest,
            "help": action.help or "",
            "type": _schema_type(action),
            "required": bool(getattr(action, "required", False)),
        }
        if getattr(action, "default", argparse.SUPPRESS) not in (argparse.SUPPRESS, None):
            entry["default"] = action.default
        if action.option_strings:
            entry["flags"] = action.option_strings
            flags.append(entry)
        else:
            positionals.append(entry)

    return {
        "type": "command" if path else "application",
        "name": path[-1] if path else "moodle",
        "aliases": list(getattr(parser, "_aliases", [])),
        "help": parser.description or "",
        "path": " ".join(["moodle"] + path).strip(),
        "flags": flags,
        "positionals": positionals,
        "subcommands": subcommands,
    }


def command_schema(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    root = _build_parser_schema(cli.parser)
    node = root
    for token in args.command:
        found = None
        for sub in node.get("subcommands", []):
            if token == sub["name"] or token in sub.get("aliases", []):
                found = sub
                break
        if found is None:
            raise CliError(f'unknown command "{token}"', EXIT_USAGE)
        node = found
    return {
        "schema_version": 1,
        "build": VERSION,
        "command": node,
    }


@dataclass
class MoodleCLI:
    parser: argparse.ArgumentParser
    args: argparse.Namespace

    def call(self, wsfunction: str, params: Dict[str, Any]) -> Any:
        base_url = self.args.base_url
        token = self.args.token
        if not base_url:
            raise CliError("missing base url (set --base-url or MOODLE_BASE_URL)", EXIT_CONFIG)
        if not token:
            raise CliError("missing token (set --token or MOODLE_WS_TOKEN)", EXIT_CONFIG)
        return invoke_ws(base_url, token, wsfunction, params, self.args.timeout)


def add_root_flags(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--env-file", default=".env.local", help="Local env file (default: .env.local)")
    parser.add_argument("--base-url", default="", help="Moodle base URL")
    parser.add_argument("--token", default="", help="Web service token")
    parser.add_argument("--enable-commands", default="", help="Comma-separated top-level allowlist")
    parser.add_argument("--json", action="store_true", help="Output JSON to stdout")
    parser.add_argument("--plain", action="store_true", help="Output stable parseable text")
    parser.add_argument("--results-only", action="store_true", help="Emit only the primary result")
    parser.add_argument("--select", default="", help="Select comma-separated fields in JSON mode")
    parser.add_argument("--dry-run", action="store_true", help="Do not make changes; preview request")
    parser.add_argument("--force", action="store_true", help="Skip confirmations for destructive commands")
    parser.add_argument("--no-input", action="store_true", help="Never prompt; fail instead")
    parser.add_argument("--verbose", action="store_true", help="Enable verbose diagnostics")
    parser.add_argument("--timeout", type=float, default=15.0, help="HTTP timeout seconds (default: 15)")
    parser.add_argument("--version", action="version", version=f"%(prog)s {VERSION}")


def add_parser(subparsers: argparse._SubParsersAction, name: str, *, description: str, aliases: Optional[Sequence[str]] = None) -> argparse.ArgumentParser:
    parser = subparsers.add_parser(name, aliases=list(aliases or []), description=description, help=description)
    parser._aliases = list(aliases or [])  # type: ignore[attr-defined]
    parser._command_name = name  # type: ignore[attr-defined]
    return parser


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="moodle",
        description="Agent-friendly Moodle CLI over local_aiagentapi.",
    )
    parser._aliases = []  # type: ignore[attr-defined]
    parser._command_name = "moodle"  # type: ignore[attr-defined]
    add_root_flags(parser)
    subparsers = parser.add_subparsers(dest="_command")

    schema_parser = add_parser(subparsers, "schema", description="Print machine-readable command schema", aliases=["help-json"])
    schema_parser.add_argument("command", nargs="*", help="Optional command path")
    schema_parser.set_defaults(handler=command_schema, command_path=["schema"])

    exit_codes_parser = add_parser(subparsers, "exit-codes", description="Print stable exit codes", aliases=["exitcodes"])
    exit_codes_parser.set_defaults(handler=command_exit_codes, command_path=["exit-codes"])

    whoami_parser = add_parser(subparsers, "whoami", description="Show current Moodle user context")
    whoami_parser.set_defaults(handler=command_context_get, command_path=["whoami"])

    agent_parser = add_parser(subparsers, "agent", description="Agent helpers")
    agent_sub = agent_parser.add_subparsers(dest="_agent_command")
    agent_exit = add_parser(agent_sub, "exit-codes", description="Print stable exit codes", aliases=["exitcodes"])
    agent_exit.set_defaults(handler=command_exit_codes, command_path=["agent", "exit-codes"])

    context_parser = add_parser(subparsers, "context", description="Context and identity helpers")
    context_sub = context_parser.add_subparsers(dest="_context_command")
    context_get = add_parser(context_sub, "get", description="Get current user context")
    context_get.set_defaults(handler=command_context_get, command_path=["context", "get"])

    courses_parser = add_parser(subparsers, "courses", description="Course helpers")
    courses_sub = courses_parser.add_subparsers(dest="_courses_command")
    courses_list = add_parser(courses_sub, "list", description="List my courses", aliases=["ls"])
    courses_list.set_defaults(handler=command_courses_list, command_path=["courses", "list"])
    courses_outline = add_parser(courses_sub, "outline", description="Get course outline")
    courses_outline.add_argument("--course-id", type=int, required=True, help="Course id")
    courses_outline.set_defaults(handler=command_courses_outline, command_path=["courses", "outline"])

    activities_parser = add_parser(subparsers, "activities", description="Activity helpers")
    activities_sub = activities_parser.add_subparsers(dest="_activities_command")
    activities_list = add_parser(activities_sub, "list", description="List visible activities in a course", aliases=["ls"])
    activities_list.add_argument("--course-id", type=int, required=True, help="Course id")
    activities_list.add_argument("--modname", default="", help="Optional module type filter, e.g. assign or quiz")
    activities_list.set_defaults(handler=command_activities_list, command_path=["activities", "list"])
    activities_due = add_parser(activities_sub, "due", description="List due/open/completion-expected timestamps", aliases=["agenda"])
    activities_due.add_argument("--course-id", type=int, default=0, help="Optional course id")
    activities_due.add_argument("--timestart", type=int, default=0, help="Optional lower timestamp bound")
    activities_due.add_argument("--timeend", type=int, default=0, help="Optional upper timestamp bound")
    activities_due.add_argument("--limit", type=int, default=100, help="Maximum rows to return")
    activities_due.set_defaults(handler=command_activities_due, command_path=["activities", "due"])
    activities_detail = add_parser(activities_sub, "detail", description="Get normalized activity detail")
    activities_detail.add_argument("--cmid", type=int, required=True, help="Course module id")
    activities_detail.set_defaults(handler=command_activities_detail, command_path=["activities", "detail"])

    assignments_parser = add_parser(subparsers, "assignments", description="Assignment helpers")
    assignments_sub = assignments_parser.add_subparsers(dest="_assignments_command")
    assignments_list = add_parser(assignments_sub, "list", description="List visible assignments in a course", aliases=["ls"])
    assignments_list.add_argument("--course-id", type=int, required=True, help="Course id")
    assignments_list.set_defaults(handler=command_assignments_list, command_path=["assignments", "list"])
    assignments_status = add_parser(assignments_sub, "status", description="List my submission status across assignments")
    assignments_status.add_argument("--course-id", type=int, default=0, help="Optional course id")
    assignments_status.add_argument("--assign-id", type=int, default=0, help="Optional assignment id")
    assignments_status.set_defaults(handler=command_assignments_status, command_path=["assignments", "status"])
    assignments_save_draft = add_parser(assignments_sub, "save-draft", description="Save online-text assignment draft")
    assignments_save_draft.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    assignments_save_draft.add_argument("--assign-id", type=int, required=True, help="Assignment id")
    assignments_save_draft.add_argument("--text", required=True, help="Draft text content")
    assignments_save_draft.add_argument("--format", type=int, default=1, help="Moodle text format constant (default: 1 / HTML)")
    assignments_save_draft.add_argument("--reason", default="", help="Audit reason")
    assignments_save_draft.set_defaults(handler=command_assignments_save_draft, command_path=["assignments", "save-draft"])
    assignments_submit_final = add_parser(assignments_sub, "submit-final", description="Submit assignment for grading")
    assignments_submit_final.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    assignments_submit_final.add_argument("--assign-id", type=int, required=True, help="Assignment id")
    assignments_submit_final.add_argument("--accept-submission-statement", action="store_true", help="Accept submission statement when required")
    assignments_submit_final.add_argument("--reason", default="", help="Audit reason")
    assignments_submit_final.set_defaults(handler=command_assignments_submit_final, command_path=["assignments", "submit-final"])

    catalog_parser = add_parser(subparsers, "catalog", description="API discovery helpers")
    catalog_sub = catalog_parser.add_subparsers(dest="_catalog_command")
    catalog_get = add_parser(catalog_sub, "get", description="Get API catalog")
    catalog_get.set_defaults(handler=command_catalog_get, command_path=["catalog", "get"])

    questions_parser = add_parser(subparsers, "questions", description="Question helpers")
    questions_sub = questions_parser.add_subparsers(dest="_questions_command")
    categories = add_parser(questions_sub, "categories", description="List question categories")
    categories.add_argument("--course-id", type=int, default=0, help="Optional course id")
    categories.add_argument("--context-id", type=int, default=0, help="Optional context id")
    categories.add_argument("--parent-id", type=int, default=0, help="Optional parent category id")
    categories.set_defaults(handler=command_questions_categories, command_path=["questions", "categories"])
    search = add_parser(questions_sub, "search", description="Search questions by text/category/course")
    search.add_argument("--query", default="", help="Optional search text")
    search.add_argument("--course-id", type=int, default=0, help="Optional course id")
    search.add_argument("--category-id", type=int, default=0, help="Optional category id")
    search.add_argument("--recurse", action="store_true", help="Include subcategories when category is set")
    search.add_argument("--qtype", action="append", default=[], help="Question type to include (repeatable)")
    search.add_argument("--limit", type=int, default=50, help="Maximum rows to return")
    search.set_defaults(handler=command_questions_search, command_path=["questions", "search"])
    pick_random = add_parser(questions_sub, "pick-random", description="Pick random questions from a category", aliases=["pick"])
    pick_random.add_argument("--category-id", type=int, required=True, help="Question category id")
    pick_random.add_argument("--count", type=int, required=True, help="Number of questions to pick")
    pick_random.add_argument("--recurse", action="store_true", help="Include subcategories")
    pick_random.add_argument("--seed", type=int, default=0, help="Optional random seed")
    pick_random.add_argument("--qtype", action="append", default=[], help="Question type to include (repeatable)")
    pick_random.set_defaults(handler=command_questions_pick_random, command_path=["questions", "pick-random"])

    render_html = add_parser(questions_sub, "render-html", description="Render questions to HTML")
    render_html.add_argument("question_ids", nargs="+", type=int, help="Question ids")
    render_html.add_argument("--shuffle-answers", action="store_true", help="Shuffle answer order")
    render_html.add_argument("--seed", type=int, default=0, help="Optional random seed")
    render_html.add_argument("--show-correction", action="store_true", help="Include correct answer markers")
    render_html.set_defaults(handler=command_questions_render_html, command_path=["questions", "render-html"])

    quiz_parser = add_parser(subparsers, "quiz", description="Quiz helpers")
    quiz_sub = quiz_parser.add_subparsers(dest="_quiz_command")
    quiz_list = add_parser(quiz_sub, "list", description="List quizzes in a course", aliases=["ls"])
    quiz_list.add_argument("--course-id", type=int, required=True, help="Course id")
    quiz_list.set_defaults(handler=command_quiz_list, command_path=["quiz", "list"])
    quiz_attempts = add_parser(quiz_sub, "attempts", description="List my quiz attempts")
    quiz_attempts.add_argument("--course-id", type=int, default=0, help="Optional course id")
    quiz_attempts.add_argument("--quiz-id", type=int, default=0, help="Optional quiz id")
    quiz_attempts.set_defaults(handler=command_quiz_attempts, command_path=["quiz", "attempts"])
    quiz_start = add_parser(quiz_sub, "start", description="Start a quiz attempt and return page zero")
    quiz_start.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    quiz_start.add_argument("--quiz-id", type=int, required=True, help="Quiz id")
    quiz_start.add_argument("--preflight-json", action="append", default=[], help="Inline JSON object for one preflight name/value pair")
    quiz_start.add_argument("--force-new", action="store_true", help="Force a fresh attempt")
    quiz_start.add_argument("--reason", default="", help="Audit reason")
    quiz_start.set_defaults(handler=command_quiz_start, command_path=["quiz", "start"])
    quiz_attempt_data = add_parser(quiz_sub, "attempt-data", description="Get one page of quiz attempt data")
    quiz_attempt_data.add_argument("--attempt-id", type=int, required=True, help="Attempt id")
    quiz_attempt_data.add_argument("--page", type=int, default=0, help="Page number")
    quiz_attempt_data.add_argument("--preflight-json", action="append", default=[], help="Inline JSON object for one preflight name/value pair")
    quiz_attempt_data.set_defaults(handler=command_quiz_attempt_data, command_path=["quiz", "attempt-data"])
    quiz_attempt_summary = add_parser(quiz_sub, "attempt-summary", description="Get quiz attempt summary")
    quiz_attempt_summary.add_argument("--attempt-id", type=int, required=True, help="Attempt id")
    quiz_attempt_summary.add_argument("--preflight-json", action="append", default=[], help="Inline JSON object for one preflight name/value pair")
    quiz_attempt_summary.set_defaults(handler=command_quiz_attempt_summary, command_path=["quiz", "attempt-summary"])
    quiz_save_attempt = add_parser(quiz_sub, "save-attempt", description="Save quiz attempt responses")
    quiz_save_attempt.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    quiz_save_attempt.add_argument("--attempt-id", type=int, required=True, help="Attempt id")
    quiz_save_attempt.add_argument("--response-json", action="append", default=[], help="Inline JSON object for one response name/value pair")
    quiz_save_attempt.add_argument("--preflight-json", action="append", default=[], help="Inline JSON object for one preflight name/value pair")
    quiz_save_attempt.add_argument("--reason", default="", help="Audit reason")
    quiz_save_attempt.set_defaults(handler=command_quiz_save_attempt, command_path=["quiz", "save-attempt"])
    quiz_submit_attempt = add_parser(quiz_sub, "submit-attempt", description="Submit a quiz attempt")
    quiz_submit_attempt.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    quiz_submit_attempt.add_argument("--attempt-id", type=int, required=True, help="Attempt id")
    quiz_submit_attempt.add_argument("--response-json", action="append", default=[], help="Inline JSON object for one response name/value pair")
    quiz_submit_attempt.add_argument("--preflight-json", action="append", default=[], help="Inline JSON object for one preflight name/value pair")
    quiz_submit_attempt.add_argument("--timeup", action="store_true", help="Submit due to timer expiry")
    quiz_submit_attempt.add_argument("--reason", default="", help="Audit reason")
    quiz_submit_attempt.set_defaults(handler=command_quiz_submit_attempt, command_path=["quiz", "submit-attempt"])
    quiz_answer = add_parser(quiz_sub, "answer", description="Apply structured answers to supported question types")
    quiz_answer.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    quiz_answer.add_argument("--attempt-id", type=int, required=True, help="Attempt id")
    quiz_answer.add_argument("--answer-json", action="append", default=[], help='Inline JSON object like {"slot":1,"choice_index":0}')
    quiz_answer.add_argument("--submit", action="store_true", help="Submit after applying answers")
    quiz_answer.add_argument("--timeup", action="store_true", help="Submit due to timer expiry")
    quiz_answer.add_argument("--preflight-json", action="append", default=[], help="Inline JSON object for one preflight name/value pair")
    quiz_answer.add_argument("--reason", default="", help="Audit reason")
    quiz_answer.set_defaults(handler=command_quiz_answer, command_path=["quiz", "answer"])
    resolve_random = add_parser(quiz_sub, "resolve-random", description="Resolve random quiz questions", aliases=["resolve"])
    resolve_random.add_argument("--quiz-id", type=int, required=True, help="Quiz id")
    resolve_random.add_argument("--copies", type=int, default=1, help="Number of copies to resolve")
    resolve_random.add_argument("--seed", type=int, default=0, help="Optional random seed")
    resolve_random.add_argument("--allow-duplicates", action="store_true", help="Allow duplicates across copies")
    resolve_random.set_defaults(handler=command_quiz_resolve_random, command_path=["quiz", "resolve-random"])

    calendar_parser = add_parser(subparsers, "calendar", description="Calendar helpers")
    calendar_sub = calendar_parser.add_subparsers(dest="_calendar_command")
    calendar_list = add_parser(calendar_sub, "list", description="List calendar events", aliases=["ls"])
    calendar_list.add_argument("--timestart", type=int, default=0, help="Start unix timestamp")
    calendar_list.add_argument("--timeend", type=int, default=0, help="End unix timestamp")
    calendar_list.add_argument("--limit", type=int, default=100, help="Maximum events to return")
    calendar_list.set_defaults(handler=command_calendar_list, command_path=["calendar", "list"])
    publish_plan = add_parser(calendar_sub, "publish-plan", description="Publish study plan items to calendar", aliases=["publish"])
    publish_plan.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    publish_plan.add_argument("--reason", default="", help="Audit reason")
    publish_plan.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    publish_plan.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one item (repeatable)")
    publish_plan.set_defaults(handler=command_calendar_publish_plan, command_path=["calendar", "publish-plan"])
    upsert_plan = add_parser(calendar_sub, "upsert-plan", description="Upsert keyed study plan items in calendar", aliases=["upsert"])
    upsert_plan.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    upsert_plan.add_argument("--plan-key", required=True, help="Stable plan key")
    upsert_plan.add_argument("--reason", default="", help="Audit reason")
    upsert_plan.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    upsert_plan.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one item (repeatable)")
    upsert_plan.set_defaults(handler=command_calendar_upsert_plan, command_path=["calendar", "upsert-plan"])

    resources_parser = add_parser(subparsers, "resources", description="Learning resource helpers")
    resources_sub = resources_parser.add_subparsers(dest="_resources_command")
    resources_list = add_parser(resources_sub, "list", description="List resource-style modules in a course", aliases=["ls"])
    resources_list.add_argument("--course-id", type=int, required=True, help="Course id")
    resources_list.set_defaults(handler=command_resources_list, command_path=["resources", "list"])

    forum_parser = add_parser(subparsers, "forum", description="Forum helpers")
    forum_sub = forum_parser.add_subparsers(dest="_forum_command")
    forum_discussions = add_parser(forum_sub, "discussions", description="List recent forum discussions", aliases=["ls"])
    forum_discussions.add_argument("--course-id", type=int, default=0, help="Optional course id")
    forum_discussions.add_argument("--forum-id", type=int, default=0, help="Optional forum id")
    forum_discussions.add_argument("--limit", type=int, default=20, help="Maximum discussions to return")
    forum_discussions.set_defaults(handler=command_forum_discussions, command_path=["forum", "discussions"])
    forum_create = add_parser(forum_sub, "create-discussion", description="Create a forum discussion")
    forum_create.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    forum_create.add_argument("--forum-id", type=int, required=True, help="Forum id")
    forum_create.add_argument("--subject", required=True, help="Discussion subject")
    forum_create.add_argument("--message", required=True, help="Discussion message")
    forum_create.add_argument("--group-id", type=int, default=0, help="Optional group id")
    forum_create.add_argument("--subscribe", action="store_true", default=True, help="Subscribe to the discussion")
    forum_create.add_argument("--no-subscribe", action="store_false", dest="subscribe", help="Do not subscribe")
    forum_create.add_argument("--pinned", action="store_true", help="Pin discussion when capability allows")
    forum_create.add_argument("--reason", default="", help="Audit reason")
    forum_create.set_defaults(handler=command_forum_create_discussion, command_path=["forum", "create-discussion"])
    forum_reply = add_parser(forum_sub, "reply", description="Reply to a forum post")
    forum_reply.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    forum_reply.add_argument("--post-id", type=int, required=True, help="Parent post id")
    forum_reply.add_argument("--subject", required=True, help="Reply subject")
    forum_reply.add_argument("--message", required=True, help="Reply message")
    forum_reply.add_argument("--subscribe", action="store_true", default=True, help="Subscribe to the discussion")
    forum_reply.add_argument("--no-subscribe", action="store_false", dest="subscribe", help="Do not subscribe")
    forum_reply.add_argument("--private-reply", action="store_true", help="Create a private reply when supported")
    forum_reply.add_argument("--reason", default="", help="Audit reason")
    forum_reply.set_defaults(handler=command_forum_reply, command_path=["forum", "reply"])
    forum_update = add_parser(forum_sub, "update-post", description="Update a forum post or discussion topic")
    forum_update.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    forum_update.add_argument("--post-id", type=int, required=True, help="Forum post id")
    forum_update.add_argument("--subject", default="", help="Updated subject")
    forum_update.add_argument("--message", default="", help="Updated message")
    forum_update.add_argument("--subscribe", action="store_true", default=True, help="Subscribe to the discussion")
    forum_update.add_argument("--no-subscribe", action="store_false", dest="subscribe", help="Do not subscribe")
    forum_update.add_argument("--pinned", action="store_true", help="Pin discussion when capability allows")
    forum_update.add_argument("--reason", default="", help="Audit reason")
    forum_update.set_defaults(handler=command_forum_update_post, command_path=["forum", "update-post"])
    forum_delete = add_parser(forum_sub, "delete-post", description="Delete a forum post or whole discussion")
    forum_delete.add_argument("--idempotency-key", required=True, help="Client idempotency key")
    forum_delete.add_argument("--post-id", type=int, required=True, help="Forum post id")
    forum_delete.add_argument("--reason", default="", help="Audit reason")
    forum_delete.set_defaults(handler=command_forum_delete_post, command_path=["forum", "delete-post"])

    notifications_parser = add_parser(subparsers, "notifications", description="Notification helpers")
    notifications_sub = notifications_parser.add_subparsers(dest="_notifications_command")
    notifications_list = add_parser(notifications_sub, "list", description="List notifications for current user", aliases=["ls"])
    notifications_list.add_argument("--limit-from", type=int, default=0, help="Pagination offset")
    notifications_list.add_argument("--limit", type=int, default=50, help="Maximum notifications to return")
    notifications_list.add_argument("--unread-only", action="store_true", help="Return unread notifications only")
    notifications_list.set_defaults(handler=command_notifications_list, command_path=["notifications", "list"])

    grades_parser = add_parser(subparsers, "grades", description="Grade helpers")
    grades_sub = grades_parser.add_subparsers(dest="_grades_command")
    grades_overview = add_parser(grades_sub, "overview", description="Show my grade overview")
    grades_overview.add_argument("--course-id", type=int, default=0, help="Optional course id")
    grades_overview.set_defaults(handler=command_grades_overview, command_path=["grades", "overview"])

    progress_parser = add_parser(subparsers, "progress", description="Completion/progress helpers")
    progress_sub = progress_parser.add_subparsers(dest="_progress_command")
    progress_course = add_parser(progress_sub, "course", description="Show my course progress")
    progress_course.add_argument("--course-id", type=int, default=0, help="Optional course id")
    progress_course.set_defaults(handler=command_progress_course, command_path=["progress", "course"])

    mathstate_parser = add_parser(subparsers, "mathstate", description="Math learning state helpers")
    mathstate_sub = mathstate_parser.add_subparsers(dest="_mathstate_command")

    mathstate_kp = add_parser(mathstate_sub, "kp-upsert", description="Upsert standard knowledge-point records")
    mathstate_kp.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_kp.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one knowledge-point item")
    mathstate_kp.set_defaults(handler=command_mathstate_kp_upsert, command_path=["mathstate", "kp-upsert"])

    mathstate_qtype = add_parser(mathstate_sub, "qtype-upsert", description="Upsert standard question-type records")
    mathstate_qtype.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_qtype.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one question-type item")
    mathstate_qtype.set_defaults(handler=command_mathstate_qtype_upsert, command_path=["mathstate", "qtype-upsert"])

    mathstate_map = add_parser(mathstate_sub, "question-map-upsert", description="Upsert Moodle question mappings to qg/kg")
    mathstate_map.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_map.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one mapping item")
    mathstate_map.set_defaults(handler=command_mathstate_question_map_upsert, command_path=["mathstate", "question-map-upsert"])

    mathstate_evidence = add_parser(mathstate_sub, "evidence-ingest", description="Ingest evidence and update mastery state")
    mathstate_evidence.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_evidence.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one evidence item")
    mathstate_evidence.set_defaults(handler=command_mathstate_evidence_ingest, command_path=["mathstate", "evidence-ingest"])

    mathstate_summary = add_parser(mathstate_sub, "student-summary", description="Show one student's math mastery summary")
    mathstate_summary.add_argument("--course-id", type=int, required=True, help="Course id")
    mathstate_summary.add_argument("--user-id", type=int, default=0, help="User id, 0 means current token user")
    mathstate_summary.add_argument("--include-kp-states", action="store_true", default=True, help="Include knowledge-point states")
    mathstate_summary.add_argument("--no-include-kp-states", action="store_false", dest="include_kp_states", help="Do not include knowledge-point states")
    mathstate_summary.add_argument("--include-qtype-states", action="store_true", default=True, help="Include question-type states")
    mathstate_summary.add_argument("--no-include-qtype-states", action="store_false", dest="include_qtype_states", help="Do not include question-type states")
    mathstate_summary.add_argument("--include-due-tasks", action="store_true", default=True, help="Include due tasks")
    mathstate_summary.add_argument("--no-include-due-tasks", action="store_false", dest="include_due_tasks", help="Do not include due tasks")
    mathstate_summary.set_defaults(handler=command_mathstate_student_summary, command_path=["mathstate", "student-summary"])

    mathstate_due = add_parser(mathstate_sub, "reviews-due", description="List due review tasks for a student")
    mathstate_due.add_argument("--course-id", type=int, required=True, help="Course id")
    mathstate_due.add_argument("--user-id", type=int, default=0, help="User id, 0 means current token user")
    mathstate_due.add_argument("--limit", type=int, default=50, help="Maximum tasks to return")
    mathstate_due.add_argument("--due-before", type=int, default=0, help="Upper due timestamp, 0 means now")
    mathstate_due.set_defaults(handler=command_mathstate_reviews_due, command_path=["mathstate", "reviews-due"])

    return parser


def apply_env_defaults(args: argparse.Namespace) -> argparse.Namespace:
    env = load_env_file(args.env_file)
    if not args.base_url:
        args.base_url = env.get("MOODLE_CLI_BASE_URL") or env.get("MOODLE_BASE_URL") or os.environ.get("MOODLE_CLI_BASE_URL") or os.environ.get("MOODLE_BASE_URL", "")
    if not args.token:
        args.token = env.get("MOODLE_CLI_TOKEN") or env.get("MOODLE_WS_TOKEN") or os.environ.get("MOODLE_CLI_TOKEN") or os.environ.get("MOODLE_WS_TOKEN", "")
    args.base_url = normalize_base_url(args.base_url) if args.base_url else ""
    if env_bool("MOODLE_CLI_AUTO_JSON") and not args.json and not args.plain and not sys.stdout.isatty():
        args.json = True
    if args.json and args.plain:
        raise CliError("cannot combine --json and --plain", EXIT_USAGE)
    return args


def enforce_enabled_commands(args: argparse.Namespace) -> None:
    enabled = (args.enable_commands or "").strip().lower()
    if not enabled:
        return
    allow = {item.strip() for item in enabled.split(",") if item.strip()}
    if not allow or "*" in allow or "all" in allow:
        return
    path = getattr(args, "command_path", [])
    if not path:
        return
    top = path[0].lower()
    if top not in allow:
        raise CliError(f'command "{top}" is not enabled (set --enable-commands to allow it)', EXIT_USAGE)


def main(argv: Optional[Sequence[str]] = None) -> int:
    argv = list(argv or sys.argv[1:])
    argv = rewrite_desire_args(argv)
    parser = build_parser()

    try:
        args = parser.parse_args(argv)
        if not hasattr(args, "handler"):
            parser.print_help()
            return EXIT_USAGE
        args = apply_env_defaults(args)
        enforce_enabled_commands(args)
        cli = MoodleCLI(parser=parser, args=args)
        result = args.handler(cli, args)
        result = transform_output(result, args)
        emit_output(result, args)
        return EXIT_OK
    except KeyboardInterrupt:
        _eprint("cancelled")
        return EXIT_CANCELLED
    except CliError as e:
        _eprint(str(e))
        return e.exit_code


if __name__ == "__main__":
    raise SystemExit(main())
