# Moodle CLI

Agent-facing remote WebService CLI wrapper for `local_aiagentapi`.

## Read This First

There are two different tool families in this repository:

| Tool family | Main path | Runs where | Requires | Use for |
| --- | --- | --- | --- | --- |
| Remote Moodle CLI | `scripts/moodle_cli.py` / `bin/moodle` | Any machine with network access | Python, Moodle base URL, WebService token/profile | Reading courses, listing quizzes, starting attempts, creating online practice quizzes through `local_aiagentapi` |
| Moodle PHP maintenance scripts | `scripts/*.php`, `admin/cli/*.php` | Moodle code directory | PHP, Moodle `config.php`, correct Moodle root | Plugin upgrade, service registration, data imports, one-off DB maintenance |

Important: `moodle_cli.py` does **not** need local PHP, `public/config.php`, or the
server code directory. It calls:

```text
https://dzexam.cn/webservice/rest/server.php
  -> local_aiagentapi
  -> Moodle server-side PHP plugin logic
```

If an agent says it cannot create an online quiz because the local environment has
no PHP or no `public/config.php`, it is using the wrong tool family. Online quiz
creation should use `quiz create-practice` through the remote Moodle CLI.

Design goals (aligned with Feishu/Lark-style CLI ergonomics):

- strong root contract (`ok / identity / data / meta`)
- JSON-first automation with stable exit codes
- command allowlisting and schema export
- `dry-run` for writes
- predictable table/csv/ndjson rendering for list resources

Important:

- this CLI is a thin wrapper
- the real backend contract remains `local_aiagentapi`
- business logic should stay server-side
- Moodle permissions still apply to the token user; admin tokens can create quizzes, student tokens should not

## Script path

- `/Users/wonder/Documents/moodle/scripts/moodle_cli.py`
- `/Users/wonder/Documents/moodle/bin/moodle`

## Root flags

- `--config-dir`
- `--profile`
- `--env-file`
- `--base-url`
- `--token`
- `--service`
- `--enable-commands`
- `--json`
- `--plain`
- `--format`
- `--results-only`
- `--select`
- `--dry-run`
- `--force`
- `--no-input`
- `--verbose`
- `--timeout`

## Available commands

- `schema`
- `exit-codes`
- `doctor`
- `setup`
- `login`
- `status`
- `agent exit-codes`
- `config list`
- `config show`
- `config init`
- `config use`
- `config delete`
- `auth login`
- `auth list`
- `auth status`
- `auth logout`
- `profile list`
- `profile use`
- `profile add`
- `profile remove`
- `profile rename`
- `whoami`
- `context get`
- `catalog get`
- `courses list`
- `courses outline`
- `activities list`
- `activities due`
- `activities detail`
- `assignments list`
- `assignments status`
- `assignments save-draft`
- `assignments submit-final`
- `resources list`
- `forum discussions`
- `forum create-discussion`
- `forum reply`
- `forum update-post`
- `forum delete-post`
- `notifications list`
- `grades overview`
- `progress course`
- `mathstate kp-upsert`
- `mathstate qtype-upsert`
- `mathstate question-map-upsert`
- `mathstate question-map-sync`
- `mathstate question-map-lookup`
- `mathstate evidence-ingest`
- `mathstate lesson-session-upsert`
- `mathstate learning-event-record`
- `mathstate review-upsert`
- `mathstate doc-job-upsert`
- `mathstate lesson-start`
- `mathstate lesson-log-append`
- `mathstate lesson-finish`
- `mathstate review-complete`
- `mathstate doc-publish-request`
- `mathstate next-recommendation`
- `mathstate student-summary`
- `mathstate reviews-due`
- `calendar list`
- `calendar upsert-plan`
- `questions categories`
- `questions search`
- `questions pick-random`
- `questions render-html`
- `quiz list`
- `quiz attempts`
- `quiz start`
- `quiz attempt-data`
- `quiz attempt-summary`
- `quiz answer`
- `quiz save-attempt`
- `quiz submit-attempt`
- `quiz resolve-random`
- `calendar publish-plan`

