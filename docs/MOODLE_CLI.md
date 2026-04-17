# Moodle CLI

Agent-facing CLI wrapper for `/Users/wonder/Documents/moodle/public/local/aiagentapi`.

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

## Script path

- `/Users/wonder/Documents/moodle/scripts/moodle_cli.py`
- `/Users/wonder/Documents/moodle/bin/moodle`

When this CLI is used inside `D:\math-markdown`, prefer the project entrypoint:

- [D:\math-markdown\apps\lobster-workbench\bin\moodle.cmd](</D:/math-markdown/apps/lobster-workbench/bin/moodle.cmd>)

The wrapper keeps the project on a single config source and avoids confusion between direct CLI defaults and project-level profile defaults.

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
- `search global`
- `search course`
- `courses list`
- `courses get`
- `courses outline`
- `activities list`
- `activities due`
- `activities get`
- `activities detail`
- `assignments list`
- `assignments get`
- `assignments status`
- `assignments save-draft`
- `assignments submit-final`
- `resources list`
- `resources get` (alias: `resources fetch`)
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
- `mathstate evidence-ingest`
- `mathstate student-summary`
- `mathstate reviews-due`
- `calendar list`
- `calendar upsert-plan`
- `questions categories`
- `questions search`
- `questions pick-random`
- `questions render-html`
- `quiz list`
- `quiz get`
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

### 1) Initialize local profile

```bash
bin/moodle config init --name prod --base-url http://dzexam.cn --activate
```

### 2) Login (recommended: browser/device flow)

```bash
bin/moodle auth login --name prod
```

Non-interactive start + complete later:

```bash
bin/moodle auth login --name prod --no-wait
```

Default human output (no `--json`) prints verification URL and resume command directly:

```text
Open this URL to approve CLI login:
http://<host>/local/aiagentapi/device_verify.php?user_code=XXXX-XXXX
Resume command: moodle auth login --device-code <DEVICE_CODE> --name <profile>
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

For `search global` / `search course`, pagination metadata is included in both `data.paging` and `meta`:

- `offset`
- `limit`
- `total_count`
- `has_more`
- `next_offset`

For core list commands (`courses/activities/resources/assignments/quiz list`), client-side pagination is also available:

- `--offset` (default `0`)
- `--limit` (default `0`, meaning all rows from offset)
- response `meta` includes the same pagination fields (`total_count`, `has_more`, `next_offset`, etc.)

For `questions search`, `forum discussions`, and `notifications list`, pagination is also available:

- `questions search`: `--offset` + `--limit` (windowed fetch + stable page slice)
- `forum discussions`: `--offset` + `--limit` (windowed fetch + stable page slice)
- `notifications list`: `--offset` (alias of legacy `--limit-from`) + `--limit`

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

- `search global`
- `search course`
- `courses list`
- `courses get`
- `courses outline`
- `activities list`
- `activities due`
- `activities get`
- `activities detail`
- `resources list`
- `resources get`
- `quiz list`
- `quiz get`
- `quiz attempts`
- `assignments list`
- `assignments get`
- `assignments status`
- `calendar list`
- `forum discussions`
- `notifications list`
- `questions search`
- `grades overview`
- `progress course`

`mathstate student-summary` and `mathstate reviews-due` currently return plugin-native JSON structures rather than the normalized resource envelope. This is intentional for now because they expose state aggregates and review-task collections directly from `local_mathstate`.

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
- `mathstate` commands are enforced server-side by `local_mathstate` capability checks plus course visibility checks.
- CLI output normalization does not grant extra access.
- Effective permissions are the same as the authenticated Moodle user token.

## Mathstate

`mathstate` is the CLI surface for `/Users/wonder/Documents/moodle/public/local/mathstate`.

Supported commands:

- `mathstate kp-upsert`
- `mathstate qtype-upsert`
- `mathstate question-map-upsert`
- `mathstate evidence-ingest`
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
  mathstate question-map-upsert \
  --item-json '{"questionid":1,"qg_id":"qg.demo.1","kg_ids":["kp.demo.1"],"mapping_source":"manual","mapping_confidence":95}'

bin/moodle --env-file .env.local --json \
  mathstate evidence-ingest \
  --item-json '{"userid":3,"courseid":3,"questionid":1,"result":"wrong","score":0,"maxscore":1}'

bin/moodle --env-file .env.local --json \
  mathstate student-summary --course-id 3 --user-id 3

bin/moodle --env-file .env.local --json \
  mathstate reviews-due --course-id 3 --user-id 3 --limit 20
```

Notes:

- Batch write commands accept either `--input <json>` or repeated `--item-json`.
- `student-summary` is course-scoped. Querying your own state requires course access; querying another user requires the `local/mathstate:view` capability in addition to course access.
- `reviews-due` returns due review/remediation tasks ordered by priority and due time.

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

### Get normalized course detail (outline envelope)

```bash
bin/moodle --env-file .env.local --json courses get --course-id 12
```

### List visible activities in a course

```bash
bin/moodle --env-file .env.local --json \
  activities list --course-id 12
```

Paged:

```bash
bin/moodle --env-file .env.local --json \
  activities list --course-id 12 --limit 20 --offset 20
```

### List assignments in a course

```bash
bin/moodle --env-file .env.local --json \
  assignments list --course-id 12
```

Paged:

```bash
bin/moodle --env-file .env.local --json \
  assignments list --course-id 12 --limit 20 --offset 0
```

### Search in one course

```bash
bin/moodle --env-file .env.local --json \
  search course --course-id 12 --query 测试 --limit 20
```

Next page:

```bash
bin/moodle --env-file .env.local --json \
  search course --course-id 12 --query 测试 --limit 20 --offset 20
```

### Search globally with kind filters

```bash
bin/moodle --env-file .env.local --json \
  search global --query 试听 --kind course --kind resource --limit 20
```

### Get one resource with detail content

```bash
bin/moodle --env-file .env.local --json \
  resources get --course-id 12 --cmid 701
```

Skip detail-content fetch:

```bash
bin/moodle --env-file .env.local --json \
  resources get --course-id 12 --cmid 701 --no-content
```

### Get one assignment + my status

```bash
bin/moodle --env-file .env.local --json \
  assignments get --course-id 12 --assign-id 401
```

### Get one quiz + my attempts summary

```bash
bin/moodle --env-file .env.local --json \
  quiz get --course-id 12 --quiz-id 666
```

### Show my assignment status

```bash
bin/moodle --env-file .env.local --json \
  assignments status --course-id 12
```

### Search questions with pagination

```bash
bin/moodle --env-file .env.local --json \
  questions search --course-id 12 --query 函数 --limit 20 --offset 20
```

### List forum discussions with pagination

```bash
bin/moodle --env-file .env.local --json \
  forum discussions --course-id 12 --limit 20 --offset 20
```

### List notifications with pagination

```bash
bin/moodle --env-file .env.local --json \
  notifications list --limit 20 --offset 40
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
