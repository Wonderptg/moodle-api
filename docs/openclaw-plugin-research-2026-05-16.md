# OpenClaw Plugin Research

Date: 2026-05-16

This note continues `docs/moodle-cli-refactor-reference-2026-05-16.md`.
It focuses on OpenClaw plugins, ClawHub samples, and how to reduce context
pressure while keeping Moodle business logic inside Moodle.

Detailed Moodle tool split follow-up:
`docs/moodle-openclaw-tool-split-2026-05-16.md`.

Tool-design Q&A follow-up:
`docs/moodle-openclaw-tool-design-qa-2026-05-16.md`.

Command-manifest v0.1 follow-up:
`docs/moodle-openclaw-command-manifest-v0.1-2026-05-16.md`.

## Scope

Questions answered here:

- How mature OpenClaw plugins are structured.
- How manifests, tools, skills, docs, and CLI bridges are organized.
- Which "big company / official / mature-team" plugins are worth learning from.
- How this maps to a future Moodle/OpenClaw plugin or skill pack.

Hard boundaries still hold:

- Do not move Moodle quiz/course/business behavior into Python, Node, or OpenClaw.
- Do not touch Student Shell or Plan Runtime as part of this CLI/plugin refactor.
- Keep `local_aiagentapi` as the Moodle capability layer.
- Keep the CLI as a thin WebService client and agent-friendly command surface.

## Sources Checked

External/current:

- ClawHub public API docs: https://documentation.openclaw.ai/clawhub/http-api
- ClawHub plugin list: https://clawhub.ai/plugins
- ClawHub package details via `/api/v1/packages/{name}`
- npm package metadata via `npm view`
- npm artifacts via `npm pack`

Local references:

- `references/openclaw-openclaw/docs/tools/plugin.md`
- `references/openclaw-openclaw/docs/plugins/manifest.md`
- `references/openclaw-openclaw/docs/plugins/agent-tools.md`
- `references/openclaw-openclaw/docs/tools/skills.md`
- `references/openclaw-openclaw/docs/concepts/context.md`
- `references/openclaw-lark`
- `references/openclaw-lark-npm/package`
- temporary unpacked samples under `/tmp/openclaw-plugin-latest`

## Current Registry Facts

ClawHub v1 paths are under `/api/v1/...`. Legacy `/api/...` endpoints are not
the correct primary route for current package work.

Targeted package recheck on 2026-05-16:

| Package | Version | Downloads | Stars | Artifact | Note |
| --- | ---: | ---: | ---: | --- | --- |
| `@openclaw/feishu` | `2026.5.12` | 2861 | 0 | `npm-pack` | OpenClaw official, community-maintained Feishu/Lark bundle |
| `@openclaw/codex` | `2026.5.12` | 2816 | 0 | `npm-pack` | Official provider/harness plugin |
| `@openclaw/memory-lancedb` | `2026.5.12` | 1414 | 0 | `npm-pack` | Official memory slot plugin |
| `@tencentdb-agent-memory/memory-tencentdb` | `0.2.2` | 1150 | 0 | `legacy-zip` | Useful memory architecture reference, older packaging |
| `@honcho-ai/openclaw-honcho` | `1.5.0` | 903 | 0 | `npm-pack` | Memory integration reference |
| `@mem0/openclaw-mem0` | `1.0.11` | 638 | 0 | `legacy-zip` | Memory vendor reference, older packaging |
| `@openclaw/lobster` | `2026.5.12` | 598 | 0 | `npm-pack` | Workflow/approval tool reference |
| `@apify/apify-openclaw-plugin` | `0.1.0` | 442 | 0 | `legacy-zip` | Data/API integration reference |
| `@opik/opik-openclaw` | `0.2.14` | 407 | 0 | `legacy-zip` | Observability reference |
| `@openclaw/diagnostics-otel` | `2026.5.12` | 398 | 0 | `npm-pack` | Minimal background service reference |
| `@dingtalk-real-ai/dingtalk-connector` | `0.8.20` | 351 | 0 | `npm-pack` | DingTalk official-team channel plugin |
| `@openclaw/slack` | `2026.5.12` | 70 | 0 | `npm-pack` | Official channel reference |

