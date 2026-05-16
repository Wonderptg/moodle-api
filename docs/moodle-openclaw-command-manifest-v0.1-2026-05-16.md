# Moodle OpenClaw Command Manifest v0.1

Date: 2026-05-16

This document defines the first command-manifest shape for the Moodle
OpenClaw tool layer. It is the next implementation step after:

- `docs/moodle-openclaw-tool-design-qa-2026-05-16.md`
- `docs/moodle-openclaw-tool-split-2026-05-16.md`

## Purpose

The command manifest should become the single source of truth for:

- `moodle_catalog`
- domain tool action routing
- CLI docs
- skill capability references
- risk gates
- dry-run and confirmation rules
- curated `moodle_api` function allowlists

It should describe what the tool layer may do without copying Moodle business
logic out of Moodle.

## Non-Goals

- Do not replace Moodle WebService definitions.
- Do not generate PHP business logic.
- Do not expose arbitrary Moodle REST calls.
- Do not include Student Shell or Plan Runtime commands in v0.1.
- Do not make every `local_aiagentapi_*` function a visible OpenClaw tool.

## Manifest File Candidates

Recommended source path:

```text
agent-skills/moodle-aiagent-stack/references/command-manifest.v0.1.json
```

Generated adapter copies:

```text
plugins/moodle-aiagent-stack/skills/moodle-aiagent-cli/references/command-manifest.v0.1.json
skills/moodle-aiagent-cli/references/command-manifest.v0.1.json
```

The source should live with the canonical skill source because
`scripts/sync_agent_skills.py` already treats that tree as the upstream for
adapters.

## Top-Level Shape

```json
{
  "schemaVersion": 1,
  "generatedAt": null,
  "source": "manual-v0.1",
  "cli": {
    "program": "python3 scripts/moodle_cli.py",
    "defaultOutput": "json"
  },
  "domains": [],
  "commands": [],
  "functions": []
}
```

## Domain Entry

```json
{
  "id": "quiz",
  "tool": "moodle_quiz",
  "skill": "moodle-quiz",
  "description": "Quiz listing, native practice quiz creation, attempt lifecycle, and structured answering.",
  "defaultVisible": false,
  "riskMax": "write"
}
```

Domain fields:

| Field | Meaning |
| --- | --- |
| `id` | Stable domain id. |
| `tool` | OpenClaw tool name. |
| `skill` | Skill that teaches this domain. |
| `description` | Short catalog text. |
| `defaultVisible` | Whether the tool should be available by default. |
| `riskMax` | Maximum risk level in the domain. |

## Command Entry

```json
{
  "id": "quiz.create_practice",
  "domain": "quiz",
  "tool": "moodle_quiz",
  "action": "create_practice",
  "layer": "shortcut",
  "risk": "write",
  "cli": {
    "path": ["quiz", "create-practice"],
    "argv": {
      "courseId": "--course-id",
      "categoryIds": "--category-id",
      "count": "--count",
      "selectionMode": "--selection-mode",
      "title": "--title",
      "section": "--section",
      "visible": "--visible",
      "reason": "--reason"
    }
  },
  "webservice": "local_aiagentapi_practice_quiz_create_from_resource",
  "supportsDryRun": true,
  "requiresConfirm": true,
  "requiresIdempotencyKey": true,
  "params": {},
  "examples": [],
  "repairHints": []
}
```

Command fields:

| Field | Meaning |
| --- | --- |
| `id` | Stable action id used by `moodle_catalog` and tools. |
| `domain` | Domain id. |
| `tool` | OpenClaw tool name. |
| `action` | Tool action. |
| `layer` | `shortcut`, `api`, or `raw`. |
| `risk` | `read`, `local_write`, `write`, `high_write`, or `destructive`. |
| `cli.path` | CLI subcommand path. |
| `cli.argv` | Mapping from manifest param names to CLI flags. |
| `webservice` | Moodle WebService function, if applicable. |
| `supportsDryRun` | Whether `--dry-run` is meaningful. |
| `requiresConfirm` | Whether tool execution must require confirmation. |
| `requiresIdempotencyKey` | Whether a stable idempotency key is required. |
| `params` | JSON Schema-like command parameter definition. |
| `examples` | Short examples for catalog output. |
| `repairHints` | Deterministic follow-up hints for common failures. |

