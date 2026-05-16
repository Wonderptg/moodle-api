# Moodle OpenClaw Tool Split

Date: 2026-05-16

This note turns the OpenClaw/Feishu plugin research into a concrete tool split
for the local Moodle AI agent stack.

It continues:

- `docs/moodle-cli-refactor-reference-2026-05-16.md`
- `docs/openclaw-plugin-research-2026-05-16.md`

## Bottom Line

Do not expose one OpenClaw tool per `local_aiagentapi_*` function.
Do not expose only one generic `moodle_cli` shell-pass-through tool either.

The tasteful split is a small set of first-class, domain-shaped tools:

- `moodle_catalog`
- `moodle_auth`
- `moodle_doctor`
- `moodle_course`
- `moodle_questionbank`
- `moodle_quiz`
- `moodle_calendar`
- `moodle_assignment`
- `moodle_forum`
- `moodle_api` as an advanced, off-by-default escape hatch

That gives Moodle roughly the same shape as Feishu/Lark's mature plugin:
business-domain tools, separate auth/repair tools, and a catalog layer that
keeps rare details out of the model's default context.

## Why This Split

Current facts:

- Current `@larksuite/openclaw-lark` declares 39 tool contracts.
- Older OpenClaw built-in Feishu declared 11 tools.
- Current Moodle `local_aiagentapi` exposes about 40 WebService functions.
- Current `scripts/moodle_cli.py` exposes many more command paths because it
  also includes config, auth, profile, formatting, and mathstate helpers.

Feishu can justify many tools because Feishu is a broad platform SDK:
bitable, calendar, IM, chat, drive, doc, wiki, task, sheet, auth, and card
interaction are distinct product domains.

Moodle here is different. Our OpenClaw layer is not the platform backend. It is
an agent-facing execution surface over:

```text
OpenClaw tool
  -> scripts/moodle_cli.py
  -> Moodle WebService
  -> local_aiagentapi
  -> Moodle native APIs and permissions
```

So the right unit is not every WebService function. The right unit is a
Moodle task domain with action dispatch inside the tool.

## First-Class Tool Contracts

| Tool | Default | Risk | Purpose |
| --- | --- | --- | --- |
| `moodle_catalog` | required | read | Search/list command and API manifest entries, examples, risks, dry-run support, repair hints. |
| `moodle_auth` | required | local write / external login | Manage profiles, device-flow login, status, logout. Keeps auth separate from business tools. |
| `moodle_doctor` | required | read | Diagnose config, token, service registration, Moodle capability, and common 401/403 failures. |
| `moodle_course` | optional | read | Course, activity, resource, grades, progress, notifications, and due-work discovery. |
| `moodle_questionbank` | optional | read | Question categories, search, random picks, and HTML rendering. |
| `moodle_quiz` | optional | write-capable | Quiz list, native practice quiz creation, attempt lifecycle, structured answering. |
| `moodle_calendar` | optional | write-capable | Calendar list, publish plan, keyed upsert plan. |
| `moodle_assignment` | optional | write-capable | Assignment list/status, save draft, submit final. |
| `moodle_forum` | optional | write/destructive | Forum discussions, create/reply/update/delete posts. |
| `moodle_api` | advanced optional | write-capable | Curated direct `local_aiagentapi_*` call after catalog lookup. Disabled unless explicitly allowed. |

Do not include `moodle_mathstate` in the first OpenClaw plugin pass. It touches
the learning-state/plan-runtime side of the system and should be treated as a
separate optional tool or separate plugin after a boundary review. The current
instruction remains: do not touch Student Shell or Plan Runtime as part of this
refactor.

## Tool Actions

### `moodle_catalog`

Actions:

- `search`
- `get_command`
- `get_function`
- `list_domains`
- `list_risks`
- `examples`

This is the most important context-pressure reducer. The model should search
the catalog before loading a domain skill or calling a write-capable tool.

Suggested parameters:

```json
{
  "action": "search",
  "query": "create practice quiz",
  "domain": "quiz",
  "layer": "shortcut",
  "risk": "all",
  "limit": 10
}
```

### `moodle_auth`

Actions:

- `setup`
- `login_start`
- `login_wait`
- `status`
- `logout`
- `list_profiles`
- `use_profile`

