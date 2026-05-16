# Moodle CLI Refactor Reference

Date: 2026-05-16

## Purpose

This document is a handoff for the next agent/window to improve the Moodle CLI without re-breaking the settled architecture. It compares three references:

- Feishu/Lark CLI: a mature agent-native business CLI.
- OpenCLI plugin architecture: manifest/registry/adapter layering for extensible command surfaces.
- Our current Moodle stack: `local_aiagentapi` Moodle plugin plus `scripts/moodle_cli.py`.

The goal is not to copy another project. The goal is to make our CLI easier for agents to understand, safer to use, and easier to extend.

## Decision / Current State

The current direction is correct:

- `local_aiagentapi` remains the canonical Moodle capability layer.
- `scripts/moodle_cli.py` remains a remote WebService CLI, not a Moodle-internal PHP runner.
- Business logic should stay inside Moodle/PHP where Moodle permissions, quiz engine, question bank, completion, and audit context are available.
- CLI refactor should improve discoverability, schema, command layering, auth onboarding, and task-shaped shortcuts.

The current weak point is not raw capability. The current weak point is readability and layering. A new agent can still confuse:

- remote CLI vs Moodle PHP maintenance scripts
- task commands vs plugin WebService functions
- setup/login/status vs lower-level config/auth commands
- fixed question creation vs random category quiz creation
- single question category vs repeated `--category-id`

## Scope

This document covers:

- What to learn from Feishu/Lark CLI.
- What to learn from OpenCLI plugin/registry architecture.
- What exists today in `local_aiagentapi`.
- What exists today in `scripts/moodle_cli.py`.
- A recommended refactor plan for another agent.

## Out Of Scope

Do not do these as part of the CLI readability refactor:

- Do not rewrite `local_aiagentapi` business logic in Python.
- Do not replace Moodle quiz attempts, grading, random slots, or question engine behavior.
- Do not change Next, Student Shell, OpenClaw, Plan Runtime, or learning ledger logic.
- Do not broaden student permissions to create quizzes.
- Do not remove existing commands before compatibility wrappers exist.
- Do not use PHP maintenance scripts for normal remote CLI operations.

## Evidence

- Feishu/Lark CLI local reference: `references/lark-cli`.
- Feishu/Lark README says the project is built for humans and AI agents, has 200+ commands, 24 skills, and a three-layer command system: shortcuts, API commands, raw API.
- Feishu/Lark shared skill: `references/lark-cli/skills/lark-shared/SKILL.md`.
- Feishu/Lark OpenAPI explorer skill: `references/lark-cli/skills/lark-openapi-explorer/SKILL.md`.
- OpenCLI local reference: `OpenCLI`.
- OpenCLI architecture doc: `OpenCLI/docs/developer/architecture.md`.
- OpenCLI plugin doc: `OpenCLI/docs/guide/plugins.md`.
- Current Moodle CLI: `scripts/moodle_cli.py`.
- Current Moodle CLI docs: `docs/MOODLE_CLI.md`.
- Current Moodle CLI skill: `skills/moodle-aiagent-cli/SKILL.md`.
- Current Moodle plugin service list: `public/local/aiagentapi/db/services.php`.
- Current Moodle plugin implementation: `public/local/aiagentapi/externallib.php`.

## Architecture / Contract

Stable boundary:

```text
AI / human caller
  -> Moodle CLI / skill wrapper
  -> Moodle WebService endpoint
  -> local_aiagentapi plugin
  -> Moodle native APIs, permissions, quiz engine, question bank, completion
```

The Moodle CLI should be a thin, agent-friendly command surface. It should not become a second backend.

Recommended future layers:

```text
Layer 1: Task Shortcuts
  e.g. quiz create-practice, quiz start, courses outline, questions categories

Layer 2: Stable API Commands
  one-to-one wrappers over curated local_aiagentapi WebService functions

Layer 3: Raw / Catalog Explorer
  discover function metadata and optionally call allowed plugin functions
```

This mirrors Feishu/Lark's best idea: `Shortcuts -> API Commands -> Raw API`, but adapted to Moodle.

