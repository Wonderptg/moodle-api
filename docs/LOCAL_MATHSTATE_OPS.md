# local_mathstate Operations Runbook

This is the minimal operational path for deploying and validating `public/local/mathstate`.

## 1) Upgrade plugin code

Run from Moodle code root (`/Users/wonder/Documents/moodle` locally, `/srv/moodle/current` on Huawei Cloud):

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

For this release (`local_mathstate` `2026042000`), no schema extension is required.
Only a code/version upgrade savepoint is applied.

Contract reference:

- `/Users/wonder/Documents/moodle/docs/LOCAL_MATHSTATE_CONTRACT.md`

## 2) Register webservice functions into `local_aiagentapi`

`local_mathstate_*` functions must be linked into the service used by token profiles.

```bash
php scripts/register_aiagentapi_service_functions.php \
  --service-shortname=local_aiagentapi \
  --function-prefix=local_mathstate_
```

Quick check:

```bash
php -r 'define("CLI_SCRIPT", true); require "config.php"; global $DB; $sid=(int)$DB->get_field("external_services","id",["shortname"=>"local_aiagentapi"]); $rows=$DB->get_records("external_services_functions",["externalserviceid"=>$sid]); echo "linked=".count($rows).PHP_EOL;'
```

## 3) Smoke test (WS read/write chain)

Use a token that is attached to `local_aiagentapi`.

```bash
python3 scripts/mathstate_ws_smoke_test.py \
  --env-file .env.local \
  --token "$MOODLE_MATHSTATE_WS_TOKEN"
```

The smoke test covers:

- `local_mathstate_question_map_sync_batch`
- `local_mathstate_question_map_lookup`
- `local_mathstate_lesson_session_upsert_batch`
- `local_mathstate_learning_event_record_batch`
- `local_mathstate_review_upsert_batch`
- `local_mathstate_doc_job_upsert_batch`
- `local_mathstate_lesson_start`
- `local_mathstate_lesson_log_append`
- `local_mathstate_lesson_finish`
- `local_mathstate_review_complete`
- `local_mathstate_doc_publish_request`
- `local_mathstate_next_recommendation`
- `local_mathstate_video_progress_summary`

Video progress smoke pattern:

```bash
python3 scripts/moodle_cli.py mathstate lesson-log-append \
  --course-id 92 \
  --user-id 2 \
  --session-key sess-video-smoke \
  --lesson-key lesson-video-smoke \
  --event-type video_heartbeat \
  --payload-json '{"resource_course_id":26,"resource_cmid":2905,"current_time_sec":120,"duration_sec":300,"watch_seconds_delta":15,"coverage_ratio":0.40}' \
  --force

python3 scripts/moodle_cli.py mathstate video-progress-summary \
  --course-id 92 \
  --user-id 2 \
  --session-key sess-video-smoke
```

## 4) Cleanup smoke data

Dry run first:

```bash
php scripts/cleanup_mathstate_smoke.php \
  --source-prefix=w2m-math-smoke- \
  --session-prefix=sess-smoke- \
  --job-prefix=docjob-smoke- \
  --target-prefix=KG-SMOKE-
```

Then execute:

```bash
php scripts/cleanup_mathstate_smoke.php \
  --source-prefix=w2m-math-smoke- \
  --session-prefix=sess-smoke- \
  --job-prefix=docjob-smoke- \
  --target-prefix=KG-SMOKE- \
  --force
```

## 5) Server safety notes (Huawei Cloud)

- Active runtime root: `/srv/moodle/current`
- Do **not** run deploy/upgrade under `/var/www/html/moodle` (legacy tree)
- Deploy first, then `upgrade.php`, then function registration, then smoke test
