<!-- Generated from agent-skills/moodle-aiagent-stack/references/capabilities.md by scripts/sync_agent_skills.py -->

# Generic Skill Reference: Moodle AI Agent Stack

This file is generated from `scripts/moodle_cli.py --json schema` by `scripts/sync_agent_skills.py`.

## Current architecture

- Backend source of truth: `public/local/aiagentapi`
- Agent-facing remote WebService CLI: `scripts/moodle_cli.py`
- WebService endpoint: `<base-url>/webservice/rest/server.php`
- Moodle PHP maintenance scripts: `scripts/*.php` and `admin/cli/*.php`
- Seed fixtures: `scripts/seed_moodle_test_data.php`
- Live regression: `scripts/test_moodle_live_cli.py`

## Tool boundary

- Use `python3 scripts/moodle_cli.py ...` for normal agent operations, including online quiz creation.
- This remote CLI needs Python plus a Moodle base URL and WebService token/profile.
- The remote CLI does not need local PHP, `public/config.php`, or the Moodle server code directory.
- Use PHP scripts only for Moodle internal maintenance such as plugin upgrade, service registration, data imports, or seeding.
- PHP scripts must run in a Moodle code tree with PHP and `config.php`.

## Root contract

- Common flags: `--json`, `--plain`, `--results-only`, `--select`, `--dry-run`, `--force`, `--no-input`, `--enable-commands`, `--env-file`, `--profile`, `--base-url`, `--token`, `--service`
- Write commands should use `--dry-run` first when supported, then rerun with `--force`.
- `catalog get` and `context get` are the preferred first calls for discovery.
- First-time login should use `setup --name <profile> --base-url <url>`, then `login --name <profile>`, then `status --name <profile>`.
- In headless agent sessions, use `login --name <profile> --no-wait` and return the `verification_url` to the user.
- OpenClaw tool/action routing should use `references/command-manifest.v0.1.json` as the source of truth.

## Generated command inventory

### Bootstrap and discovery

- `agent exit-codes`
- `catalog get`
- `context get`
- `exit-codes`
- `login`
- `schema`
- `setup`
- `status`
- `whoami`

### Course and activity reads

- `activities detail`
- `activities due`
- `activities list`
- `courses list`
- `courses outline`
- `resources list`

### Assignments

- `assignments list`
- `assignments save-draft`
- `assignments status`
- `assignments submit-final`

### Calendar

- `calendar list`
- `calendar publish-plan`
- `calendar upsert-plan`

### Forum and notifications

- `forum create-discussion`
- `forum delete-post`
- `forum discussions`
- `forum reply`
- `forum update-post`
- `notifications list`

### Questions and question bank

- `questions categories`
- `questions pick-random`
- `questions render-html`
- `questions search`

### Quiz lifecycle

- `quiz answer`
- `quiz attempt-data`
- `quiz attempt-summary`
- `quiz attempts`
- `quiz list`
- `quiz resolve-random`
- `quiz save-attempt`
- `quiz start`
- `quiz submit-attempt`

### Grades and progress

- `grades overview`
- `progress course`

### Other commands

- `auth list`
- `auth login`
- `auth logout`
- `auth status`
- `config delete`
- `config init`
- `config list`
- `config show`
- `config use`
- `doctor`
- `mathstate doc-job-upsert`
- `mathstate doc-publish-request`
- `mathstate evidence-ingest`
- `mathstate kp-upsert`
- `mathstate learning-event-record`
- `mathstate lesson-finish`
- `mathstate lesson-log-append`
- `mathstate lesson-session-upsert`
- `mathstate lesson-start`
- `mathstate next-recommendation`
- `mathstate qtype-upsert`
- `mathstate question-map-lookup`
- `mathstate question-map-sync`
- `mathstate question-map-upsert`
- `mathstate review-complete`
- `mathstate review-upsert`
- `mathstate reviews-due`
- `mathstate student-summary`
- `profile add`
- `profile list`
- `profile remove`
- `profile rename`
- `profile use`

## Structured quiz answering

- Preferred path: `quiz start` -> `quiz attempt-data` -> `quiz answer` -> `quiz submit-attempt`
- `quiz answer` reads `responseschema`, translates structured answers into Moodle raw field names, then saves or submits through stable low-level web-service calls.

## Stable seeded fixtures

- Course: `AIAGENT_DEMO_101`
- Question bank: `AI Agent Question Bank`
- Category: `AI Agent Demo Category`
- Assignment: `AI Agent Assignment 1`
- Forum: `AI Agent Forum`
- Random-resolution quiz: `AI Agent Quiz 1`
- Stable answering quiz: `AI Agent Answer Quiz 1`

## Fixed validation entrypoints

```bash
php scripts/seed_moodle_test_data.php
php scripts/register_aiagentapi_service_functions.php
python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed
```
