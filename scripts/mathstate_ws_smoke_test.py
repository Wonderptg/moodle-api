#!/usr/bin/env python3
"""
Smoke test for Moodle local_mathstate webservice write/read chain.

Covers:
1) question_map_sync_batch
2) question_map_lookup
3) lesson_session_upsert_batch
4) learning_event_record_batch
5) review_upsert_batch + reviews_due readback
6) doc_job_upsert_batch
7) lesson_start / lesson_log_append / lesson_finish
8) review_complete
9) doc_publish_request
10) next_recommendation

Usage:
  python3 scripts/mathstate_ws_smoke_test.py --env-file .env.local --token <LOCAL_MATHSTATE_TOKEN>
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
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, List, Optional


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
            key = m.group(1)
            val = _strip_quotes(m.group(2).strip())
            env[key] = val
    return env


def normalize_base_url(base_url: str) -> str:
    base_url = base_url.strip()
    if base_url.endswith("/"):
        base_url = base_url[:-1]
    return base_url


@dataclass(frozen=True)
class WsResponse:
    status: int
    raw: bytes
    json: Optional[Any]


def ws_call(
    *,
    base_url: str,
    token: str,
    wsfunction: str,
    params: Dict[str, str],
    timeout_s: float = 10.0,
) -> WsResponse:
    endpoint = f"{base_url}/webservice/rest/server.php"
    payload = {
        "wstoken": token,
        "wsfunction": wsfunction,
        "moodlewsrestformat": "json",
        **params,
    }
    data = urllib.parse.urlencode(payload).encode("utf-8")
    req = urllib.request.Request(endpoint, method="POST", data=data)
    req.add_header("Content-Type", "application/x-www-form-urlencoded")

    try:
        with urllib.request.urlopen(req, timeout=timeout_s) as resp:
            raw = resp.read()
            status = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        status = e.code
    except Exception as e:
        raise RuntimeError(f"Request failed: {endpoint}: {e}") from e

    decoded_json = None
    try:
        decoded_json = json.loads(raw.decode("utf-8", errors="strict"))
    except Exception:
        decoded_json = None

    return WsResponse(status=status, raw=raw, json=decoded_json)


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


def encode_scalar(value: Any) -> str:
    if isinstance(value, bool):
        return "1" if value else "0"
    if value is None:
        return ""
    if isinstance(value, (dict, list)):
        return json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    return str(value)


def flatten_batch(items: List[Dict[str, Any]]) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for i, item in enumerate(items):
        for key, value in item.items():
            if isinstance(value, list):
                for j, part in enumerate(value):
                    out[f"items[{i}][{key}][{j}]"] = encode_scalar(part)
                continue
            out[f"items[{i}][{key}]"] = encode_scalar(value)
    return out


def expect_json(resp: WsResponse, context: str) -> Dict[str, Any]:
    if not isinstance(resp.json, dict):
        sample = resp.raw[:400].decode("utf-8", errors="replace")
        raise RuntimeError(f"{context}: expected JSON object but got: status={resp.status} body={sample}")
    return resp.json


def expect_ok(resp_json: Dict[str, Any], context: str) -> Dict[str, Any]:
    if not resp_json.get("ok"):
        raise RuntimeError(f"{context}: response ok=false: {json.dumps(resp_json, ensure_ascii=False)}")
    return resp_json


def call_batch(
    *,
    base_url: str,
    token: str,
    wsfunction: str,
    items: List[Dict[str, Any]],
    timeout: float,
) -> Dict[str, Any]:
    params = flatten_batch(items)
    resp = ws_call(base_url=base_url, token=token, wsfunction=wsfunction, params=params, timeout_s=timeout)
    return expect_ok(expect_json(resp, wsfunction), wsfunction)


def flatten_params(values: Dict[str, Any]) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for key, value in values.items():
        if isinstance(value, list):
            for idx, part in enumerate(value):
                out[f"{key}[{idx}]"] = encode_scalar(part)
            continue
        out[key] = encode_scalar(value)
    return out


def call_object(
    *,
    base_url: str,
    token: str,
    wsfunction: str,
    params: Dict[str, Any],
    timeout: float,
) -> Dict[str, Any]:
    resp = ws_call(
        base_url=base_url,
        token=token,
        wsfunction=wsfunction,
        params=flatten_params(params),
        timeout_s=timeout,
    )
    return expect_ok(expect_json(resp, wsfunction), wsfunction)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--env-file", default=".env.local", help="Env file path (default: .env.local)")
    ap.add_argument("--base-url", default="", help="Override MOODLE_BASE_URL")
    ap.add_argument("--token", default="", help="Override token (recommended: local_mathstate service token)")
    ap.add_argument(
        "--token-env-key",
        default="MOODLE_MATHSTATE_WS_TOKEN",
        help="Env key for token fallback (default: MOODLE_MATHSTATE_WS_TOKEN, then MOODLE_WS_TOKEN)",
    )
    ap.add_argument("--course-id", type=int, default=2, help="Target course id (default: 2)")
    ap.add_argument("--user-id", type=int, default=2, help="Target user id (default: 2)")
    ap.add_argument("--question-id", type=int, default=1, help="Target question id (default: 1)")
    ap.add_argument("--questionbankentry-id", type=int, default=1, help="Target question bank entry id (default: 1)")
    ap.add_argument("--timeout", type=float, default=10.0, help="HTTP timeout seconds")
    ap.add_argument("--start-server", action="store_true", help="Auto start php -S if base-url is not reachable")
    ap.add_argument("--keep-server", action="store_true", help="Keep auto-started server alive after test")
    args = ap.parse_args()

    env_from_file = load_env_file(args.env_file) if args.env_file else {}
    base_url = args.base_url or env_from_file.get("MOODLE_BASE_URL") or os.environ.get("MOODLE_BASE_URL", "")
    token = (
        args.token
        or env_from_file.get(args.token_env_key, "")
        or os.environ.get(args.token_env_key, "")
        or env_from_file.get("MOODLE_WS_TOKEN", "")
        or os.environ.get("MOODLE_WS_TOKEN", "")
    )

    if not base_url:
        _eprint("Missing base url. Set MOODLE_BASE_URL or pass --base-url.")
        return 2
    if not token:
        _eprint(
            f"Missing token. Set {args.token_env_key}/MOODLE_WS_TOKEN in env file or pass --token."
        )
        return 2
    if args.course_id <= 0 or args.user_id <= 0 or args.question_id <= 0:
        _eprint("course-id, user-id, question-id must be > 0.")
        return 2

    base_url = normalize_base_url(base_url)

    proc: Optional[subprocess.Popen[bytes]] = None
    if not check_alive(base_url, timeout_s=max(2.0, args.timeout)):
        if not args.start_server:
            _eprint(f"Base URL not reachable: {base_url}. Re-run with --start-server.")
            return 3

        parsed = urllib.parse.urlparse(base_url)
        host = parsed.hostname or "127.0.0.1"
        port = parsed.port or (443 if parsed.scheme == "https" else 80)
        if host not in ("127.0.0.1", "localhost") or port != 8000:
            _eprint(f"--start-server only supports http://127.0.0.1:8000 style base url (got {base_url}).")
            return 3

        log_path = "/tmp/moodle-mathstate-smoke.error.log"
        repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        proc = start_php_server(cwd=repo_root, host=host, port=port, log_path=log_path)
        for _ in range(50):
            if check_alive(base_url, timeout_s=0.2):
                break
            time.sleep(0.1)
        else:
            stop_php_server(proc)
            _eprint(f"Failed to start php server at {base_url}. See {log_path}.")
            return 3

    suffix = int(time.time())
    source_id = f"w2m-math-smoke-{suffix}"
    session_key = f"sess-smoke-{suffix}"
    student_session_key = f"sess-smoke-{suffix}-student"
    job_key = f"docjob-smoke-{suffix}"
    request_job_key = f"docjob-smoke-{suffix}-request"
    target_ref = f"KG-SMOKE-{suffix}"
    lesson_key = f"course{args.course_id}_lesson_smoke"
    qg_id = f"QG-SMOKE-{suffix}"
    now_ts = int(time.time())
    due_ts = now_ts + 3600

    try:
        sync_result = call_batch(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_question_map_sync_batch",
            timeout=args.timeout,
            items=[{
                "source_id": source_id,
                "questionid": args.question_id,
                "questionbankentryid": args.questionbankentry_id,
                "source_question_name": "mathstate smoke question",
                "lesson_key": lesson_key,
                "lesson_match_type": "manual",
                "lesson_confidence": "high",
                "lesson_source": "smoke",
                "lesson_candidate_keys": [lesson_key],
                "qg_id": qg_id,
                "kg_ids": [target_ref, f"{target_ref}-2"],
                "mapping_source": "sidecar",
                "mapping_confidence": "95",
                "review_status": "pending",
                "review_notes": "ws smoke",
                "evidence_excerpt": "ws smoke excerpt",
            }],
        )
        if not sync_result.get("synced"):
            raise RuntimeError(f"question_map_sync_batch did not sync any row: {sync_result}")

        lookup_resp = ws_call(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_question_map_lookup",
            params={"source_id": source_id, "limit": "10"},
            timeout_s=args.timeout,
        )
        lookup_result = expect_ok(expect_json(lookup_resp, "local_mathstate_question_map_lookup"), "local_mathstate_question_map_lookup")
        matched = [x for x in lookup_result.get("items", []) if str(x.get("source_id")) == source_id]
        if not matched:
            raise RuntimeError(f"question_map_lookup could not find source_id={source_id}: {lookup_result}")

        session_result = call_batch(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_lesson_session_upsert_batch",
            timeout=args.timeout,
            items=[{
                "userid": args.user_id,
                "courseid": args.course_id,
                "session_key": session_key,
                "lesson_key": lesson_key,
                "status": "active",
                "progress_json": {"step": 1, "total": 3},
                "summary_json": {"note": "ws smoke"},
                "started_at": now_ts,
                "last_event_at": now_ts,
                "source": "agent",
            }],
        )

        lesson_start_result = call_object(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_lesson_start",
            timeout=args.timeout,
            params={
                "courseid": args.course_id,
                "userid": args.user_id,
                "session_key": student_session_key,
                "lesson_key": lesson_key,
                "source": "agent",
                "progress_json": {"step": 1, "total": 2},
            },
        )
        if not lesson_start_result.get("session_key"):
            raise RuntimeError(f"lesson_start returned no session key: {lesson_start_result}")

        event_result = call_batch(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_learning_event_record_batch",
            timeout=args.timeout,
            items=[{
                "userid": args.user_id,
                "courseid": args.course_id,
                "session_key": session_key,
                "lesson_key": lesson_key,
                "questionid": args.question_id,
                "qg_id": qg_id,
                "kg_ids": [target_ref],
                "event_type": "practice",
                "result": "correct",
                "score": 1,
                "maxscore": 1,
                "source": "agent",
                "payload_json": {"mode": "smoke"},
                "occurred_at": now_ts,
            }],
        )
        event_items = event_result.get("items", [])
        if not event_items:
            raise RuntimeError(f"learning_event_record_batch returned no items: {event_result}")

        lesson_log_result = call_object(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_lesson_log_append",
            timeout=args.timeout,
            params={
                "courseid": args.course_id,
                "userid": args.user_id,
                "session_key": student_session_key,
                "lesson_key": lesson_key,
                "questionid": args.question_id,
                "qg_id": qg_id,
                "kg_ids": [target_ref],
                "event_type": "practice",
                "result": "correct",
                "score": 1,
                "maxscore": 1,
                "source": "agent",
                "payload_json": {"mode": "student-smoke"},
                "occurred_at": now_ts + 1,
            },
        )
        if not lesson_log_result.get("event_id"):
            raise RuntimeError(f"lesson_log_append returned no event id: {lesson_log_result}")

        lesson_finish_result = call_object(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_lesson_finish",
            timeout=args.timeout,
            params={
                "courseid": args.course_id,
                "userid": args.user_id,
                "session_key": student_session_key,
                "status": "completed",
                "ended_at": now_ts + 2,
                "summary_json": {"result": "smoke done"},
            },
        )

        review_result = call_batch(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_review_upsert_batch",
            timeout=args.timeout,
            items=[{
                "userid": args.user_id,
                "courseid": args.course_id,
                "target_type": "kp",
                "target_ref": target_ref,
                "title": f"Review {target_ref}",
                "task_kind": "review",
                "priority": 0.9,
                "source_reason": "smoke",
                "payload_json": {"from": "mathstate_ws_smoke"},
                "status": "todo",
                "due_at": due_ts,
                "linked_doc_url": "https://example.local/doc/smoke",
            }],
        )

        due_resp = ws_call(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_reviews_due",
            params={
                "courseid": str(args.course_id),
                "userid": str(args.user_id),
                "limit": "50",
                "due_before": str(due_ts + 60),
            },
            timeout_s=args.timeout,
        )
        due_result = expect_ok(expect_json(due_resp, "local_mathstate_reviews_due"), "local_mathstate_reviews_due")
        due_refs = {str(item.get("target_ref", "")) for item in due_result.get("items", [])}
        if target_ref not in due_refs:
            raise RuntimeError(f"reviews_due missing inserted target_ref={target_ref}: {due_result}")

        review_complete_result = call_object(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_review_complete",
            timeout=args.timeout,
            params={
                "courseid": args.course_id,
                "userid": args.user_id,
                "target_type": "kp",
                "target_ref": target_ref,
                "status": "done",
                "note": "smoke complete",
            },
        )

        doc_job_result = call_batch(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_doc_job_upsert_batch",
            timeout=args.timeout,
            items=[{
                "job_key": job_key,
                "userid": args.user_id,
                "courseid": args.course_id,
                "target_type": "review",
                "target_ref": target_ref,
                "doc_ref": f"doc-{suffix}",
                "provider": "agent",
                "job_type": "summary",
                "status": "queued",
                "request_payload_json": {"lang": "zh-CN"},
                "result_payload_json": {},
                "queued_at": now_ts,
            }],
        )

        doc_request_result = call_object(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_doc_publish_request",
            timeout=args.timeout,
            params={
                "courseid": args.course_id,
                "userid": args.user_id,
                "job_key": request_job_key,
                "target_type": "review",
                "target_ref": target_ref,
                "doc_ref": f"docreq-{suffix}",
                "provider": "agent",
                "job_type": "summary",
                "request_payload_json": {"lang": "zh-CN"},
                "queued_at": now_ts,
            },
        )

        next_result = call_object(
            base_url=base_url,
            token=token,
            wsfunction="local_mathstate_next_recommendation",
            timeout=args.timeout,
            params={
                "courseid": args.course_id,
                "userid": args.user_id,
                "limit": 5,
                "due_before": due_ts + 60,
            },
        )

        summary = {
            "ok": True,
            "base_url": base_url,
            "course_id": args.course_id,
            "user_id": args.user_id,
            "source_id": source_id,
            "session_key": session_key,
            "review_target_ref": target_ref,
            "job_key": job_key,
            "question_map_sync": sync_result,
            "question_map_lookup_count": lookup_result.get("count", 0),
            "lesson_session": session_result,
            "lesson_start": lesson_start_result,
            "learning_event": event_result,
            "lesson_log_append": lesson_log_result,
            "lesson_finish": lesson_finish_result,
            "review_upsert": review_result,
            "reviews_due_count": due_result.get("count", 0),
            "review_complete": review_complete_result,
            "doc_job_upsert": doc_job_result,
            "doc_publish_request": doc_request_result,
            "next_recommendation_count": next_result.get("count", 0),
        }
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        return 0
    except Exception as e:
        _eprint(f"FAILED: {e}")
        return 1
    finally:
        if proc is not None and not args.keep_server:
            stop_php_server(proc)


if __name__ == "__main__":
    raise SystemExit(main())
