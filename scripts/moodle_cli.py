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
import csv
import getpass
import json
import os
import platform
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import webbrowser
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

VERSION = "0.9.0"

CONFIG_SCHEMA_VERSION = 1
DEFAULT_SERVICE_SHORTNAME = "local_aiagentapi"
DEFAULT_PROFILE_NAME = "default"
PROFILE_NAME_RE = re.compile(r"^[A-Za-z0-9._-]+$")
KEYCHAIN_SERVICE = "moodle-cli"

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
    "identity",
    "meta",
    "data",
    "_notice",
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
    def __init__(
        self,
        message: str,
        exit_code: int = EXIT_ERROR,
        *,
        error_type: str = "",
        error_code: Optional[Any] = None,
        hint: str = "",
        detail: Optional[Any] = None,
    ) -> None:
        super().__init__(message)
        self.exit_code = exit_code
        self.error_type = str(error_type or "")
        self.error_code = error_code
        self.hint = str(hint or "")
        self.detail = detail


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


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def resolve_default_config_dir() -> str:
    xdg = os.environ.get("XDG_CONFIG_HOME", "").strip()
    if xdg:
        return os.path.join(os.path.expanduser(xdg), "moodle-cli")
    return os.path.join(os.path.expanduser("~/.config"), "moodle-cli")


def resolve_config_dir(raw: str, env: Dict[str, str]) -> str:
    value = (
        raw
        or env.get("MOODLE_CLI_CONFIG_DIR")
        or os.environ.get("MOODLE_CLI_CONFIG_DIR", "")
        or resolve_default_config_dir()
    )
    return os.path.abspath(os.path.expanduser(value))


def config_path_for_dir(config_dir: str) -> str:
    return os.path.join(config_dir, "config.json")


def blank_cli_config() -> Dict[str, Any]:
    return {
        "version": CONFIG_SCHEMA_VERSION,
        "current_profile": "",
        "profiles": {},
    }


def normalize_profile_name(name: str) -> str:
    value = (name or "").strip()
    if not value:
        return DEFAULT_PROFILE_NAME
    if not PROFILE_NAME_RE.fullmatch(value):
        raise CliError(
            "invalid profile name: use letters, numbers, dot, underscore, or dash",
            EXIT_USAGE,
        )
    return value


def load_cli_config(config_dir: str) -> Dict[str, Any]:
    path = config_path_for_dir(config_dir)
    if not os.path.exists(path):
        return blank_cli_config()
    try:
        with open(path, "r", encoding="utf-8") as f:
            loaded = json.load(f)
    except json.JSONDecodeError as e:
        raise CliError(f"invalid CLI config JSON: {e}", EXIT_CONFIG) from e
    except OSError as e:
        raise CliError(f"cannot read CLI config: {e}", EXIT_CONFIG) from e
    if not isinstance(loaded, dict):
        raise CliError("invalid CLI config: expected top-level object", EXIT_CONFIG)
    config = blank_cli_config()
    config["version"] = int(loaded.get("version") or CONFIG_SCHEMA_VERSION)
    config["current_profile"] = str(loaded.get("current_profile") or "")
    profiles = loaded.get("profiles")
    if isinstance(profiles, dict):
        config["profiles"] = {
            str(name): value
            for name, value in profiles.items()
            if isinstance(name, str) and isinstance(value, dict)
        }
    return config


def save_cli_config(config_dir: str, config: Dict[str, Any]) -> None:
    os.makedirs(config_dir, exist_ok=True)
    path = config_path_for_dir(config_dir)
    tmp_path = f"{path}.{uuid.uuid4().hex}.tmp"
    try:
        os.chmod(config_dir, 0o700)
    except OSError:
        pass
    try:
        with open(tmp_path, "w", encoding="utf-8") as f:
            json.dump(config, f, ensure_ascii=False, indent=2, sort_keys=True)
            f.write("\n")
        try:
            os.chmod(tmp_path, 0o600)
        except OSError:
            pass
        os.replace(tmp_path, path)
    except OSError as e:
        raise CliError(f"cannot write CLI config: {e}", EXIT_CONFIG) from e


def keychain_available() -> bool:
    if env_bool("MOODLE_CLI_DISABLE_KEYCHAIN"):
        return False
    if platform.system().lower() != "darwin":
        return False
    return os.path.exists("/usr/bin/security")


