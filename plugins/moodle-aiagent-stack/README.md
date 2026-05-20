# Moodle AI Agent Stack Plugin

This directory is one Moodle agent plugin package with multiple host adapters.

If you are an AI seeing this project for the first time, start here. The goal is
to expose Moodle tools to OpenClaw and Codex without moving Moodle business
logic out of Moodle.

## One-Screen Map

```text
Moodle business logic
  public/local/aiagentapi/

Stable agent boundary
  scripts/moodle_cli.py

Shared command contract
  skills/moodle-aiagent-cli/references/command-manifest.v0.1.json

Shared runtime
  shared/runtime.mjs

OpenClaw adapter
  openclaw.plugin.json
  index.ts
  index.js
  shared/openclaw-tools.mjs

Codex adapter
  .codex-plugin/plugin.json
  .mcp.json
  scripts/moodle-mcp-server.mjs

Human/AI operating guide
  skills/moodle-aiagent-cli/SKILL.md
```

## Mental Model

The plugin is a thin adapter layer:

```text
OpenClaw tool or Codex MCP tool
  -> structured argv
  -> python3 scripts/moodle_cli.py --json ...
  -> Moodle WebService endpoint
  -> public/local/aiagentapi
```

Do not add Moodle business rules to `index.ts`, `index.js`,
`shared/openclaw-tools.mjs`, `scripts/moodle-mcp-server.mjs`, or
`shared/runtime.mjs`. The adapter/runtime layer should only route actions,
validate safety gates, and build CLI arguments.

## What To Read First

1. `README.md`: package layout and development rules.
2. `skills/moodle-aiagent-cli/SKILL.md`: how an AI should use the tools.
3. `skills/moodle-aiagent-cli/references/command-manifest.v0.1.json`: tool/action/CLI/API mapping.
4. `docs/moodle-openclaw-codex-dual-plugin-2026-05-18.md`: short ADR for why OpenClaw and Codex need separate entrypoints.

## Tool Surface

The package exposes the same ten tool names in OpenClaw and Codex:

```text
moodle_catalog
moodle_auth
moodle_doctor
moodle_course
moodle_questionbank
moodle_quiz
moodle_calendar
moodle_assignment
moodle_forum
moodle_api
```

Routing rule:

- Start with `moodle_catalog` when action or parameter names are unclear.
- Use a domain tool for actual work.
- Use `moodle_doctor` after a failed call.
- Use `moodle_api` only as an advanced escape hatch.

## Safety Rules

- The tools never accept arbitrary shell strings.
- Reads are direct CLI calls.
- Writes default to dry-run preview.
- Actual writes require `dryRun=false`, `confirm=true`, and `idempotencyKey`.
- `moodle_forum.delete_post` also requires destructive-action enablement.
- `moodle_api.call` is off by default.
- Student Shell and Plan Runtime are outside this plugin's scope.

## Install Locally

OpenClaw local development:

```bash
OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins install \
  --dangerously-force-unsafe-install \
  --link /Users/wonder/Documents/moodle/plugins/moodle-aiagent-stack
```

Codex local marketplace:

```bash
codex plugin marketplace add /Users/wonder/Documents/moodle
```

Codex MCP-only fallback:

```bash
codex mcp add moodle_aiagent -- \
  node /Users/wonder/Documents/moodle/plugins/moodle-aiagent-stack/scripts/moodle-mcp-server.mjs
```

## Validation

Run these after changing plugin metadata, skills, manifests, or adapters:

```bash
python3 scripts/validate_command_manifest.py
python3 scripts/validate_openclaw_moodle_plugin.py
node scripts/validate_moodle_mcp_server.mjs
```

OpenClaw inspect:

```bash
OPENCLAW_HOME=/Users/wonder/Documents/moodle/tmp/openclaw-home \
  openclaw --profile moodle-local plugins inspect moodle-aiagent-stack --json
```

Expected OpenClaw result:

- `format: "openclaw"`
- `imported: true`
- `configSchema: true`
- all ten Moodle tool names are present
- `moodle_api` is optional

Codex isolated checks:

```bash
mkdir -p tmp/codex-home tmp/codex-home-mcp

CODEX_HOME=/Users/wonder/Documents/moodle/tmp/codex-home \
  codex plugin marketplace add /Users/wonder/Documents/moodle

CODEX_HOME=/Users/wonder/Documents/moodle/tmp/codex-home-mcp \
  codex mcp add moodle_aiagent -- \
  node /Users/wonder/Documents/moodle/plugins/moodle-aiagent-stack/scripts/moodle-mcp-server.mjs

CODEX_HOME=/Users/wonder/Documents/moodle/tmp/codex-home-mcp codex mcp list
```

## How To Change Things

When changing tool behavior:

1. Update the canonical command manifest in `agent-skills/moodle-aiagent-stack/references/command-manifest.v0.1.json`.
2. Update the canonical skill in `agent-skills/moodle-aiagent-stack/SKILL.md` if the AI operating workflow changed.
3. Run `python3 scripts/sync_agent_skills.py`.
4. Update shared routing/argv/write-gate logic in `shared/runtime.mjs` if needed.
5. Update host-specific schemas only when the exposed tool parameters changed:
   `shared/openclaw-tools.mjs` for OpenClaw, `scripts/moodle-mcp-server.mjs`
   for Codex MCP.
6. Run the validation commands above.

`index.ts`, `index.js`, and `scripts/moodle-mcp-server.mjs` should stay thin.
Shared behavior belongs in `shared/runtime.mjs` so OpenClaw and Codex do not
drift.

## Common Mistakes

- Do not make Codex read `openclaw.plugin.json` directly. Codex needs
  `.codex-plugin/plugin.json` and MCP.
- Do not make OpenClaw depend on `.codex-plugin/`. OpenClaw uses
  `openclaw.plugin.json`, `package.json#openclaw.extensions`, and the
  installed-runtime `package.json#openclaw.runtimeExtensions` JS entry.
- Do not add one MCP tool per Moodle WebService function. Use domain tools and
  keep `moodle_api` as the optional escape hatch.
- Do not reimplement command routing separately in OpenClaw and Codex. Put
  shared behavior in `shared/runtime.mjs`.
- Do not bypass dry-run/confirm/idempotency gates for writes.
- Do not change Student Shell or Plan Runtime as part of this plugin refactor.
