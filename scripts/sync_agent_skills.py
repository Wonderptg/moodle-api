#!/usr/bin/env python3
"""Sync the canonical generic skill into platform-specific adapters."""

from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional


ROOT = Path(__file__).resolve().parent.parent
CANONICAL_SKILL = ROOT / "agent-skills" / "moodle-aiagent-stack" / "SKILL.md"
CANONICAL_REF_DIR = ROOT / "agent-skills" / "moodle-aiagent-stack" / "references"
CANONICAL_REF = CANONICAL_REF_DIR / "capabilities.md"
MOODLE_CLI = ROOT / "scripts" / "moodle_cli.py"


@dataclass(frozen=True)
class AdapterSpec:
    path: Path
    reference_path: Path
    name: str
    description: str
    title: str
    adapter_note: str
    metadata: Optional[str] = None


ADAPTERS = [
    AdapterSpec(
        path=ROOT / ".claude" / "skills" / "moodle-aiagent-cli" / "SKILL.md",
        reference_path=ROOT / ".claude" / "skills" / "moodle-aiagent-cli" / "references" / "capabilities.md",
        name="moodle-aiagent-cli",
        description=(
            "Use when working with the local Moodle AI agent stack in this repository: "
            "reading or writing Moodle data through the remote WebService CLI, "
            "seeding demo data, running live regression, or extending `local_aiagentapi`. Trigger "
            "for tasks involving courses, calendar plans, assignments, forums, quizzes, question "
            "banks, or Moodle automation in this repo."
        ),
        title="Moodle AI Agent CLI",
        adapter_note="This is a Claude Code adapter for the canonical generic skill.",
    ),
    AdapterSpec(
        path=ROOT / "plugins" / "moodle-aiagent-stack" / "skills" / "moodle-aiagent-cli" / "SKILL.md",
        reference_path=ROOT
        / "plugins"
        / "moodle-aiagent-stack"
        / "skills"
        / "moodle-aiagent-cli"
        / "references"
        / "capabilities.md",
        name="moodle-aiagent-cli",
        description=(
            "Use when working with the local Moodle AI agent stack in this repository: "
            "reading or writing Moodle data through the remote WebService CLI, "
            "seeding demo data, running live regression, or extending `local_aiagentapi`. Trigger "
            "for tasks involving courses, calendar plans, assignments, forums, quizzes, question "
            "banks, or Moodle automation in this repo."
        ),
        title="Moodle AI Agent CLI",
        adapter_note="This is a Claude Code plugin adapter for the canonical generic skill.",
    ),
    AdapterSpec(
        path=ROOT / "skills" / "moodle-aiagent-cli" / "SKILL.md",
        reference_path=ROOT / "skills" / "moodle-aiagent-cli" / "references" / "capabilities.md",
        name="moodle-aiagent-cli",
        description=(
            "Use this skill for the Moodle AI agent stack in this repository. It covers Moodle "
            "reads and writes through the remote WebService CLI, seeded regression, quiz attempts, question-bank "
            "actions, calendar plans, assignments, forums, and extending `local_aiagentapi`."
        ),
        title="Moodle AI Agent CLI",
        adapter_note="This is an OpenClaw-oriented adapter for the canonical generic skill.",
        metadata='metadata: {"openclaw":{"requires":{"bins":["python3"]}}}',
    ),
]


def parse_frontmatter(text: str) -> tuple[str, str]:
    if not text.startswith("---\n"):
        raise ValueError("Expected markdown frontmatter.")
    parts = text.split("\n---\n", 1)
    if len(parts) != 2:
        raise ValueError("Unclosed markdown frontmatter.")
    return parts[0][4:], parts[1]


def strip_first_heading(markdown_body: str) -> str:
    lines = markdown_body.splitlines()
    while lines and not lines[0].strip():
        lines = lines[1:]
    if lines and lines[0].startswith("# "):
        lines = lines[1:]
        if lines and not lines[0].strip():
            lines = lines[1:]
    return "\n".join(lines).rstrip() + "\n"


def build_frontmatter(spec: AdapterSpec) -> str:
    lines = [
        "---",
        f"name: {spec.name}",
        f"description: {spec.description}",
    ]
    if spec.metadata:
        lines.append(spec.metadata)
    lines.append("---")
    return "\n".join(lines)


def build_adapter_markdown(spec: AdapterSpec, canonical_body: str) -> str:
    adapter_header = (
        f"# {spec.title}\n\n"
        "<!-- Generated from agent-skills/moodle-aiagent-stack/SKILL.md by scripts/sync_agent_skills.py -->\n\n"
        f"{spec.adapter_note} Canonical source: `agent-skills/moodle-aiagent-stack/SKILL.md`.\n\n"
    )
    return build_frontmatter(spec) + "\n\n" + adapter_header + canonical_body


def build_reference_markdown(canonical_reference: str) -> str:
    header = "<!-- Generated from agent-skills/moodle-aiagent-stack/references/capabilities.md by scripts/sync_agent_skills.py -->\n\n"
    return header + canonical_reference.lstrip()


def sync_reference_assets(target_dir: Path) -> None:
    target_dir.mkdir(parents=True, exist_ok=True)
    for source_path in sorted(CANONICAL_REF_DIR.iterdir()):
        if not source_path.is_file() or source_path.name == CANONICAL_REF.name:
            continue
        target_path = target_dir / source_path.name
        target_path.write_bytes(source_path.read_bytes())


def schema() -> Dict[str, Any]:
    out = subprocess.check_output(
        ["python3", str(MOODLE_CLI), "--json", "schema"],
        cwd=str(ROOT),
        text=True,
    )
    payload = json.loads(out)
    return payload["command"]