def keychain_set(account: str, secret: str) -> None:
    if not keychain_available():
        raise CliError("keychain not available", EXIT_CONFIG)
    proc = subprocess.run(
        ["/usr/bin/security", "add-generic-password", "-U", "-a", account, "-s", KEYCHAIN_SERVICE, "-w", secret],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        message = (proc.stderr or proc.stdout or "keychain write failed").strip()
        raise CliError(f"cannot store token in keychain: {message}", EXIT_CONFIG)


def keychain_get(account: str) -> str:
    if not keychain_available():
        return ""
    proc = subprocess.run(
        ["/usr/bin/security", "find-generic-password", "-a", account, "-s", KEYCHAIN_SERVICE, "-w"],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        return ""
    return (proc.stdout or "").strip()


def keychain_remove(account: str) -> None:
    if not keychain_available():
        return
    subprocess.run(
        ["/usr/bin/security", "delete-generic-password", "-a", account, "-s", KEYCHAIN_SERVICE],
        capture_output=True,
        text=True,
        check=False,
    )


def profile_token_account(profile_name: str, profile: Dict[str, Any]) -> str:
    base_url = normalize_base_url(str(profile.get("base_url") or ""))
    username = str(profile.get("username") or "")
    service = str(profile.get("service") or DEFAULT_SERVICE_SHORTNAME)
    return f"profile:{profile_name}:{base_url}:{service}:{username}"


def resolve_profile_token(profile_name: str, profile: Dict[str, Any]) -> str:
    storage = str(profile.get("token_storage") or "")
    if storage == "keychain":
        account = str(profile.get("token_account") or profile_token_account(profile_name, profile))
        return keychain_get(account)
    return str(profile.get("token") or "")


def clear_profile_token(profile_name: str, profile: Dict[str, Any]) -> Dict[str, Any]:
    updated = dict(profile)
    account = str(updated.get("token_account") or profile_token_account(profile_name, updated))
    if str(updated.get("token_storage") or "") == "keychain":
        keychain_remove(account)
    for key in ("token", "token_account", "token_storage"):
        updated.pop(key, None)
    return updated


def store_profile_token(profile_name: str, profile: Dict[str, Any], token: str) -> Tuple[Dict[str, Any], str]:
    updated = clear_profile_token(profile_name, profile)
    if keychain_available():
        account = profile_token_account(profile_name, updated)
        keychain_set(account, token)
        updated["token_storage"] = "keychain"
        updated["token_account"] = account
        return updated, "keychain"
    updated["token_storage"] = "file"
    updated["token"] = token
    return updated, "file"


def get_profile(config: Dict[str, Any], profile_name: str) -> Dict[str, Any]:
    profiles = config.get("profiles")
    if not isinstance(profiles, dict):
        return {}
    value = profiles.get(profile_name)
    return value if isinstance(value, dict) else {}


def set_profile(config: Dict[str, Any], profile_name: str, profile: Dict[str, Any]) -> None:
    profiles = config.setdefault("profiles", {})
    if not isinstance(profiles, dict):
        config["profiles"] = {}
        profiles = config["profiles"]
    profiles[profile_name] = profile


def delete_profile(config: Dict[str, Any], profile_name: str) -> bool:
    profiles = config.get("profiles")
    if not isinstance(profiles, dict) or profile_name not in profiles:
        return False
    del profiles[profile_name]
    if config.get("current_profile") == profile_name:
        config["current_profile"] = ""
    return True


def summarize_profile(profile_name: str, profile: Dict[str, Any], *, is_current: bool = False) -> Dict[str, Any]:
    token = resolve_profile_token(profile_name, profile)
    storage = str(profile.get("token_storage") or ("file" if profile.get("token") else ""))
    return {
        "name": profile_name,
        "is_current": is_current,
        "base_url": str(profile.get("base_url") or ""),
        "service": str(profile.get("service") or DEFAULT_SERVICE_SHORTNAME),
        "username": str(profile.get("username") or ""),
        "full_name": str(profile.get("full_name") or ""),
        "user_id": int(profile.get("user_id") or 0),
        "has_token": bool(token),
        "token_storage": storage,
        "token_preview": redact_token(token),
        "last_login_at": str(profile.get("last_login_at") or ""),
        "last_verified_at": str(profile.get("last_verified_at") or ""),
    }


def redact_token(token: str) -> str:
    if not token:
        return ""
    if len(token) <= 8:
        return "*" * len(token)
    return f"{token[:4]}...{token[-4:]}"


def decode_json_bytes(raw: bytes, *, failure_message: str, exit_code: int) -> Any:
    text = raw.decode("utf-8", errors="replace").strip()
    try:
        return json.loads(text)
    except Exception:
        match = re.search(r"(\{[\s\S]*\}|\[[\s\S]*\])\s*$", text)
        if match:
            try:
                return json.loads(match.group(1))
            except Exception:
                pass
        sample = text[:300]
        raise CliError(f"{failure_message}: {sample}", exit_code)


def prompt_text(label: str, *, default: str = "", secret: bool = False) -> str:
    hint = f" [{default}]" if default else ""
    if secret:
        value = getpass.getpass(f"{label}{hint}: ")
    else:
        value = input(f"{label}{hint}: ")
    value = value.strip()
    return value or default


def resolve_target_profile(args: argparse.Namespace) -> str:
    return normalize_profile_name(getattr(args, "profile_name", "") or args.profile)


def request_login_token(
    base_url: str,
    username: str,
    password: str,
    service: str,
    timeout: float,
) -> Dict[str, Any]:
    endpoint = f"{base_url}/login/token.php"
    payload = urllib.parse.urlencode({
        "username": username,
        "password": password,
        "service": service,
    }).encode("utf-8")
    req = urllib.request.Request(endpoint, method="POST", data=payload)
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read()
            status = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        status = e.code
    except urllib.error.URLError as e:
        raise CliError(f"login failed: {e.reason}", EXIT_RETRYABLE) from e
    except Exception as e:
        raise CliError(f"login failed: {e}", EXIT_RETRYABLE) from e

    decoded = decode_json_bytes(
        raw,
        failure_message="login failed: expected JSON response",
        exit_code=EXIT_RETRYABLE,
    )
    if not isinstance(decoded, dict):
        raise CliError("login failed: unexpected response payload", EXIT_AUTH_REQUIRED)
    if decoded.get("token"):
        return decoded
    message = str(
        decoded.get("error")
        or decoded.get("error_description")
        or decoded.get("debuginfo")
        or decoded.get("message")
        or "invalid login"
    )
    exit_code = EXIT_AUTH_REQUIRED if status < 500 else EXIT_RETRYABLE
    raise CliError(f"login failed: {message}", exit_code)


def device_flow_endpoint(base_url: str) -> str:
    return f"{base_url}/local/aiagentapi/device_flow.php"


def request_device_authorization(base_url: str, service: str, timeout: float) -> Dict[str, Any]:
    endpoint = device_flow_endpoint(base_url)
    payload = urllib.parse.urlencode({
        "action": "start",
        "service": service,
    }).encode("utf-8")
    req = urllib.request.Request(endpoint, method="POST", data=payload)
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read()
            status = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        status = e.code
    except urllib.error.URLError as e:
        raise CliError(f"device login failed: {e.reason}", EXIT_RETRYABLE) from e
    decoded = decode_json_bytes(
        raw,
        failure_message="device login failed: expected JSON response",
        exit_code=EXIT_RETRYABLE,
    )
    if not isinstance(decoded, dict):
        raise CliError("device login failed: unexpected response payload", EXIT_RETRYABLE)
    if decoded.get("device_code"):
        return decoded
    message = str(decoded.get("error_description") or decoded.get("error") or "device authorization failed")
    exit_code = EXIT_ERROR if status < 500 else EXIT_RETRYABLE
    raise CliError(message, exit_code)


def poll_device_authorization_once(base_url: str, device_code: str, timeout: float) -> Dict[str, Any]:
    endpoint = device_flow_endpoint(base_url)
    payload = urllib.parse.urlencode({
        "action": "poll",
        "device_code": device_code,
    }).encode("utf-8")
    req = urllib.request.Request(endpoint, method="POST", data=payload)
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read()
            status = resp.status
    except urllib.error.HTTPError as e:
        raw = e.read()
        status = e.code
    except urllib.error.URLError as e:
        raise CliError(f"device login poll failed: {e.reason}", EXIT_RETRYABLE) from e
    decoded = decode_json_bytes(
        raw,
        failure_message="device login poll failed: expected JSON response",
        exit_code=EXIT_RETRYABLE,
    )
    if not isinstance(decoded, dict):
        raise CliError("device login poll failed: unexpected response payload", EXIT_RETRYABLE)
    decoded["_http_status"] = status
    return decoded


def wait_for_device_authorization(
    base_url: str,
    device_code: str,
    *,
    interval: int,
    expires_in: int,
    timeout: float,
) -> Dict[str, Any]:
    deadline = time.monotonic() + max(1, int(expires_in))
    poll_every = max(1, int(interval))
    while time.monotonic() < deadline:
        payload = poll_device_authorization_once(base_url, device_code, timeout)
        if payload.get("access_token"):
            return payload
        error = str(payload.get("error") or "")
        if error == "authorization_pending":
            time.sleep(poll_every)
            continue
        if error == "access_denied":
            raise CliError(str(payload.get("error_description") or "authorization denied"), EXIT_AUTH_REQUIRED)
        if error in {"expired_token", "invalid_grant"}:
            raise CliError(str(payload.get("error_description") or "device code expired"), EXIT_AUTH_REQUIRED)
        raise CliError(str(payload.get("error_description") or error or "device login failed"), EXIT_ERROR)
    raise CliError("device authorization timed out", EXIT_AUTH_REQUIRED)


def verify_profile_session(base_url: str, token: str, timeout: float) -> Dict[str, Any]:
    return invoke_ws(base_url, token, "local_aiagentapi_get_user_context", {}, timeout)


def probe_url(url: str, timeout: float, *, method: str = "GET") -> Tuple[bool, str]:
    req = urllib.request.Request(url, method=method)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return True, f"HTTP {resp.status}"
    except urllib.error.HTTPError as e:
        if e.code < 500:
            return True, f"HTTP {e.code}"
        return False, f"HTTP {e.code}"
    except Exception as e:
        return False, str(e)


def parse_http_status(detail: str) -> Optional[int]:
    match = re.search(r"\bHTTP\s+(\d{3})\b", detail)
    if not match:
        return None
    try:
        return int(match.group(1))
    except ValueError:
        return None


def persist_login_result(
    args: argparse.Namespace,
    profile_name: str,
    profile: Dict[str, Any],
    *,
    base_url: str,
    service: str,
    token: str,
    verified: Dict[str, Any],
) -> Dict[str, Any]:
    user = verified.get("data", {}).get("user", {}) if isinstance(verified, dict) else {}
    profile.update({
        "base_url": base_url,
        "service": service,
        "username": str(user.get("username") or str(profile.get("username") or "")),
        "full_name": str(user.get("fullname") or ""),
        "user_id": int(user.get("userid") or 0),
        "last_login_at": utc_now_iso(),
        "last_verified_at": utc_now_iso(),
    })
    profile, storage_backend = store_profile_token(profile_name, profile, token)
    set_profile(args.cli_config, profile_name, profile)
    args.cli_config["current_profile"] = profile_name
    save_cli_config(args.config_dir, args.cli_config)
    return {
        "ok": True,
        "storage_backend": storage_backend,
        "profile": summarize_profile(profile_name, profile, is_current=True),
        "user": user,
        "context": verified.get("data", {}).get("context", {}) if isinstance(verified, dict) else {},
    }


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

    decoded = decode_json_bytes(
        raw,
        failure_message="expected JSON response but got",
        exit_code=EXIT_RETRYABLE,
    )

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

    # Support both legacy Moodle envelope and Feishu-style envelope.
    if "data" in value and "ok" in value:
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


def command_path_starts_with(args: argparse.Namespace, prefix: Sequence[str]) -> bool:
    path = list(getattr(args, "command_path", []) or [])
    if len(path) < len(prefix):
        return False
    return path[: len(prefix)] == list(prefix)


def command_path_equals(args: argparse.Namespace, target: Sequence[str]) -> bool:
    return list(getattr(args, "command_path", []) or []) == list(target)


def coerce_bool(value: Any) -> bool:
    return bool(value)


def iso_from_unix(value: Any) -> str:
    try:
        timestamp = int(value or 0)
    except Exception:
        return ""
    if timestamp <= 0:
        return ""
    return datetime.fromtimestamp(timestamp, tz=timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def safe_int(value: Any, default: int = 0) -> int:
    try:
        return int(value)
    except Exception:
        return default


def compact_text(value: Any, *, limit: int = 120) -> str:
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    if len(text) <= limit:
        return text
    return text[: limit - 1].rstrip() + "…"


def top_module_mix(module_stats: Sequence[Dict[str, Any]], *, limit: int = 3) -> str:
    parts: List[str] = []
    for item in list(module_stats)[:limit]:
        modname = str(item.get("type") or item.get("modname") or "")
        count = safe_int(item.get("count"))
        if not modname:
            continue
        parts.append(f"{modname}:{count}")
    return ", ".join(parts)


KNOWN_ARRAY_FIELDS: Sequence[str] = (
    "items",
    "results",
    "courses",
    "sections",
    "activities",
    "resources",
    "quizzes",
    "notes",
    "downloads",
)


def with_items_alias(data: Dict[str, Any], primary_key: str) -> Dict[str, Any]:
    out = dict(data)
    primary = out.get(primary_key)
    if isinstance(primary, list):
        out["items"] = primary
    return out


def find_array_field(data: Dict[str, Any]) -> str:
    for name in KNOWN_ARRAY_FIELDS:
        if isinstance(data.get(name), list):
            return name
    candidates = sorted(key for key, val in data.items() if isinstance(val, list))
    return candidates[0] if candidates else ""


def extract_items_for_output(value: Any) -> Optional[List[Any]]:
    if isinstance(value, list):
        return list(value)
    if not isinstance(value, dict):
        return None
    data_obj = value.get("data")
    if isinstance(data_obj, dict):
        field = find_array_field(data_obj)
        if field:
            return list(data_obj.get(field) or [])
    field = find_array_field(value)
    if field:
        return list(value.get(field) or [])
    return None


def build_cli_envelope(
    *,
    ok: bool,
    data: Dict[str, Any],
    meta: Optional[Dict[str, Any]] = None,
    error: Optional[Dict[str, Any]] = None,
    identity: str = "user",
) -> Dict[str, Any]:
    envelope: Dict[str, Any] = {
        "ok": ok,
        "identity": identity,
        "data": data,
    }
    if meta:
        envelope["meta"] = meta
    if error:
        envelope["error"] = error
    return envelope


ERROR_TYPE_BY_EXIT: Dict[int, str] = {
    EXIT_ERROR: "api_error",
    EXIT_USAGE: "validation",
    EXIT_EMPTY_RESULTS: "empty_results",
    EXIT_AUTH_REQUIRED: "auth",
    EXIT_NOT_FOUND: "not_found",
    EXIT_PERMISSION_DENIED: "permission",
    EXIT_RATE_LIMITED: "rate_limit",
    EXIT_RETRYABLE: "network",
    EXIT_CONFIG: "config",
    EXIT_CANCELLED: "cancelled",
}


def build_error_envelope(error: CliError, args: Optional[argparse.Namespace]) -> Dict[str, Any]:
    identity = "anonymous"
    if args is not None:
        token = str(getattr(args, "token", "") or "")
        identity = "user" if token else "anonymous"
    err: Dict[str, Any] = {
        "type": error.error_type or ERROR_TYPE_BY_EXIT.get(error.exit_code, "error"),
        "code": error.error_code if error.error_code is not None else error.exit_code,
        "message": str(error),
    }
    if error.hint:
        err["hint"] = error.hint
    if error.detail is not None:
        err["detail"] = error.detail
    return {
        "ok": False,
        "identity": identity,
        "error": err,
    }


def error_output_uses_envelope(args: Optional[argparse.Namespace]) -> bool:
    if args is None:
        return not sys.stderr.isatty()
    if bool(getattr(args, "plain", False)):
        return False
    if bool(getattr(args, "json", False)):
        return True
    fmt = str(getattr(args, "format", "") or "").strip().lower()
    if fmt in {"json", "table", "csv", "ndjson"}:
        return True
    return not sys.stderr.isatty()


def emit_cli_error(error: CliError, args: Optional[argparse.Namespace]) -> None:
    if error_output_uses_envelope(args):
        print(json.dumps(build_error_envelope(error, args), ensure_ascii=False, indent=2), file=sys.stderr)
        return
    _eprint(str(error))


def normalize_course_item(course: Dict[str, Any]) -> Dict[str, Any]:
    category = {
        "id": safe_int(course.get("categoryid")),
        "name": str(course.get("categoryname") or ""),
        "path": str(course.get("categorypath") or ""),
        "display_path": str(course.get("categorydisplaypath") or ""),
        "path_names": list(course.get("categorypathnames") or []),
    }
    semantic = dict(course.get("semantic") or {})
    module_stats = [
        {
            "type": str(item.get("modname") or ""),
            "count": safe_int(item.get("count")),
        }
        for item in list(semantic.get("module_stats") or [])
    ]
    learning = {
        "course_type": str(semantic.get("course_type") or ""),
        "learning_mode": str(semantic.get("learning_mode") or ""),
        "agent_strategy": str(semantic.get("agent_strategy") or ""),
        "confidence": str(semantic.get("confidence") or ""),
        "reasons": list(semantic.get("reasons") or []),
    }
    item: Dict[str, Any] = {
        "resource_type": "course",
        "id": safe_int(course.get("id")),
        "title": str(course.get("fullname") or ""),
        "code": str(course.get("shortname") or ""),
        "format": str(course.get("format") or ""),
        "language": str(course.get("lang") or ""),
        "category": category,
        "completion_enabled": coerce_bool(course.get("enablecompletion")),
        "learning": learning,
        "module_stats": module_stats,
    }
    if "visible" in course or "startdate" in course or "enddate" in course:
        item["availability"] = {
            "visible": coerce_bool(course.get("visible")),
            "start_at": iso_from_unix(course.get("startdate")),
            "end_at": iso_from_unix(course.get("enddate")),
        }
    return item


def normalize_course_module(module: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "module",
        "id": safe_int(module.get("cmid")),
        "instance_id": safe_int(module.get("instance")),
        "kind": str(module.get("modname") or ""),
        "title": str(module.get("name") or ""),
        "visible": coerce_bool(module.get("uservisible", module.get("visible"))),
        "url": str(module.get("url") or ""),
    }


def normalize_course_section(section: Dict[str, Any]) -> Dict[str, Any]:
    modules = [normalize_course_module(item) for item in list(section.get("modules") or [])]
    return {
        "resource_type": "section",
        "id": safe_int(section.get("id")),
        "index": safe_int(section.get("sectionnum")),
        "title": str(section.get("name") or ""),
        "summary": str(section.get("summary") or ""),
        "module_count": len(modules),
        "modules": modules,
    }


def normalize_activity_item(activity: Dict[str, Any], *, resource_type: str = "activity") -> Dict[str, Any]:
    return {
        "resource_type": resource_type,
        "id": safe_int(activity.get("cmid")),
        "instance_id": safe_int(activity.get("instance")),
        "kind": str(activity.get("modname") or ""),
        "title": str(activity.get("name") or ""),
        "section": safe_int(activity.get("sectionnum")),
        "visible": coerce_bool(activity.get("visible", activity.get("uservisible"))),
        "url": str(activity.get("url") or ""),
        "external_url": str(activity.get("externalurl") or ""),
        "summary": compact_text(activity.get("summaryhtml"), limit=120),
        "open_at": iso_from_unix(activity.get("openfrom")),
        "due_at": iso_from_unix(activity.get("dueto")),
        "completion_expected_at": iso_from_unix(activity.get("completionexpected")),
    }


def normalize_quiz_item(quiz: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "quiz",
        "id": safe_int(quiz.get("quizid")),
        "activity_id": safe_int(quiz.get("cmid")),
        "title": str(quiz.get("name") or ""),
        "section": safe_int(quiz.get("sectionnum")),
        "visible": coerce_bool(quiz.get("visible")),
        "url": str(quiz.get("url") or ""),
    }


def normalize_due_activity_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "activity_due",
        "id": safe_int(item.get("cmid")),
        "instance_id": safe_int(item.get("instance")),
        "kind": str(item.get("modname") or ""),
        "title": str(item.get("name") or ""),
        "course_id": safe_int(item.get("courseid")),
        "course_code": str(item.get("courseshortname") or ""),
        "course_title": str(item.get("coursefullname") or ""),
        "due_type": str(item.get("duetype") or ""),
        "due_at": iso_from_unix(item.get("duetime")),
        "is_overdue": coerce_bool(item.get("overdue")),
        "url": str(item.get("url") or ""),
    }


def normalize_assignment_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "assignment",
        "id": safe_int(item.get("assignid")),
        "activity_id": safe_int(item.get("cmid")),
        "title": str(item.get("name") or ""),
        "section": safe_int(item.get("sectionnum")),
        "visible": coerce_bool(item.get("visible")),
        "url": str(item.get("url") or ""),
        "open_at": iso_from_unix(item.get("allowsubmissionsfromdate")),
        "due_at": iso_from_unix(item.get("duedate")),
        "cutoff_at": iso_from_unix(item.get("cutoffdate")),
        "grading_due_at": iso_from_unix(item.get("gradingduedate")),
        "always_show_description": coerce_bool(item.get("alwaysshowdescription")),
        "team_submission": coerce_bool(item.get("teamsubmission")),
        "draft_enabled": coerce_bool(item.get("submissiondrafts")),
    }


def normalize_assignment_status_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "assignment_status",
        "id": safe_int(item.get("assignid")),
        "activity_id": safe_int(item.get("cmid")),
        "course_id": safe_int(item.get("courseid")),
        "course_code": str(item.get("courseshortname") or ""),
        "title": str(item.get("name") or ""),
        "window_status": str(item.get("windowstatus") or ""),
        "submission_status": str(item.get("submissionstatus") or ""),
        "submitted_at": iso_from_unix(item.get("submittedat")),
        "updated_at": iso_from_unix(item.get("timemodified")),
        "attempt": safe_int(item.get("attemptnumber")),
        "open_at": iso_from_unix(item.get("allowsubmissionsfromdate")),
        "due_at": iso_from_unix(item.get("duedate")),
        "cutoff_at": iso_from_unix(item.get("cutoffdate")),
        "grading_due_at": iso_from_unix(item.get("gradingduedate")),
        "is_overdue": coerce_bool(item.get("isoverdue")),
        "is_graded": coerce_bool(item.get("isgraded")),
        "grade": item.get("grade"),
        "max_grade": item.get("maxgrade"),
        "url": str(item.get("url") or ""),
    }


