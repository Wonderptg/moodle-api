# Plugins (local + official references)

This repo contains:

- a custom local plugin for the AI agent API (`local_aiagentapi`)
- reference checkouts of some official/public plugins (kept under `references/` for reading and comparison)

## Local plugin in this repo

### `local_aiagentapi` (custom)

- Code: `/Users/wonder/Documents/moodle/public/local/aiagentapi`
- Purpose: provide a small, agent-friendly API layer on top of Moodle Web Services (read + write with guardrails).
- WS functions:
  - `local_aiagentapi_get_user_context`
  - `local_aiagentapi_calendar_publish_plan`
- Smoke test:
  - `/Users/wonder/Documents/moodle/scripts/aiagentapi_smoke_test.py`
  - Run: `python3 scripts/aiagentapi_smoke_test.py --env-file .env.local --start-server`

## Official/public plugins (reference checkouts)

These are downloaded as **reference code** so we can study how others implement auth/permissions and webservice integration.
They are not automatically installed into the running Moodle instance unless you copy them into the correct plugin directory.

### Microsoft 365 Copilot integration: `local_copilot` (reference)

- Reference code: `/Users/wonder/Documents/moodle/references/moodle-plugins/moodle-local_copilot`
- Plugin component: `local_copilot` (see `version.php`)
- What it does: integrates Moodle with Microsoft 365 Copilot/Copilot Chat via declarative agents (teacher/student).
- Declared dependencies (from `version.php`):
  - `local_oauth2`
  - `webservice_restful`

Install location if you want to enable it in Moodle:

- `/Users/wonder/Documents/moodle/public/local/copilot`

After copying files:

1. Log in as admin and open Site administration -> Notifications, or run `php -d max_input_vars=5000 admin/cli/upgrade.php --non-interactive`.
2. Follow the plugin’s README for OAuth and agent configuration.

### REST facade for Moodle WS: `webservice_restful` (reference)

- Reference code: `/Users/wonder/Documents/moodle/references/moodle-plugins/moodle-webservice_restful`
- Plugin component: `webservice_restful` (see `version.php`)
- What it does:
  - exposes each WS function as a discrete URL (more REST-like)
  - supports JSON request bodies
  - returns non-200 HTTP status codes for malformed/unauthorized calls
- Supported Moodle branches (as declared in its `version.php`): 4.2 to 4.5
  - Note: this repo is Moodle 5.1.2. If we install it here, expect possible compatibility issues until verified.

Install location if you want to enable it in Moodle:

- `/Users/wonder/Documents/moodle/public/webservice/restful`

After copying files:

1. Run upgrade (Notifications or `admin/cli/upgrade.php`).
2. Enable the protocol in Site administration -> Plugins -> Web services -> Manage protocols.

## Private/reference repos (non-market)

These are private repos used as implementation references (not installed by default).

### `moodle-offlinequiz` (Wonderptg)

- Reference code: `/Users/wonder/Documents/moodle/references/moodle-offlinequiz`
- What it provides: random question selection and HTML export workflow (useful for exam/worksheet generation).
- Use in this project: study implementation patterns; adapt into `local_aiagentapi` endpoints if needed.

## AI API implementation method (policy)

All new AI-facing functionality must follow this workflow to keep security, correctness, and maintainability consistent:

1. Define the feature precisely: read/write scope, permissions, context boundaries, batch size, and side effects.
2. Check Moodle core WS first, then plugin WS for similar functionality.
3. Decide reuse vs. re-implement:
   - If a WS function is safe and suitable, **wrap it** in our `local_aiagentapi` layer.
   - If not safe/suitable, **study the plugin’s logic** and re-implement an AI-friendly API in `local_aiagentapi`.
4. All external access must go through `local_aiagentapi`:
   - centralized auth, idempotency, audit logs, and error handling
   - stable input/output schemas for agents

Plugins are **references**, not drop-in APIs. We use them to shorten design/implementation, not to bypass our API layer.