`stars` is currently not useful as a ranking signal in the sampled plugin
details. Downloads and artifact quality are more useful, but the best signal is
still package design: manifest, compatibility, config schema, contracts, skills,
and install metadata.

## Compatibility Notes

Installed local OpenClaw:

```text
OpenClaw 2026.4.26 (be8c246)
```

Important package compatibility:

- `@larksuite/openclaw-lark@2026.5.13` requires `openclaw >=2026.5.4`.
- `@dingtalk-real-ai/dingtalk-connector@0.8.20` requires `openclaw >=2026.4.9`.
- Current local OpenClaw can study both packages, but should not install/run the
  Lark official package until OpenClaw is upgraded past `2026.5.4`.

## What Mature Plugins Do Well

### 1. Manifest first

Good plugins treat `openclaw.plugin.json` as the public contract:

- `id`
- `configSchema`
- `channels` / `providers` / `kind`
- `contracts.tools` where relevant
- `skills` directories only when needed
- `activation`
- `commandAliases`
- `uiHints`

OpenClaw validates config from the manifest without executing plugin code. This
is a strong design point: discovery and validation stay cheap and safe.

### 2. Strict schemas

The mature examples use `additionalProperties: false` heavily. This prevents
silent typo config and makes UI/config repair possible.

For Moodle, this argues for a command/API manifest with explicit parameter
schemas. It should be possible to validate a CLI command before any Moodle call.

### 3. Runtime code is not prompt code

Official npm-pack plugins ship runtime code in `dist/`, plus a small manifest.
The model does not need to read runtime code to use the plugin.

For Moodle, do not make the agent read `externallib.php` or a huge Python CLI
file during normal use. The agent should read a compact skill plus an on-demand
command manifest.

### 4. Activation is deliberate

Examples:

- `@openclaw/codex`: `activation.onStartup = false`, `onAgentHarnesses = ["codex"]`.
- `@openclaw/memory-lancedb`: `activation.onStartup = false`, `onCommands = ["ltm"]`.
- `@openclaw/lobster`: starts on startup, but exposes one optional workflow tool.
- `@openclaw/diagnostics-otel`: starts on startup, exposes no model-facing skill.

This is the cleanest context-pressure lesson: only load behavior when it is
needed, and avoid turning every capability into always-visible prompt surface.

### 5. Tool contracts are explicit

Mature plugins declare contracts:

- `@openclaw/lobster`: one tool, `lobster`, marked optional.
- `@openclaw/memory-lancedb`: three tools, `memory_forget`,
  `memory_recall`, `memory_store`.
- `@larksuite/openclaw-lark`: a large set of Feishu tool contracts.

For Moodle, avoid exposing all 37 `local_aiagentapi_*` functions as separate
always-visible tools. A better model is:

- one task shortcut tool or CLI path,
- one stable API call tool for curated WebService calls,
- one catalog/explorer tool for discovery.

That keeps model schemas small while preserving coverage.

### 6. Skills are routers, not entire manuals

OpenClaw includes only skill metadata in the initial context. The full
`SKILL.md` is read on demand. That means skill count and descriptions matter,
but heavy details can live behind the skill file and its references.

Good patterns:

- A tiny always-active channel output rule skill.
- A domain skill that says when to use a capability.
- Troubleshooting/auth repair as a separate skill.
- Deep examples and parameter tables in `references/*.md`.

Bad pattern to avoid:

- One giant always-active skill containing every command, every example, and
  every API edge case.

### 7. Channel connector vs business CLI split

DingTalk is the strongest "big-company taste" reference here.

The DingTalk connector skill states a clear boundary: the connector handles the
message channel, while DingTalk business operations go through `dws` CLI. This
keeps the channel plugin from becoming a giant business backend.

For Moodle, the analog is:

```text
OpenClaw skill/plugin
  -> Moodle CLI command surface
  -> Moodle WebService
  -> local_aiagentapi
  -> Moodle native APIs
```

OpenClaw should not become the Moodle backend.

### 8. Auth repair is a first-class product surface

Feishu/Lark and DingTalk both provide explicit auth/troubleshooting skills or
commands. They do not leave the agent guessing after a 401/403.

For Moodle, this maps directly to:

- `moodle status`
- `moodle doctor`
- `moodle auth repair`
- scope/capability/service-token explanations
- exact admin URL or command to fix the issue

## "Tasteful" Reference Shortlist

Use this shortlist when designing our Moodle/OpenClaw layer.

1. `@openclaw/codex`

Best lesson: dynamic tool loading and strong manifest/UI hints. It has a
`codexDynamicToolsLoading` switch with `searchable` vs `direct`, which is exactly
the kind of idea we want for Moodle raw/API discovery.

2. `@dingtalk-real-ai/dingtalk-connector`

Best lesson: channel plugin stays a connector, business actions route to a CLI.
It also has small channel rules, a troubleshooting skill, and a full CLI skill
with deep references.

3. `@larksuite/openclaw-lark`

Best lesson: enterprise platform plugin split into `core/`, `channel/`,
`commands/`, `card/`, `tools/`, and domain skills. The auth and permission repair
surface is worth copying. The large number of tools is not something to copy
blindly for Moodle.

4. `@openclaw/memory-lancedb`

Best lesson: memory is a plugin kind/slot, with command-gated activation,
explicit tool contracts, and config UI hints. Good model for optional, powerful
capabilities that should not dominate context.

5. `@openclaw/lobster`

Best lesson: a powerful workflow system can be exposed as one optional tool plus
a concise skill. Very relevant if Moodle later needs repeatable multi-step
flows with approval checkpoints.

6. `@openclaw/diagnostics-otel`

Best lesson: a plugin can be useful without exposing anything to the model. Some
Moodle helper behavior should be background diagnostics, not prompt content.

7. `@tencentdb-agent-memory/memory-tencentdb`, `@mem0/openclaw-mem0`,
   `@apify/apify-openclaw-plugin`, `@opik/opik-openclaw`

Best lesson: recognizable teams often package a narrow product boundary. But
several are still `legacy-zip`, so learn domain boundary ideas from them, not
necessarily packaging architecture.

## Recommended Moodle/OpenClaw Shape

If we build a first-class OpenClaw-facing Moodle plugin/skill pack, keep it
small:

```text
moodle-openclaw/
  openclaw.plugin.json
  package.json
  dist/
  skills/
    moodle-cli/SKILL.md
    moodle-quiz/SKILL.md
    moodle-course/SKILL.md
    moodle-troubleshoot/SKILL.md
  references/
    command-manifest.json
    api-catalog.md
    auth-repair.md
    quiz-recipes.md
```

Native tool surface should be narrow:

- `moodle_cli`: run a curated task shortcut.
- `moodle_api`: call a curated `local_aiagentapi` function by name with JSON params.
- `moodle_catalog`: list/search commands, params, risks, examples.

Do not create one OpenClaw tool per Moodle WebService function unless there is a
measured reason. Tool schemas are real context cost.

## Tool-First Architecture

OpenClaw tools should be first-class in the Moodle design. Skills should teach
the model when and how to use tools; tools should be the stable execution
surface.

Recommended tool contracts:

| Tool | Default | Purpose | Side effects |
| --- | --- | --- | --- |
| `moodle_catalog` | required | Search/list command and API manifest entries. Returns parameters, risk, examples, dry-run support, and repair hints. | none |
| `moodle_cli` | optional | Execute a curated shortcut command from the manifest. Best for agent-facing tasks like `quiz.create_practice`. | possible writes |
| `moodle_api` | optional | Call a curated `local_aiagentapi_*` function by id with JSON params. Best for stable low-level API use after catalog lookup. | possible writes |
| `moodle_doctor` | required or optional | Diagnose config/token/service/capability problems and return exact repair steps. Could also be a `mode` inside `moodle_catalog`. | none |