## Feishu / Lark CLI Lessons

What is worth copying:

- Three-layer command system:
  `+shortcuts` for common tasks, generated API commands for platform endpoints, and raw API for full coverage.
- Strong shared skill:
  `lark-shared` teaches config, auth, identity, scope, permission errors, and safety rules before domain skills run.
- Auth is explicit:
  `config init`, `auth login`, `auth status`, `--no-wait`, `--device-code`, user/bot identity, scope-specific repair.
- Agent handoff is first-class:
  AI quick start tells agents exactly when to send an authorization URL to the user.
- Permission failures are actionable:
  errors include missing scopes, console URL, and a suggested repair command.
- Safety has protocol:
  high-risk writes require explicit confirmation and structured exit behavior.
- OpenAPI explorer exists:
  when a shortcut does not cover a need, agents can discover raw API docs and call `lark-cli api`.

What not to copy blindly:

- Moodle does not have Lark's scope model. Moodle permissions are role/capability/context based.
- Moodle quiz behavior must stay native; raw API fallback must not bypass Moodle permission or attempt logic.
- We should not expose every Moodle internal function as a public command.

## OpenCLI Plugin Lessons

OpenCLI's strongest contribution is extensibility and discovery:

- Central registry: commands register metadata such as site/name/description/strategy/args/columns.
- Manifest-first plugin install: `opencli-plugin.json` can declare plugin name, version, compatibility, and monorepo sub-plugins.
- Adapter layer is separate from engine layer:
  registry, discovery, execution, output, browser bridge, and adapters are separate concerns.
- Strategies are explicit:
  `public`, `cookie`, `header`, `intercept`, `ui`, `local`.
- Skills guide agents through adapter authoring, repair, and usage.
- The manifest/registry is machine-readable enough for agents to search capabilities.

What this means for Moodle:

- We do not need OpenCLI's browser bridge for Moodle CLI.
- We should borrow the registry/manifest idea.
- Moodle CLI should have a small command manifest generated from the command registry and plugin catalog.
- Skills should be generated from the same source as CLI docs, not hand-maintained in three different places.

## Moodle Plugin Current State

Plugin:

- Component: `local_aiagentapi`.
- Service: `local_aiagentapi`.
- Service functions registered in `public/local/aiagentapi/db/services.php`.
- Current function count: 37 `local_aiagentapi_*` functions.
- Core capability gate: `local/aiagentapi:use`.
- Admin access page exists for authorizing users and token readiness.
- WebService token should remain service-scoped.

Major capability groups:

- bootstrap:
  `get_user_context`, `get_api_catalog`
- course/resource:
  course list, course outline, activities, resources, progress, grades
- quiz attempt:
  start attempt, get page data, save, submit, answer structured questions
- question bank:
  categories, search, pick random, render HTML
- quiz creation:
  `practice_quiz_create_from_resource`
- calendar:
  list, publish plan, upsert plan
- assignments/forum/notifications:
  read and limited user writes

Recent important state:

- `practice_quiz_create_from_resource` supports up to 120 questions.
- It supports repeated category ids through `categoryid` plus `categoryids`.
- CLI usage is repeatable:
  `--category-id 619 --category-id 616`.
- In `random_category` mode, Moodle native random slots are added per selected category count.
- Production dry-run verified:
  `courseid=116`, `categoryid=619`, `categoryid=616`, `count=30`, `--random` returned `ok=true` and selected both `multichoice` and `truefalse`.

Plugin boundaries:

- Keep Moodle-native quiz attempts, random slots, grading, question rendering, and permission checks in PHP.
- Keep idempotency and dry-run for writes.
- Keep service authorization in Moodle, not in the Python CLI.

## Moodle CLI Current State

Main file:

- `scripts/moodle_cli.py`

Command wrapper:

- `bin/moodle`

Current nature:

- Python remote WebService client.
- Does not need local PHP.
- Does not need `public/config.php`.
- Does not need to run from the Moodle server checkout.
- Calls:

