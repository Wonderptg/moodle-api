#!/usr/bin/env python3
"""
Smoke test for Moodle local_aiagentapi.

Goals:
- deterministic, repeatable read/write checks
- can run locally without external dependencies (stdlib only)
- optionally boot the php -S dev server with settings that avoid PHP 8.5 deprecation noise in responses

Usage:
  python3 scripts/aiagentapi_smoke_test.py --env-file .env.local --start-server

Env file format (simple "export KEY=VALUE" lines are supported):
  MOODLE_BASE_URL=http://127.0.0.1:8000
  MOODLE_WS_TOKEN=...
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
import uuid
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple


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
    headers: Dict[str, str]
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
            headers = {k.lower(): v for k, v in resp.headers.items()}
            status = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        headers = {k.lower(): v for k, v in e.headers.items()}
        status = e.code
    except Exception as e:
        raise RuntimeError(f"Request failed: {endpoint}: {e}") from e

    decoded_json = None
    try:
        decoded_json = json.loads(raw.decode("utf-8", errors="strict"))
    except Exception:
        decoded_json = None

    return WsResponse(status=status, headers=headers, raw=raw, json=decoded_json)


def check_alive(base_url: str, timeout_s: float = 2.0) -> bool:
    try:
        with urllib.request.urlopen(f"{base_url}/", timeout=timeout_s) as resp:
            return 200 <= resp.status < 500
    except Exception:
        return False


def start_php_server(cwd: str, host: str, port: int, log_path: str) -> subprocess.Popen[bytes]:
    # Avoid JSON corruption from PHP 8.5 deprecation warnings.
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
    # Put in its own process group so we can kill it and children.
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


def flatten_items_params(items: List[Dict[str, Any]]) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for i, item in enumerate(items):
        for k, v in item.items():
            out[f"items[{i}][{k}]"] = str(v)
    return out


def expect_json(resp: WsResponse, context: str) -> Any:
    if resp.json is None:
        sample = resp.raw[:500].decode("utf-8", errors="replace")
        raise RuntimeError(f"{context}: expected JSON but got non-JSON response (status={resp.status}): {sample}")
    return resp.json


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--env-file", default=".env.local", help="Env file path (default: .env.local)")
    ap.add_argument("--base-url", default="", help="Override MOODLE_BASE_URL")
    ap.add_argument("--token", default="", help="Override MOODLE_WS_TOKEN")
    ap.add_argument("--start-server", action="store_true", help="Start php -S server if base-url is not reachable")
    ap.add_argument("--keep-server", action="store_true", help="Do not stop the php server after the test")
    ap.add_argument("--no-write", action="store_true", help="Skip the write call (only do read + dry-run)")
    ap.add_argument("--timeout", type=float, default=10.0, help="HTTP timeout seconds (default: 10)")
    args = ap.parse_args()

    env_from_file = load_env_file(args.env_file) if args.env_file else {}
    base_url = args.base_url or env_from_file.get("MOODLE_BASE_URL") or os.environ.get("MOODLE_BASE_URL", "")
    token = args.token or env_from_file.get("MOODLE_WS_TOKEN") or os.environ.get("MOODLE_WS_TOKEN", "")

    if not base_url:
        _eprint("Missing base url. Set MOODLE_BASE_URL or pass --base-url.")
        return 2
    if not token:
        _eprint("Missing token. Set MOODLE_WS_TOKEN or pass --token.")
        return 2

    base_url = normalize_base_url(base_url)

    proc: Optional[subprocess.Popen[bytes]] = None

    # If base url isn't reachable, optionally start server at 127.0.0.1:8000 and try again.
    if not check_alive(base_url, timeout_s=2.0):
        if not args.start_server:
            _eprint(f"Base URL not reachable: {base_url}. Re-run with --start-server to boot php -S.")
            return 3

        parsed = urllib.parse.urlparse(base_url)
        host = parsed.hostname or "127.0.0.1"
        port = parsed.port or (443 if parsed.scheme == "https" else 80)
        if host not in ("127.0.0.1", "localhost") or port != 8000:
            _eprint(f"--start-server only supports base url like http://127.0.0.1:8000 (got {base_url}).")
            return 3

        log_path = "/tmp/moodle-php-server.error.log"
        proc = start_php_server(cwd=os.path.dirname(os.path.abspath(__file__)) + "/..", host=host, port=port, log_path=log_path)
        # Wait for server.
        for _ in range(50):
            if check_alive(base_url, timeout_s=0.2):
                break
            time.sleep(0.1)
        else:
            stop_php_server(proc)
            _eprint(f"Failed to start php server at {base_url}. See {log_path}.")
            return 3

    try:
        # 1) Read.
        resp = ws_call(base_url=base_url, token=token, wsfunction="local_aiagentapi_get_user_context", params={}, timeout_s=args.timeout)
        j = expect_json(resp, "get_user_context")
        if not isinstance(j, dict) or not j.get("ok") or "data" not in j or "user" not in j["data"]:
            raise RuntimeError(f"get_user_context: unexpected response: {j}")
        print("OK read local_aiagentapi_get_user_context:", json.dumps(j["data"]["user"], ensure_ascii=False))

        # 2) API catalog.
        resp = ws_call(base_url=base_url, token=token, wsfunction="local_aiagentapi_get_api_catalog", params={}, timeout_s=args.timeout)
        j = expect_json(resp, "get_api_catalog")
        if not isinstance(j, dict) or not j.get("ok") or "data" not in j or "endpoints" not in j["data"]:
            raise RuntimeError(f"get_api_catalog: unexpected response: {j}")
        names = {ep.get("name") for ep in (j["data"]["endpoints"] or []) if isinstance(ep, dict)}
        required = {
            "local_aiagentapi_get_user_context",
            "local_aiagentapi_get_api_catalog",
            "local_aiagentapi_questionbank_pick_random",
            "local_aiagentapi_questions_render_html",
            "local_aiagentapi_quiz_resolve_random",
            "local_aiagentapi_calendar_publish_plan",
        }
        missing = sorted(required - names)
        if missing:
            raise RuntimeError(f"get_api_catalog: missing endpoints: {missing}")
        print("OK read local_aiagentapi_get_api_catalog:", f"endpoints={len(names)}")

        # 3) Dry run.
        now = int(time.time())
        idk_dry = f"testdry{now}{uuid.uuid4().hex[:6]}"
        dry_items = [
            {
                "name": "Smoke test plan item (dry)",
                "description": "Dry-run only",
                "timestart": now + 300,
                "timeduration": 1800,
            }
        ]
        dry_params = {
            "idempotency_key": idk_dry,
            "dry_run": "1",
            "reason": "aiagentapi_smoke_test",
            **flatten_items_params(dry_items),
        }
        resp = ws_call(base_url=base_url, token=token, wsfunction="local_aiagentapi_calendar_publish_plan", params=dry_params, timeout_s=args.timeout)
        j = expect_json(resp, "calendar_publish_plan dry_run")
        if not isinstance(j, dict) or not j.get("ok") or not j.get("dry_run"):
            raise RuntimeError(f"calendar_publish_plan dry_run: expected ok=true dry_run=true, got: {j}")
        print("OK write(dry) local_aiagentapi_calendar_publish_plan:", f"audit_id={j.get('audit_id')}")

        if args.no_write:
            print("SKIP write local_aiagentapi_calendar_publish_plan (--no-write)")
            return 0

        # 3) Write.
        idk_write = f"testwrite{int(time.time())}{uuid.uuid4().hex[:6]}"
        write_items = [
            {
                "name": "Smoke test plan item (write)",
                "description": "Should create one user calendar event",
                "timestart": now + 600,
                "timeduration": 1800,
            }
        ]
        write_params = {
            "idempotency_key": idk_write,
            "dry_run": "0",
            "reason": "aiagentapi_smoke_test",
            **flatten_items_params(write_items),
        }
        resp = ws_call(base_url=base_url, token=token, wsfunction="local_aiagentapi_calendar_publish_plan", params=write_params, timeout_s=args.timeout)
        j = expect_json(resp, "calendar_publish_plan write")
        if not isinstance(j, dict) or not j.get("ok") or j.get("dry_run"):
            raise RuntimeError(f"calendar_publish_plan write: expected ok=true dry_run=false, got: {j}")
        created = (((j.get("data") or {}) if isinstance(j.get("data"), dict) else {}).get("created_events")) or []
        if not isinstance(created, list) or len(created) < 1:
            raise RuntimeError(f"calendar_publish_plan write: expected created_events, got: {j}")
        event_id = created[0].get("id") if isinstance(created[0], dict) else None
        print("OK write local_aiagentapi_calendar_publish_plan:", f"audit_id={j.get('audit_id')}", f"event_id={event_id}")

        return 0
    finally:
        if proc is not None and not args.keep_server:
            stop_php_server(proc)


if __name__ == "__main__":
    raise SystemExit(main())