def normalize_calendar_event_item(item: Dict[str, Any]) -> Dict[str, Any]:
    start_at = iso_from_unix(item.get("timestart"))
    duration = safe_int(item.get("timeduration"))
    end_at = ""
    if start_at and duration > 0:
        end_at = iso_from_unix(safe_int(item.get("timestart")) + duration)
    return {
        "resource_type": "calendar_event",
        "id": safe_int(item.get("id")),
        "title": str(item.get("name") or ""),
        "event_type": str(item.get("eventtype") or ""),
        "start_at": start_at,
        "end_at": end_at,
        "duration_seconds": duration,
        "course_id": safe_int(item.get("courseid")),
        "group_id": safe_int(item.get("groupid")),
        "user_id": safe_int(item.get("userid")),
        "visible": coerce_bool(item.get("visible")),
        "url": str(item.get("url") or ""),
    }


def normalize_forum_discussion_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "forum_discussion",
        "id": safe_int(item.get("discussionid")),
        "post_id": safe_int(item.get("postid")),
        "course_id": safe_int(item.get("courseid")),
        "course_code": str(item.get("courseshortname") or ""),
        "forum_id": safe_int(item.get("forumid")),
        "forum_title": str(item.get("forumname") or ""),
        "title": str(item.get("subject") or ""),
        "author": str(item.get("userfullname") or ""),
        "created_at": iso_from_unix(item.get("created")),
        "updated_at": iso_from_unix(item.get("timemodified") or item.get("modified")),
        "replies": safe_int(item.get("numreplies")),
        "unread": safe_int(item.get("numunread")),
        "pinned": coerce_bool(item.get("pinned")),
        "locked": coerce_bool(item.get("locked")),
        "can_reply": coerce_bool(item.get("canreply")),
        "url": str(item.get("url") or ""),
    }


def normalize_notification_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "notification",
        "id": safe_int(item.get("id")),
        "from_user_id": safe_int(item.get("useridfrom")),
        "to_user_id": safe_int(item.get("useridto")),
        "title": str(item.get("subject") or ""),
        "summary": compact_text(item.get("smallmessage"), limit=120),
        "message": compact_text(item.get("fullmessage"), limit=200),
        "component": str(item.get("component") or ""),
        "event_type": str(item.get("eventtype") or ""),
        "created_at": iso_from_unix(item.get("timecreated")),
        "read_at": iso_from_unix(item.get("timeread")),
        "is_read": coerce_bool(item.get("read")),
    }


def normalize_question_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "question",
        "id": safe_int(item.get("id")),
        "title": str(item.get("name") or ""),
        "kind": str(item.get("qtype") or ""),
        "category_id": safe_int(item.get("categoryid")),
        "category_name": str(item.get("categoryname") or ""),
        "default_mark": item.get("defaultmark"),
        "created_at": iso_from_unix(item.get("timecreated")),
        "updated_at": iso_from_unix(item.get("timemodified")),
    }