```text
<base-url>/webservice/rest/server.php
  -> local_aiagentapi
```

Current top-level command groups:

- `setup`
- `login`
- `status`
- `doctor`
- `schema`
- `exit-codes`
- `config`
- `auth`
- `profile`
- `whoami`
- `context`
- `catalog`
- `courses`
- `activities`
- `assignments`
- `resources`
- `forum`
- `notifications`
- `grades`
- `progress`
- `mathstate`
- `calendar`
- `questions`
- `quiz`

Current strengths:

- Has machine-readable `schema`.
- Has stable JSON mode.
- Has `--dry-run`, `--force`, `--no-input`, `--select`, `--results-only`.
- Has top-level `setup`, `login`, `status` aliases for onboarding.
- Has profile-based auth and device-flow login.
- Has a skill file for agents.
- Has `quiz create-practice` with repeatable `--category-id`.
- Has `quiz start`, `quiz attempt-data`, `quiz answer`, `quiz save-attempt`, `quiz submit-attempt`.

Current weaknesses:

- `scripts/moodle_cli.py` is too large and mixes parser registration, command handlers, formatting, auth, HTTP, and domain behavior.
- CLI docs, skills, plugin catalog, and command schema are related but not generated from one source.
- The current `schema` comes from argparse, not from a domain manifest that also explains safety/risk/capability.
- Some command names are backend-shaped instead of teacher/student task-shaped.
- Another agent can still mistake PHP maintenance scripts for the remote CLI path.
- Raw plugin function discovery exists through `catalog`, but there is no clean `api call` equivalent like Feishu/Lark.
- Write safety is present, but risk levels are not formalized like Feishu/Lark's `confirmation_required` protocol.

## Implementation Home

Recommended files for the next agent:

- `scripts/moodle_cli.py`
- `docs/MOODLE_CLI.md`
- `agent-skills/moodle-aiagent-stack/SKILL.md`
- `skills/moodle-aiagent-cli/SKILL.md`
- `plugins/moodle-aiagent-stack/skills/moodle-aiagent-cli/SKILL.md`
- `public/local/aiagentapi/externallib.php`
- `public/local/aiagentapi/db/services.php`
- `scripts/sync_agent_skills.py`

Recommended new files:

- `scripts/moodle_cli/registry.py`
- `scripts/moodle_cli/runtime.py`
- `scripts/moodle_cli/output.py`
- `scripts/moodle_cli/auth.py`
- `scripts/moodle_cli/http.py`
- `scripts/moodle_cli/commands/quiz.py`
- `scripts/moodle_cli/commands/questions.py`
- `scripts/moodle_cli/commands/courses.py`
- `scripts/moodle_cli/commands/mathstate.py`
- `configs/moodle-cli-command-manifest.v0.1.json` or generated `docs/samples/moodle-cli-command-manifest.v0.1.json`

Use these paths as a direction, not a hard requirement. Keep the first refactor small.

## Forbidden Files / Boundaries

Do not touch these unless the user explicitly asks:

- Next local classroom project.
- Student Shell plugin.
- OpenClaw Plan Runtime.
- Understanding Ledger.
- Moodle core quiz tables directly.
- Moodle core WebService token behavior except via existing access-management helpers.

Do not rewrite or remove:

- `local_aiagentapi` permission model.
- `practice_quiz_create_from_resource` Moodle-native quiz creation.
- Device-flow login behavior.
- Existing command names without backwards-compatible aliases.

## Recommended Refactor

### P0: Make the current CLI easier to read

Goal:

Split the monolithic file without changing behavior.

Suggested steps:

1. Extract HTTP client/profile/auth helpers from `scripts/moodle_cli.py`.
2. Extract output formatting and error envelopes.
3. Extract parser registration by command group.
4. Keep the public command names unchanged.
5. Keep `python3 scripts/moodle_cli.py ...` working.
6. Run existing smoke checks after each slice.

Acceptance:

