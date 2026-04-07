---
name: moodle-aiagent-cli
description: Use when working with the local Moodle AI agent stack in this repository: reading or writing Moodle data through the AI-friendly API, using the project CLI, seeding demo data, running live regression, or extending `local_aiagentapi`. Trigger for tasks involving courses, calendar plans, assignments, forums, quizzes, question banks, or Moodle automation in this repo.
---

# Moodle AI Agent CLI

<!-- Generated from agent-skills/moodle-aiagent-stack/SKILL.md by scripts/sync_agent_skills.py -->

This is a Claude Code plugin adapter for the canonical generic skill. Canonical source: `agent-skills/moodle-aiagent-stack/SKILL.md`.

This is the platform-neutral skill source for the Moodle AI agent stack in this repository.

Use it when an agent should operate through the repo's stable CLI and AI-friendly API instead of manual Moodle UI actions.

## Canonical workflow

1. Discover the current user and API surface first:
   - `python3 scripts/moodle_cli.py --env-file .env.local --json context get`
   - `python3 scripts/moodle_cli.py --env-file .env.local --json catalog get`
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
