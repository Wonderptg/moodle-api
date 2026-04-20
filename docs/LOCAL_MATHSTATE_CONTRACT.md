# local_mathstate Contract (Runtime + Compatibility)

This document defines the current online contract for `local_mathstate` runtime APIs.

- Plugin version baseline: `2026042000`
- Scope: runtime storage + question map bridge + student-facing lesson/review/doc actions
- Goal: keep CLI and WS integration stable across old/new payload shapes

## 1) Canonical model

For new callers, prefer these canonical fields:

- `courseid` + `userid` for user-course scope
- `session_key` as lesson runtime identifier
- `job_key` as doc job identifier
- `review_task_id` as review runtime identifier
- `questionid` / `questionbankentryid` / `source_id` for question-map bridge resolution
- video runtime is session-scoped and aggregated into `local_mathstate_lesson_session.progress_json`

All write actions are still capability-checked in Moodle (`local/mathstate:*`, `moodle/course:view`, etc.).

## 2) Compatibility model

To support existing clients, the service currently accepts alias fields and normalizes internally.

### `local_mathstate_lesson_finish`

- Canonical input:
  - `courseid`, `userid`, `session_key`, `status`, `ended_at`
- Compatibility aliases accepted:
  - `session_id` (alias of `session_key`)
  - `finished_at` (alias of `ended_at`)
  - `summary_text`, `outcome`, `duration_sec`, `payload_json` (merged into summary payload)
- Stable return keys:
  - `session_key`, `session_id`, `status`, `ended_at`, `record_id`, `action`

### `local_mathstate_lesson_log_append`

- Canonical input:
  - `courseid`, `userid`, `session_key`, `event_type`, `result`, `score`, `maxscore`
- Common optional enrichments:
  - `lesson_key`, `questionid`, `questionusageid`, `cmid`, `qg_id`, `kg_ids`, `payload_json`, `occurred_at`
- CLI behavior:
  - when `session_key` is present, CLI always carries `courseid` + `userid`
  - sparse payload removes empty defaults before WS call
- Supported video runtime event types:
  - `video_heartbeat`
  - `video_paused`
  - `video_seek`
  - `video_completed`
- Video payload lives in `payload_json` and should carry:
  - `resource_course_id`
  - `resource_cmid`
  - `current_time_sec`
  - `duration_sec`
  - `watch_seconds_delta`
  - `coverage_ratio`
  - `completed`
- These events only update runtime session progress. They do **not** update `student_kp` / `student_qtype`
  unless a practice-style `result` is also explicitly present.

### `local_mathstate_review_complete`

- Canonical input:
  - `courseid`, `userid`, `review_id` (or `review_task_id`)
- Compatibility resolution path:
  - accepts `review_task_id`, `session_id`/`session_key`, `lesson_key`, `target_type`, `target_ref`, `completion_note`
  - if review row is not found, service can create a minimal placeholder review and then mark completion
- Stable return keys:
  - `review_task_id`, `review_id`, `status`, `completed_at`, `record_id`, `action`

### `local_mathstate_doc_publish_request`

- Canonical input:
  - `courseid`, `userid`, `job_key`, `job_type`, `target_type`, `target_ref`, `doc_ref`
- Compatibility alias accepted:
  - `doc_type` (mapped to `job_type` when `job_type` is empty)
- Stable return keys:
  - `job_key`, `doc_job_id`, `status`, `queued_at`, `record_id`, `action`

### `local_mathstate_question_map_sync_batch`

- Supports:
  - `items` batch payload
  - `dry_run` boolean (resolve only, no writes)
- Return shape includes:
  - `dry_run`, `synced`, `unresolved`, `ambiguous`

### `local_mathstate_question_map_lookup`

- Bridge lookup filters:
  - `questionid`, `questionbankentryid`, `source_id`, `qg_id`, `lesson_key`, `limit`
- Includes top-level bridge hints in each item:
  - `chapter`, `section`, `source_question_name`

### `local_mathstate_video_progress_summary`

- Read-only runtime summary for video playback progress.
- Canonical filters:
  - `courseid`, `userid`
  - optional `session_key`, `lesson_key`, `cmid`
  - optional `resource_course_id`, `resource_cmid`
- Stable summary fields:
  - `watched_seconds`
  - `coverage_ratio`
  - `last_position_sec`
  - `completed`
  - plus session/resource ids for workbench joins

### `local_mathstate_student_summary`

- Still returns mastery + due review state.
- Now also returns `video_progress` summary items when `include_video_progress=true` (default).
- This is additive only:
  - no Moodle core completion changes
  - no direct mapping from `video_completed` to mastery increase

## 3) Service wiring contract

`local_mathstate_*` functions must be linked into the token-facing service (usually `local_aiagentapi`).

Register/refresh:

```bash
php scripts/register_aiagentapi_service_functions.php \
  --service-shortname=local_aiagentapi \
  --function-prefix=local_mathstate_
```

If functions exist in `mdl_external_functions` but are not callable, verify `mdl_external_services_functions` mapping first.

## 4) Verification commands

WS smoke:

```bash
python3 scripts/mathstate_ws_smoke_test.py \
  --env-file .env.local \
  --token "$MOODLE_MATHSTATE_WS_TOKEN"
```

Cleanup smoke residue:

```bash
php scripts/cleanup_mathstate_smoke.php \
  --source-prefix=w2m-math-smoke- \
  --session-prefix=sess-smoke- \
  --job-prefix=docjob-smoke- \
  --target-prefix=KG-SMOKE- \
  --force
```

## 5) Non-goals (current phase)

- no BKT model
- no advanced spaced-repetition scheduler
- no workbench-layer schema coupling

Current runtime is intentionally minimal and designed for stable CLI + WS integration.

Additional non-goals for this phase:

- no Moodle core completion override
- no direct mastery uplift from video completion
