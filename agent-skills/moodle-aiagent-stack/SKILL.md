---
name: moodle-aiagent-stack
description: Generic agent skill for the local Moodle AI agent stack in this repository. Use when an agent needs to read or write Moodle data through the AI-friendly API and CLI, run seeded local regression, inspect available capabilities, or extend `local_aiagentapi`. Covers courses, activities, calendar plans, assignments, forums, quizzes, and question-bank workflows.
---

# Moodle AI Agent Stack

This is the platform-neutral skill source for the Moodle AI agent stack in this repository.

Use it when an agent should operate through the repo's stable CLI and AI-friendly API instead of manual Moodle UI actions.

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
python3 scripts/moodle_cli.py setup --name prod --base-url http://dzexam.cn
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
python3 scripts/moodle_cli.py config init --name prod --base-url http://dzexam.cn --activate
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