## Quick start (new computer)

### Fast path for AI agents

Use these top-level aliases first. They are easier for agents to discover than
the lower-level `config` / `auth` commands:

```bash
bin/moodle setup --name prod --base-url https://dzexam.cn
bin/moodle login --name prod
bin/moodle status --name prod
bin/moodle --json context get
```

If `login` is running in a headless or background agent session, use:

```bash
bin/moodle login --name prod --no-wait
```

Then send the returned `verification_url` to the user and later resume polling:

```bash
bin/moodle login --name prod --device-code <DEVICE_CODE>
```

### 1) Initialize local profile

```bash
bin/moodle config init --name prod --base-url https://dzexam.cn --activate
```

### 2) Login (recommended: browser/device flow)

```bash
bin/moodle auth login --name prod
```

Equivalent top-level alias:

```bash
bin/moodle login --name prod
```

Non-interactive start + complete later:

```bash
bin/moodle auth login --name prod --no-wait
```

Username/password fallback:

```bash
bin/moodle auth login --name prod --username wonderhow --password '***'
```

### 3) Verify session

```bash
bin/moodle doctor
bin/moodle --json auth status
bin/moodle --json context get
```

Equivalent top-level alias:

```bash
bin/moodle --json status
```

## Output contract

### Success envelope

Most normalized read commands return:

```json
{
  "ok": true,
  "identity": "user",
  "data": {},
  "meta": {
    "count": 0,
    "primary_resource": "..."
  }
}
```

For list-oriented commands, `data.items` is provided as a generic array alias for agent consumption, while domain keys are preserved (`courses`, `activities`, `quizzes`, etc.).

### Error envelope

In machine modes (`--json`, `--format json|table|csv|ndjson`, or non-TTY stderr), errors are emitted as structured JSON:

```json
{
  "ok": false,
  "identity": "user",
  "error": {
    "type": "validation",
    "code": 2,
    "message": "..."
  }
}
```

### Normalized read coverage (current)

The following commands currently use normalized resource output with `meta.primary_resource` and `data.items`:

- `courses list`
- `courses outline`
- `activities list`
- `activities due`
- `activities detail`
- `resources list`
- `quiz list`
- `quiz attempts`
- `assignments list`
- `assignments status`
- `calendar list`
- `forum discussions`
- `notifications list`
- `questions search`
- `grades overview`
- `progress course`

## Format modes

- `--json`: full envelope JSON
- `--format pretty`: human-friendly summary text (for selected commands)
- `--format table`: tabular view
- `--format csv`: CSV output
- `--format ndjson`: line-delimited JSON rows

Examples:

```bash
bin/moodle --format table courses list
bin/moodle --format ndjson activities due --course-id 108 --limit 20
bin/moodle --format csv notifications list --limit 20
```

## Permission model

- Authorization is enforced server-side by Moodle capability checks in `local_aiagentapi`.
- CLI output normalization does not grant extra access.
- Effective permissions are the same as the authenticated Moodle user token.

## Mathstate

`mathstate` is the CLI surface for `/Users/wonder/Documents/moodle/public/local/mathstate`.

Compatibility and payload contract reference:

- `/Users/wonder/Documents/moodle/docs/LOCAL_MATHSTATE_CONTRACT.md`

Supported commands:

- `mathstate kp-upsert`
- `mathstate qtype-upsert`
- `mathstate question-map-upsert`
- `mathstate question-map-sync`
- `mathstate question-map-lookup`
- `mathstate evidence-ingest`
- `mathstate lesson-session-upsert`
- `mathstate learning-event-record`
- `mathstate review-upsert`
- `mathstate doc-job-upsert`
- `mathstate lesson-start`
- `mathstate lesson-log-append`
- `mathstate lesson-finish`
- `mathstate review-complete`
- `mathstate doc-publish-request`
- `mathstate next-recommendation`
- `mathstate student-summary`
- `mathstate reviews-due`

Examples:

```bash
bin/moodle --env-file .env.local --json \
  mathstate kp-upsert \
  --item-json '{"kg_id":"kp.demo.1","name":"集合概念"}'

bin/moodle --env-file .env.local --json \
  mathstate qtype-upsert \
  --item-json '{"qg_id":"qg.demo.1","name":"集合概念题","knowledge_points":["kp.demo.1"]}'

bin/moodle --env-file .env.local --json \
  mathstate question-map-sync \
  --item-json '{"source_id":"w2m-math-smoke-1","questionid":1,"questionbankentryid":1,"qg_id":"qg.demo.1","kg_ids":["kp.demo.1"],"lesson_key":"course2_lesson01","mapping_source":"sidecar"}'

bin/moodle --env-file .env.local --json \
  mathstate reviews-due --course-id 108 --user-id 2 --limit 20

bin/moodle --env-file .env.local --json \
  mathstate lesson-start --course-id 108 --user-id 2 --lesson-key course108_lesson01

bin/moodle --env-file .env.local --json \
  mathstate next-recommendation --course-id 108 --user-id 2 --limit 5
```

## Examples

### Show config and auth state

```bash
bin/moodle config list
bin/moodle --json auth status
```

### Show current user

```bash
bin/moodle --env-file .env.local --json context get
```

### Show API catalog

```bash
bin/moodle --env-file .env.local --json catalog get
```

### List my courses

```bash
bin/moodle --env-file .env.local --json courses list
```

### Get course outline

```bash
bin/moodle --env-file .env.local --json courses outline --course-id 12
```

### List visible activities in a course

```bash
bin/moodle --env-file .env.local --json \
  activities list --course-id 12
```

### List assignments in a course

```bash
bin/moodle --env-file .env.local --json \
  assignments list --course-id 12
```

### Show my assignment status

```bash
bin/moodle --env-file .env.local --json \
  assignments status --course-id 12
```

### Save an assignment draft

```bash
bin/moodle --env-file .env.local --json --force \
  assignments save-draft \
  --idempotency-key assign-draft-001 \
  --assign-id 7 \
  --text '<p>Draft generated by the AI agent.</p>'
```

### Submit an assignment for grading

```bash
bin/moodle --env-file .env.local --json --force \
  assignments submit-final \
  --idempotency-key assign-submit-001 \
  --assign-id 7
```

### List due/open activity timestamps

```bash
bin/moodle --env-file .env.local --json \
  activities due --course-id 12 --limit 20
```

### Get one activity detail

```bash
bin/moodle --env-file .env.local --json \
  activities detail --cmid 3
```

### List resource-style learning materials

```bash
bin/moodle --env-file .env.local --json \
  resources list --course-id 12
```

### List forum discussions

```bash
bin/moodle --env-file .env.local --json \
  forum discussions --course-id 12 --limit 20
```

### Create a forum discussion

```bash
bin/moodle --env-file .env.local --json --force \
  forum create-discussion \
  --idempotency-key forum-create-001 \
  --forum-id 9 \
  --subject "Question about Unit 1" \
  --message '<p>Can you clarify the second example?</p>'
```

### Reply to a forum post

```bash
bin/moodle --env-file .env.local --json --force \
  forum reply \
  --idempotency-key forum-reply-001 \
  --post-id 42 \
  --subject "Re: Question about Unit 1" \
  --message '<p>Here is a short reply drafted by the agent.</p>'
```

### Update a forum post

```bash
bin/moodle --env-file .env.local --json --force \
  forum update-post \
  --idempotency-key forum-update-001 \
  --post-id 42 \
  --subject "Updated subject" \
  --message '<p>Updated content.</p>'
```

### Delete a forum post

```bash
bin/moodle --env-file .env.local --json --force \
  forum delete-post \
  --idempotency-key forum-delete-001 \
  --post-id 42
```

### List my notifications

```bash
bin/moodle --env-file .env.local --json \
  notifications list --limit 20
```

### Show my grade overview

```bash
bin/moodle --env-file .env.local --json \
  grades overview --course-id 12
```