- `python3 -m py_compile scripts/moodle_cli.py`
- `python3 scripts/moodle_cli.py --json schema quiz create-practice`
- `python3 scripts/moodle_cli.py quiz create-practice --help`
- Existing `--profile dzexam --json whoami` still works.

### P0: Add a manifest-like command catalog

Goal:

Agents should see command purpose, risk, write/read type, Moodle capability, and examples from one place.

Minimum shape:

```json
{
  "version": "0.1",
  "commands": [
    {
      "path": "quiz create-practice",
      "kind": "task_shortcut",
      "type": "write",
      "risk": "moodle_write",
      "webservice": "local_aiagentapi_practice_quiz_create_from_resource",
      "capabilities": [
        "local/aiagentapi:use",
        "moodle/course:manageactivities",
        "mod/quiz:addinstance",
        "moodle/question:useall"
      ],
      "dry_run": true,
      "idempotent": true,
      "examples": [
        "quiz create-practice --course-id 116 --category-id 619 --category-id 616 --count 30 --random"
      ]
    }
  ]
}
```

Acceptance:

- `moodle schema` still works.
- A new `moodle catalog cli` or `moodle schema --manifest` can expose this richer metadata.
- Docs and skills can be generated or checked against the manifest later.

### P0: Formalize command layers

Goal:

Make Feishu/Lark's three-layer idea explicit in Moodle terms.

Recommended layers:

- `shortcut`: task-shaped command, preferred for agents.
- `api`: stable one-to-one wrapper over curated `local_aiagentapi` functions.
- `raw`: limited function caller / catalog explorer for admin/debug only.

Do not expose raw calls to students.

Possible commands:

```bash
moodle shortcuts list
moodle api catalog
moodle api show local_aiagentapi_practice_quiz_create_from_resource
moodle api call local_aiagentapi_practice_quiz_create_from_resource --json-body request.json
```

If implementing `api call`, require:

- allowlist
- `--dry-run` for writes when supported
- no secrets in logs
- Moodle token permissions still enforced

### P1: Improve onboarding and auth repair

Goal:

Match the clarity of `lark-shared`.

Keep:

```bash
moodle setup --name prod --base-url https://dzexam.cn
moodle login --name prod
moodle status --name prod
```

Add better failure hints:

- missing base URL -> run `setup`
- missing token -> run `login`
- token lacks plugin access -> ask admin to open Moodle `local_aiagentapi/manage_access.php`
- `cannotcreatetoken` -> explain pre-created service-scoped token requirement
- permission denied on quiz creation -> token user lacks course/question/quiz capabilities

### P1: Make write safety explicit

Goal:

Borrow Feishu/Lark's risk protocol without overbuilding.

Add metadata:

- `risk: read`
- `risk: user_write`
- `risk: course_write`
- `risk: quiz_create`
- `risk: destructive_write`

Rules:

- `--dry-run` should be recommended for all writes that support it.
- destructive writes should require `--force` and clear JSON error when missing.
- JSON error should include `hint`.

### P1: Regenerate skills from canonical source

Goal:

Avoid drift across:

- `agent-skills/moodle-aiagent-stack/SKILL.md`
- `skills/moodle-aiagent-cli/SKILL.md`
- `plugins/moodle-aiagent-stack/skills/moodle-aiagent-cli/SKILL.md`
- `docs/MOODLE_CLI.md`

Current `scripts/sync_agent_skills.py` exists. Refactor should make one canonical source obvious and verify generated outputs.

## Pitfalls

### Pitfall: Confusing remote CLI with Moodle PHP maintenance scripts

Observed:

Another agent said quiz creation could not run because the local environment lacked PHP or `public/config.php`.

Cause:

The distinction between `scripts/moodle_cli.py` and Moodle PHP maintenance scripts was not obvious enough.

Rule:

For normal online quiz creation, use the remote CLI through `local_aiagentapi`. PHP is only for server maintenance, plugin upgrade, service registration, and imports.

Affected files:

- `scripts/moodle_cli.py`
- `docs/MOODLE_CLI.md`
- `skills/moodle-aiagent-cli/SKILL.md`

### Pitfall: Treating question category as a single source

