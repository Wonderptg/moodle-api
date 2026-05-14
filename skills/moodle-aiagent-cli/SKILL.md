---
name: moodle-aiagent-cli
description: Use this skill for the Moodle AI agent stack in this repository. It covers Moodle reads and writes through the remote WebService CLI, seeded regression, quiz attempts, question-bank actions, calendar plans, assignments, forums, and extending `local_aiagentapi`.
metadata:
  {
    "openclaw":
      {
        "requires": { "bins": ["python3"] }
      }
  }
---

# Moodle AI Agent CLI

<!-- Generated from agent-skills/moodle-aiagent-stack/SKILL.md by scripts/sync_agent_skills.py -->

This is an OpenClaw-oriented adapter for the canonical generic skill. Canonical source: `agent-skills/moodle-aiagent-stack/SKILL.md`.

This is the platform-neutral skill source for the Moodle AI agent stack in this repository.

Use it when an agent should operate through the repo's stable CLI and AI-friendly API instead of manual Moodle UI actions.

## Execution model

The primary CLI is `scripts/moodle_cli.py`. It is a remote WebService client:

```text
python3 scripts/moodle_cli.py ...
  -> <base-url>/webservice/rest/server.php
  -> local_aiagentapi
  -> Moodle server-side plugin code
```

Use this path for normal agent work, including reading courses, listing quizzes,
starting attempts, and creating online practice quizzes. It only needs Python,
a Moodle base URL, and a WebService token/profile. It does not need local PHP,
`public/config.php`, or the Moodle server code directory.

The PHP files under `scripts/*.php` are Moodle internal maintenance scripts.
Use them only for plugin upgrades, service registration, data imports, seeding,
or direct Moodle maintenance. Those scripts must run in a Moodle code tree with
PHP and `config.php`.

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
python3 scripts/moodle_cli.py setup --name prod --base-url https://dzexam.cn
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
python3 scripts/moodle_cli.py config init --name prod --base-url https://dzexam.cn --activate
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

## Online quiz creation

Use the remote CLI, not a PHP maintenance script:

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --force \
  quiz create-practice \
  --idempotency-key <stable-key> \
  --course-id <courseid> \
  --category-id <question-category-id> \
  --count 5 \
  --random \
  --title "课后练习"
```

If this fails, check the WebService token user's Moodle permissions first. Do
not switch to a `php scripts/*.php` path unless the task is explicit server
maintenance.

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
