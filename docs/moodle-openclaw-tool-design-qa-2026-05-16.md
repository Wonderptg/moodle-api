# Moodle OpenClaw Tool Design Q&A

Date: 2026-05-16

This note records the user's tool-design questions and the research conclusions
from local OpenClaw/Feishu references. It is intended as a handoff for the next
implementation window.

Related docs:

- `docs/openclaw-plugin-research-2026-05-16.md`
- `docs/moodle-openclaw-tool-split-2026-05-16.md`
- `docs/moodle-cli-refactor-reference-2026-05-16.md`

## Questions Asked

The user asked, in order:

1. "tool呢，这个里面没有关于tool的部分，openclaw现在tool是一等公民"
2. "怎么拆分tool呢，你先调研，然后给结论"
3. "飞书是怎么分tool的，我记得飞书有十几个tool"
4. "tool的设计规范是啥样的，能和飞书的tool对应起来吗"
5. "不同的tool会不会有不同的设计方案？"

## Short Answers

1. OpenClaw tools are first-class JSON-schema functions registered by plugins.
   They should be declared in the plugin manifest where possible, gated by
   config/allowlists, and paired with skills that teach routing rather than
   duplicate every schema.

2. Current Feishu/Lark has 39 tool contracts in the latest local npm package,
   not "ten-ish". The older OpenClaw built-in Feishu plugin had 11 tools, which
   likely explains the user's memory.

3. Feishu splits tools by product domain and execution path:
   OAPI domain tools, MCP doc tools, OAuth/auth tools, and one interactive
   ask-user-question tool.

4. Moodle can map to Feishu's design roles, not its exact names. The right
   Moodle unit is a task/domain tool with `action` dispatch, not one OpenClaw
   tool per `local_aiagentapi_*` function.

5. Different tools should have different designs. A catalog tool, an auth tool,
   a doctor tool, a read-only course tool, a quiz workflow tool, and a raw/API
   escape hatch have different risk, schema, result, and activation rules.

## Evidence

### OpenClaw tool basics

Local reference:

- `references/openclaw-openclaw/docs/plugins/agent-tools.md`

Findings:

- A plugin registers tools with `api.registerTool({ name, description,
  parameters, execute })`.
- Tool parameters are JSON Schema / TypeBox.
- Tools can be optional via `api.registerTool(tool, { optional: true })`.
- Optional tools are not auto-enabled; they require tool allowlists.
- OpenClaw docs recommend optional tools for side effects, extra credentials, or
  extra binaries.

### OpenClaw plugin basics

Local reference:

- `references/openclaw-openclaw/docs/tools/plugin.md`

Findings:

- Plugins can register agent tools, CLI commands, background services, gateway
  handlers, channels, and skills.
- Config validation uses `openclaw.plugin.json` and JSON Schema without
  executing plugin code.
- Plugin manifests are therefore the right place for public contracts and
  static discovery metadata.

### Current Feishu tool count

Local reference:

- `references/openclaw-lark-npm/package/openclaw.plugin.json`

Finding:

- Latest local `@larksuite/openclaw-lark` package declares 39 tools under
  `contracts.tools`.

Important examples:

- `feishu_bitable_app_table_record`
- `feishu_calendar_event`
- `feishu_task_task`
- `feishu_im_user_message`
- `feishu_create_doc`
- `feishu_fetch_doc`
- `feishu_update_doc`
- `feishu_oauth`
- `feishu_oauth_batch_auth`
- `feishu_ask_user_question`

### Legacy Feishu tool count

Local reference:

- `references/openclaw-openclaw/extensions/feishu`

Finding:

- The older built-in Feishu plugin registers 11 tools:
  `feishu_doc`, `feishu_app_scopes`, `feishu_wiki`, `feishu_drive`,
  `feishu_perm`, and six bitable tools.

This likely explains the user's memory that Feishu had "ten-ish" tools.

### Feishu registration shape

Local reference:

- `references/openclaw-lark/index.ts`
- `references/openclaw-lark/src/tools/oapi/index.ts`

Feishu root registration:

- `registerOapiTools(api)`
- `registerFeishuMcpDocTools(api)`
- `registerFeishuOAuthTool(api)`
- `registerFeishuOAuthBatchAuthTool(api)`
- `registerAskUserQuestionTool(api)`

OAPI domain registration:

- `bitable`
- `calendar`
- `chat`
- `common`
- `drive`
- `im`
- `search`
- `sheets`
- `task`
- `wiki`

### Feishu action pattern

Local references:

- `references/openclaw-lark/src/tools/oapi/calendar/event.ts`
- `references/openclaw-lark/src/tools/oapi/bitable/app-table-record.ts`

Findings:

- Feishu does not make one tool per low-level API endpoint.
- It often makes one tool per product resource, then uses an `action` field:
  `create`, `list`, `get`, `patch`, `search`, `reply`, `batch_create`,
  `batch_update`, `batch_delete`, etc.
- Complex resource tools use TypeBox unions keyed by `action`.
- Resource-specific pitfalls live in skills and references, not only in the
  tool description.

### Feishu gating and result pattern

Local references:

- `references/openclaw-lark/src/tools/helpers.ts`
- `references/openclaw-lark/src/core/tools-config.ts`

Findings:

- Feishu wraps `api.registerTool` with `registerTool(api, tool, opts)`.
- Registration checks `channels.feishu.tools.deny`.
- Deny patterns can match exact names or prefixes like `feishu_calendar_*`.
- Feishu returns OpenClaw-compatible `content` plus structured `details`.

## Tool Design Norms For Moodle

These are the rules we should use for Moodle tools.

### 1. Manifest first

The OpenClaw plugin manifest should declare the public tool contracts:

```json
{
  "contracts": {
    "tools": [
      "moodle_catalog",
      "moodle_auth",
      "moodle_doctor",
      "moodle_course",
      "moodle_questionbank",
      "moodle_quiz",
      "moodle_calendar",
      "moodle_assignment",
      "moodle_forum",
      "moodle_api"
    ]
  }
}
```

### 2. Domain tools, not function tools

Do not expose every `local_aiagentapi_*` WebService function as a separate
OpenClaw tool.

Use domain tools with action dispatch:

```text
moodle_quiz(action="create_practice", params={...})
moodle_quiz(action="start", params={...})
moodle_questionbank(action="categories", params={...})
moodle_course(action="outline", params={...})
```

This corresponds to Feishu's:

```text
feishu_calendar_event(action="create", ...)
feishu_bitable_app_table_record(action="list", ...)
feishu_task_task(action="patch", ...)
```

### 3. Strict outer schema, manifest-driven inner validation

Each Moodle tool should have a small strict outer schema:

- `action`
- `params`
- `profile`
- `dryRun`
- `confirm`
- `idempotencyKey`

Action-specific validation should come from the command manifest and runtime,
not from one giant exposed JSON Schema. This keeps tool context compact while
still making execution safe.

### 4. Optional by risk

Default-visible:

- `moodle_catalog`
- `moodle_auth`
- `moodle_doctor`

Optional or dynamically loadable:

- `moodle_course`
- `moodle_questionbank`
- `moodle_quiz`
- `moodle_calendar`
- `moodle_assignment`
- `moodle_forum`

Advanced and off by default:

- `moodle_api`

### 5. No free-form shell passthrough

Domain tools may call `scripts/moodle_cli.py`, but they must build argv from a
manifest entry. They must not accept an arbitrary shell command string.

### 6. Unified result envelope

All Moodle tools should return:

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

This improves on Feishu's `content/details` pattern by adding Moodle-specific
risk, dry-run, repair, and audit fields.

## Feishu To Moodle Mapping

| Feishu role | Feishu examples | Moodle tool | Notes |
| --- | --- | --- | --- |
| Manifest/tool contract | `contracts.tools` | `contracts.tools` | Same idea: static public capability declaration. |
| Auth tools | `feishu_oauth`, `feishu_oauth_batch_auth` | `moodle_auth` | Separate login/profile/token flow from business actions. |
| Troubleshooting | `/feishu_doctor`, `feishu-troubleshoot` | `moodle_doctor` | Return exact repair hints for token/service/capability failures. |
| Domain search/discovery | `feishu_search_doc_wiki`, domain skills | `moodle_catalog` | Catalog reduces context pressure and routes to tools. |
| Calendar domain | `feishu_calendar_event`, `feishu_calendar_freebusy` | `moodle_calendar` | Direct conceptual match. |
| Structured records | `feishu_bitable_app_table_record` | `moodle_questionbank` | Categories/search/render are structured resource reads. |
| Lifecycle task | `feishu_task_task` | `moodle_quiz`, `moodle_assignment` | Create/start/save/submit/status are workflow states. |
| Conversation/message | `feishu_chat`, `feishu_im_user_message` | `moodle_forum` | Discussions, replies, updates, deletes. |
| MCP doc path | `feishu_create_doc`, `feishu_fetch_doc`, `feishu_update_doc` | no first-pass equivalent | Moodle docs/resources stay behind course/resource reads for now. |
| Interactive prompt | `feishu_ask_user_question` | no first-pass equivalent | Could later support confirmation cards; not needed for CLI-first pass. |
| Raw/API layer | Feishu OAPI domain tools | `moodle_api` | Moodle API layer must be curated and off by default. |