This mirrors Feishu's separate OAuth tools. It keeps auth repair out of domain
tools and makes headless login flows explicit.

Important behavior:

- `login_start` should support a no-wait device-flow style result with
  `verification_url`, `user_code`, and expiry.
- Tool results must mask tokens.
- Local profile writes are allowed, but business writes are not implied.

### `moodle_doctor`

Actions:

- `check`
- `explain_error`
- `repair_hint`
- `service_functions`
- `capability_hint`

This tool should wrap `moodle doctor`, `moodle status`, and selected catalog
checks. It should return exact next commands or Moodle admin URLs when possible.

### `moodle_course`

Actions and current CLI/API mapping:

| Action | CLI command | WebService |
| --- | --- | --- |
| `context` | `context get` / `whoami` | `local_aiagentapi_get_user_context` |
| `list_courses` | `courses list` | `local_aiagentapi_courses_list_my` |
| `outline` | `courses outline` | `local_aiagentapi_course_get_outline` |
| `list_activities` | `activities list` | `local_aiagentapi_activities_list_by_course` |
| `activity_detail` | `activities detail` | `local_aiagentapi_course_activity_detail` |
| `due` | `activities due` | `local_aiagentapi_activities_due_list` |
| `resources` | `resources list` | `local_aiagentapi_resources_list_by_course` |
| `grades` | `grades overview` | `local_aiagentapi_grades_overview_my` |
| `progress` | `progress course` | `local_aiagentapi_course_progress_my` |
| `notifications` | `notifications list` | `local_aiagentapi_notifications_list_my` |

This tool should be read-only.

### `moodle_questionbank`

Actions and current CLI/API mapping:

| Action | CLI command | WebService |
| --- | --- | --- |
| `categories` | `questions categories` | `local_aiagentapi_question_categories_list` |
| `search` | `questions search` | `local_aiagentapi_questionbank_search` |
| `pick_random` | `questions pick-random` | `local_aiagentapi_questionbank_pick_random` |
| `render_html` | `questions render-html` | `local_aiagentapi_questions_render_html` |

This should stay read-only. It can prepare inputs for `moodle_quiz`, but it
must not create quizzes itself.

### `moodle_quiz`

Actions and current CLI/API mapping:

| Action | CLI command | WebService | Risk |
| --- | --- | --- | --- |
| `list` | `quiz list` | `local_aiagentapi_quiz_list_by_course` | read |
| `create_practice` | `quiz create-practice` | `local_aiagentapi_practice_quiz_create_from_resource` | write |
| `resolve_random` | `quiz resolve-random` | `local_aiagentapi_quiz_resolve_random` | read |
| `attempts` | `quiz attempts` | `local_aiagentapi_quiz_attempts_my` | read |
| `start` | `quiz start` | `local_aiagentapi_quiz_start_attempt` | write |
| `attempt_data` | `quiz attempt-data` | `local_aiagentapi_quiz_get_attempt_data` | read |
| `attempt_summary` | `quiz attempt-summary` | `local_aiagentapi_quiz_get_attempt_summary` | read |
| `answer` | `quiz answer` | `local_aiagentapi_quiz_answer_questions` plus save/submit path | write |
| `save_attempt` | `quiz save-attempt` | `local_aiagentapi_quiz_save_attempt` | write |
| `submit_attempt` | `quiz submit-attempt` | `local_aiagentapi_quiz_submit_attempt` | write |

Important Moodle-specific rules:

- Practice quiz creation must remain Moodle-native.
- Random category mode should keep using Moodle random slots.
- `categoryids` should support repeated Moodle question categories.
- `count` max remains 120 unless the Moodle plugin changes it.
- All writes need idempotency keys where the current CLI requires them.
- Writes should default to dry-run when the action supports it.

### `moodle_calendar`

Actions and current CLI/API mapping:

| Action | CLI command | WebService | Risk |
| --- | --- | --- | --- |
| `list` | `calendar list` | `local_aiagentapi_calendar_list` | read |
| `publish_plan` | `calendar publish-plan` | `local_aiagentapi_calendar_publish_plan` | write |
| `upsert_plan` | `calendar upsert-plan` | `local_aiagentapi_calendar_plan_upsert` | write |

