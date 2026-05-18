# Moodle OpenClaw/Codex Dual Plugin ADR

Date: 2026-05-18

## Decision

Keep one Moodle plugin package with two host adapters:

- OpenClaw: `plugins/moodle-aiagent-stack/openclaw.plugin.json` and `index.ts`
- Codex: `plugins/moodle-aiagent-stack/.codex-plugin/plugin.json`, `.mcp.json`,
  and `scripts/moodle-mcp-server.mjs`

Both adapters share the same command manifest, skill docs, CLI boundary, and
safety rules. Do not create a separate Codex-only Moodle implementation.

## Reason

OpenClaw and Codex expose tools through different protocols. OpenClaw loads
first-class tools from its plugin runtime; Codex sees first-class callable tools
through MCP. A shared package plus thin host adapters keeps the behavior aligned
without moving Moodle business logic into the adapter layer.

## Current Entry Point

For day-to-day use and for a zero-context AI, start here:

```text
plugins/moodle-aiagent-stack/README.md
```

That README contains the file map, install commands, tool routing, safety
rules, validation commands, and common mistakes.

## Boundary

- Moodle business logic stays in `public/local/aiagentapi`.
- `scripts/moodle_cli.py` remains the stable agent boundary.
- Adapter code builds structured argv and enforces safety gates.
- Student Shell and Plan Runtime stay out of this plugin refactor.

## Follow-Up

Completed: shared command routing, argv construction, CLI execution, catalog
search, error explanation, API gating, and write gates now live in
`plugins/moodle-aiagent-stack/shared/runtime.mjs`.

Host-specific files now stay thin:

- `index.ts`: OpenClaw schemas and tool registration.
- `scripts/moodle-mcp-server.mjs`: MCP schemas and JSON-RPC framing.