### Show my course progress

```bash
bin/moodle --env-file .env.local --json \
  progress course --course-id 12
```

### List calendar events

```bash
bin/moodle --env-file .env.local --json \
  calendar list --timestart 1762502400 --timeend 1765094400 --limit 50
```

### List question categories

```bash
bin/moodle --env-file .env.local --json \
  questions categories --course-id 12
```

### Search questions

```bash
bin/moodle --env-file .env.local --json \
  questions search --category-id 34 --query algebra --limit 20
```

### Show only endpoint names

```bash
bin/moodle --env-file .env.local --json --results-only --select name catalog get
```

### Pick random questions

```bash
bin/moodle --env-file .env.local --json \
  questions pick-random --category-id 12 --count 5 --recurse
```

### Render questions to HTML

```bash
bin/moodle --env-file .env.local --json \
  questions render-html 101 102 103 --show-correction
```

### Resolve random quiz questions

```bash
bin/moodle --env-file .env.local --json \
  quiz resolve-random --quiz-id 15 --copies 2
```

### List quizzes in a course

```bash
bin/moodle --env-file .env.local --json \
  quiz list --course-id 12
```

### Create a post-lesson practice quiz

Creates a quiz from existing Moodle question-bank questions. The server picks questions from
`local_oc_shell_resource_map` / `local_mathstate_question_map` first, then falls back to standard
KG/QG Chinese names and explicit text tags. Created quizzes are hidden by default; pass
`--visible` only when you want students to see it immediately.

By default the quiz contains fixed question slots. Use `--random` or
`--selection-mode random_category` to create Moodle native random slots from the selected question
category. Random category mode depends on Moodle question categories/tags, so for precise random
pools the question bank should keep each lesson or KG/QG pool in its own category or Moodle tags.

```bash
bin/moodle --env-file .env.local --json --dry-run \
  quiz create-practice \
  --idempotency-key practice-preview-001 \
  --course-id 26 \
  --cmid 2943 \
  --count 5

bin/moodle --env-file .env.local --json --force \
  quiz create-practice \
  --idempotency-key practice-create-001 \
  --course-id 26 \
  --cmid 2943 \
  --count 5 \
  --title "课后练习 - 1.3 集合间的基本运算"

bin/moodle --env-file .env.local --json --force \
  quiz create-practice \
  --idempotency-key practice-random-001 \
  --course-id 26 \
  --cmid 2943 \
  --count 5 \
  --random \
  --title "随机课后练习 - 1.3 集合间的基本运算"
```

### List my quiz attempts

```bash
bin/moodle --env-file .env.local --json \
  quiz attempts --course-id 12
```

### Start a quiz attempt

```bash
bin/moodle --env-file .env.local --json --force \
  quiz start \
  --idempotency-key quiz-start-001 \
  --quiz-id 15
```

### Fetch one attempt page

```bash
bin/moodle --env-file .env.local --json \
  quiz attempt-data --attempt-id 21 --page 0
```

### Fetch an attempt summary

```bash
bin/moodle --env-file .env.local --json \
  quiz attempt-summary --attempt-id 21
```

### Answer quiz questions with structured inputs

```bash
bin/moodle --env-file .env.local --json --force \
  quiz answer \
  --idempotency-key quiz-answer-001 \
  --attempt-id 21 \
  --answer-json '{"slot":1,"choice_index":1}' \
  --answer-json '{"slot":2,"choice_indexes":[0,2]}'
```

### Save quiz responses

```bash
bin/moodle --env-file .env.local --json --force \
  quiz save-attempt \
  --idempotency-key quiz-save-001 \
  --attempt-id 21 \
  --response-json '{"q1:1_answer":"0"}'
```

### Submit a quiz attempt

```bash
bin/moodle --env-file .env.local --json --force \
  quiz submit-attempt \
  --idempotency-key quiz-submit-001 \
  --attempt-id 21
```

### Dry-run calendar write