def normalize_activity_detail_item(item: Dict[str, Any]) -> Dict[str, Any]:
    normalized = normalize_activity_item(item, resource_type="activity")
    normalized["content"] = {
        "summary_html": str(item.get("summaryhtml") or ""),
        "content_html": str(item.get("contenthtml") or ""),
    }
    return normalized


def normalize_quiz_attempt_entry(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "attempt_id": safe_int(item.get("attemptid")),
        "number": safe_int(item.get("attempt")),
        "state": str(item.get("state") or ""),
        "started_at": iso_from_unix(item.get("timestart")),
        "finished_at": iso_from_unix(item.get("timefinish")),
        "sum_grades": item.get("sumgrades"),
    }


def normalize_quiz_attempts_item(item: Dict[str, Any]) -> Dict[str, Any]:
    attempts = [normalize_quiz_attempt_entry(entry) for entry in list(item.get("attempts") or [])]
    return {
        "resource_type": "quiz_attempt_group",
        "id": safe_int(item.get("quizid")),
        "activity_id": safe_int(item.get("cmid")),
        "course_id": safe_int(item.get("courseid")),
        "course_code": str(item.get("courseshortname") or ""),
        "title": str(item.get("name") or ""),
        "open_at": iso_from_unix(item.get("timeopen")),
        "close_at": iso_from_unix(item.get("timeclose")),
        "attempts_allowed": safe_int(item.get("attemptsallowed")),
        "attempts_made": safe_int(item.get("attemptsmade")),
        "attempts_left": safe_int(item.get("attemptsleft")),
        "best_grade": item.get("bestgrade"),
        "max_grade": item.get("grademax"),
        "has_unfinished": coerce_bool(item.get("hasunfinished")),
        "url": str(item.get("url") or ""),
        "attempt_count": len(attempts),
        "attempts": attempts,
    }


def normalize_grade_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "grade_item",
        "id": safe_int(item.get("itemid")),
        "title": str(item.get("itemname") or ""),
        "item_type": str(item.get("itemtype") or ""),
        "module": str(item.get("itemmodule") or ""),
        "instance_id": safe_int(item.get("iteminstance")),
        "grade": item.get("grade"),
        "grade_min": item.get("grademin"),
        "grade_max": item.get("grademax"),
        "percentage": item.get("percentage"),
        "feedback": compact_text(item.get("feedback"), limit=160),
        "graded_at": iso_from_unix(item.get("dategraded")),
    }


def normalize_grade_course_item(item: Dict[str, Any]) -> Dict[str, Any]:
    grade_items = [normalize_grade_item(entry) for entry in list(item.get("items") or [])]
    return {
        "resource_type": "grade_course",
        "id": safe_int(item.get("courseid")),
        "code": str(item.get("courseshortname") or ""),
        "title": str(item.get("coursefullname") or ""),
        "course_grade": item.get("coursegrade"),
        "grade_min": item.get("grademin"),
        "grade_max": item.get("grademax"),
        "graded_items_count": len(grade_items),
        "items": grade_items,
    }


def normalize_progress_status_item(item: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "resource_type": "progress_status",
        "id": safe_int(item.get("cmid")),
        "kind": str(item.get("modname") or ""),
        "instance_id": safe_int(item.get("instance")),
        "state": safe_int(item.get("state")),
        "completed_at": iso_from_unix(item.get("timecompleted")),
        "tracking": safe_int(item.get("tracking")),
        "is_overall_complete": coerce_bool(item.get("isoverallcomplete")),
        "visible": coerce_bool(item.get("uservisible")),
    }


def normalize_progress_course_item(item: Dict[str, Any]) -> Dict[str, Any]:
    statuses = [normalize_progress_status_item(entry) for entry in list(item.get("statuses") or [])]
    return {
        "resource_type": "progress_course",
        "id": safe_int(item.get("courseid")),
        "code": str(item.get("courseshortname") or ""),
        "title": str(item.get("coursefullname") or ""),
        "completion_enabled": coerce_bool(item.get("completionenabled")),
        "progress_percent": item.get("progresspercent"),
        "completed_count": safe_int(item.get("completedcount")),
        "total_count": safe_int(item.get("totalcount")),
        "statuses": statuses,
    }


def summarize_outline_modules(sections: Sequence[Dict[str, Any]]) -> List[Dict[str, Any]]:
    counts: Dict[str, int] = {}
    for section in sections:
        for module in list(section.get("modules") or []):
            kind = str(module.get("kind") or "")
            if not kind:
                continue
            counts[kind] = counts.get(kind, 0) + 1
    stats = [{"type": kind, "count": count} for kind, count in sorted(counts.items())]
    stats.sort(key=lambda item: (-item["count"], item["type"]))
    return stats


def build_course_route(course: Dict[str, Any], sections: Sequence[Dict[str, Any]]) -> Dict[str, Any]:
    learning = dict(course.get("learning") or {})
    course_type = str(learning.get("course_type") or "")
    strategy = str(learning.get("agent_strategy") or "")
    first_nonempty_section = next((section for section in sections if section.get("modules")), None)
    first_section_index = safe_int(first_nonempty_section.get("index")) if first_nonempty_section else 0
    notes: List[str] = []
    if course_type == "question_bank":
        notes.append("Start from quizzes and question-bank modules before reading support materials.")
    elif course_type == "video_course":
        notes.append("Start from lesson-style page/resource modules and consume content in section order.")
    elif course_type == "trial_course":
        notes.append("Treat this as a preview course and focus on low-friction introductory content.")
    elif course_type == "mixed_course":
        notes.append("Alternate between lesson modules and practice modules rather than using a single path.")
    else:
        notes.append("Use section order as the default navigation path.")
    return {
        "strategy": strategy,
        "entry_section": first_section_index,
        "notes": notes,
    }