Observed:

Math questions for one chapter can be split across multiple Moodle question categories, for example selection questions in category `619` and true/false questions in category `616`.

Cause:

The first `create-practice` shape accepted only one `categoryid`.

Rule:

`quiz create-practice` must support repeated `--category-id` and server-side `categoryids`.

Affected files:

- `scripts/moodle_cli.py`
- `public/local/aiagentapi/externallib.php`

### Pitfall: Moving Moodle-native quiz behavior into Python

Observed:

There is recurring temptation to make the CLI "create tests" directly.

Cause:

The CLI is easier for agents to see than Moodle internals, so agents may put business logic in Python.

Rule:

Python CLI chooses and submits task intent. Moodle plugin creates quizzes and uses Moodle-native APIs.

Affected files:

- `scripts/moodle_cli.py`
- `public/local/aiagentapi/externallib.php`

### Pitfall: Duplicated command knowledge

Observed:

Command docs, skill text, plugin catalog metadata, and parser help can drift.

Cause:

No single manifest/registry owns command metadata.

Rule:

Introduce a manifest or registry metadata layer, then generate or validate docs/skills from it.

Affected files:

- `scripts/moodle_cli.py`
- `docs/MOODLE_CLI.md`
- `agent-skills/moodle-aiagent-stack/SKILL.md`
- `skills/moodle-aiagent-cli/SKILL.md`

## Verification

Known good checks for the current state:

```bash
php -l public/local/aiagentapi/externallib.php
python3 -m py_compile scripts/moodle_cli.py
python3 scripts/moodle_cli.py quiz create-practice --help
python3 scripts/moodle_cli.py --json schema quiz create-practice
python3 scripts/moodle_cli.py --profile dzexam --json whoami
```

Production dry-run check for mixed categories:

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --dry-run --force \
  quiz create-practice \
  --idempotency-key practice-mixed-category-preview-001 \
  --course-id 116 \
  --category-id 619 \
  --category-id 616 \
  --count 30 \
  --random \
  --title "第一章混合题型测试"
```

Expected:

- `ok=true`
- `dry_run=true`
- `requested_count=30`
- `created_count=30`
- question list includes both `categoryid=619` and `categoryid=616`
- no actual quiz is created

## Follow-Up

Recommended next prompt for another agent:

```text
Please refactor the Moodle CLI for readability and agent ergonomics.

Read first:
- docs/moodle-cli-refactor-reference-2026-05-16.md
- docs/MOODLE_CLI.md
- skills/moodle-aiagent-cli/SKILL.md
- scripts/moodle_cli.py
- public/local/aiagentapi/db/services.php
- public/local/aiagentapi/externallib.php

Goals:
1. Keep local_aiagentapi as the backend capability layer.
2. Keep scripts/moodle_cli.py behavior compatible.
3. Do not move Moodle business logic into Python.
4. Split the CLI into clearer modules or introduce a registry/manifest if that can be done safely.
5. Preserve setup/login/status, schema, JSON output, dry-run, force, profile auth, and quiz create-practice.
6. Make quiz create-practice support repeated --category-id exactly as it does now.
7. Improve docs/skills only through one canonical source or a clear sync path.

P0:
- Extract parser/handler/runtime/output/auth concerns enough that future agents can read the CLI.
- Add or draft a command manifest with command path, risk, read/write type, WebService function, capability, dry-run/idempotency support, and examples.
- Keep all existing commands working.

Do not touch:
- Next/Student Shell/OpenClaw/Plan Runtime.
- Moodle core quiz tables directly.
- Student permissions.
- local_aiagentapi auth model unless a test proves it is broken.

Verification:
- php -l public/local/aiagentapi/externallib.php
- python3 -m py_compile scripts/moodle_cli.py
- python3 scripts/moodle_cli.py --json schema quiz create-practice
- python3 scripts/moodle_cli.py quiz create-practice --help
- python3 scripts/moodle_cli.py --profile dzexam --json whoami
- dry-run mixed category quiz creation with course 116, category 619, category 616, count 30.
```
