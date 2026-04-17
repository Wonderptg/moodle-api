import argparse
import os
import shlex
import textwrap

import pexpect

DEFAULT_SCRIPT = textwrap.dedent(
    """
    printf -- "--- nginx config ---\\n"
    if command -v nginx >/dev/null 2>&1; then
      nginx -T 2>/dev/null | sed -n "1,220p"
    else
      echo "nginx not installed"
    fi

    printf -- "\\n--- apache vhosts ---\\n"
    if command -v apachectl >/dev/null 2>&1; then
      apachectl -S 2>/dev/null | sed -n "1,220p"
    elif command -v httpd >/dev/null 2>&1; then
      httpd -S 2>/dev/null | sed -n "1,220p"
    else
      echo "apache/httpd not installed"
    fi

    printf -- "\\n--- document root refs ---\\n"
    grep -Rni "DocumentRoot\\|root\\s\\|server_name\\|VirtualHost" /etc/nginx /etc/httpd /etc/apache2 2>/dev/null | sed -n "1,220p"

    printf -- "\\n--- candidate config.php files ---\\n"
    find /srv /var/www /usr/share/nginx/html /opt -type f -name config.php 2>/dev/null | sed -n "1,220p"
    """
).strip()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Probe a remote Moodle server over SSH without hardcoded credentials.")
    parser.add_argument("--host", default=os.environ.get("SERVER_PROBE_HOST"))
    parser.add_argument("--user", default=os.environ.get("SERVER_PROBE_USER", "root"))
    parser.add_argument("--password", default=os.environ.get("SERVER_PROBE_PASSWORD"))
    parser.add_argument("--timeout", type=int, default=int(os.environ.get("SERVER_PROBE_TIMEOUT", "60")))
    parser.add_argument("--script-file")
    parser.add_argument("--insecure-hostkey", action="store_true")
    return parser.parse_args()


def load_script(script_file: str | None) -> str:
    if script_file:
        with open(script_file, "r", encoding="utf-8") as handle:
            return handle.read().strip()
    return DEFAULT_SCRIPT


def build_ssh_command(user: str, host: str, insecure_hostkey: bool) -> str:
    command = ["ssh", "-tt"]
    if insecure_hostkey:
        command.extend(["-o", "StrictHostKeyChecking=no", "-o", "UserKnownHostsFile=/dev/null"])
    command.append(f"{user}@{host}")
    return " ".join(shlex.quote(part) for part in command)


def open_shell(ssh_command: str, password: str | None, timeout: int) -> pexpect.spawn:
    child = pexpect.spawn(ssh_command, encoding="utf-8", timeout=timeout)
    patterns = [r"[Pp]assword:", r"[#\\$] ", r"yes/no", pexpect.EOF, pexpect.TIMEOUT]
    while True:
        matched = child.expect(patterns)
        if matched == 0:
            if not password:
                raise RuntimeError("SSH requested a password but SERVER_PROBE_PASSWORD / --password was not provided.")
            child.sendline(password)
            continue
        if matched == 1:
            return child
        if matched == 2:
            raise RuntimeError("SSH asked to confirm the host key. Re-run with --insecure-hostkey or pre-seed known_hosts.")
        if matched == 3:
            raise RuntimeError("SSH session closed before a shell prompt became available.")
        raise RuntimeError("Timed out while opening SSH session.")


def run_probe(host: str, user: str, password: str | None, script: str, timeout: int, insecure_hostkey: bool) -> str:
    shell = open_shell(build_ssh_command(user, host, insecure_hostkey), password, timeout)
    shell.sendline("set -e")
    for line in script.splitlines():
        shell.sendline(line)
    shell.sendline("exit")
    shell.expect(pexpect.EOF)
    return shell.before


def main() -> int:
    args = parse_args()
    if not args.host:
        raise SystemExit("Missing host. Set SERVER_PROBE_HOST or pass --host.")
    script = load_script(args.script_file)
    print(run_probe(args.host, args.user, args.password, script, args.timeout, args.insecure_hostkey))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