def transform_courses_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    courses = [normalize_course_item(item) for item in list(raw_data.get("courses") or [])]
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"courses": courses}, "courses"),
        meta={
            "count": len(courses),
            "primary_resource": "courses",
        },
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_courses_outline_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    course = normalize_course_item(dict(raw_data.get("course") or {}))
    sections = [normalize_course_section(item) for item in list(raw_data.get("sections") or [])]
    module_stats = summarize_outline_modules(sections)
    module_count = sum(item.get("module_count", 0) for item in sections)
    outline = {
        "sections": sections,
        "content_summary": {
            "section_count": len(sections),
            "module_count": module_count,
            "module_stats": module_stats,
        },
        "route": build_course_route(course, sections),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({
            "course": course,
            "outline": outline,
            "sections": sections,
        }, "sections"),
        meta={
            "section_count": len(sections),
            "module_count": module_count,
            "primary_resource": "outline",
        },
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_activities_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    course = normalize_course_item(dict(raw_data.get("course") or {}))
    activities = [normalize_activity_item(item, resource_type="activity") for item in list(raw_data.get("activities") or [])]
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"course": course, "activities": activities}, "activities"),
        meta={"count": len(activities), "primary_resource": "activities"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_resources_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    course = normalize_course_item(dict(raw_data.get("course") or {}))
    resources = [normalize_activity_item(item, resource_type="resource") for item in list(raw_data.get("resources") or [])]
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"course": course, "resources": resources}, "resources"),
        meta={"count": len(resources), "primary_resource": "resources"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_quiz_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    course = normalize_course_item(dict(raw_data.get("course") or {}))
    quizzes = [normalize_quiz_item(item) for item in list(raw_data.get("quizzes") or [])]
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"course": course, "quizzes": quizzes}, "quizzes"),
        meta={"count": len(quizzes), "primary_resource": "quizzes"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_course_command_output(value: Any, args: argparse.Namespace) -> Any:
    if command_path_equals(args, ["courses", "list"]):
        return transform_courses_list_result(value)
    if command_path_equals(args, ["courses", "outline"]):
        return transform_courses_outline_result(value)
    if command_path_equals(args, ["activities", "list"]):
        return transform_activities_list_result(value)
    if command_path_equals(args, ["resources", "list"]):
        return transform_resources_list_result(value)
    if command_path_equals(args, ["quiz", "list"]):
        return transform_quiz_list_result(value)
    return value


def transform_activities_due_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    items = [normalize_due_activity_item(item) for item in list(raw_data.get("items") or [])]
    window = {
        "start_at": iso_from_unix(raw_data.get("timestart")),
        "end_at": iso_from_unix(raw_data.get("timeend")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"window": window, "items": items}, "items"),
        meta={"count": len(items), "primary_resource": "activity_due_items"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_assignments_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    course = normalize_course_item(dict(raw_data.get("course") or {}))
    assignments = [normalize_assignment_item(item) for item in list(raw_data.get("assignments") or [])]
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"course": course, "assignments": assignments}, "assignments"),
        meta={"count": len(assignments), "primary_resource": "assignments"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_assignments_status_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    assignments = [normalize_assignment_status_item(item) for item in list(raw_data.get("assignments") or [])]
    filters = {
        "course_id": safe_int(raw_data.get("courseid")),
        "assignment_id": safe_int(raw_data.get("assignid")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"filters": filters, "assignments": assignments}, "assignments"),
        meta={"count": len(assignments), "primary_resource": "assignment_status"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_calendar_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    events = [normalize_calendar_event_item(item) for item in list(raw_data.get("events") or [])]
    window = {
        "start_at": iso_from_unix(raw_data.get("timestart")),
        "end_at": iso_from_unix(raw_data.get("timeend")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"window": window, "events": events}, "events"),
        meta={"count": len(events), "primary_resource": "calendar_events"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_forum_discussions_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    discussions = [normalize_forum_discussion_item(item) for item in list(raw_data.get("discussions") or [])]
    filters = {
        "course_id": safe_int(raw_data.get("courseid")),
        "forum_id": safe_int(raw_data.get("forumid")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"filters": filters, "discussions": discussions}, "discussions"),
        meta={"count": len(discussions), "primary_resource": "forum_discussions"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_notifications_list_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    notifications = [normalize_notification_item(item) for item in list(raw_data.get("notifications") or [])]
    page = {
        "offset": safe_int(raw_data.get("limitfrom")),
        "limit": safe_int(raw_data.get("limitnum")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"page": page, "notifications": notifications}, "notifications"),
        meta={"count": len(notifications), "primary_resource": "notifications"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_questions_search_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    questions = [normalize_question_item(item) for item in list(raw_data.get("questions") or [])]
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"questions": questions}, "questions"),
        meta={"count": len(questions), "primary_resource": "questions"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_activities_detail_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    course = normalize_course_item(dict(raw_data.get("course") or {}))
    activity = normalize_activity_detail_item(dict(raw_data.get("activity") or {}))
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"course": course, "activity": activity, "activities": [activity]}, "activities"),
        meta={"count": 1, "primary_resource": "activity_detail"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_quiz_attempts_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    quizzes = [normalize_quiz_attempts_item(item) for item in list(raw_data.get("quizzes") or [])]
    filters = {
        "course_id": safe_int(raw_data.get("courseid")),
        "quiz_id": safe_int(raw_data.get("quizid")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"filters": filters, "quizzes": quizzes}, "quizzes"),
        meta={"count": len(quizzes), "primary_resource": "quiz_attempt_groups"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_grades_overview_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    courses = [normalize_grade_course_item(item) for item in list(raw_data.get("courses") or [])]
    filters = {
        "course_id": safe_int(raw_data.get("courseid")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"filters": filters, "courses": courses}, "courses"),
        meta={"count": len(courses), "primary_resource": "grade_courses"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_progress_course_result(value: Any) -> Any:
    if not isinstance(value, dict):
        return value
    raw_data = value.get("data")
    if not isinstance(raw_data, dict):
        return value
    courses = [normalize_progress_course_item(item) for item in list(raw_data.get("courses") or [])]
    filters = {
        "course_id": safe_int(raw_data.get("courseid")),
    }
    return build_cli_envelope(
        ok=bool(value.get("ok", True)),
        identity="user",
        data=with_items_alias({"filters": filters, "courses": courses}, "courses"),
        meta={"count": len(courses), "primary_resource": "progress_courses"},
        error=value.get("error") if isinstance(value.get("error"), dict) else None,
    )


def transform_extended_command_output(value: Any, args: argparse.Namespace) -> Any:
    if command_path_equals(args, ["activities", "due"]):
        return transform_activities_due_result(value)
    if command_path_equals(args, ["assignments", "list"]):
        return transform_assignments_list_result(value)
    if command_path_equals(args, ["assignments", "status"]):
        return transform_assignments_status_result(value)
    if command_path_equals(args, ["calendar", "list"]):
        return transform_calendar_list_result(value)
    if command_path_equals(args, ["forum", "discussions"]):
        return transform_forum_discussions_result(value)
    if command_path_equals(args, ["notifications", "list"]):
        return transform_notifications_list_result(value)
    if command_path_equals(args, ["questions", "search"]):
        return transform_questions_search_result(value)
    if command_path_equals(args, ["activities", "detail"]):
        return transform_activities_detail_result(value)
    if command_path_equals(args, ["quiz", "attempts"]):
        return transform_quiz_attempts_result(value)
    if command_path_equals(args, ["grades", "overview"]):
        return transform_grades_overview_result(value)
    if command_path_equals(args, ["progress", "course"]):
        return transform_progress_course_result(value)
    return value


def emit_ndjson_rows(rows: Sequence[Dict[str, Any]]) -> None:
    for row in rows:
        print(json.dumps(row, ensure_ascii=False, separators=(",", ":")))


def emit_csv_rows(rows: Sequence[Dict[str, Any]]) -> None:
    if not rows:
        return
    fieldnames = list(rows[0].keys())
    writer = csv.DictWriter(sys.stdout, fieldnames=fieldnames)
    writer.writeheader()
    for row in rows:
        writer.writerow({key: scalar_to_text(row.get(key)) for key in fieldnames})


def emit_table_rows(rows: Sequence[Dict[str, Any]]) -> None:
    if not rows:
        print("(no data)")
        return
    columns = list(rows[0].keys())
    widths = {
        column: max(len(column), max(len(scalar_to_text(row.get(column))) for row in rows))
        for column in columns
    }
    print("  ".join(column.ljust(widths[column]) for column in columns))
    for row in rows:
        print("  ".join(scalar_to_text(row.get(column)).ljust(widths[column]) for column in columns))


def rows_from_generic_value(value: Any) -> List[Dict[str, Any]]:
    if isinstance(value, list):
        rows: List[Dict[str, Any]] = []
        for item in value:
            if isinstance(item, dict):
                rows.append({str(key): scalar_to_text(val) for key, val in item.items()})
            else:
                rows.append({"value": scalar_to_text(item)})
        return rows
    if isinstance(value, dict):
        return [{str(key): scalar_to_text(val) for key, val in value.items()}]
    return [{"value": scalar_to_text(value)}]


def build_courses_list_rows(value: Dict[str, Any]) -> List[Dict[str, Any]]:
    courses = list(((value.get("data") or {}).get("courses") or []))
    rows: List[Dict[str, Any]] = []
    for course in courses:
        category = dict(course.get("category") or {})
        learning = dict(course.get("learning") or {})
        rows.append({
            "id": safe_int(course.get("id")),
            "title": str(course.get("title") or ""),
            "category": str(category.get("display_path") or category.get("name") or ""),
            "course_type": str(learning.get("course_type") or ""),
            "mode": str(learning.get("learning_mode") or ""),
            "strategy": str(learning.get("agent_strategy") or ""),
            "confidence": str(learning.get("confidence") or ""),
            "module_mix": top_module_mix(list(course.get("module_stats") or [])),
        })
    return rows


def build_courses_outline_section_rows(value: Dict[str, Any]) -> List[Dict[str, Any]]:
    outline = dict(((value.get("data") or {}).get("outline") or {}))
    sections = list(outline.get("sections") or [])
    rows: List[Dict[str, Any]] = []
    for section in sections:
        rows.append({
            "section": safe_int(section.get("index")),
            "title": str(section.get("title") or ""),
            "modules": safe_int(section.get("module_count")),
            "summary": compact_text(section.get("summary"), limit=80),
        })
    return rows


def build_activities_rows(value: Dict[str, Any]) -> List[Dict[str, Any]]:
    activities = list(((value.get("data") or {}).get("activities") or [])
                      or ((value.get("data") or {}).get("resources") or [])
                      or ((value.get("data") or {}).get("quizzes") or []))
    rows: List[Dict[str, Any]] = []
    for item in activities:
        if str(item.get("resource_type") or "") == "quiz":
            rows.append({
                "id": safe_int(item.get("id")),
                "title": str(item.get("title") or ""),
                "section": safe_int(item.get("section")),
                "visible": "yes" if coerce_bool(item.get("visible")) else "no",
                "url": str(item.get("url") or ""),
            })
            continue
        rows.append({
            "id": safe_int(item.get("id")),
            "kind": str(item.get("kind") or ""),
            "title": str(item.get("title") or ""),
            "section": safe_int(item.get("section")),
            "visible": "yes" if coerce_bool(item.get("visible")) else "no",
            "due_at": str(item.get("due_at") or ""),
        })
    return rows


def emit_courses_list_pretty(value: Dict[str, Any]) -> None:
    rows = build_courses_list_rows(value)
    meta = dict(value.get("meta") or {})
    print(f"Courses: {safe_int(meta.get('count'))}")
    if rows:
        print("")
        emit_table_rows(rows)


def emit_courses_outline_pretty(value: Dict[str, Any]) -> None:
    data = dict(value.get("data") or {})
    course = dict(data.get("course") or {})
    outline = dict(data.get("outline") or {})
    learning = dict(course.get("learning") or {})
    category = dict(course.get("category") or {})
    route = dict(outline.get("route") or {})
    summary = dict(outline.get("content_summary") or {})

    print(str(course.get("title") or ""))
    if course.get("code"):
        print(f"Code: {course.get('code')}")
    if category.get("display_path") or category.get("name"):
        print(f"Category: {category.get('display_path') or category.get('name')}")
    print(
        "Type: {course_type} | Mode: {mode} | Strategy: {strategy} | Confidence: {confidence}".format(
            course_type=learning.get("course_type") or "",
            mode=learning.get("learning_mode") or "",
            strategy=learning.get("agent_strategy") or "",
            confidence=learning.get("confidence") or "",
        )
    )
    print(
        "Sections: {sections} | Modules: {modules} | Mix: {mix}".format(
            sections=safe_int(summary.get("section_count")),
            modules=safe_int(summary.get("module_count")),
            mix=top_module_mix(list(summary.get("module_stats") or [])),
        )
    )
    if route.get("notes"):
        print(f"Route: {route['notes'][0]}")
    sections = list(outline.get("sections") or [])
    if sections:
        print("")
    for section in sections:
        title = str(section.get("title") or "").strip() or f"Section {safe_int(section.get('index'))}"
        print(f"[{safe_int(section.get('index'))}] {title}")
        summary_text = compact_text(section.get("summary"), limit=140)
        if summary_text:
            print(f"  {summary_text}")
        for module in list(section.get("modules") or []):
            print(f"  - {module.get('kind')} | {module.get('title')}")


def emit_learning_list_pretty(value: Dict[str, Any], *, list_key: str, heading: str) -> None:
    data = dict(value.get("data") or {})
    meta = dict(value.get("meta") or {})
    course = dict(data.get("course") or {})
    print(f"{heading}: {safe_int(meta.get('count'))}")
    if course.get("title"):
        print(f"Course: {course.get('title')}")
    rows = build_activities_rows(value)
    if rows:
        print("")
        emit_table_rows(rows)


def emit_course_output(value: Any, args: argparse.Namespace) -> bool:
    supported = {
        ("courses", "list"),
        ("courses", "outline"),
        ("activities", "list"),
        ("resources", "list"),
        ("quiz", "list"),
    }
    path_tuple = tuple(getattr(args, "command_path", []) or [])
    if path_tuple not in supported:
        return False
    if not isinstance(value, dict):
        return False
    if args.json:
        print(json.dumps(value, ensure_ascii=False, indent=2))
        return True
    if args.plain:
        emit_plain(value)
        return True

    fmt = (getattr(args, "format", "") or "").strip().lower()
    if fmt in {"", "pretty"}:
        if command_path_equals(args, ["courses", "list"]):
            emit_courses_list_pretty(value)
            return True
        if command_path_equals(args, ["courses", "outline"]):
            emit_courses_outline_pretty(value)
            return True
        if command_path_equals(args, ["activities", "list"]):
            emit_learning_list_pretty(value, list_key="activities", heading="Activities")
            return True
        if command_path_equals(args, ["resources", "list"]):
            emit_learning_list_pretty(value, list_key="resources", heading="Resources")
            return True
        if command_path_equals(args, ["quiz", "list"]):
            emit_learning_list_pretty(value, list_key="quizzes", heading="Quizzes")
            return True
    if fmt == "json":
        print(json.dumps(value, ensure_ascii=False, indent=2))
        return True
    if fmt == "table":
        items = extract_items_for_output(value)
        rows = rows_from_generic_value(items if items is not None else unwrap_primary(value))
        emit_table_rows(rows)
        return True
    if fmt == "csv":
        items = extract_items_for_output(value)
        rows = rows_from_generic_value(items if items is not None else unwrap_primary(value))
        emit_csv_rows(rows)
        return True
    if fmt == "ndjson":
        items = extract_items_for_output(value)
        primary = items if items is not None else unwrap_primary(value)
        if isinstance(primary, list):
            if primary and all(isinstance(item, dict) for item in primary):
                emit_ndjson_rows(list(primary))
            else:
                for item in primary:
                    print(json.dumps(item, ensure_ascii=False, separators=(",", ":")))
            return True
        print(json.dumps(primary, ensure_ascii=False, separators=(",", ":")))
        return True
    raise CliError(f"unsupported --format {fmt!r} for learning commands", EXIT_USAGE)


def transform_output(value: Any, args: argparse.Namespace) -> Any:
    if tuple(getattr(args, "command_path", []) or []) in {
        ("courses", "list"),
        ("courses", "outline"),
        ("activities", "list"),
        ("resources", "list"),
        ("quiz", "list"),
    }:
        value = transform_course_command_output(value, args)
    if tuple(getattr(args, "command_path", []) or []) in {
        ("activities", "due"),
        ("activities", "detail"),
        ("assignments", "list"),
        ("assignments", "status"),
        ("calendar", "list"),
        ("forum", "discussions"),
        ("notifications", "list"),
        ("questions", "search"),
        ("quiz", "attempts"),
        ("grades", "overview"),
        ("progress", "course"),
    }:
        value = transform_extended_command_output(value, args)
    if args.results_only:
        value = unwrap_primary(value)
    if args.select:
        fields = [item.strip() for item in args.select.split(",") if item.strip()]
        value = select_fields(value, fields)
    return value


def emit_output(value: Any, args: argparse.Namespace) -> None:
    if emit_course_output(value, args):
        return
    fmt = (getattr(args, "format", "") or "").strip().lower()
    if fmt == "json":
        print(json.dumps(value, ensure_ascii=False, indent=2))
        return
    if fmt == "pretty":
        if isinstance(value, str):
            print(value)
        else:
            print(json.dumps(value, ensure_ascii=False, indent=2))
        return
    if fmt == "table":
        emit_table_rows(rows_from_generic_value(unwrap_primary(value)))
        return
    if fmt == "csv":
        emit_csv_rows(rows_from_generic_value(unwrap_primary(value)))
        return
    if fmt == "ndjson":
        primary = unwrap_primary(value)
        if isinstance(primary, list):
            if primary and all(isinstance(item, dict) for item in primary):
                emit_ndjson_rows(list(primary))
            else:
                for item in primary:
                    print(json.dumps(item, ensure_ascii=False, separators=(",", ":")))
        else:
            print(json.dumps(primary, ensure_ascii=False, separators=(",", ":")))
        return
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


def command_mathstate_question_map_sync(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_question_map_sync_batch", {
        "items": resolve_batch_items(args, label="mathstate question-map-sync"),
    })


def command_mathstate_question_map_lookup(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_question_map_lookup", {
        "questionid": args.question_id,
        "questionbankentryid": args.questionbankentry_id,
        "source_id": args.source_id,
        "qg_id": args.qg_id,
        "lesson_key": args.lesson_key,
        "limit": args.limit,
    })


def command_mathstate_evidence_ingest(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_evidence_ingest_batch", {
        "items": resolve_batch_items(args, label="mathstate evidence-ingest"),
    })


def command_mathstate_learning_event_record(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_learning_event_record_batch", {
        "items": resolve_batch_items(args, label="mathstate learning-event-record"),
    })


def command_mathstate_lesson_session_upsert(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_lesson_session_upsert_batch", {
        "items": resolve_batch_items(args, label="mathstate lesson-session-upsert"),
    })