## Official/public plugins (reference ZIPs)

These are ZIP downloads extracted under:

- `/Users/wonder/Documents/moodle/references/moodle-plugins/_extracted`

They are **not installed** unless you copy them into the correct Moodle plugin directory.

| Component | Type | Install path (if enabled) | Summary / why it matters for API work |
| --- | --- | --- | --- |
| `block_aipromptgen` | block | `public/blocks/aipromptgen` | AI prompt builder for teachers; structured prompt fields + optional provider call. Good UI-driven prompt metadata. |
| `block_openai_chat` | block | `public/blocks/openai_chat` | AI chat block using Moodle AI subsystem; shows persona + source-of-truth config patterns. |
| `gradereport_quizanalytics` | grade report | `public/grade/report/quizanalytics` | Quiz analytics graphs based on attempts and question stats. |
| `local_ai_manager` | local | `public/local/ai_manager` | Multi-tenant AI backend; defines `aitool_*` and `aipurpose_*` subplugins for tool selection and purpose routing. |
| `local_assign_ai` | local | `public/local/assign_ai` | AI-assisted assignment grading/feedback; depends on DataCurso AI Provider. |
| `local_coursegen` | local | `public/local/coursegen` | AI course/activity generator (syllabus or instructional design). DataCurso AI Provider required. |
| `mod_adaptivequiz` | mod | `public/mod/adaptivequiz` | Adaptive testing activity using difficulty-tagged question bank items. |
| `mod_offlinequiz` | mod | `public/mod/offlinequiz` | Paper-based quizzes with scanned answer sheets and automatic evaluation. |
| `mod_publication` | mod | `public/mod/publication` | Student Folder activity for collecting and publishing submissions. |
| `quiz_archiver` | quiz subplugin | `public/mod/quiz/report/archiver` | Archive quiz attempts to PDF/HTML; uses an external worker and per-job webservice tokens. |
| `quizaccess_ai` | quiz accessrule | `public/mod/quiz/accessrule/ai` | Blocks AI-dependent quizzes when AI tools/purposes are unavailable. |
| `qtype_coderunner` | question type | `public/question/type/coderunner` | Run program code to grade answers in programming questions. |
| `tool_mucertify` | admin tool | `public/admin/tool/mucertify` | Certifications and compliance tracking; depends on MuTMS suite. |
| `tool_mulib` | admin tool | `public/admin/tool/mulib` | Shared MuTMS library utilities (AJAX forms, JSON validation, external DB API). |
| `tool_muprog` | admin tool | `public/admin/tool/muprog` | Programs/learning pathways with allocation and scheduling; depends on MuTMS suite. |
| `tool_mutenancy` | admin tool | `public/admin/tool/mutenancy` | Multi-tenancy for Moodle; requires a core patch (per README). |

Notes:

- `local_ai_manager` includes multiple AI tool subplugins (`aitool_*`) and AI purpose subplugins (`aipurpose_*`) inside the ZIP.
- There are duplicate ZIPs for `local_coursegen` (`local_coursegen_moodle51_2026012300.zip` and `local_coursegen_moodle51_2026012300 (1).zip`); contents appear identical.
- We are not limited to the current ZIP set; additional plugins can be downloaded from the Moodle plugins directory or GitHub and added under `references/` for analysis.

## WS coverage and AI API suitability (reference ZIPs)

Scan source of truth: each plugin’s `db/services.php` (and `externallib.php` where applicable).
These functions are **not available** unless the plugin is installed and the functions are added to an External Service in Moodle.

