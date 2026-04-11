#!/usr/bin/env python3
"""
Deploy local_aiagentapi device/browser login files to a remote Moodle server.

This script is designed for password-based SSH environments and automates:
1) upload bundle via SCP
2) in-place backup of target files
3) extract bundle to Moodle code root
4) plugin upgrade + external service re-registration
5) optional HTTP endpoint probe
"""

from __future__ import annotations

import argparse
import getpass
import json
import os
import re
import shlex
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import List, Optional, Tuple

import pexpect


FILES_TO_DEPLOY: List[str] = [
    "public/local/aiagentapi/locallib.php",
    "public/local/aiagentapi/device_flow.php",
    "public/local/aiagentapi/device_verify.php",
    "public/local/aiagentapi/db/install.xml",
    "public/local/aiagentapi/db/upgrade.php",
    "public/local/aiagentapi/version.php",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Deploy Moodle local_aiagentapi device-flow files over SSH/SCP.")
    parser.add_argument("--host", required=True, help="Remote SSH host")
    parser.add_argument("--user", default="root", help="Remote SSH user (default: root)")
    parser.add_argument("--password", default="", help="Remote SSH password (omit to prompt)")
    parser.add_argument("--repo-root", default="/srv/moodle/current", help="Remote Moodle code root")
    parser.add_argument("--base-url", default="", help="Optional base URL to probe after deploy")
    parser.add_argument("--timeout", type=int, default=90, help="SSH/SCP timeout seconds")
    parser.add_argument("--retries", type=int, default=3, help="Retry attempts for transient SSH/SCP failures")
    parser.add_argument("--insecure-hostkey", action="store_true", help="Disable strict host key checks")
    parser.add_argument("--skip-upgrade", action="store_true", help="Skip admin/cli/upgrade.php and service registration")
    parser.add_argument("--dry-run", action="store_true", help="Print commands only, do not execute")
    return parser.parse_args()


def shell_join(parts: List[str]) -> str:
    return " ".join(shlex.quote(part) for part in parts)


def build_ssh_command(user: str, host: str, remote_command: str, insecure_hostkey: bool) -> str:
    parts = ["ssh"]
    if insecure_hostkey:
        parts.extend(["-o", "StrictHostKeyChecking=no", "-o", "UserKnownHostsFile=/dev/null"])
    parts.append(f"{user}@{host}")
    parts.append(remote_command)
    return shell_join(parts)


def build_scp_command(user: str, host: str, local_path: str, remote_path: str, insecure_hostkey: bool) -> str:
    parts = ["scp"]
    if insecure_hostkey:
        parts.extend(["-o", "StrictHostKeyChecking=no", "-o", "UserKnownHostsFile=/dev/null"])
    parts.extend([local_path, f"{user}@{host}:{remote_path}"])
    return shell_join(parts)


def run_interactive(command: str, password: str, timeout: int) -> Tuple[int, str]:
    child = pexpect.spawn(command, encoding="utf-8", timeout=timeout)
    output_chunks: List[str] = []
    prompts = [
        r"[Pp]assword:",
        r"Are you sure you want to continue connecting \(yes/no(/\[fingerprint\])?\)\?",
        r"yes/no",
        pexpect.EOF,
        pexpect.TIMEOUT,
    ]

    while True:
        matched = child.expect(prompts)
        output_chunks.append(child.before or "")
        if matched == 0:
            if not password:
                child.close(force=True)
                raise RuntimeError("SSH/SCP requested password but none was provided.")
            child.sendline(password)
            continue
        if matched in (1, 2):
            child.sendline("yes")
            continue
        if matched == 3:
            break
        child.close(force=True)
        raise RuntimeError(f"Command timed out: {command}")

    child.close()
    exitcode = child.exitstatus if child.exitstatus is not None else 1
    output = "".join(output_chunks).strip()
    return exitcode, output


def is_transient_ssh_failure(output: str) -> bool:
    lowered = output.lower()
    return (
        "connection reset" in lowered
        or "connection timed out" in lowered
        or "kex_exchange_identification" in lowered
        or "connection closed by remote host" in lowered
    )


def run_or_fail(
    command: str,
    password: str,
    timeout: int,
    dry_run: bool,
    label: str,
    retries: int,
) -> str:
    if dry_run:
        print(f"[dry-run] {label}: {command}")
        return ""
    attempts = max(1, retries)
    last_error = ""
    for attempt in range(1, attempts + 1):
        code, output = run_interactive(command, password, timeout)
        if code == 0:
            return output
        last_error = f"{label} failed (exit {code})\n{output}"
        if attempt < attempts and is_transient_ssh_failure(output):
            time.sleep(min(5, attempt))
            continue
        raise RuntimeError(last_error)
    raise RuntimeError(last_error or f"{label} failed")


def create_bundle(repo_root: Path) -> Path:
    for rel in FILES_TO_DEPLOY:
        path = repo_root / rel
        if not path.exists():
            raise FileNotFoundError(f"missing local file: {path}")

    tmpdir = Path(tempfile.mkdtemp(prefix="moodle-deviceflow-deploy-"))
    bundle = tmpdir / "deviceflow_bundle.tgz"
    subprocess.run(
        ["tar", "-czf", str(bundle), "-C", str(repo_root), *FILES_TO_DEPLOY],
        check=True,
    )
    return bundle


def probe_http(url: str, timeout: float = 10.0) -> Tuple[bool, str]:
    req = urllib.request.Request(url, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return True, f"HTTP {response.status}"
    except urllib.error.HTTPError as e:
        return (e.code < 500), f"HTTP {e.code}"
    except Exception as e:
        return False, str(e)


def parse_http_status(detail: str) -> Optional[int]:
    match = re.search(r"\bHTTP\s+(\d{3})\b", detail)
    if not match:
        return None
    return int(match.group(1))


def main() -> int:
    args = parse_args()
    if args.password:
        password = args.password
    elif args.dry_run:
        password = "__DRY_RUN__"
    else:
        try:
            password = getpass.getpass(f"SSH password for {args.user}@{args.host}: ")
        except EOFError as e:
            raise SystemExit("No TTY available for password prompt. Re-run with --password.") from e

    local_repo = Path(__file__).resolve().parent.parent
    bundle = create_bundle(local_repo)
    timestamp = int(time.time())
    remote_staging = f"/tmp/moodle-aiagentapi-deviceflow-{timestamp}"
    remote_bundle = f"{remote_staging}/deviceflow_bundle.tgz"

    ssh_mkdir = build_ssh_command(
        args.user,
        args.host,
        shell_join(["mkdir", "-p", remote_staging]),
        args.insecure_hostkey,
    )
    run_or_fail(ssh_mkdir, password, args.timeout, args.dry_run, "create remote staging dir", args.retries)

    scp_bundle = build_scp_command(
        args.user,
        args.host,
        str(bundle),
        remote_bundle,
        args.insecure_hostkey,
    )
    run_or_fail(scp_bundle, password, args.timeout, args.dry_run, "upload deployment bundle", args.retries)

    file_list_for_loop = " ".join(shlex.quote(rel) for rel in FILES_TO_DEPLOY)
    backup_cmd = (
        f"set -e; cd {shlex.quote(args.repo_root)}; "
        "stamp=$(date +%Y%m%d-%H%M%S); "
        f"for rel in {file_list_for_loop}; do "
        'if [ -f "$rel" ]; then cp "$rel" "$rel.bak.$stamp"; fi; '
        "done"
    )
    ssh_backup = build_ssh_command(args.user, args.host, backup_cmd, args.insecure_hostkey)
    run_or_fail(ssh_backup, password, args.timeout, args.dry_run, "backup existing files", args.retries)

    extract_cmd = shell_join(["tar", "-xzf", remote_bundle, "-C", args.repo_root])
    ssh_extract = build_ssh_command(args.user, args.host, extract_cmd, args.insecure_hostkey)
    run_or_fail(ssh_extract, password, args.timeout, args.dry_run, "extract bundle to repo root", args.retries)

    if not args.skip_upgrade:
        upgrade_cmd = (
            f"set -e; cd {shlex.quote(args.repo_root)}; "
            "if [ -f admin/cli/upgrade.php ]; then up='admin/cli/upgrade.php'; "
            "elif [ -f public/admin/cli/upgrade.php ]; then up='public/admin/cli/upgrade.php'; "
            "else echo 'upgrade.php not found under admin/cli or public/admin/cli'; exit 1; fi; "
            "php \"$up\" --non-interactive; "
            "php scripts/register_aiagentapi_service_functions.php"
        )
        ssh_upgrade = build_ssh_command(args.user, args.host, upgrade_cmd, args.insecure_hostkey)
        run_or_fail(
            ssh_upgrade,
            password,
            args.timeout,
            args.dry_run,
            "run Moodle upgrade and register service functions",
            args.retries,
        )

    cleanup_cmd = shell_join(["rm", "-rf", remote_staging])
    ssh_cleanup = build_ssh_command(args.user, args.host, cleanup_cmd, args.insecure_hostkey)
    run_or_fail(ssh_cleanup, password, args.timeout, args.dry_run, "cleanup remote staging dir", args.retries)

    result = {
        "ok": True,
        "host": args.host,
        "user": args.user,
        "repo_root": args.repo_root,
        "files_deployed": FILES_TO_DEPLOY,
        "skip_upgrade": args.skip_upgrade,
        "dry_run": args.dry_run,
    }

    if args.base_url and not args.dry_run:
        base_url = args.base_url.rstrip("/")
        checks = []
        for name, url in [
            ("device_flow_start", f"{base_url}/local/aiagentapi/device_flow.php"),
            ("device_verify_page", f"{base_url}/local/aiagentapi/device_verify.php?user_code=PING"),
        ]:
            ok, detail = probe_http(url, timeout=10.0)
            checks.append({
                "name": name,
                "url": url,
                "ok": ok,
                "detail": detail,
                "http_status": parse_http_status(detail),
            })
        result["http_checks"] = checks

    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
