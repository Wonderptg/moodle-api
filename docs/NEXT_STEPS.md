# Next Steps

This document tracks the immediate next development work for the Moodle AI Agent Stack.

## Priority A: Documentation navigation

Goal:

- make `docs/` easier to scan for anyone joining the project
- reduce time spent hunting for the right markdown file
- make README the project entrypoint and `docs/` the structured reference layer

Scope:

1. add a `docs/INDEX.md` or equivalent navigation page
2. group documents by purpose:
   - local setup
   - CLI and API usage
   - upgrade rehearsal
   - phone auth migration
   - plugin and reference research
   - frontend / OpenClaw planning
3. mark which documents are:
   - canonical references
   - historical notes
   - local-only secrets or non-git notes
4. add short “when to read this” blurbs to each doc entry

Recommended outcome:

- a new contributor should be able to open one page and understand where to look next

## Priority B: First frontend prototype

Goal:

Build the first user-facing AI learning frontend where users interact mainly with an AI tutor instead of the Moodle UI.

Target flow:

1. use real upgraded rehearsal environment: `http://127.0.0.1:8003`
2. use real course: `course 92 / 2026四类综合`
3. show the current study plan
4. allow AI-guided “start today’s learning” flow
5. launch one real quiz flow using `quiz 431 / 政治思想和职业道德`

Recommended v1 page contents:

1. course summary card
2. 7-day study plan card
3. “Start today” action
4. quiz workspace for one real quiz
5. AI chat / tutor panel

Backend architecture for this work:

- frontend app
- agent gateway
- OpenClaw runtime
- `scripts/moodle_cli.py`
- `public/local/aiagentapi`
- Moodle as the system of record

Important rule:

- do not let the browser call Moodle directly
- do not expose Moodle tokens to the browser
- keep all Moodle interaction behind the agent gateway

Reference:

- `/Users/wonder/Documents/moodle/docs/OPENCLAW_FRONTEND_ARCHITECTURE.md`
- `/Users/wonder/Documents/moodle/docs/TIMETABLE_DAILY_SESSION_ARCHITECTURE.md`

## Recommended implementation order

1. finish a docs navigation page
2. scaffold the frontend shell
3. connect one real course dashboard
4. connect one real study-plan sync action
5. connect one real quiz lifecycle
6. expand to broader tutoring and progress views

## Current recommendation

If we want the fastest visible product progress, start with Priority B after adding a small docs navigation page.

The best first deliverable is:

- one AI tutor dashboard for `course 92`
- one synced study plan
- one working quiz interaction path on `quiz 431`