Calendar writes should require explicit confirmation unless a dry-run preview
has already been accepted.

### `moodle_assignment`

Actions and current CLI/API mapping:

| Action | CLI command | WebService | Risk |
| --- | --- | --- | --- |
| `list` | `assignments list` | `local_aiagentapi_assignments_list_by_course` | read |
| `status` | `assignments status` | `local_aiagentapi_assignments_my_status` | read |
| `save_draft` | `assignments save-draft` | `local_aiagentapi_assignment_save_draft` | write |
| `submit_final` | `assignments submit-final` | `local_aiagentapi_assignment_submit_final` | write |

`submit_final` should be treated as a high-risk write because it changes the
student submission state.

### `moodle_forum`

Actions and current CLI/API mapping:

| Action | CLI command | WebService | Risk |
| --- | --- | --- | --- |
| `discussions` | `forum discussions` | `local_aiagentapi_forum_discussions_list` | read |
| `create_discussion` | `forum create-discussion` | `local_aiagentapi_forum_create_discussion` | write |
| `reply` | `forum reply` | `local_aiagentapi_forum_reply_post` | write |
| `update_post` | `forum update-post` | `local_aiagentapi_forum_update_post` | write |
| `delete_post` | `forum delete-post` | `local_aiagentapi_forum_delete_post` | destructive |

Forum delete is the only first-pass destructive action. Keep it behind
`allowDestructive=true`, `confirm=true`, and a manifest risk check.

### `moodle_api`

Actions:

- `call`

This is not a free-form Moodle REST tool. It may call only manifest-listed
`local_aiagentapi_*` functions. It exists for the Feishu-style API/raw layer,
but should be disabled by default.

Runtime rules:

- Reject unknown function ids before network I/O.
- Reject functions not listed in `allowedApiFunctions` when that config is set.
- In normal use, require a prior `moodle_catalog.get_function` lookup.
- For write functions, require `dryRun=false`, `confirm=true`, and an
  idempotency key where applicable.

## Layering

Use the same conceptual layering as Feishu/Lark, adapted to Moodle:

| Layer | Moodle form | Tool path |
| --- | --- | --- |
| Shortcut | task-shaped command | `moodle_quiz.create_practice`, `moodle_course.outline` |
| API | curated WebService function | `moodle_api.call(functionId, params)` |
| Raw | discovery only in first pass | `moodle_catalog.get_function`, no arbitrary raw Moodle call |

The key design choice: `moodle_api` is a controlled lower layer, not the default
way to work.

## Tool Schema Pattern

Use one strict schema per tool with:

- `action`
- `params`
- `profile`
- `dryRun`
- `confirm`
- `idempotencyKey`

Do not make one huge JSON Schema union containing every parameter for every
Moodle command. Put action-specific validation in the command manifest and
runtime. That keeps the exposed tool schema stable and compact.

Example:

```json
{
  "name": "moodle_quiz",
  "parameters": {
    "type": "object",
    "additionalProperties": false,
    "required": ["action", "params"],
    "properties": {
      "action": {
        "type": "string",
        "enum": [
          "list",
          "create_practice",
          "resolve_random",
          "attempts",
          "start",
          "attempt_data",
          "attempt_summary",
          "answer",
          "save_attempt",
          "submit_attempt"
        ]
      },
      "params": { "type": "object" },
      "profile": { "type": "string" },
      "dryRun": { "type": "boolean", "default": true },
      "confirm": { "type": "boolean", "default": false },
      "idempotencyKey": { "type": "string" }
    }
  }
}
```

## Result Envelope

All Moodle tools should return the same compact envelope:

```json
{
  "ok": true,
  "tool": "moodle_quiz",
  "action": "create_practice",
  "risk": "write",
  "dryRun": true,
  "profile": "dzexam",
  "data": {},
  "warnings": [],
  "repairHints": [],
  "audit": {
    "command": "quiz create-practice",
    "webservice": "local_aiagentapi_practice_quiz_create_from_resource",
    "idempotencyKey": "..."
  }
}
```

Large Moodle results should be summarized by default. If a full payload is
needed, the tool can write a local artifact and return the artifact path.

## Context Pressure Rules

- `moodle_catalog`, `moodle_auth`, and `moodle_doctor` can be default-visible.
- Domain tools should be optional/dynamically loadable where OpenClaw supports
  that.
