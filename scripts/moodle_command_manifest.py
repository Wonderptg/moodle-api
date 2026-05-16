#!/usr/bin/env python3
"""Query the Moodle OpenClaw command manifest.

This is the local implementation core for the future `moodle_catalog` tool.
It intentionally reads a static manifest and performs no Moodle network I/O.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent.parent
DEFAULT_MANIFEST = (
    ROOT
    / "agent-skills"
    / "moodle-aiagent-stack"
    / "references"
    / "command-manifest.v0.1.json"
)


def load_manifest(path: Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("manifest root must be an object")
    return payload


def emit(payload: dict[str, Any]) -> None:
    print(json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True))


def text_blob(entry: dict[str, Any]) -> str:
    parts: list[str] = []
    for key in ("id", "domain", "tool", "action", "layer", "risk", "webservice", "description"):
        value = entry.get(key)
        if value is not None:
            parts.append(str(value))
    examples = entry.get("examples")
    if isinstance(examples, list):
        parts.extend(str(item) for item in examples)
    hints = entry.get("repairHints")
    if isinstance(hints, list):
        parts.extend(str(item) for item in hints)
    return "\n".join(parts).lower()


def normalize_search_text(value: str) -> str:
    value = value.lower().replace("_", " ").replace("-", " ")
    return re.sub(r"\s+", " ", value).strip()


def apply_filters(
    entries: list[dict[str, Any]],
    *,
    query: str = "",
    domain: str = "",
    layer: str = "all",
    risk: str = "all",
    limit: int = 20,
) -> list[dict[str, Any]]:
    raw_query = query.strip().lower()
    normalized_terms = normalize_search_text(query).split()
    out: list[dict[str, Any]] = []
    for entry in entries:
        if domain and entry.get("domain") != domain:
            continue
        if layer != "all" and entry.get("layer") != layer:
            continue
        if risk != "all" and entry.get("risk") != risk:
            continue
        blob = text_blob(entry)
        normalized_blob = normalize_search_text(blob)
        if raw_query and raw_query not in blob and not all(term in normalized_blob for term in normalized_terms):
            continue
        out.append(entry)
        if len(out) >= limit:
            break
    return out


def compact_command(entry: dict[str, Any]) -> dict[str, Any]:
    return {
        "id": entry.get("id"),
        "domain": entry.get("domain"),
        "tool": entry.get("tool"),
        "action": entry.get("action"),
        "layer": entry.get("layer"),
        "risk": entry.get("risk"),
        "cli": entry.get("cli"),
        "webservice": entry.get("webservice"),
        "supportsDryRun": entry.get("supportsDryRun"),
        "requiresConfirm": entry.get("requiresConfirm"),
        "requiresIdempotencyKey": entry.get("requiresIdempotencyKey"),
        "examples": entry.get("examples", []),
        "repairHints": entry.get("repairHints", []),
    }


def index_by_id(entries: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    return {str(entry.get("id")): entry for entry in entries if entry.get("id")}


def command_search(args: argparse.Namespace, manifest: dict[str, Any]) -> dict[str, Any]:
    commands = [entry for entry in manifest.get("commands", []) if isinstance(entry, dict)]
    matches = apply_filters(
        commands,
        query=args.query,
        domain=args.domain,
        layer=args.layer,
        risk=args.risk,
        limit=args.limit,
    )
    return {
        "ok": True,
        "action": "search",
        "data": [compact_command(entry) for entry in matches],
        "meta": {
            "count": len(matches),
            "limit": args.limit,
            "schemaVersion": manifest.get("schemaVersion"),
        },
    }


def command_get_command(args: argparse.Namespace, manifest: dict[str, Any]) -> dict[str, Any]:
    commands = [entry for entry in manifest.get("commands", []) if isinstance(entry, dict)]
    entry = index_by_id(commands).get(args.id)
    return {
        "ok": entry is not None,
        "action": "get-command",
        "data": entry,
        "error": None if entry else f"Unknown command id: {args.id}",
    }


def command_get_function(args: argparse.Namespace, manifest: dict[str, Any]) -> dict[str, Any]:
    functions = [entry for entry in manifest.get("functions", []) if isinstance(entry, dict)]
    entry = index_by_id(functions).get(args.id)
    return {
        "ok": entry is not None,
        "action": "get-function",
        "data": entry,
        "error": None if entry else f"Unknown function id: {args.id}",
    }


def command_list_domains(_args: argparse.Namespace, manifest: dict[str, Any]) -> dict[str, Any]:
    domains = [entry for entry in manifest.get("domains", []) if isinstance(entry, dict)]
    return {
        "ok": True,
        "action": "list-domains",
        "data": domains,
        "meta": {"count": len(domains), "schemaVersion": manifest.get("schemaVersion")},
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Query Moodle OpenClaw command manifest")
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    subparsers = parser.add_subparsers(dest="command", required=True)

    search = subparsers.add_parser("search", description="Search manifest command entries")
    search.add_argument("query", nargs="?", default="")
    search.add_argument("--domain", default="")
    search.add_argument("--layer", choices=["shortcut", "api", "raw", "all"], default="all")
    search.add_argument("--risk", choices=["read", "local_write", "write", "high_write", "destructive", "all"], default="all")
    search.add_argument("--limit", type=int, default=20)
    search.set_defaults(handler=command_search)

    get_command = subparsers.add_parser("get-command", description="Get one command entry by id")
    get_command.add_argument("id")
    get_command.set_defaults(handler=command_get_command)

    get_function = subparsers.add_parser("get-function", description="Get one function entry by id")
    get_function.add_argument("id")
    get_function.set_defaults(handler=command_get_function)

    list_domains = subparsers.add_parser("list-domains", description="List manifest domains")
    list_domains.set_defaults(handler=command_list_domains)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    manifest = load_manifest(args.manifest.resolve())
    result = args.handler(args, manifest)
    emit(result)
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
