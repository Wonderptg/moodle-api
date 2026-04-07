---
name: moodle-aiagent-cli
description: Use this skill for the Moodle AI agent stack in this repository. It covers local Moodle reads and writes through the project CLI, seeded regression, quiz attempts, question-bank actions, calendar plans, assignments, forums, and extending `local_aiagentapi`.
metadata:
  {
    "openclaw":
      {
        "requires": { "bins": ["python3", "php"] }
      }
  }
---

# Moodle AI Agent CLI

<!-- Generated from agent-skills/moodle-aiagent-stack/SKILL.md by scripts/sync_agent_skills.py -->

This is an OpenClaw-oriented adapter for the canonical generic skill. Canonical source: `agent-skills/moodle-aiagent-stack/SKILL.md`.

This is the platform-neutral skill source for the Moodle AI agent stack in this repository.

Use it when an agent should operate through the repo's stable CLI and AI-friendly API instead of manual Moodle UI actions.

## Environment choice

For this repository, prefer the upgraded real-content rehearsal environment first:

- real-content testing: `python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json ...`
- seeded demo / local regression: `python3 scripts/moodle_cli.py --env-file .env.local --json ...`

Use `.env.upgrade51.local` when the goal is to inspect real courses, real outlines, real quizzes, real calendar items, or student-facing upgraded behavior on `http://127.0.0.1:8003`.

Use `.env.local` only when the goal is seeded deterministic regression on the local demo site at `http://127.0.0.1:8000`.

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
- OpenClaw-facing adapters live under `skills/`

If the capability list or workflow changes, update this skill first, then sync the adapters.

## Read next

- `references/capabilities.md`