- Skills should route to tools, not duplicate every command schema.
- Long examples belong in `references/*.md`.
- Runtime code should not be prompt content.
- `moodle_api` should be advanced and off by default.

## Skill Split

Recommended skills:

- `moodle-core`: tiny router, architecture, safety, catalog-first rule.
- `moodle-auth`: setup/login/status/doctor repair.
- `moodle-course`: course/activity/resource/progress read workflows.
- `moodle-questionbank`: categories/search/random/rendering workflows.
- `moodle-quiz`: quiz creation and attempt lifecycle.
- `moodle-calendar`: plan publishing/upsert rules.
- `moodle-assignment`: draft/submit safety.
- `moodle-forum`: discussion/reply/update/delete safety.

Do not make one giant always-active Moodle skill. That would undo the whole
point of making tools first-class.

## Implementation Order

1. Add a command manifest source.

Start with the current CLI commands and annotate:

- command id
- domain
- layer
- risk
- dry-run support
- idempotency requirement
- WebService function
- examples
- repair hints

2. Generate catalog artifacts.

Generate:

- CLI `schema`
- skill reference capability tables
- OpenClaw `references/command-manifest.json`
- docs snippets

3. Implement `moodle_catalog`.

This is read-only and immediately useful. It is also the safest place to
stabilize the manifest format.

4. Implement `moodle_auth` and `moodle_doctor`.

Auth repair should become deterministic before domain tools start writing.

5. Implement domain tools over structured CLI invocation.

Each domain tool maps `action + params` to an argv array. No free-form shell
strings.

6. Add `moodle_api` last.

It should consume the same manifest and enforce allowlists.

## Implementation Status

Started in this workspace:

- Added manifest source:
  `agent-skills/moodle-aiagent-stack/references/command-manifest.v0.1.json`
- Added manifest validator:
  `scripts/validate_command_manifest.py`
- Added local catalog query core:
  `scripts/moodle_command_manifest.py`
- Updated skill sync so adapter reference folders receive the manifest.
- Added first OpenClaw runtime plugin files:
  `plugins/moodle-aiagent-stack/openclaw.plugin.json`
  `plugins/moodle-aiagent-stack/index.ts`

Current runtime plugin status:

- Manifest declares implemented tools:
  `moodle_catalog`, `moodle_auth`, `moodle_doctor`, `moodle_course`, and
  `moodle_questionbank`, `moodle_quiz`, `moodle_calendar`,
  `moodle_assignment`, `moodle_forum`, and `moodle_api`.
- `moodle_catalog` supports `search`, `get_command`, `get_function`, and
  `list_domains`.
- `moodle_auth` supports `setup`, `login_start`, `status`, `list_profiles`,
  `use_profile`, and `logout`.
- `moodle_doctor` supports `check` and `explain_error`.
- `moodle_course` supports `context`, `list_courses`, `outline`,
  `list_activities`, `activity_detail`, `due`, `resources`, `grades`,
  `progress`, and `notifications`.
- `moodle_questionbank` supports `categories`, `search`, `pick_random`, and
  `render_html`.
- `moodle_quiz` supports read actions first: `list`, `resolve_random`,
  `attempts`, `attempt_data`, and `attempt_summary`.
- `moodle_quiz` also supports guarded write actions: `create_practice`,
  `start`, `save_attempt`, `submit_attempt`, and `answer`.
- `moodle_calendar` supports `list`, `publish_plan`, and `upsert_plan`.
- `moodle_assignment` supports `list`, `status`, `save_draft`, and
  `submit_final`.
- `moodle_forum` supports `discussions`, `create_discussion`, `reply`,
  `update_post`, and `delete_post`.
- `moodle_api` supports `list_allowed`, `get_function`, and guarded `call`.
- It reads `skills/moodle-aiagent-cli/references/command-manifest.v0.1.json`
  by default.
- `moodle_catalog` is read-only and performs no Moodle network I/O.
- `moodle_auth`/`moodle_doctor` invoke `scripts/moodle_cli.py` through
  structured argv arrays, never arbitrary shell strings. Local write actions
  such as `logout` and `use_profile` require `confirm=true`.