def collect_leaf_commands(node: Dict[str, Any]) -> List[str]:
    subcommands = node.get("subcommands") or []
    if not subcommands:
        path = str(node.get("path", "")).strip()
        return [path.replace("moodle ", "", 1)] if path else []
    out: List[str] = []
    for sub in subcommands:
        out.extend(collect_leaf_commands(sub))
    return out


def generate_canonical_reference() -> str:
    root = schema()
    all_leaf_commands = sorted(set(collect_leaf_commands(root)))

    groups = [
        (
            "Bootstrap and discovery",
            {"setup", "login", "status", "context", "catalog", "schema", "whoami", "exit-codes", "agent"},
        ),
        (
            "Course and activity reads",
            {"courses", "activities", "resources"},
        ),
        (
            "Assignments",
            {"assignments"},
        ),
        (
            "Calendar",
            {"calendar"},
        ),
        (
            "Forum and notifications",
            {"forum", "notifications"},
        ),
        (
            "Questions and question bank",
            {"questions"},
        ),
        (
            "Quiz lifecycle",
            {"quiz"},
        ),
        (
            "Grades and progress",
            {"grades", "progress"},
        ),
    ]

    grouped: List[str] = []
    remaining = list(all_leaf_commands)
    for title, prefixes in groups:
        commands = [cmd for cmd in remaining if cmd.split()[0] in prefixes]
        if not commands:
            continue
        grouped.append(f"### {title}")
        grouped.append("")
        grouped.extend(f"- `{cmd}`" for cmd in commands)
        grouped.append("")
        remaining = [cmd for cmd in remaining if cmd not in commands]

    if remaining:
        grouped.append("### Other commands")
        grouped.append("")
        grouped.extend(f"- `{cmd}`" for cmd in remaining)
        grouped.append("")

    root_flags = [
        "--json",
        "--plain",
        "--results-only",
        "--select",
        "--dry-run",
        "--force",
        "--no-input",
        "--enable-commands",
        "--env-file",
        "--profile",
        "--base-url",
        "--token",
        "--service",
    ]

    lines = [
        "# Generic Skill Reference: Moodle AI Agent Stack",
        "",
        "This file is generated from `scripts/moodle_cli.py --json schema` by `scripts/sync_agent_skills.py`.",
        "",
        "## Current architecture",
        "",
        "- Backend source of truth: `public/local/aiagentapi`",
        "- Agent-facing remote WebService CLI: `scripts/moodle_cli.py`",
        "- WebService endpoint: `<base-url>/webservice/rest/server.php`",
        "- Moodle PHP maintenance scripts: `scripts/*.php` and `admin/cli/*.php`",
        "- Seed fixtures: `scripts/seed_moodle_test_data.php`",
        "- Live regression: `scripts/test_moodle_live_cli.py`",
        "",
        "## Tool boundary",
        "",
        "- Use `python3 scripts/moodle_cli.py ...` for normal agent operations, including online quiz creation.",
        "- This remote CLI needs Python plus a Moodle base URL and WebService token/profile.",
        "- The remote CLI does not need local PHP, `public/config.php`, or the Moodle server code directory.",
        "- Use PHP scripts only for Moodle internal maintenance such as plugin upgrade, service registration, data imports, or seeding.",
        "- PHP scripts must run in a Moodle code tree with PHP and `config.php`.",
        "",
        "## Root contract",
        "",
        "- Common flags: " + ", ".join(f"`{flag}`" for flag in root_flags),
        "- Write commands should use `--dry-run` first when supported, then rerun with `--force`.",
        "- `catalog get` and `context get` are the preferred first calls for discovery.",
        "- First-time login should use `setup --name <profile> --base-url <url>`, then `login --name <profile>`, then `status --name <profile>`.",
        "- In headless agent sessions, use `login --name <profile> --no-wait` and return the `verification_url` to the user.",
        "- OpenClaw tool/action routing should use `references/command-manifest.v0.1.json` as the source of truth.",
        "",
        "## Generated command inventory",
        "",
    ]
    lines.extend(grouped)
    lines.extend(
        [
            "## Structured quiz answering",
            "",
            "- Preferred path: `quiz start` -> `quiz attempt-data` -> `quiz answer` -> `quiz submit-attempt`",
            "- `quiz answer` reads `responseschema`, translates structured answers into Moodle raw field names, then saves or submits through stable low-level web-service calls.",
            "",
            "## Stable seeded fixtures",
            "",
            "- Course: `AIAGENT_DEMO_101`",
            "- Question bank: `AI Agent Question Bank`",
            "- Category: `AI Agent Demo Category`",
            "- Assignment: `AI Agent Assignment 1`",
            "- Forum: `AI Agent Forum`",
            "- Random-resolution quiz: `AI Agent Quiz 1`",
            "- Stable answering quiz: `AI Agent Answer Quiz 1`",
            "",
            "## Fixed validation entrypoints",
            "",
            "```bash",
            "php scripts/seed_moodle_test_data.php",
            "php scripts/register_aiagentapi_service_functions.php",
            "python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed",
            "```",
            "",
        ]
    )
    return "\n".join(lines)


def write_text(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def main() -> int:
    canonical_skill_text = CANONICAL_SKILL.read_text(encoding="utf-8")
    _, canonical_body = parse_frontmatter(canonical_skill_text)
    canonical_body = strip_first_heading(canonical_body)
    canonical_reference = generate_canonical_reference()
    write_text(CANONICAL_REF, canonical_reference)

    for spec in ADAPTERS:
        write_text(spec.path, build_adapter_markdown(spec, canonical_body))
        write_text(spec.reference_path, build_reference_markdown(canonical_reference))
        sync_reference_assets(spec.reference_path.parent)

    print("Generated canonical Moodle skill reference and synced Claude/OpenClaw adapters.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