## Different Tool Types Need Different Designs

### Control tools

Tools:

- `moodle_catalog`
- `moodle_auth`
- `moodle_doctor`
- `moodle_api`

Design:

- Small schema.
- Deterministic output.
- Strong repair hints.
- No large Moodle payloads by default.
- `moodle_api` must be allowlisted and disabled by default.

### Simple read tools

Tools:

- `moodle_course`
- `moodle_questionbank`

Design:

- Read-only.
- Safe to make optional but easy to enable.
- Support pagination, filtering, and summary.
- Return compact results by default.
- No `confirm` needed.

### Workflow tools

Tools:

- `moodle_quiz`
- `moodle_assignment`
- `moodle_calendar`

Design:

- Action-driven lifecycle.
- Risk annotation per action.
- `dryRun` first when supported.
- `confirm=true` for real writes.
- Idempotency required where CLI/WebService requires it.
- Use Moodle-native WebService behavior; do not move business logic into the
  OpenClaw plugin.

### Destructive/write-heavy tools

Tools:

- `moodle_forum`

Design:

- Read actions can be normal.
- Delete/update actions require stronger gates.
- `delete_post` should require `confirm=true` and plugin config such as
  `allowDestructive=true`.
- Prefer returning a preview before deleting.

## Per-Tool Design Templates

### `moodle_catalog`

Template:

```json
{
  "action": "search",
  "query": "practice quiz",
  "domain": "quiz",
  "layer": "shortcut",
  "risk": "all",
  "limit": 10
}
```

Returns manifest entries, not Moodle business payloads.

### `moodle_auth`

Template:

```json
{
  "action": "login_start",
  "profile": "prod",
  "baseUrl": "https://example.edu",
  "noWait": true
}
```

Returns masked auth state plus user-facing verification URL when needed.

### `moodle_doctor`

Template:

```json
{
  "action": "explain_error",
  "error": "invalidtoken",
  "profile": "prod"
}
```

Returns likely cause, next CLI command, and Moodle admin repair hints.

### `moodle_course`

Template:

```json
{
  "action": "outline",
  "params": { "courseId": 116 },
  "profile": "dzexam"
}
```

Read-only and summary-friendly.

### `moodle_questionbank`

Template:

```json
{
  "action": "categories",
  "params": { "courseId": 116 },
  "profile": "dzexam"
}
```

Read-only preparation for quiz creation.

### `moodle_quiz`

Template:

```json
{
  "action": "create_practice",
  "params": {
    "courseId": 116,
    "categoryIds": [619, 616],
    "count": 30,
    "selectionMode": "random_category",
    "title": "Practice Quiz"
  },
  "profile": "dzexam",
  "dryRun": true,
  "confirm": false,
  "idempotencyKey": "stable-client-key"
}
```

Workflow-heavy and write-capable. This is the most important domain tool.

### `moodle_api`

Template:

```json
{
  "action": "call",
  "functionId": "local_aiagentapi_quiz_list_by_course",
  "params": { "courseid": 116 },
  "profile": "dzexam",
  "dryRun": true,
  "confirm": false
}
```

Advanced escape hatch. Must require catalog lookup and allowlist checks.

## Implementation Continuation

The next concrete implementation step is not to scaffold every tool at once.
Start with data:

1. Create a command manifest source.
2. Add risk, domain, layer, dry-run, idempotency, WebService, and examples.
3. Generate a catalog artifact from it.
4. Implement `moodle_catalog` first.
5. Implement `moodle_auth` and `moodle_doctor`.
6. Add `moodle_quiz` next, because it is the highest-value workflow tool.

This order keeps the context and risk profile sane while moving toward a
first-class OpenClaw tool surface.
