#!/usr/bin/env python3
"""Validate the Moodle OpenClaw plugin wiring.

This is intentionally a lightweight static check. The workspace does not ship
OpenClaw's TypeScript SDK dependencies, so this script catches the contract
drift we can validate locally: plugin manifest, runtime registrations, command
manifest, copied adapter references, and safety-gate config.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent.parent
PLUGIN_DIR = ROOT / "plugins" / "moodle-aiagent-stack"
PLUGIN_MANIFEST = PLUGIN_DIR / "openclaw.plugin.json"
PLUGIN_PACKAGE = PLUGIN_DIR / "package.json"
PLUGIN_SOURCE = PLUGIN_DIR / "index.ts"
PLUGIN_RUNTIME = PLUGIN_DIR / "shared" / "runtime.mjs"
COMMAND_MANIFEST = (
    ROOT
    / "agent-skills"
    / "moodle-aiagent-stack"
    / "references"
    / "command-manifest.v0.1.json"
)
SYNCED_COMMAND_MANIFESTS = [
    PLUGIN_DIR / "skills" / "moodle-aiagent-cli" / "references" / "command-manifest.v0.1.json",
    ROOT / "skills" / "moodle-aiagent-cli" / "references" / "command-manifest.v0.1.json",
]
WRITE_RISKS = {"write", "high_write", "destructive"}
REQUIRED_CONFIG_KEYS = {
    "manifestPath",
    "maxSearchResults",
    "repoRoot",
    "pythonBin",
    "cliPath",
    "commandTimeoutMs",
    "defaultWriteDryRun",
    "allowDestructive",
    "enableApiTool",
    "allowApiWrites",
    "allowedApiFunctions",
}


def load_json(path: Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError(f"{path}: expected JSON object")
    return payload


def registered_tools(source: str) -> list[str]:
    return re.findall(r'name:\s*"([^"]+)"', source)


def duplicates(values: list[str]) -> list[str]:
    seen: set[str] = set()
    dupes: set[str] = set()
    for value in values:
        if value in seen:
            dupes.add(value)
        seen.add(value)
    return sorted(dupes)


def runtime_tool_actions(source: str) -> dict[str, set[str]]:
    match = re.search(r"export\s+const\s+TOOL_ACTIONS\s*=\s*\{(?P<body>.*?)\n\};", source, re.S)
    if not match:
        return {}
    body = match.group("body")
    actions: dict[str, set[str]] = {}
    for tool, values in re.findall(r"(moodle_[A-Za-z0-9_]+)\s*:\s*\[(.*?)\]", body, re.S):
        actions[tool] = set(re.findall(r'"([^"]+)"', values))
    return actions


def validate() -> list[str]:
    errors: list[str] = []

    plugin = load_json(PLUGIN_MANIFEST)
    package = load_json(PLUGIN_PACKAGE)
    source = PLUGIN_SOURCE.read_text(encoding="utf-8")
    runtime_source = PLUGIN_RUNTIME.read_text(encoding="utf-8")
    command_manifest = load_json(COMMAND_MANIFEST)

    package_openclaw = package.get("openclaw")
    if not isinstance(package_openclaw, dict):
        errors.append("package.json must include openclaw metadata")
    else:
        extensions = package_openclaw.get("extensions")
        if extensions != ["./index.ts"]:
            errors.append('package.json openclaw.extensions must be ["./index.ts"]')

    if "@sinclair/typebox" in source or 'from "typebox"' in source:
        errors.append("runtime source should not depend on external TypeBox packages for local OpenClaw loading")

    tools = (((plugin.get("contracts") or {}).get("tools")) or [])
    if not isinstance(tools, list) or not all(isinstance(item, str) for item in tools):
        errors.append("openclaw.plugin.json contracts.tools must be a string array")
        tools = []
    tool_names = list(tools)

    tool_dupes = duplicates(tool_names)
    if tool_dupes:
        errors.append(f"duplicate tool contracts: {', '.join(tool_dupes)}")

    registrations = registered_tools(source)
    registration_dupes = duplicates(registrations)
    if registration_dupes:
        errors.append(f"duplicate runtime registrations: {', '.join(registration_dupes)}")

    missing = sorted(set(tool_names) - set(registrations))
    extra = sorted(set(registrations) - set(tool_names))
    if missing:
        errors.append(f"tools declared but not registered: {', '.join(missing)}")
    if extra:
        errors.append(f"tools registered but not declared: {', '.join(extra)}")

    config_schema = plugin.get("configSchema")
    if not isinstance(config_schema, dict):
        errors.append("openclaw.plugin.json configSchema must be an object")
        config_props: dict[str, Any] = {}
    else:
        if config_schema.get("additionalProperties") is not False:
            errors.append("configSchema.additionalProperties must be false")
        props = config_schema.get("properties")
        config_props = props if isinstance(props, dict) else {}

    missing_config = sorted(REQUIRED_CONFIG_KEYS - set(config_props))
    if missing_config:
        errors.append(f"configSchema missing required keys: {', '.join(missing_config)}")

    if (config_props.get("defaultWriteDryRun") or {}).get("default") is not True:
        errors.append("defaultWriteDryRun default must be true")
    if (config_props.get("allowDestructive") or {}).get("default") is not False:
        errors.append("allowDestructive default must be false")
    if (config_props.get("enableApiTool") or {}).get("default") is not False:
        errors.append("enableApiTool default must be false")
    if (config_props.get("allowApiWrites") or {}).get("default") is not False:
        errors.append("allowApiWrites default must be false")

    domains = [item for item in command_manifest.get("domains", []) if isinstance(item, dict)]
    commands = [item for item in command_manifest.get("commands", []) if isinstance(item, dict)]
    functions = [item for item in command_manifest.get("functions", []) if isinstance(item, dict)]

    for domain in domains:
        tool = str(domain.get("tool") or "")
        if tool not in tool_names:
            errors.append(f"domain {domain.get('id')} uses undeclared tool {tool}")

    for command in commands:
        label = str(command.get("id") or "<missing>")
        tool = str(command.get("tool") or "")
        risk = str(command.get("risk") or "")
        if tool not in tool_names:
            errors.append(f"command {label} uses undeclared tool {tool}")
        if risk in WRITE_RISKS:
            if command.get("requiresConfirm") is not True:
                errors.append(f"write command {label} must require confirm")
            if command.get("supportsDryRun") is not True:
                errors.append(f"write command {label} should support dry-run")
        if risk in WRITE_RISKS and command.get("requiresIdempotencyKey") is not True:
            errors.append(f"write command {label} must require idempotency key")
        if risk == "destructive":
            required_config = command.get("requiresPluginConfig")
            if not isinstance(required_config, dict) or required_config.get("allowDestructive") is not True:
                errors.append(f"destructive command {label} must require allowDestructive=true")

    tool_actions = runtime_tool_actions(runtime_source)
    if not tool_actions:
        errors.append("shared runtime must export parseable TOOL_ACTIONS")
    else:
        for command in commands:
            tool = str(command.get("tool") or "")
            action = str(command.get("action") or "")
            if tool == "moodle_catalog":
                continue
            if tool in tool_names and action and action not in tool_actions.get(tool, set()):
                errors.append(f"command {command.get('id')} action {action!r} missing from shared TOOL_ACTIONS.{tool}")

    for function in functions:
        function_id = str(function.get("id") or "")
        if not function_id.startswith("local_aiagentapi_"):
            errors.append(f"moodle_api function entry is not local_aiagentapi_*: {function_id}")
        if function.get("risk") in WRITE_RISKS and function.get("allowedByDefault") is True:
            errors.append(f"write function {function_id} must not be allowedByDefault")

    canonical_bytes = COMMAND_MANIFEST.read_bytes()
    for synced_path in SYNCED_COMMAND_MANIFESTS:
        if not synced_path.exists():
            errors.append(f"missing synced manifest copy: {synced_path.relative_to(ROOT)}")
            continue
        if synced_path.read_bytes() != canonical_bytes:
            errors.append(f"synced manifest is stale: {synced_path.relative_to(ROOT)}")

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate Moodle OpenClaw plugin wiring")
    parser.parse_args()
    errors = validate()
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1
    print(f"OK: {PLUGIN_MANIFEST}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
