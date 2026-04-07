# local_aiagentapi local config (dev/testing)

This document is for local development/testing only.

## Security note

Do **not** commit real tokens, passwords, or personal accounts into this repository (including `README.md`).
Keep secrets in a local file (e.g. `.env.local`) and add it to `.git/info/exclude` so it never gets committed.

## Current local site

- Base URL: `http://127.0.0.1:8000/`
- REST endpoint: `http://127.0.0.1:8000/webservice/rest/server.php`

## PHP 8.5 note (deprecation noise)

This workspace currently uses PHP 8.5 (Homebrew). Moodle 5.1.2 may emit PHP `E_DEPRECATED` warnings
which can break JSON responses and sometimes even interfere with sessions if displayed in output.

Recommended dev server command (suppresses deprecations in HTTP responses):

```bash
php -d max_input_vars=5000 \
  -d display_errors=0 -d html_errors=0 \
  -d log_errors=1 -d error_log=/tmp/moodle-php-server.error.log \
  -d error_reporting='E_ALL & ~E_DEPRECATED' \
  -S 127.0.0.1:8000 -t public
```

## External services

You should have created an External service containing these functions:

- `local_aiagentapi_get_user_context`
- `local_aiagentapi_get_api_catalog`
- `local_aiagentapi_courses_list_my`
- `local_aiagentapi_course_get_outline`
- `local_aiagentapi_quiz_list_by_course`
- `local_aiagentapi_activities_list_by_course`
- `local_aiagentapi_assignments_list_by_course`
- `local_aiagentapi_calendar_list`
- `local_aiagentapi_activities_due_list`
- `local_aiagentapi_assignments_my_status`
- `local_aiagentapi_quiz_attempts_my`
- `local_aiagentapi_quiz_start_attempt`
- `local_aiagentapi_quiz_get_attempt_data`
- `local_aiagentapi_quiz_get_attempt_summary`
- `local_aiagentapi_quiz_save_attempt`
- `local_aiagentapi_quiz_submit_attempt`
- `local_aiagentapi_grades_overview_my`
- `local_aiagentapi_course_progress_my`
- `local_aiagentapi_course_activity_detail`
- `local_aiagentapi_resources_list_by_course`
- `local_aiagentapi_forum_discussions_list`
- `local_aiagentapi_notifications_list_my`
- `local_aiagentapi_question_categories_list`
- `local_aiagentapi_questionbank_search`
- `local_aiagentapi_questionbank_pick_random`
- `local_aiagentapi_questions_render_html`
- `local_aiagentapi_quiz_resolve_random`
- `local_aiagentapi_calendar_publish_plan`
- `local_aiagentapi_calendar_plan_upsert`
- `local_aiagentapi_assignment_save_draft`
- `local_aiagentapi_assignment_submit_final`
- `local_aiagentapi_forum_create_discussion`
- `local_aiagentapi_forum_reply_post`
- `local_aiagentapi_forum_update_post`
- `local_aiagentapi_forum_delete_post`

## Local secrets (recommended)

Create a local file `.env.local` (do not commit it) with:

```bash
export MOODLE_BASE_URL="http://127.0.0.1:8000"
export MOODLE_WS_TOKEN="PUT_YOUR_USER_TOKEN_HERE"
```

For this workspace, the actual local-only test credentials are stored in:

- `.env.local`
- `docs/AIAGENTAPI_LOCAL_SECRETS.local.md`

Then prevent committing it:

```bash
echo "/.env.local" >> .gitignore
```

Load it in your shell session:

```bash
source .env.local
```

## Quick test (curl)

## Smoke test (script)

Run a deterministic smoke test (read + dry-run write + write):

```bash
python3 scripts/aiagentapi_smoke_test.py --env-file .env.local --start-server
```

## Live regression (seeded workflow)

Run the seeded end-to-end regression for the local CLI + `local_aiagentapi`:

```bash
python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed
```

If the local site is not already listening on `127.0.0.1:8000`, let the script start PHP's built-in server:

```bash
python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed --start-server
```

What it verifies:

- demo seed data exists
- CLI can read context/catalog/courses/activities/assignments/quizzes/resources/forums
- student-facing reads work: due list, assignment status, quiz attempts, grades, progress, notifications
- quiz attempt lifecycle works: start, page fetch, summary, save, submit
- question category/search/random-pick/render flows work
- quiz random resolution works
- calendar write can be dry-run and committed
- keyed calendar plan upsert can preview and create
- assignment draft save works
- assignment final submit works
- forum discussion create/reply/update/delete works
- written calendar event is readable afterwards

## Seed demo data

Create or refresh the local demo course, qbank, assignment, and quizzes:

```bash
php scripts/seed_moodle_test_data.php
```

The seed now keeps two quiz fixtures:

- `AI Agent Quiz 1`: fixed + random slot, used for random resolution tests
- `AI Agent Answer Quiz 1`: fixed single-choice + fixed multi-choice, used for stable structured answering regression

After upgrades, register all `local_aiagentapi_*` functions into the unified external service:

```bash
php scripts/register_aiagentapi_service_functions.php
```

Current seeded objects in this workspace:

- Course: `AIAGENT_DEMO_101` (`courseid=3`)
- Question category: `AI Agent Demo Category` (`categoryid=3`)
- Assignment: `AI Agent Assignment 1` (`cmid=3`)
- Page resource: `AI Agent Course Guide`
- URL resource: `AI Agent Reference Link`
- Forum: `AI Agent Forum`
- Quiz: `AI Agent Quiz 1` (`quizid=1`, `cmid=7`)

## CLI quick test

The local CLI wrapper lives at:

- `bin/moodle`
- `scripts/moodle_cli.py`

Basic CLI checks:

```bash
bin/moodle --json exit-codes
bin/moodle --json schema
bin/moodle --env-file .env.local --json courses list
bin/moodle --env-file .env.local --json calendar list
bin/moodle --env-file .env.local --json context get
bin/moodle --env-file .env.local --json catalog get
```

### 1) Get user context

```bash
curl -sS "$MOODLE_BASE_URL/webservice/rest/server.php" \
  -d "wstoken=$MOODLE_WS_TOKEN" \
  -d "wsfunction=local_aiagentapi_get_user_context" \
  -d "moodlewsrestformat=json"
```

### 2) Get API catalog

```bash
curl -sS "$MOODLE_BASE_URL/webservice/rest/server.php" \
  -d "wstoken=$MOODLE_WS_TOKEN" \
  -d "wsfunction=local_aiagentapi_get_api_catalog" \
  -d "moodlewsrestformat=json"
```

### 3) Publish plan to calendar (dry run)

```bash
NOW="$(date +%s)"
curl -sS "$MOODLE_BASE_URL/webservice/rest/server.php" \
  -d "wstoken=$MOODLE_WS_TOKEN" \
  -d "wsfunction=local_aiagentapi_calendar_publish_plan" \
  -d "moodlewsrestformat=json" \
  -d "idempotency_key=test-$(date +%s)" \
  -d "dry_run=1" \
  -d "reason=dev-test" \
  -d "items[0][name]=Study Plan Item 1" \
  -d "items[0][description]=Read chapter 1" \
  -d "items[0][timestart]=$NOW" \
  -d "items[0][timeduration]=3600"
```

### 3) Publish plan to calendar (write)

Set `dry_run=0` (or omit it) and keep the same payload. Use a new `idempotency_key` per request.