```bash
bin/moodle --env-file .env.local --json --dry-run \
  calendar publish-plan \
  --idempotency-key plan-preview-001 \
  --item-json '{"name":"Read chapter 1","description":"Unit 1","timestart":1762502400,"timeduration":3600}'
```

### Real calendar write

```bash
bin/moodle --env-file .env.local --json --force \
  calendar publish-plan \
  --idempotency-key plan-write-001 \
  --input /path/to/plan.json
```

### Upsert a keyed study plan

```bash
bin/moodle --env-file .env.local --json --force \
  calendar upsert-plan \
  --idempotency-key plan-sync-001 \
  --plan-key weekly-plan \
  --item-json '{"item_key":"task-1","name":"Study session","description":"chapter 3","timestart":1762502400,"timeduration":3600}'
```

## Plan file format

`--input` accepts either:

1. a JSON array of items
2. an object with an `items` array

Each item should look like:

```json
{
  "name": "Study session",
  "description": "Chapter 3 review",
  "timestart": 1762502400,
  "timeduration": 3600
}
```

## Local validation

CLI-only validation:

```bash
python3 -m unittest scripts/test_moodle_cli.py
bin/moodle schema
bin/moodle exit-codes
```

Live calls against local Moodle:

```bash
php scripts/seed_moodle_test_data.php
php scripts/register_aiagentapi_service_functions.php
bin/moodle --env-file .env.local --json context get
bin/moodle --env-file .env.local --json catalog get
bin/moodle --env-file .env.local --json activities list --course-id 3
bin/moodle --env-file .env.local --json activities due --course-id 3
bin/moodle --env-file .env.local --json assignments status --course-id 3
bin/moodle --env-file .env.local --json --force assignments save-draft --idempotency-key test-draft-1 --assign-id 1 --text '<p>Draft text</p>'
bin/moodle --env-file .env.local --json --force assignments submit-final --idempotency-key test-submit-1 --assign-id 1
bin/moodle --env-file .env.local --json resources list --course-id 3
bin/moodle --env-file .env.local --json forum discussions --course-id 3 --limit 20
bin/moodle --env-file .env.local --json --force forum create-discussion --idempotency-key test-forum-create-1 --forum-id 2 --subject "Test" --message '<p>hello</p>'
bin/moodle --env-file .env.local --json --force forum reply --idempotency-key test-forum-reply-1 --post-id 1 --subject "Re: Test" --message '<p>reply</p>'
bin/moodle --env-file .env.local --json --force forum update-post --idempotency-key test-forum-update-1 --post-id 1 --subject "Updated Test" --message '<p>updated</p>'
bin/moodle --env-file .env.local --json --force forum delete-post --idempotency-key test-forum-delete-1 --post-id 1
bin/moodle --env-file .env.local --json quiz attempts --course-id 3
bin/moodle --env-file .env.local --json --force quiz start --idempotency-key test-quiz-start-1 --quiz-id 1
bin/moodle --env-file .env.local --json quiz attempt-data --attempt-id 1 --page 0
bin/moodle --env-file .env.local --json quiz attempt-summary --attempt-id 1
bin/moodle --env-file .env.local --json --force quiz save-attempt --idempotency-key test-quiz-save-1 --attempt-id 1
bin/moodle --env-file .env.local --json --force quiz submit-attempt --idempotency-key test-quiz-submit-1 --attempt-id 1
bin/moodle --env-file .env.local --json grades overview --course-id 3
bin/moodle --env-file .env.local --json progress course --course-id 3
bin/moodle --env-file .env.local --json questions search --course-id 3
bin/moodle --env-file .env.local --json quiz resolve-random --quiz-id 1
bin/moodle --env-file .env.local --json --dry-run calendar upsert-plan --idempotency-key test-1 --plan-key demo --item-json '{"item_key":"task-1","name":"Preview","description":"preview","timestart":1762502400,"timeduration":1800}'
```

Full seeded live regression:

```bash
python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed
```

If the local site is down, let the script start PHP's dev server first:

```bash
python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed --start-server
```
