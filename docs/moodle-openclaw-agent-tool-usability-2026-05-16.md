# Moodle OpenClaw Tool Usability Guide - 2026-05-16

This note answers the practical question: how do we make a Moodle OpenClaw tool
surface that another AI can use directly from the plugin, with minimal extra
context and fewer wrong calls?

## Research Inputs

Local references:

- `references/openclaw-lark`: Feishu/Lark official OpenClaw plugin source.
- `references/openclaw-openclaw`: local OpenClaw runtime source, especially
  optional tool registration in `src/plugins/registry.ts` and
  `src/plugins/tools.optional.test.ts`.
- `/tmp/openclaw-plugin-latest/openclaw-lobster-2026.5.12/package`: small
  optional workflow tool with a concise `SKILL.md`.
- `/tmp/openclaw-plugin-latest/dingtalk-real-ai-dingtalk-connector-0.8.20/package`:
  channel/plugin split plus CLI-oriented skill docs.

External references checked:

- Anthropic, "Writing Tools for Agents"
  (`https://www.anthropic.com/engineering/writing-tools-for-agents`): treat
  tool definitions and tool outputs as part of the agent's prompt/context;
  optimize names, descriptions, schemas, and results for agent use.
- OpenAI function calling guide
  (`https://platform.openai.com/docs/guides/function-calling`): expose
  functions with descriptions and JSON Schema parameters so the model can
  produce valid structured calls.
- Model Context Protocol tool docs
  (`https://modelcontextprotocol.io/docs/concepts/tools`): tools are
  model-controlled actions with names, descriptions, schemas, and optional
  annotations such as read-only or destructive hints.

## What Good Tools Look Like

A good agent tool is not just an API wrapper. It is a small contract that tells
the model when to call it, what arguments are legal, what will happen, and how
to recover.

Recommended contract:

- Name: domain first, intent second, no clever names. Example:
  `moodle_questionbank`, not `question_magic`.
- Description: one sentence for when to use it, one sentence for safety or
  routing. Mention the preferred predecessor tool when relevant.
- Parameters: strict JSON Schema, enums for actions, bounded integers, explicit
  array semantics, and no arbitrary shell strings.
- Execution: structured argv or API calls only; no command-string passthrough.
- Output: stable envelope with `ok`, `action`, `data`, `meta`, `error`, and
  repair hints. Keep default data compact and add limits/pagination.
- Safety: encode write risk in both schema/config and runtime checks. Dry run
  first, explicit confirmation for non-dry-run writes, idempotency keys for
  mutating operations, and separate gates for destructive actions.
- Context pressure: default-visible tools should be common and safe. Advanced,
  broad, or dangerous tools should be optional, searchable, or allowlisted.

## Tool Categories

Control tools:

- Examples: `moodle_catalog`, `moodle_auth`, `moodle_doctor`.
- Design: always available, narrow actions, mostly read/local-write, excellent
  error messages.
- Purpose: let the model discover capabilities, repair auth, and avoid guessing.

Read domain tools:

- Examples: `moodle_course`, `moodle_questionbank`.
- Design: read-only by default, allow filters/limits, return IDs needed by
  later calls.
- Purpose: gather course/category/quiz context before writes.

Workflow/domain write tools:

- Examples: `moodle_quiz`, `moodle_calendar`, `moodle_assignment`,
  `moodle_forum`.
- Design: mixed read/write actions are acceptable when the workflow is cohesive,
  but all writes need dry-run/confirm/idempotency gates.
- Purpose: map user intent to Moodle-safe business workflows without exposing
  raw PHP business logic in TypeScript/Python.

Escape hatch tools:

- Example: `moodle_api`.
- Design: optional/off by default, allowlisted, manifest-backed, never raw
  arbitrary WebService access.
- Purpose: cover rare advanced needs without bloating the default tool surface.

## What Good Instructions Look Like

The runtime tool description should be short because it sits in the active
tool list. The skill/reference docs can be longer because they are read only
when relevant.

Minimum docs for another AI:

- Activation sentence: when to use this plugin/skill.
- First move: "call `moodle_catalog` if unsure."
- Routing table: intent -> tool -> action family.
- Safety rule: how writes, destructive calls, and auth repair work.
- Examples: 3 to 5 complete JSON tool calls for common jobs.
- Error workflow: failed tool -> `moodle_doctor explain_error` -> retry only
  after a concrete fix.
- Boundaries: do not touch Student Shell / Plan Runtime / `mathstate` in this
  plugin.
- References: manifest path and command lookup command.

Avoid:

- Long marketing descriptions.
- Lists of every backend WebService function in the main skill body.
- Asking the model to infer undocumented IDs, field names, or Moodle business
  rules.
- One generic "run arbitrary CLI" tool.

## Moodle Plugin Assessment

What is already good:

- Tool split is domain-oriented instead of one tool per Moodle function.
- `moodle_catalog` provides discovery, so the model does not need the whole
  command catalog in context.
- Tool handlers use structured argv arrays, not arbitrary shell strings.
- Writes default to dry-run and require confirmation/idempotency keys.
- `moodle_api.call` is config-gated and manifest-backed.
- Manifest validation now checks array CLI semantics.

What was improved in this pass:

- `moodle_api` is now registered as an optional OpenClaw tool, matching its
  role as an advanced escape hatch.
- Runtime tool descriptions now explicitly route agents toward
  `moodle_catalog`, domain tools, dry-run first, and `moodle_doctor` after
  failures.
- The canonical skill now has an OpenClaw tool-first workflow with routing,
  write gates, result-reading guidance, and API escape-hatch rules.

Remaining high-value improvements:

- Add a tiny smoke-test harness that invokes representative tool calls through
  OpenClaw, not only static inspection.
- Add per-action examples to the manifest for JSON tool calls, not only CLI
  examples.
- Add output-shape examples for `details.data` so another AI knows which IDs to
  carry forward.
- Consider optionalizing lower-frequency write-heavy tools if real OpenClaw
  sessions show context pressure: likely candidates are `moodle_assignment`,
  `moodle_forum`, and possibly `moodle_calendar`.

## Direct-Use Recipe for Another AI

1. If the user intent is unclear, call:

```json
{ "action": "search", "query": "<user intent>" }
```

with `moodle_catalog`.

2. For reads, call the matching domain tool with `action` and `params`:

```json
{ "action": "outline", "params": { "courseId": 116 } }
```

3. For practice quiz creation, discover category ids first, then dry-run:

```json
{ "action": "categories", "params": { "courseId": 116 } }
```

```json
{
  "action": "create_practice",
  "params": {
    "courseId": 116,
    "categoryIds": [619, 616],
    "count": 30,
    "random": true,
    "title": "Practice Quiz"
  },
  "dryRun": true,
  "idempotencyKey": "practice-quiz-<stable-task-id>"
}
```

4. Only after the user approves the preview, repeat with:

```json
{ "dryRun": false, "confirm": true, "idempotencyKey": "same-stable-key" }
```

5. On any failure, do not guess. Call `moodle_doctor`:

```json
{ "action": "explain_error", "error": "<captured error text>" }
```

6. Use `moodle_api` only when a domain tool cannot express the job:

```json
{ "action": "get_function", "functionId": "local_aiagentapi_courses_list" }
```

Then call only if plugin config and manifest policy allow it.