## Function Entry

`functions` is for curated `moodle_api.call`, not default tool routing.

```json
{
  "id": "local_aiagentapi_quiz_list_by_course",
  "domain": "quiz",
  "layer": "api",
  "risk": "read",
  "allowedByDefault": true,
  "params": {
    "courseid": { "type": "integer", "required": true }
  }
}
```

Rules:

- `moodle_api` may call only listed functions.
- Write functions should be `allowedByDefault: false`.
- Unknown function ids must be rejected before network I/O.

## Risk Levels

| Risk | Meaning | Gate |
| --- | --- | --- |
| `read` | No Moodle state change. | No confirm. |
| `local_write` | Writes local CLI profile/config only. | Confirm only when overwriting/removing. |
| `write` | Changes Moodle state. | Dry-run first when supported, then confirm. |
| `high_write` | Irreversible or important user-visible state change. | Confirm, idempotency where possible, explicit audit. |
| `destructive` | Delete/removal behavior. | Plugin config must allow destructive actions plus confirm. |

Examples:

- `courses.outline`: `read`
- `auth.setup`: `local_write`
- `quiz.create_practice`: `write`
- `assignment.submit_final`: `high_write`
- `forum.delete_post`: `destructive`

## Parameter Shape

Use a compact JSON-Schema-like shape:

```json
{
  "courseId": {
    "type": "integer",
    "required": true,
    "cli": "--course-id",
    "api": "courseid",
    "description": "Moodle course id."
  },
  "categoryIds": {
    "type": "array",
    "items": { "type": "integer" },
    "required": false,
    "repeatableCli": true,
    "cli": "--category-id",
    "api": "categoryids",
    "description": "Repeatable Moodle question category ids."
  }
}
```

Parameter naming rule:

- Manifest names use camelCase for tool-facing params.
- CLI flags stay kebab-case.
- WebService params stay Moodle/PHP names such as `courseid`,
  `categoryids`, or `idempotencykey`.

## Seed Domains

```json
[
  {
    "id": "catalog",
    "tool": "moodle_catalog",
    "skill": "moodle-core",
    "description": "Search and inspect Moodle command/API manifest entries.",
    "defaultVisible": true,
    "riskMax": "read"
  },
  {
    "id": "auth",
    "tool": "moodle_auth",
    "skill": "moodle-auth",
    "description": "Profile setup, device-flow login, auth status, and logout.",
    "defaultVisible": true,
    "riskMax": "local_write"
  },
  {
    "id": "doctor",
    "tool": "moodle_doctor",
    "skill": "moodle-auth",
    "description": "Diagnose config, token, service, and Moodle capability problems.",
    "defaultVisible": true,
    "riskMax": "read"
  },
  {
    "id": "course",
    "tool": "moodle_course",
    "skill": "moodle-course",
    "description": "Read courses, activities, resources, grades, progress, and notifications.",
    "defaultVisible": false,
    "riskMax": "read"
  },
  {
    "id": "questionbank",
    "tool": "moodle_questionbank",
    "skill": "moodle-questionbank",
    "description": "Read Moodle question categories, search questions, pick random questions, and render question HTML.",
    "defaultVisible": false,
    "riskMax": "read"
  },
  {
    "id": "quiz",
    "tool": "moodle_quiz",
    "skill": "moodle-quiz",
    "description": "Quiz listing, native practice quiz creation, attempt lifecycle, and structured answering.",
    "defaultVisible": false,
    "riskMax": "write"
  },
  {
    "id": "calendar",
    "tool": "moodle_calendar",
    "skill": "moodle-calendar",
    "description": "List Moodle calendar events and publish/upsert study plan events.",
    "defaultVisible": false,
    "riskMax": "write"
  },
  {
    "id": "assignment",
    "tool": "moodle_assignment",
    "skill": "moodle-assignment",
    "description": "Read assignment status, save drafts, and submit final work.",
    "defaultVisible": false,
    "riskMax": "high_write"
  },
  {
    "id": "forum",
    "tool": "moodle_forum",
    "skill": "moodle-forum",
    "description": "Read discussions and create/reply/update/delete forum posts.",
    "defaultVisible": false,
    "riskMax": "destructive"
  }
]
```