Tool defaults:

- `moodle_catalog` should be available by default because it is read-only and
  reduces context pressure.
- `moodle_cli` should be optional because it can write to Moodle.
- `moodle_api` should be optional because it is lower-level and easier to misuse.
- Destructive commands should require both manifest metadata and runtime
  confirmation (`dryRun` first, then explicit `confirm`/`force`).

Example OpenClaw plugin manifest:

```json
{
  "id": "moodle-aiagent",
  "name": "Moodle AI Agent",
  "description": "Small OpenClaw tool surface for the Moodle local_aiagentapi stack.",
  "activation": {
    "onStartup": false,
    "onCommands": ["moodle"]
  },
  "contracts": {
    "tools": ["moodle_catalog", "moodle_cli", "moodle_api", "moodle_doctor"]
  },
  "toolMetadata": {
    "moodle_cli": { "optional": true },
    "moodle_api": { "optional": true }
  },
  "skills": ["./skills"],
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "defaultProfile": { "type": "string" },
      "configDir": { "type": "string" },
      "allowWrites": { "type": "boolean", "default": false },
      "allowedApiFunctions": {
        "type": "array",
        "items": { "type": "string" },
        "default": []
      }
    }
  },
  "uiHints": {
    "defaultProfile": {
      "label": "Default Moodle Profile",
      "placeholder": "prod"
    },
    "allowWrites": {
      "label": "Allow Writes",
      "help": "Permit write-capable Moodle commands after dry-run/confirmation."
    },
    "allowedApiFunctions": {
      "label": "Allowed API Functions",
      "advanced": true
    }
  }
}
```

Suggested tool schemas:

```json
{
  "name": "moodle_catalog",
  "parameters": {
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "query": { "type": "string" },
      "layer": { "type": "string", "enum": ["shortcut", "api", "raw", "all"] },
      "risk": { "type": "string", "enum": ["read", "write", "destructive", "all"] },
      "limit": { "type": "integer", "minimum": 1, "maximum": 50 }
    }
  }
}
```

```json
{
  "name": "moodle_cli",
  "parameters": {
    "type": "object",
    "additionalProperties": false,
    "required": ["commandId", "params"],
    "properties": {
      "commandId": { "type": "string" },
      "params": { "type": "object" },
      "profile": { "type": "string" },
      "dryRun": { "type": "boolean", "default": true },
      "confirm": { "type": "boolean", "default": false },
      "idempotencyKey": { "type": "string" }
    }
  }
}
```

```json
{
  "name": "moodle_api",
  "parameters": {
    "type": "object",
    "additionalProperties": false,
    "required": ["functionId", "params"],
    "properties": {
      "functionId": { "type": "string" },
      "params": { "type": "object" },
      "profile": { "type": "string" },
      "dryRun": { "type": "boolean", "default": true },
      "confirm": { "type": "boolean", "default": false },
      "idempotencyKey": { "type": "string" }
    }
  }
}
```

Tool execution rules:

- All tool calls must resolve `commandId` or `functionId` through the manifest
  first. No arbitrary shell command passthrough.
- `moodle_cli` should invoke `scripts/moodle_cli.py` with structured params,
  not free-form command strings.
- `moodle_api` should call only curated `local_aiagentapi_*` functions and should
  reject unknown functions before network I/O.
- Write-capable manifest entries should require `dryRun=false` plus
  `confirm=true`, or use a two-step workflow where the first call returns a
  confirmation payload.
- The tool result should return a small envelope: `ok`, `commandId`/`functionId`,
  `risk`, `dryRun`, `data`, `warnings`, `repairHints`, `audit`.
- Large result sets should be summarized by default and expose pagination or a
  saved artifact path instead of dumping everything into context.

Skill split:

- `moodle-cli`: router skill, command layers, safety rules.
- `moodle-quiz`: quiz creation/attempt/answering recipes and common pitfalls.
- `moodle-course`: course/resource/progress read workflows.
- `moodle-troubleshoot`: token, service, capability, auth repair.

Reference docs:

- Put long examples, schema tables, and error catalogs under `references/`.
- Make the model read them only when needed.
- Generate docs/skills from the same manifest where practical.

## Command Manifest Proposal

The command manifest should become the source of truth for CLI docs and skills.

Minimal shape:

```json
{
  "id": "quiz.create_practice",
  "layer": "shortcut",
  "command": "quiz create-practice",
  "webservice": "local_aiagentapi_practice_quiz_create_from_resource",
  "description": "Create a Moodle-native practice quiz from a resource/question category set.",
  "risk": "write",
  "supportsDryRun": true,
  "params": {
    "courseid": { "type": "integer", "required": true },
    "categoryids": { "type": "array", "items": "integer" },
    "count": { "type": "integer", "minimum": 1, "maximum": 120 }
  },
  "examples": [
    "moodle quiz create-practice --course-id 116 --category-id 619 --category-id 616 --count 30 --random --dry-run"
  ]
}
```

This gives us:

- CLI help generation.
- Skill generation.
- `moodle catalog search`.
- safer validation before WebService calls.
- a clean split between shortcut/API/raw layers.

## Context Pressure Rules For Moodle

Use these rules as a design checklist:

- Keep always-active skills tiny.
- Prefer domain skills over one giant skill.
- Keep tool count low.
- Put rare commands behind catalog search.
- Put detailed examples in references.
- Make writes optional/confirmable.
- Make dry-run visible in the command manifest.
- Make auth repair deterministic and command-driven.
- Do not inject Moodle plugin PHP or full CLI source into context.
- Prefer "searchable catalog" for raw/API coverage.

## Immediate Next Refactor Targets

1. Split `scripts/moodle_cli.py` for readability only.

Suggested modules:

- config/auth/profile
- HTTP/WebService client
- command registry/manifest
- quiz commands
- course/resource commands
- question commands
- output/rendering/errors

2. Add command manifest generation.

Start with existing commands. Do not expand capability surface yet.

3. Add shortcut/API/raw layers.

Shortcut layer: stable task commands.
API layer: curated one-to-one `local_aiagentapi` wrappers.
Raw layer: catalog explorer for allowed plugin functions, not arbitrary Moodle.

4. Improve auth repair.

The goal is that a 401/403/token/service error tells the next agent exactly
what command or admin action fixes it.

5. Make skill/doc same-source.

Generate `docs/MOODLE_CLI.md` and `skills/moodle-aiagent-cli/SKILL.md` from the
command manifest plus small hand-written safety sections.

## Validation Commands

Registry/package checks:

```bash
openclaw --version
npm view @larksuite/openclaw-lark version peerDependencies --json
npm view @dingtalk-real-ai/dingtalk-connector version peerDependencies --json
node -e 'fetch("https://clawhub.ai/api/v1/packages/"+encodeURIComponent("@openclaw/codex")).then(r=>r.json()).then(j=>console.log(j.package.stats,j.package.compatibility))'
```

Moodle CLI checks after refactor:

```bash
python3 -m py_compile scripts/moodle_cli.py
python3 scripts/moodle_cli.py --help
python3 scripts/moodle_cli.py schema
python3 scripts/moodle_cli.py doctor
python3 scripts/moodle_cli.py quiz create-practice --course-id 116 --category-id 619 --category-id 616 --count 30 --random --dry-run
```

## Bottom Line

The best OpenClaw plugins are not impressive because they expose lots of stuff.
They are impressive because they keep contracts small, activation deliberate,
runtime code out of prompt context, and repair paths explicit.

For Moodle, the tasteful design is a thin OpenClaw/CLI layer over a Moodle-native
capability backend, with a manifest-driven command catalog and small domain
skills. That gives agents enough power without forcing every session to carry
the entire Moodle API in context.