def command_mathstate_review_upsert(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_review_upsert_batch", {
        "items": resolve_batch_items(args, label="mathstate review-upsert"),
    })


def command_mathstate_doc_job_upsert(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return cli.call("local_mathstate_doc_job_upsert_batch", {
        "items": resolve_batch_items(args, label="mathstate doc-job-upsert"),
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


def command_config_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    config = args.cli_config
    current_profile = str(config.get("current_profile") or "")
    profiles = []
    for name in sorted((config.get("profiles") or {}).keys()):
        profile = get_profile(config, name)
        profiles.append(summarize_profile(name, profile, is_current=(name == current_profile)))
    return {
        "config_dir": args.config_dir,
        "config_path": args.config_path,
        "current_profile": current_profile,
        "profiles": profiles,
    }


def command_config_show(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = resolve_target_profile(args)
    profile = get_profile(args.cli_config, profile_name)
    if not profile:
        raise CliError(f'profile "{profile_name}" not found', EXIT_NOT_FOUND)
    summary = summarize_profile(profile_name, profile, is_current=(args.cli_config.get("current_profile") == profile_name))
    if args.show_token:
        summary["token"] = resolve_profile_token(profile_name, profile)
    return {
        "config_dir": args.config_dir,
        "config_path": args.config_path,
        "current_profile": str(args.cli_config.get("current_profile") or ""),
        "profile": summary,
    }


def command_config_init(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = resolve_target_profile(args)
    profile = dict(get_profile(args.cli_config, profile_name))
    base_url = args.base_url or str(profile.get("base_url") or "")
    if not base_url and sys.stdin.isatty() and not args.no_input:
        base_url = prompt_text("Moodle base URL")
    base_url = normalize_base_url(base_url)
    if not base_url:
        raise CliError("config init requires --base-url for a new profile", EXIT_USAGE)

    service = args.service or str(profile.get("service") or DEFAULT_SERVICE_SHORTNAME)
    profile.update({
        "base_url": base_url,
        "service": service,
        "updated_at": utc_now_iso(),
    })
    set_profile(args.cli_config, profile_name, profile)
    if args.activate or not args.cli_config.get("current_profile"):
        args.cli_config["current_profile"] = profile_name
    save_cli_config(args.config_dir, args.cli_config)
    return {
        "ok": True,
        "config_dir": args.config_dir,
        "config_path": args.config_path,
        "current_profile": str(args.cli_config.get("current_profile") or ""),
        "profile": summarize_profile(profile_name, profile, is_current=(args.cli_config.get("current_profile") == profile_name)),
    }


def command_config_use(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = normalize_profile_name(args.profile_name)
    profile = get_profile(args.cli_config, profile_name)
    if not profile:
        raise CliError(f'profile "{profile_name}" not found', EXIT_NOT_FOUND)
    args.cli_config["current_profile"] = profile_name
    save_cli_config(args.config_dir, args.cli_config)
    return {
        "ok": True,
        "current_profile": profile_name,
        "profile": summarize_profile(profile_name, profile, is_current=True),
    }


def command_config_delete(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = normalize_profile_name(args.profile_name)
    deleted = delete_profile(args.cli_config, profile_name)
    if not deleted:
        raise CliError(f'profile "{profile_name}" not found', EXIT_NOT_FOUND)
    save_cli_config(args.config_dir, args.cli_config)
    return {
        "ok": True,
        "deleted": True,
        "profile": profile_name,
        "current_profile": str(args.cli_config.get("current_profile") or ""),
    }


def command_auth_login(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = resolve_target_profile(args)
    profile = dict(get_profile(args.cli_config, profile_name))

    base_url = normalize_base_url(args.base_url or str(profile.get("base_url") or ""))
    if not base_url and sys.stdin.isatty() and not args.no_input:
        base_url = normalize_base_url(prompt_text("Moodle base URL"))
    if not base_url:
        raise CliError("auth login requires --base-url or a configured profile base URL", EXIT_USAGE)

    service = args.service or str(profile.get("service") or DEFAULT_SERVICE_SHORTNAME)
    if args.device_code:
        payload = wait_for_device_authorization(
            base_url,
            args.device_code,
            interval=args.interval or 5,
            expires_in=args.expires_in or 600,
            timeout=args.timeout,
        )
        token = str(payload.get("access_token") or "")
        verified = verify_profile_session(base_url, token, args.timeout)
        return persist_login_result(
            args,
            profile_name,
            profile,
            base_url=base_url,
            service=service,
            token=token,
            verified=verified,
        )

    password_requested = bool(args.username or args.password)
    if password_requested:
        username = (args.username or str(profile.get("username") or "")).strip()
        if not username and sys.stdin.isatty() and not args.no_input:
            username = prompt_text("Username")
        if not username:
            raise CliError("auth login requires --username", EXIT_USAGE)

        password = args.password or ""
        if not password and sys.stdin.isatty() and not args.no_input:
            password = prompt_text("Password", secret=True)
        if not password:
            raise CliError("auth login requires --password in non-interactive mode", EXIT_USAGE)

        token_response = request_login_token(base_url, username, password, service, args.timeout)
        token = str(token_response.get("token") or "")
        verified = verify_profile_session(base_url, token, args.timeout)
        return persist_login_result(
            args,
            profile_name,
            profile,
            base_url=base_url,
            service=service,
            token=token,
            verified=verified,
        )

    start = request_device_authorization(base_url, service, args.timeout)
    verification_url = str(start.get("verification_uri_complete") or start.get("verification_uri") or "")
    if args.no_wait:
        return {
            "ok": True,
            "profile": profile_name,
            "base_url": base_url,
            "service": service,
            "device_code": str(start.get("device_code") or ""),
            "user_code": str(start.get("user_code") or ""),
            "verification_url": verification_url,
            "expires_in": int(start.get("expires_in") or 600),
            "interval": int(start.get("interval") or 5),
        }

    browser_opened = False
    if verification_url and not args.no_browser:
        browser_opened = bool(webbrowser.open(verification_url))
    if not args.json:
        _eprint(f"Open this URL to approve CLI login: {verification_url}")
        _eprint(f"User code: {start.get('user_code')}")
        if browser_opened:
            _eprint("Opened the browser for approval.")
        _eprint("Waiting for browser approval...")

    payload = wait_for_device_authorization(
        base_url,
        str(start.get("device_code") or ""),
        interval=int(start.get("interval") or 5),
        expires_in=int(start.get("expires_in") or 600),
        timeout=args.timeout,
    )
    token = str(payload.get("access_token") or "")
    verified = verify_profile_session(base_url, token, args.timeout)
    result = persist_login_result(
        args,
        profile_name,
        profile,
        base_url=base_url,
        service=service,
        token=token,
        verified=verified,
    )
    result["verification_url"] = verification_url
    result["user_code"] = str(start.get("user_code") or "")
    result["browser_opened"] = browser_opened
    return result


def command_auth_status(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = resolve_target_profile(args)
    profile = get_profile(args.cli_config, profile_name)
    base_url = normalize_base_url(args.base_url or str(profile.get("base_url") or ""))
    service = args.service or str(profile.get("service") or DEFAULT_SERVICE_SHORTNAME)
    token = args.token or resolve_profile_token(profile_name, profile)
    payload: Dict[str, Any] = {
        "profile": summarize_profile(profile_name, profile, is_current=(args.cli_config.get("current_profile") == profile_name)),
        "base_url": base_url,
        "service": service,
        "identity": "user" if token else "anonymous",
        "logged_in": bool(token),
        "verified": False,
    }
    if args.offline:
        return payload
    if not base_url or not token:
        return payload
    try:
        verified = verify_profile_session(base_url, token, args.timeout)
    except CliError as e:
        payload["error"] = {
            "message": str(e),
            "exit_code": e.exit_code,
        }
        return payload
    payload["verified"] = True
    payload["user"] = verified.get("data", {}).get("user", {})
    payload["context"] = verified.get("data", {}).get("context", {})
    if profile:
        updated = dict(profile)
        updated["last_verified_at"] = utc_now_iso()
        set_profile(args.cli_config, profile_name, updated)
        save_cli_config(args.config_dir, args.cli_config)
        payload["profile"] = summarize_profile(profile_name, updated, is_current=(args.cli_config.get("current_profile") == profile_name))
    return payload


def command_auth_logout(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = resolve_target_profile(args)
    profile = dict(get_profile(args.cli_config, profile_name))
    if not profile:
        raise CliError(f'profile "{profile_name}" not found', EXIT_NOT_FOUND)
    profile = clear_profile_token(profile_name, profile)
    for key in ("last_verified_at", "user_id", "full_name"):
        profile.pop(key, None)
    set_profile(args.cli_config, profile_name, profile)
    save_cli_config(args.config_dir, args.cli_config)
    return {
        "ok": True,
        "logged_out": True,
        "profile": summarize_profile(profile_name, profile, is_current=(args.cli_config.get("current_profile") == profile_name)),
    }


def command_auth_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    current_profile = str(args.cli_config.get("current_profile") or "")
    items: List[Dict[str, Any]] = []
    for name in sorted((args.cli_config.get("profiles") or {}).keys()):
        profile = get_profile(args.cli_config, name)
        token = resolve_profile_token(name, profile)
        if not token:
            continue
        item = summarize_profile(name, profile, is_current=(name == current_profile))
        items.append(item)
    return {
        "current_profile": current_profile,
        "profiles": items,
    }


def command_profile_list(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return command_config_list(cli, args)


def command_profile_use(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return command_config_use(cli, args)


def command_profile_add(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    args.activate = args.use
    return command_config_init(cli, args)


def command_profile_remove(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    return command_config_delete(cli, args)


def command_profile_rename(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    old_name = normalize_profile_name(args.old_name)
    new_name = normalize_profile_name(args.new_name)
    if old_name == new_name:
        raise CliError("old and new profile names are the same", EXIT_USAGE)
    profile = get_profile(args.cli_config, old_name)
    if not profile:
        raise CliError(f'profile "{old_name}" not found', EXIT_NOT_FOUND)
    if get_profile(args.cli_config, new_name):
        raise CliError(f'profile "{new_name}" already exists', EXIT_USAGE)
    updated = dict(profile)
    token = resolve_profile_token(old_name, updated)
    updated = clear_profile_token(old_name, updated)
    was_current = args.cli_config.get("current_profile") == old_name
    if token:
        updated, _ = store_profile_token(new_name, updated, token)
    delete_profile(args.cli_config, old_name)
    set_profile(args.cli_config, new_name, updated)
    if was_current:
        args.cli_config["current_profile"] = new_name
    save_cli_config(args.config_dir, args.cli_config)
    return {
        "ok": True,
        "old_name": old_name,
        "new_name": new_name,
        "profile": summarize_profile(new_name, updated, is_current=(args.cli_config.get("current_profile") == new_name)),
    }


def command_doctor(cli: "MoodleCLI", args: argparse.Namespace) -> Any:
    profile_name = args.profile
    profile = get_profile(args.cli_config, profile_name)
    checks: List[Dict[str, Any]] = []

    def add(name: str, status: str, message: str, hint: str = "") -> None:
        item = {"name": name, "status": status, "message": message}
        if hint:
            item["hint"] = hint
        checks.append(item)

    if os.path.exists(args.config_path):
        add("config_file", "pass", f"config found at {args.config_path}")
    else:
        add("config_file", "warn", "config file not initialized", "run: moodle config init --name default --base-url <url> --activate")

    if profile:
        add("profile", "pass", f'profile "{profile_name}" loaded')
    else:
        add("profile", "fail", f'profile "{profile_name}" not found', "run: moodle config init --name <profile> --base-url <url>")
        return {"ok": False, "profile": profile_name, "checks": checks}

    base_url = normalize_base_url(args.base_url or str(profile.get("base_url") or ""))
    if base_url:
        add("base_url", "pass", base_url)
    else:
        add("base_url", "fail", "missing Moodle base URL", "set --base-url or run: moodle config init")

    service = args.service or str(profile.get("service") or DEFAULT_SERVICE_SHORTNAME)
    add("service", "pass", service)

    token = args.token or resolve_profile_token(profile_name, profile)
    if token:
        add("token_local", "pass", f"token available via {str(profile.get('token_storage') or 'file')}")
    else:
        add("token_local", "fail", "no stored token", "run: moodle auth login")

    if args.offline:
        add("network", "skip", "skipped (--offline)")
        return {"ok": all(item["status"] != "fail" for item in checks), "profile": profile_name, "checks": checks}

    if base_url:
        for name, url in [
            ("site_root", f"{base_url}/"),
            ("login_endpoint", f"{base_url}/login/token.php?appsitecheck=1"),
            ("device_verify_page", f"{base_url}/local/aiagentapi/device_verify.php?user_code=PING"),
            ("webservice_endpoint", f"{base_url}/webservice/rest/server.php"),
        ]:
            ok, detail = probe_url(url, args.timeout)
            status = "pass" if ok else "warn"
            hint = ""
            httpstatus = parse_http_status(detail)
            if httpstatus == 404:
                status = "warn"
                if name == "device_verify_page":
                    hint = (
                        "device/browser login page is missing on server; deploy "
                        "public/local/aiagentapi/device_verify.php and run plugin upgrade"
                    )
                else:
                    hint = "endpoint missing on server"
            add(name, status, f"{url} -> {detail}", hint=hint)

    if base_url and token:
        try:
            verified = verify_profile_session(base_url, token, args.timeout)
            user = verified.get("data", {}).get("user", {})
            add("token_verified", "pass", f"user {user.get('username') or user.get('userid')}")
        except CliError as e:
            add("token_verified", "fail", str(e), "run: moodle auth login")
    else:
        add("token_verified", "skip", "skipped (missing base_url or token)")

    return {"ok": all(item["status"] != "fail" for item in checks), "profile": profile_name, "checks": checks}


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
    parser.add_argument("--config-dir", default="", help="CLI config directory (default: XDG or ~/.config/moodle-cli)")
    parser.add_argument("--profile", default="", help="Profile name to use")
    parser.add_argument("--base-url", default="", help="Moodle base URL")
    parser.add_argument("--token", default="", help="Web service token")
    parser.add_argument("--service", default="", help=f"External service shortname (default: {DEFAULT_SERVICE_SHORTNAME})")
    parser.add_argument("--enable-commands", default="", help="Comma-separated top-level allowlist")
    parser.add_argument("--json", action="store_true", help="Output JSON to stdout")
    parser.add_argument("--plain", action="store_true", help="Output stable parseable text")
    parser.add_argument("--format", default="", help="Output format for supported commands: json|pretty|table|csv|ndjson")
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

    doctor_parser = add_parser(subparsers, "doctor", description="Health check config, auth, and connectivity")
    doctor_parser.add_argument("--offline", action="store_true", help="Skip network checks")
    doctor_parser.set_defaults(handler=command_doctor, command_path=["doctor"])

    config_parser = add_parser(subparsers, "config", description="CLI profile and config helpers")
    config_sub = config_parser.add_subparsers(dest="_config_command")
    config_list = add_parser(config_sub, "list", description="List configured profiles", aliases=["ls"])
    config_list.set_defaults(handler=command_config_list, command_path=["config", "list"])
    config_show = add_parser(config_sub, "show", description="Show one profile")
    config_show.add_argument("--name", dest="profile_name", default="", help="Profile name (defaults to current profile)")
    config_show.add_argument("--show-token", action="store_true", help="Include the raw token in output")
    config_show.set_defaults(handler=command_config_show, command_path=["config", "show"])
    config_init = add_parser(config_sub, "init", description="Create or update a profile")
    config_init.add_argument("--name", dest="profile_name", default="", help="Profile name (defaults to current/default profile)")
    config_init.add_argument("--activate", action="store_true", help="Make this profile current")
    config_init.set_defaults(handler=command_config_init, command_path=["config", "init"])
    config_use = add_parser(config_sub, "use", description="Switch current profile")
    config_use.add_argument("profile_name", help="Profile name to activate")
    config_use.set_defaults(handler=command_config_use, command_path=["config", "use"])
    config_delete = add_parser(config_sub, "delete", description="Delete a profile", aliases=["rm", "remove"])
    config_delete.add_argument("profile_name", help="Profile name to delete")
    config_delete.set_defaults(handler=command_config_delete, command_path=["config", "delete"])

    auth_parser = add_parser(subparsers, "auth", description="Authenticate and manage tokens")
    auth_sub = auth_parser.add_subparsers(dest="_auth_command")
    auth_login = add_parser(auth_sub, "login", description="Login via browser/device flow or username/password")
    auth_login.add_argument("--name", dest="profile_name", default="", help="Profile name (defaults to current/default profile)")
    auth_login.add_argument("--username", default="", help="Moodle username")
    auth_login.add_argument("--password", default="", help="Moodle password (omit to prompt interactively)")
    auth_login.add_argument("--device-code", default="", help="Resume polling with an existing device code")
    auth_login.add_argument("--expires-in", type=int, default=0, help="Device code lifetime when resuming polling")
    auth_login.add_argument("--interval", type=int, default=0, help="Device poll interval when resuming polling")
    auth_login.add_argument("--no-wait", action="store_true", help="Start browser/device login and return device code immediately")
    auth_login.add_argument("--no-browser", action="store_true", help="Do not attempt to open the browser automatically")
    auth_login.set_defaults(handler=command_auth_login, command_path=["auth", "login"])
    auth_list = add_parser(auth_sub, "list", description="List logged-in profiles")
    auth_list.set_defaults(handler=command_auth_list, command_path=["auth", "list"])
    auth_status = add_parser(auth_sub, "status", description="Show current auth status")
    auth_status.add_argument("--name", dest="profile_name", default="", help="Profile name (defaults to current/default profile)")
    auth_status.add_argument("--offline", action="store_true", help="Do not verify against the server")
    auth_status.set_defaults(handler=command_auth_status, command_path=["auth", "status"])
    auth_logout = add_parser(auth_sub, "logout", description="Remove the stored token from a profile")
    auth_logout.add_argument("--name", dest="profile_name", default="", help="Profile name (defaults to current/default profile)")
    auth_logout.set_defaults(handler=command_auth_logout, command_path=["auth", "logout"])

    profile_parser = add_parser(subparsers, "profile", description="Manage CLI profiles")
    profile_sub = profile_parser.add_subparsers(dest="_profile_command")
    profile_list = add_parser(profile_sub, "list", description="List profiles", aliases=["ls"])
    profile_list.set_defaults(handler=command_profile_list, command_path=["profile", "list"])
    profile_use = add_parser(profile_sub, "use", description="Switch current profile")
    profile_use.add_argument("profile_name", help="Profile name to activate")
    profile_use.set_defaults(handler=command_profile_use, command_path=["profile", "use"])
    profile_add = add_parser(profile_sub, "add", description="Add a new profile")
    profile_add.add_argument("--name", dest="profile_name", required=True, help="Profile name")
    profile_add.add_argument("--use", action="store_true", help="Make the profile current after adding")
    profile_add.set_defaults(handler=command_profile_add, command_path=["profile", "add"])
    profile_remove = add_parser(profile_sub, "remove", description="Remove a profile", aliases=["rm", "delete"])
    profile_remove.add_argument("profile_name", help="Profile name to remove")
    profile_remove.set_defaults(handler=command_profile_remove, command_path=["profile", "remove"])
    profile_rename = add_parser(profile_sub, "rename", description="Rename a profile")
    profile_rename.add_argument("old_name", help="Existing profile name")
    profile_rename.add_argument("new_name", help="New profile name")
    profile_rename.set_defaults(handler=command_profile_rename, command_path=["profile", "rename"])

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

    mathstate_map_sync = add_parser(mathstate_sub, "question-map-sync", description="Sync question map bridge rows from sidecar payload")
    mathstate_map_sync.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_map_sync.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one bridge item")
    mathstate_map_sync.set_defaults(handler=command_mathstate_question_map_sync, command_path=["mathstate", "question-map-sync"])

    mathstate_map_lookup = add_parser(mathstate_sub, "question-map-lookup", description="Lookup question map rows by source/question/qg/lesson keys")
    mathstate_map_lookup.add_argument("--question-id", type=int, default=0, help="Question id filter")
    mathstate_map_lookup.add_argument("--questionbankentry-id", type=int, default=0, help="Question bank entry id filter")
    mathstate_map_lookup.add_argument("--source-id", default="", help="Source id filter")
    mathstate_map_lookup.add_argument("--qg-id", default="", help="Question-group id filter")
    mathstate_map_lookup.add_argument("--lesson-key", default="", help="Lesson key filter")
    mathstate_map_lookup.add_argument("--limit", type=int, default=20, help="Maximum rows to return")
    mathstate_map_lookup.set_defaults(handler=command_mathstate_question_map_lookup, command_path=["mathstate", "question-map-lookup"])

    mathstate_evidence = add_parser(mathstate_sub, "evidence-ingest", description="Ingest evidence and update mastery state")
    mathstate_evidence.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_evidence.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one evidence item")
    mathstate_evidence.set_defaults(handler=command_mathstate_evidence_ingest, command_path=["mathstate", "evidence-ingest"])

    mathstate_lesson_session = add_parser(mathstate_sub, "lesson-session-upsert", description="Upsert lesson session runtime records")
    mathstate_lesson_session.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_lesson_session.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one session item")
    mathstate_lesson_session.set_defaults(handler=command_mathstate_lesson_session_upsert, command_path=["mathstate", "lesson-session-upsert"])

    mathstate_learning_event = add_parser(mathstate_sub, "learning-event-record", description="Record learning-event runtime rows")
    mathstate_learning_event.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_learning_event.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one learning event item")
    mathstate_learning_event.set_defaults(handler=command_mathstate_learning_event_record, command_path=["mathstate", "learning-event-record"])

    mathstate_review = add_parser(mathstate_sub, "review-upsert", description="Upsert review/remediation tasks")
    mathstate_review.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_review.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one review task item")
    mathstate_review.set_defaults(handler=command_mathstate_review_upsert, command_path=["mathstate", "review-upsert"])

    mathstate_doc_job = add_parser(mathstate_sub, "doc-job-upsert", description="Upsert document generation job rows")
    mathstate_doc_job.add_argument("--input", default="", help="JSON file path containing items array (or - for stdin)")
    mathstate_doc_job.add_argument("--item-json", action="append", default=[], help="Inline JSON object for one doc job item")
    mathstate_doc_job.set_defaults(handler=command_mathstate_doc_job_upsert, command_path=["mathstate", "doc-job-upsert"])

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
    args.config_dir = resolve_config_dir(args.config_dir, env)
    args.config_path = config_path_for_dir(args.config_dir)
    args.cli_config = load_cli_config(args.config_dir)

    profile_name = (
        args.profile
        or env.get("MOODLE_CLI_PROFILE")
        or os.environ.get("MOODLE_CLI_PROFILE", "")
        or str(args.cli_config.get("current_profile") or "")
        or DEFAULT_PROFILE_NAME
    )
    args.profile = normalize_profile_name(profile_name)
    profile = get_profile(args.cli_config, args.profile)

    if not args.base_url:
        args.base_url = (
            str(profile.get("base_url") or "")
            or env.get("MOODLE_CLI_BASE_URL")
            or env.get("MOODLE_BASE_URL")
            or os.environ.get("MOODLE_CLI_BASE_URL")
            or os.environ.get("MOODLE_BASE_URL", "")
        )
    if not args.token:
        args.token = (
            resolve_profile_token(args.profile, profile)
            or env.get("MOODLE_CLI_TOKEN")
            or env.get("MOODLE_WS_TOKEN")
            or os.environ.get("MOODLE_CLI_TOKEN")
            or os.environ.get("MOODLE_WS_TOKEN", "")
        )
    if not args.service:
        args.service = (
            str(profile.get("service") or "")
            or env.get("MOODLE_CLI_SERVICE")
            or os.environ.get("MOODLE_CLI_SERVICE", "")
            or DEFAULT_SERVICE_SHORTNAME
        )
    args.base_url = normalize_base_url(args.base_url) if args.base_url else ""
    if env_bool("MOODLE_CLI_AUTO_JSON") and not args.json and not args.plain and not sys.stdout.isatty():
        args.json = True
    if args.json and args.plain:
        raise CliError("cannot combine --json and --plain", EXIT_USAGE)
    if args.format:
        normalized_format = str(args.format).strip().lower()
        if normalized_format not in {"json", "pretty", "table", "csv", "ndjson"}:
            raise CliError("unsupported --format: use json, pretty, table, csv, or ndjson", EXIT_USAGE)
        args.format = normalized_format
        if args.json or args.plain:
            raise CliError("cannot combine --format with --json or --plain", EXIT_USAGE)
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
    args: Optional[argparse.Namespace] = None

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
        emit_cli_error(e, args)
        return e.exit_code


if __name__ == "__main__":
    raise SystemExit(main())