## Seed Commands

Use these entries first to validate the manifest shape:

```json
[
  {
    "id": "course.context",
    "domain": "course",
    "tool": "moodle_course",
    "action": "context",
    "layer": "shortcut",
    "risk": "read",
    "cli": { "path": ["context", "get"], "argv": {} },
    "webservice": "local_aiagentapi_get_user_context",
    "supportsDryRun": false,
    "requiresConfirm": false,
    "requiresIdempotencyKey": false,
    "params": {},
    "examples": ["moodle --json context get"],
    "repairHints": ["If token is missing, run auth.status then auth.login_start."]
  },
  {
    "id": "course.outline",
    "domain": "course",
    "tool": "moodle_course",
    "action": "outline",
    "layer": "shortcut",
    "risk": "read",
    "cli": {
      "path": ["courses", "outline"],
      "argv": { "courseId": "--course-id" }
    },
    "webservice": "local_aiagentapi_course_get_outline",
    "supportsDryRun": false,
    "requiresConfirm": false,
    "requiresIdempotencyKey": false,
    "params": {
      "courseId": { "type": "integer", "required": true, "api": "courseid" }
    },
    "examples": ["moodle --json courses outline --course-id 116"],
    "repairHints": ["If the course is not visible, check enrollment and role permissions."]
  },
  {
    "id": "questionbank.categories",
    "domain": "questionbank",
    "tool": "moodle_questionbank",
    "action": "categories",
    "layer": "shortcut",
    "risk": "read",
    "cli": {
      "path": ["questions", "categories"],
      "argv": { "courseId": "--course-id" }
    },
    "webservice": "local_aiagentapi_question_categories_list",
    "supportsDryRun": false,
    "requiresConfirm": false,
    "requiresIdempotencyKey": false,
    "params": {
      "courseId": { "type": "integer", "required": true, "api": "courseid" }
    },
    "examples": ["moodle --json questions categories --course-id 116"],
    "repairHints": ["If categories are empty, check question bank permissions and course context."]
  },
  {
    "id": "quiz.create_practice",
    "domain": "quiz",
    "tool": "moodle_quiz",
    "action": "create_practice",
    "layer": "shortcut",
    "risk": "write",
    "cli": {
      "path": ["quiz", "create-practice"],
      "argv": {
        "courseId": "--course-id",
        "cmid": "--cmid",
        "lessonKey": "--lesson-key",
        "title": "--title",
        "count": "--count",
        "section": "--section",
        "categoryIds": "--category-id",
        "kgIds": "--kg-id",
        "qgIds": "--qg-id",
        "tags": "--tag",
        "seed": "--seed",
        "allowPartial": "--allow-partial",
        "selectionMode": "--selection-mode",
        "random": "--random",
        "visible": "--visible",
        "reason": "--reason"
      }
    },
    "webservice": "local_aiagentapi_practice_quiz_create_from_resource",
    "supportsDryRun": true,
    "requiresConfirm": true,
    "requiresIdempotencyKey": true,
    "params": {
      "courseId": { "type": "integer", "required": true, "api": "courseid" },
      "categoryIds": {
        "type": "array",
        "items": { "type": "integer" },
        "required": false,
        "repeatableCli": true,
        "api": "categoryids"
      },
      "count": { "type": "integer", "required": false, "minimum": 1, "maximum": 120, "default": 5 },
      "selectionMode": { "type": "string", "enum": ["fixed", "random_category"], "default": "fixed" },
      "random": { "type": "boolean", "required": false, "description": "Shortcut for selectionMode=random_category." },
      "title": { "type": "string", "required": false },
      "visible": { "type": "boolean", "required": false }
    },
    "examples": [
      "moodle --profile dzexam --json --dry-run quiz create-practice --idempotency-key <key> --course-id 116 --category-id 619 --category-id 616 --count 30 --random"
    ],
    "repairHints": [
      "If creation fails with permission errors, check local/aiagentapi:use and quiz/question bank capabilities.",
      "If count validation fails, current supported maximum is 120."
    ]
  },
  {
    "id": "quiz.start",
    "domain": "quiz",
    "tool": "moodle_quiz",
    "action": "start",
    "layer": "shortcut",
    "risk": "write",
    "cli": {
      "path": ["quiz", "start"],
      "argv": {
        "quizId": "--quiz-id",
        "cmid": "--cmid"
      }
    },
    "webservice": "local_aiagentapi_quiz_start_attempt",
    "supportsDryRun": false,
    "requiresConfirm": true,
    "requiresIdempotencyKey": true,
    "params": {
      "quizId": { "type": "integer", "required": false, "api": "quizid" },
      "cmid": { "type": "integer", "required": false, "api": "cmid" }
    },
    "examples": ["moodle --json --force quiz start --idempotency-key <key> --quiz-id 123"],
    "repairHints": ["If no quiz id is known, call quiz.list first."]
  },
  {
    "id": "forum.delete_post",
    "domain": "forum",
    "tool": "moodle_forum",
    "action": "delete_post",
    "layer": "shortcut",
    "risk": "destructive",
    "cli": {
      "path": ["forum", "delete-post"],
      "argv": {
        "idempotencyKey": "--idempotency-key",
        "postId": "--post-id",
        "reason": "--reason"
      }
    },
    "webservice": "local_aiagentapi_forum_delete_post",
    "supportsDryRun": true,
    "requiresConfirm": true,
    "requiresIdempotencyKey": true,
    "requiresPluginConfig": { "allowDestructive": true },
    "params": {
      "idempotencyKey": { "type": "string", "required": true, "api": "idempotency_key" },
      "postId": { "type": "integer", "required": true, "api": "postid" },
      "reason": { "type": "string", "required": false, "api": "reason" }
    },
    "examples": [
      "python3 scripts/moodle_cli.py --json --dry-run forum delete-post --idempotency-key <key> --post-id 123"
    ],
    "repairHints": [
      "Only use after explicit user request and permission confirmation. Actual delete requires allowDestructive=true."
    ]
  }
]
```