- `moodle_course` and `moodle_questionbank` also invoke the CLI through
  structured argv arrays and are read-only.
- `moodle_quiz` invokes the CLI through structured argv arrays. Write actions
  default to `dryRun=true`; actual writes require `dryRun=false`,
  `confirm=true`, and an `idempotencyKey`.
- `moodle_calendar`, `moodle_assignment`, and `moodle_forum` follow the same
  structured argv and write-gate pattern.
- `moodle_forum.delete_post` additionally requires plugin config
  `allowDestructive=true` when `dryRun=false`.
- `moodle_api.call` is disabled unless plugin config `enableApiTool=true`.
  With no explicit `allowedApiFunctions`, it only calls manifest functions with
  `allowedByDefault=true`. Write-capable API calls additionally require
  `allowApiWrites=true` and the normal write gates.
- The runtime no longer depends on external TypeBox packages for schemas; it
  builds plain JSON Schema objects locally so linked OpenClaw loading does not
  need a plugin-local `node_modules`.
- The plugin now includes `plugins/moodle-aiagent-stack/package.json` with
  `openclaw.extensions: ["./index.ts"]`. This is required because OpenClaw
  2026.4.26 checks package extensions before Claude-compatible bundle metadata;
  without it, the adjacent `.claude-plugin/` directory makes OpenClaw classify
  the package as a Claude skill bundle and skip `index.ts` tool registration.
- Local OpenClaw validation passed under an isolated profile:
  `OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home openclaw --profile moodle-local ...`.
  `plugins inspect moodle-aiagent-stack --json` reports `format: "openclaw"`,
  `imported: true`, `configSchema: true`, and all 10 tool names.

## Validation

Read-only checks:

```bash
python3 scripts/moodle_cli.py --json schema
python3 scripts/moodle_cli.py --json catalog get
python3 scripts/moodle_cli.py --json doctor
```

Quiz write preview:

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --dry-run \
  quiz create-practice \
  --idempotency-key openclaw-tool-split-smoke \
  --course-id 116 \
  --category-id 619 \
  --category-id 616 \
  --count 30 \
  --random
```

Static checks after code exists:

```bash
python3 -m py_compile scripts/moodle_cli.py
python3 -m py_compile scripts/validate_openclaw_moodle_plugin.py
python3 scripts/validate_openclaw_moodle_plugin.py
OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins registry --refresh --json
OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins inspect moodle-aiagent-stack --json
npm test
openclaw --version
```

## Decision

First pass should target 9 normal tools plus one advanced API tool, not 40
function tools:

```text
Required:
  moodle_catalog
  moodle_auth
  moodle_doctor

Domain:
  moodle_course
  moodle_questionbank
  moodle_quiz
  moodle_calendar
  moodle_assignment
  moodle_forum

Advanced:
  moodle_api
```

This mirrors the good parts of Feishu's current plugin while staying honest
about Moodle's boundary: OpenClaw should orchestrate, the CLI should transport,
and Moodle/PHP should own Moodle behavior.

## Current Audit

As of the 2026-05-16 implementation pass, the scoped Moodle business surface is
covered:

- 10 OpenClaw runtime tools load locally: `moodle_catalog`, `moodle_auth`,
  `moodle_doctor`, `moodle_course`, `moodle_questionbank`, `moodle_quiz`,
  `moodle_calendar`, `moodle_assignment`, `moodle_forum`, and `moodle_api`.
- The command manifest covers all 44 intended non-`mathstate` business CLI
  leaf commands and deliberately excludes Student Shell / Plan Runtime
  `mathstate` commands, local config plumbing, raw schema/debug commands, and
  lower-level duplicate auth/profile management.
- Safety gates are present for all write/high-write/destructive commands:
  dry-run support, `confirm=true`, idempotency keys, and
  `allowDestructive=true` for `forum.delete_post`.
- `moodle_api.call` remains advanced/off by default and can only call
  manifest-listed `local_aiagentapi_*` functions.

The main remaining design improvement is context-pressure tuning, not feature
coverage: `moodle_api` is now optional because it is an advanced escape hatch.
A later pass can add optional/profile-gated activation for lower-frequency
domains such as `moodle_assignment` and `moodle_forum` if the tool list proves
too heavy in real use.
