---
name: moodle-aiagent-cli
description: "Use when working with the local Moodle AI agent stack in this repository: reading or writing Moodle data through the remote WebService CLI, seeding demo data, running live regression, or extending `local_aiagentapi`. Trigger for tasks involving courses, calendar plans, assignments, forums, quizzes, question banks, or Moodle automation in this repo."
---

# Moodle AI Agent CLI

<!-- Generated from agent-skills/moodle-aiagent-stack/SKILL.md by scripts/sync_agent_skills.py -->

This is a plugin adapter for the canonical generic skill. Canonical source: `agent-skills/moodle-aiagent-stack/SKILL.md`.

This is the platform-neutral skill source for the Moodle AI agent stack in this repository.

Use it when an agent should operate through the repo's stable CLI and AI-friendly API instead of manual Moodle UI actions.

## Execution model

The primary CLI is `scripts/moodle_cli.py`. It is a remote WebService client:

```text
python3 scripts/moodle_cli.py ...
  -> <base-url>/webservice/rest/server.php
  -> local_aiagentapi
  -> Moodle server-side plugin code
```

Use this path for normal agent work, including reading courses, listing quizzes,
starting attempts, and creating online practice quizzes. It only needs Python,
a Moodle base URL, and a WebService token/profile. It does not need local PHP,
`public/config.php`, or the Moodle server code directory.

The PHP files under `scripts/*.php` are Moodle internal maintenance scripts.
Use them only for plugin upgrades, service registration, data imports, seeding,
or direct Moodle maintenance. Those scripts must run in a Moodle code tree with
PHP and `config.php`.

## Tool-first workflow

When OpenClaw or Codex MCP tools from `moodle-aiagent-stack` are available,
prefer them over typing raw CLI commands. The tools call
`scripts/moodle_cli.py` with structured argv, preserve safety gates, and return
structured details.

OpenClaw loads these tools through `openclaw.plugin.json`,
`package.json#openclaw.extensions`, and
`package.json#openclaw.runtimeExtensions`.
`index.ts` is the source entry, `index.js` is the installed runtime entry, and
`shared/openclaw-tools.mjs` owns the OpenClaw tool schemas/registration.
Codex loads the same tool surface through `.codex-plugin/plugin.json`,
`.mcp.json`, and `scripts/moodle-mcp-server.mjs`.

Start with `moodle_catalog` when the right action or parameter names are
unclear:

```json
{ "action": "search", "query": "course outline" }
```

Then call the domain tool:

```json
{ "action": "outline", "params": { "courseId": 116 } }
```

Use this routing:

- `moodle_auth`: setup, device login, profile status, profile switching, logout
- `moodle_doctor`: diagnose auth/config/capability/network errors; use `explain_error` after a failed tool call
- `moodle_course`: context, courses, outlines, activities, resources, grades, progress, notifications
- `moodle_questionbank`: categories, search, search_details, random question selection, question HTML rendering
- `moodle_quiz`: quiz lists, attempts, random resolution, practice quiz creation, answering, submission
- `moodle_calendar`: event reads and study-plan calendar writes
- `moodle_assignment`: assignment reads, draft save, final submit
- `moodle_forum`: discussions, replies, post updates, guarded deletes
- `moodle_api`: optional escape hatch; use only after `moodle_catalog get_function` shows no domain tool covers the need

For writes, keep the first call as a dry run. Do not set `dryRun=false` unless
the user asked for the change and the tool call includes `confirm=true` plus a
stable `idempotencyKey`. Treat `forum.delete_post` as destructive; it also
requires plugin config `allowDestructive=true`.

In OpenClaw, read tool results from `details.ok`, `details.data`,
`details.meta`, and `details.error`. In Codex MCP, read the same payload from
`structuredContent` or parse the JSON text in `content[0].text`. The
human-facing `content` text is only a summary.

For question-bank browsing:

- Use `moodle_questionbank(action=search)` for cheap lists of ids and metadata.
- Use `moodle_questionbank(action=render_html)` when you already have question ids.
- Use `moodle_questionbank(action=search_details)` when the user wants one searched page with question text, options, answer/correction markers, and Moodle feedback/analysis in the same call. Keep `limit` modest, typically 20 or less. Set `showCorrection=true` and `includeFeedback=true` when answers and analysis are requested.

## Environment choice

For this repository, prefer the upgraded real-content rehearsal environment first:

- real-content testing: `python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json ...`
- seeded demo / local regression: `python3 scripts/moodle_cli.py --env-file .env.local --json ...`

Use `.env.upgrade51.local` when the goal is to inspect real courses, real outlines, real quizzes, real calendar items, or student-facing upgraded behavior on `http://127.0.0.1:8003`.

Use `.env.local` only when the goal is seeded deterministic regression on the local demo site at `http://127.0.0.1:8000`.

## First-time login

If a command fails with a missing token or missing base URL, do not keep guessing
env files. Set up and verify a profile first:

```bash
python3 scripts/moodle_cli.py setup --name prod --base-url https://dzexam.cn
python3 scripts/moodle_cli.py login --name prod
python3 scripts/moodle_cli.py status --name prod
```

For headless agent sessions, start login without waiting and give the returned
`verification_url` to the user:

```bash
python3 scripts/moodle_cli.py --json login --name prod --no-wait
```

The old explicit commands still work:

```bash
python3 scripts/moodle_cli.py config init --name prod --base-url https://dzexam.cn --activate
python3 scripts/moodle_cli.py auth login --name prod
python3 scripts/moodle_cli.py auth status --name prod
```

## Canonical workflow

1. Discover the current user and API surface first:
   - real-content: `python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json context get`
   - real-content: `python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json catalog get`
   - demo regression: `python3 scripts/moodle_cli.py --env-file .env.local --json context get`
   - demo regression: `python3 scripts/moodle_cli.py --env-file .env.local --json catalog get`
2. For write operations, use `--dry-run` first when supported, then rerun with `--force`.
3. Seed deterministic local fixtures before validating behavior:
   - `php scripts/seed_moodle_test_data.php`
4. Use the fixed end-to-end regression for confidence:
   - `python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed`
5. If web-service functions changed, re-register them:
   - `php scripts/register_aiagentapi_service_functions.php`

## Online quiz creation

Use the remote CLI, not a PHP maintenance script:

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --force \
  quiz create-practice \
  --idempotency-key <stable-key> \
  --course-id <courseid> \
  --category-id <question-category-id> \
  --category-id <another-question-category-id> \
  --count 5 \
  --random \
  --title "课后练习"
```

Repeat `--category-id` when one qbank course stores the same chapter's questions in multiple Moodle question categories.

If this fails, check the WebService token user's Moodle permissions first. Do
not switch to a `php scripts/*.php` path unless the task is explicit server
maintenance.

## Architecture rules

- Keep business logic in `public/local/aiagentapi`
- Keep `scripts/moodle_cli.py` as a thin agent-facing wrapper
- Prefer seeded fixtures over random site state
- For quiz answering, prefer:
  - `quiz start`
  - `quiz attempt-data`
  - `quiz answer`
  - `quiz submit-attempt`

## Adapter rule

Treat this folder as the canonical skill source.

- Claude Code adapters live under `.claude/skills/` and `plugins/`
- Codex plugin metadata lives under `plugins/moodle-aiagent-stack/.codex-plugin/`
- Codex MCP metadata lives in `plugins/moodle-aiagent-stack/.mcp.json`
- OpenClaw-facing adapters live under `skills/`

If the capability list or workflow changes, update this skill first, then sync the adapters.

## Read next

- `plugins/moodle-aiagent-stack/README.md` when editing plugin internals or installing it for another host
- `plugins/moodle-aiagent-stack/USE_CASES.md` for concrete tool-call recipes
- `references/capabilities.md`