## Generation Plan

1. Hand-author v0.1 manifest for the core domains above.
2. Add a validator script that checks:
   - unique command ids
   - known tools/domains
   - known risk values
   - all write commands have gates
   - all command examples start with `moodle` or `python3 scripts/moodle_cli.py`
3. Teach `scripts/sync_agent_skills.py` to copy manifest references.
4. Later, generate more manifest fields from `scripts/moodle_cli.py --json schema`.

## Implementation Status

Implemented in this workspace:

- Source manifest:
  `agent-skills/moodle-aiagent-stack/references/command-manifest.v0.1.json`
- Synced adapter copies:
  `plugins/moodle-aiagent-stack/skills/moodle-aiagent-cli/references/command-manifest.v0.1.json`
  `skills/moodle-aiagent-cli/references/command-manifest.v0.1.json`
- Validator:
  `scripts/validate_command_manifest.py`
- OpenClaw plugin wiring validator:
  `scripts/validate_openclaw_moodle_plugin.py`
- Local catalog query core for future `moodle_catalog`:
  `scripts/moodle_command_manifest.py`

Current manifest size:

- 9 domains
- 44 command entries
- 25 curated function entries

Current validation commands:

```bash
python3 -m json.tool agent-skills/moodle-aiagent-stack/references/command-manifest.v0.1.json
python3 scripts/validate_command_manifest.py
python3 scripts/validate_openclaw_moodle_plugin.py
python3 scripts/moodle_command_manifest.py search "create practice" --domain quiz
python3 scripts/moodle_command_manifest.py get-command quiz.create_practice
python3 scripts/moodle_command_manifest.py get-function local_aiagentapi_practice_quiz_create_from_resource
```

First OpenClaw runtime plugin files are also present:

```text
plugins/moodle-aiagent-stack/openclaw.plugin.json
plugins/moodle-aiagent-stack/package.json
plugins/moodle-aiagent-stack/index.ts
```

Current `openclaw.plugin.json` declares implemented runtime tools:
`moodle_catalog`, `moodle_auth`, `moodle_doctor`, `moodle_course`,
`moodle_questionbank`, `moodle_quiz`, `moodle_calendar`,
`moodle_assignment`, `moodle_forum`, and `moodle_api`.

The package metadata is intentionally present even for local development:
OpenClaw 2026.4.26 discovers `package.json#openclaw.extensions` before
Claude-compatible bundle metadata. Because this plugin also carries
`.claude-plugin/plugin.json` for Claude/marketplace compatibility, omitting the
package metadata causes OpenClaw to load it as a Claude bundle with skills only
and no registered tools.

Local OpenClaw validation result:

```bash
OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins install \
  --dangerously-force-unsafe-install \
  -l /Users/wonder/Documents/moodle/plugins/moodle-aiagent-stack

OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins registry --refresh --json

OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local config validate --json

OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins inspect moodle-aiagent-stack --json
```

The install command warns about `child_process`, which is expected because the
runtime executes `python3 scripts/moodle_cli.py` with structured argv. After the
package metadata fix, `plugins inspect` reports `format: "openclaw"`,
`imported: true`, `configSchema: true`, and 10 registered tools.

`moodle_quiz` now has manifest entries for the read actions
`list`, `resolve_random`, `attempts`, `attempt_data`, and `attempt_summary`,
plus guarded write actions `create_practice`, `start`, `save_attempt`,
`submit_attempt`, and `answer`. Quiz writes are marked with confirmation and
idempotency gates; `submit_attempt` and `answer` are `high_write` because they
can submit student attempt state.

`moodle_calendar`, `moodle_assignment`, and `moodle_forum` are now represented
in the manifest and runtime. Calendar and assignment writes use the same
dry-run/confirm/idempotency gate. Forum delete is `destructive`, requires an
idempotency key, supports dry-run previews, and requires plugin config
`allowDestructive=true` before any non-dry-run delete can execute.

`moodle_api` is implemented as an advanced lower layer over manifest-listed
functions. `list_allowed` and `get_function` are read-only discovery actions.
`call` is disabled unless plugin config `enableApiTool=true`; with no explicit
`allowedApiFunctions`, only functions marked `allowedByDefault=true` are
callable. Write-capable functions require `allowApiWrites=true` plus the same
dry-run/confirm/idempotency gates used by domain tools.

## Functionality Audit

Post-implementation audit on 2026-05-16:

- `scripts/moodle_cli.py --json schema` exposes 78 leaf commands.
- After intentionally excluding `mathstate`, `config`, `agent`, schema/debug
  commands, and duplicate profile/auth management commands outside this
  plugin's target surface, the target business surface is 44 CLI paths.
- `command-manifest.v0.1.json` maps exactly those 44 target paths with no
  missing business path and no manifest path that is absent from the CLI.
- The manifest has 9 domains, 44 command entries, and 25 curated
  `local_aiagentapi_*` function entries.

One consistency gap was fixed during the audit: `questionbank.render_html`
uses repeatable positional question ids, so `params.questionIds` now declares
`positionalCli=true`. The manifest validator now checks array CLI mappings:
flag arrays must declare `repeatableCli=true`, and positional arrays must
declare `positionalCli=true`.

The runtime `moodle_api.call` path was also tightened so required array params
cannot be satisfied by an empty array.

## Open Questions

- Whether some domain tools should become optional/profile-gated to reduce
  OpenClaw context pressure. `moodle_api` is now optional because it is an
  advanced escape hatch; a future context-pressure pass could also make
  heavier/less common domain tools optional if real sessions feel too heavy.
- Whether `moodle_api` belongs in the first runnable plugin. Current
  recommendation: define its manifest role now, implement it last.
- Whether `moodle_mathstate` should become a separate plugin. Current
  recommendation: yes, defer until Plan Runtime boundaries are reviewed.
