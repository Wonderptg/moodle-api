#!/usr/bin/env python3
"""Validate the Moodle OpenClaw command manifest."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Iterable


ROOT = Path(__file__).resolve().parent.parent
DEFAULT_MANIFEST = (
    ROOT
    / "agent-skills"
    / "moodle-aiagent-stack"
    / "references"
    / "command-manifest.v0.1.json"
)
VALID_RISKS = {"read", "local_write", "write", "high_write", "destructive"}
VALID_LAYERS = {"shortcut", "api", "raw"}
WRITE_RISKS = {"write", "high_write", "destructive"}


def load_json(path: Path) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"{path}: invalid JSON: {exc}") from exc
    if not isinstance(payload, dict):
        raise ValueError(f"{path}: manifest root must be an object")
    return payload


def require_list(payload: dict[str, Any], key: str) -> list[Any]:
    value = payload.get(key)
    if not isinstance(value, list):
        raise ValueError(f"manifest.{key} must be an array")
    return value


def find_duplicates(values: Iterable[str]) -> list[str]:
    seen: set[str] = set()
    duplicates: set[str] = set()
    for value in values:
        if value in seen:
            duplicates.add(value)
        seen.add(value)
    return sorted(duplicates)


def as_object(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ValueError(f"{label} must be an object")
    return value


def validate_examples(entry: dict[str, Any], label: str) -> list[str]:
    errors: list[str] = []
    examples = entry.get("examples", [])
    if not isinstance(examples, list):
        return [f"{label}.examples must be an array"]
    for index, example in enumerate(examples):
        if not isinstance(example, str) or not example.strip():
            errors.append(f"{label}.examples[{index}] must be a non-empty string")
            continue
        if not (
            example.startswith("python3 scripts/moodle_cli.py")
            or example.startswith("moodle ")
            or example.startswith("moodle --")
        ):
            errors.append(
                f"{label}.examples[{index}] should start with "
                "'python3 scripts/moodle_cli.py' or 'moodle'"
            )
    return errors


def validate_cli_params(command: dict[str, Any], label: str) -> list[str]:
    errors: list[str] = []
    cli = command.get("cli")
    params = command.get("params")
    if not isinstance(cli, dict) or not isinstance(params, dict):
        return errors

    argv = cli.get("argv")
    if not isinstance(argv, dict):
        return errors

    for param_name, flag in argv.items():
        param_label = f"{label}.cli.argv.{param_name}"
        if not isinstance(flag, str) or not flag:
            errors.append(f"{param_label} must be a non-empty string")
            continue

        schema = params.get(param_name)
        if not isinstance(schema, dict):
            errors.append(f"{param_label} references missing params.{param_name}")
            continue

        is_array = schema.get("type") == "array"
        if is_array and flag.startswith("--") and schema.get("repeatableCli") is not True:
            errors.append(f"{label}.params.{param_name} array flag mapping must set repeatableCli=true")
        if is_array and not flag.startswith("--") and schema.get("positionalCli") is not True:
            errors.append(f"{label}.params.{param_name} positional array mapping must set positionalCli=true")
        if not is_array and schema.get("repeatableCli") is True:
            errors.append(f"{label}.params.{param_name} repeatableCli=true requires type=array")
        if not is_array and schema.get("positionalCli") is True:
            errors.append(f"{label}.params.{param_name} positionalCli=true requires type=array")

    return errors


def validate_manifest(payload: dict[str, Any]) -> list[str]:
    errors: list[str] = []

    domains = [as_object(item, f"domains[{index}]") for index, item in enumerate(require_list(payload, "domains"))]
    commands = [as_object(item, f"commands[{index}]") for index, item in enumerate(require_list(payload, "commands"))]
    functions = [as_object(item, f"functions[{index}]") for index, item in enumerate(require_list(payload, "functions"))]

    domain_ids = [str(item.get("id", "")) for item in domains]
    command_ids = [str(item.get("id", "")) for item in commands]
    function_ids = [str(item.get("id", "")) for item in functions]

    for label, values in (("domain", domain_ids), ("command", command_ids), ("function", function_ids)):
        missing = [index for index, value in enumerate(values) if not value]
        if missing:
            errors.append(f"{label} ids missing at indexes {missing}")
        duplicates = find_duplicates(values)
        if duplicates:
            errors.append(f"duplicate {label} ids: {', '.join(duplicates)}")

    domain_set = set(domain_ids)
    tool_by_domain = {str(item.get("id")): str(item.get("tool", "")) for item in domains}

    for index, domain in enumerate(domains):
        label = f"domains[{index}]({domain.get('id')})"
        risk = domain.get("riskMax")
        if risk not in VALID_RISKS:
            errors.append(f"{label}.riskMax must be one of {sorted(VALID_RISKS)}")
        if not domain.get("tool"):
            errors.append(f"{label}.tool is required")
        if not domain.get("skill"):
            errors.append(f"{label}.skill is required")

    for index, command in enumerate(commands):
        label = f"commands[{index}]({command.get('id')})"
        domain_id = command.get("domain")
        if domain_id not in domain_set:
            errors.append(f"{label}.domain references unknown domain {domain_id!r}")
        elif command.get("tool") != tool_by_domain.get(str(domain_id)):
            errors.append(f"{label}.tool does not match domain tool {tool_by_domain.get(str(domain_id))!r}")

        if command.get("risk") not in VALID_RISKS:
            errors.append(f"{label}.risk must be one of {sorted(VALID_RISKS)}")
        if command.get("layer") not in VALID_LAYERS:
            errors.append(f"{label}.layer must be one of {sorted(VALID_LAYERS)}")
        if not command.get("action"):
            errors.append(f"{label}.action is required")

        cli = command.get("cli")
        if not isinstance(cli, dict):
            errors.append(f"{label}.cli must be an object")
        else:
            path = cli.get("path")
            if not isinstance(path, list) or not all(isinstance(part, str) and part for part in path):
                errors.append(f"{label}.cli.path must be a non-empty string array")
            argv = cli.get("argv")
            if not isinstance(argv, dict):
                errors.append(f"{label}.cli.argv must be an object")

        params = command.get("params")
        if not isinstance(params, dict):
            errors.append(f"{label}.params must be an object")
        else:
            errors.extend(validate_cli_params(command, label))

        risk = command.get("risk")
        if risk in WRITE_RISKS and command.get("requiresConfirm") is not True:
            errors.append(f"{label} has risk={risk!r} and must set requiresConfirm=true")
        if risk == "destructive":
            plugin_config = command.get("requiresPluginConfig")
            if not isinstance(plugin_config, dict) or plugin_config.get("allowDestructive") is not True:
                errors.append(f"{label} is destructive and must require allowDestructive=true")
        if command.get("requiresIdempotencyKey") not in (True, False):
            errors.append(f"{label}.requiresIdempotencyKey must be boolean")
        if command.get("supportsDryRun") not in (True, False):
            errors.append(f"{label}.supportsDryRun must be boolean")

        errors.extend(validate_examples(command, label))

    for index, function in enumerate(functions):
        label = f"functions[{index}]({function.get('id')})"
        if function.get("domain") not in domain_set:
            errors.append(f"{label}.domain references unknown domain {function.get('domain')!r}")
        if function.get("risk") not in VALID_RISKS:
            errors.append(f"{label}.risk must be one of {sorted(VALID_RISKS)}")
        if function.get("layer") not in VALID_LAYERS:
            errors.append(f"{label}.layer must be one of {sorted(VALID_LAYERS)}")
        if function.get("allowedByDefault") not in (True, False):
            errors.append(f"{label}.allowedByDefault must be boolean")
        if function.get("risk") in WRITE_RISKS and function.get("allowedByDefault") is True:
            errors.append(f"{label} is write-capable and should not be allowedByDefault")

    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate Moodle OpenClaw command manifest")
    parser.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    args = parser.parse_args()

    manifest_path = args.manifest.resolve()
    payload = load_json(manifest_path)
    errors = validate_manifest(payload)
    if errors:
        for error in errors:
            print(f"ERROR: {error}")
        return 1

    print(f"OK: {manifest_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