| Component | WS functions found | Suitable as direct AI API? | Notes |
| --- | --- | --- | --- |
| `block_aipromptgen` | none | No | UI-only block; no WS endpoints. |
| `block_openai_chat` | none | No | UI-only block; no WS endpoints. |
| `gradereport_quizanalytics` | `moodle_quizanalytics_analytic` | Partial | Read-only analytics; `ajax=true`, `loginrequired=true`. Good for dashboards but not write actions. |
| `local_ai_manager` | `local_ai_manager_post_query`, `local_ai_manager_get_ai_config`, `local_ai_manager_get_ai_info`, `local_ai_manager_get_purpose_options`, `local_ai_manager_get_user_quota`, `local_ai_manager_vertex_cache_status`, `local_ai_manager_get_prompts`, `local_ai_manager_get_purposes_usage_info` | Partial | This is already an AI backend; still needs guardrails, auditing, and prompt/data controls before exposing to an agent. |
| `local_assign_ai` | `local_assign_ai_get_details`, `local_assign_ai_update_response`, `local_assign_ai_change_status`, `local_assign_ai_process_submission`, `local_assign_ai_approve_all_pending`, `local_assign_ai_get_progress`, `local_assign_ai_get_token` | Partial | Write-capable and powerful; requires careful capability mapping and workflow constraints. |
| `local_coursegen` | `local_coursegen_create_mod`, `local_coursegen_create_mod_stream`, `local_coursegen_create_course`, `local_coursegen_plan_course_message`, `local_coursegen_plan_course_execute` | Partial | Strong candidates for AI workflows, but expect strict permissions and validation. |
| `mod_adaptivequiz` | none | No | Activity module without WS endpoints. |
| `mod_offlinequiz` | `mod_offlinequiz_set_question_version`, `mod_offlinequiz_add_random_questions` | Partial | Narrow, write-oriented functions; requires course/quiz context validation. |
| `mod_publication` | `mod_publication_get_onlinetextpreview` | Partial | Read-only preview WS. |
| `quiz_archiver` | `quiz_archiver_generate_attempt_report`, `quiz_archiver_get_attempts_metadata`, `quiz_archiver_update_job_status`, `quiz_archiver_process_uploaded_artifact`, `quiz_archiver_get_backup_status` | Partial | Designed for an external worker service with scoped tokens; not a general-purpose API. |
| `quizaccess_ai` | none | No | Access rule only; no WS endpoints. |
| `qtype_coderunner` | `qtype_coderunner_run_in_sandbox` | Partial | Internal sandbox runner; intended for logged-in usage and specific capability. |
| `tool_mucertify` | `tool_mucertify_get_certifications`, `tool_mucertify_get_certification_assignments`, `tool_mucertify_get_certification_periods` (+ form autocomplete WS) | Partial | Mostly read-only; useful for reporting but needs role/tenant scoping. |
| `tool_mulib` | `tool_mulib_form_autocomplete_extdb_query_contextid` | No | Only form autocomplete helper. |
| `tool_muprog` | `tool_muprog_get_programs`, `tool_muprog_get_program_allocations`, `tool_muprog_source_manual_allocate_users`, `tool_muprog_delete_program_allocations`, `tool_muprog_update_program_allocation`, `tool_muprog_archive_program_allocation`, `tool_muprog_restore_program_allocation`, `tool_muprog_source_cohort_get_cohorts`, `tool_muprog_source_cohort_add_cohort`, `tool_muprog_source_cohort_delete_cohort` (+ many form autocomplete WS) | Partial | Good coverage for programs/allocations, but needs strict capability checks and audit trail. |
| `tool_mutenancy` | `tool_mutenancy_get_tenants`, `tool_mutenancy_create_tenant`, `tool_mutenancy_update_tenant`, `tool_mutenancy_get_managers`, `tool_mutenancy_add_manager`, `tool_mutenancy_remove_manager`, `tool_mutenancy_allocate_user` (+ form autocomplete WS) | No | Admin-level tenant management; too risky to expose directly. |

## Notes

- Keep tokens and passwords in `.env.local` and `docs/*.local.md` (ignored by git).
- If you downloaded additional official plugins (ZIPs or repos), place them under the correct Moodle plugin type directory
  and add a short entry to this doc (component name + install path + why we use it).
