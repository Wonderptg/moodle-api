# What Makes a Good CLI

This document defines our working standard for a good CLI, especially for AI-agent usage. It is based on the reference projects under `/Users/wonder/Documents/moodle/references/cli-research` and adapted to our Moodle architecture.

Primary ergonomics reference:

- `/Users/wonder/Documents/moodle/references/cli-research/gogcli`

Primary methodology reference:

- `/Users/wonder/Documents/moodle/references/cli-research/CLI-Anything`

## Purpose

A good CLI is not just "something that can run commands". It should:

- be easy for humans to discover
- be safe for automation to call
- produce predictable output
- map to real tasks instead of leaking backend internals
- stay maintainable as capabilities grow

For our project, a good CLI is a thin harness over `/Users/wonder/Documents/moodle/public/local/aiagentapi`, not a second backend.

## The standard

We will judge a CLI across 8 dimensions.

### 1. Task fit

A good CLI is task-shaped.

Good:
- `calendar list --from ... --to ...`
- `calendar publish-plan --input plan.json`
- `questions pick-random --category 12 --count 10`

Bad:
- exposing raw database tables
- mirroring every low-level backend function one-to-one
- command names that only make sense to plugin authors

Rule:
- Commands should represent real user or agent goals, not internal implementation details.

### 2. Discoverability

A good CLI is self-describing.

It should have:
- clear top-level groups
- useful `--help`
- examples in help text for risky or non-obvious commands
- stable naming

Good patterns:
- small number of top-level groups
- noun-based grouping with consistent verbs
- aliases only when they reduce friction, not when they create ambiguity

Rule:
- If a new user cannot find the right command by reading `--help`, the CLI is not good enough.

### 3. Machine-readability

A good agent-facing CLI must be machine-safe by default.

It should have:
- strict JSON output mode
- no decorative output in JSON mode
- stable exit codes
- stable error envelopes
- a schema or command catalog that can be generated from the real registry

Good patterns from references:
- `--json`
- `--plain`
- `--results-only`
- `--select`
- command schema export
- command allowlisting for sandboxed or agent-only runs

Rule:
- JSON mode must be parseable even in CI, piping, and headless execution.

### 4. Safety for writes

A good CLI treats write operations as high-risk by default.

Write commands should support:
- `dry_run`
- `force`
- `no_input`
- idempotency where relevant
- audit metadata where relevant

Good examples:
- create/update/delete actions that can preview intended changes
- commands that never silently prompt during automation
- root-level intent flags instead of implicit behavior

Rule:
- If an automated caller can trigger an irreversible write without explicit intent signals, the CLI is unsafe.

### 5. Context and auth clarity

A good CLI makes identity and execution context explicit.

It should support:
- clear account selection
- clear environment/config override behavior
- explicit token or profile selection
- no hidden context switching

Good patterns:
- root-level account flags
- env var overrides
- isolated config per account or client

Rule:
- The caller should always know which account, tenant, or user context a command will run under.

### 6. Composability

A good CLI works well with scripts, files, and other tools.

It should:
- read inputs from flags or files
- write outputs to stdout cleanly
- keep stderr for logs and errors
- support being composed with `jq`, `xargs`, `rg`, `fzf`, and scripts

Good patterns:
- `--input file.json`
- `--output path`
- JSON to stdout, diagnostics to stderr

Rule:
- If a command is annoying to script, it is not automation-grade.

### 7. Maintainability

A good CLI is easy to extend without turning into a mess.

It should have:
- centralized command registration
- separate runtime wiring from command definition
- shared output formatting rules
- shared error handling
- tests for both unit behavior and end-to-end flows

Good patterns:
- registry-generated schema
- one execution context built at the root
- thin command layer over stable service functions
- centralized root flags and output behavior, as seen in `gogcli`

Rule:
- Adding a new command should not require inventing a new architecture every time.

### 8. Progressive growth

A good CLI can start small and grow carefully.

It should:
- begin with a narrow high-value surface
- allow future extension
- avoid giant top-level sprawl
- support domain splitting if the product grows

Rule:
- Version 1 should not try to cover every backend capability.

## Good CLI checklist

Before we accept a new command or command group, it should pass this checklist:

- Does it map to a real task?
- Is the name understandable without reading source code?
- Does `--help` explain what it does?
- Does JSON mode stay clean and parseable?
- Are writes protected by `dry_run` or equivalent safeguards?
- Is auth/context explicit?
- Can it be scripted cleanly?
- Does it reuse shared output/error conventions?
- Is it covered by automated tests?

If the answer to two or more of these is "no", the command probably should not ship yet.

## Anti-patterns

These patterns are strong signals of a bad CLI:

- human-only pretty output with no machine mode
- debug logs mixed into stdout
- silent prompts in non-interactive environments
- one command per backend function with no task shaping
- hidden writes during what looks like a read command
- auth behavior that depends on undocumented local state
- huge command trees with no prioritization
- output formats that change casually between versions

## Human CLI vs agent CLI

Not every good human CLI is a good agent CLI.

Human-first CLI traits:
- rich colors
- interactive prompts
- forgiving flows
- visually dense summaries

Agent-first CLI traits:
- deterministic behavior
- explicit flags
- strict JSON
- no surprise prompts
- stable error/output contracts

Our direction:
- human mode is useful for debugging
- agent mode is the primary design target

## What this means for Moodle

For our Moodle work, a good CLI should:

- be a thin wrapper over `local_aiagentapi`
- expose only high-value task commands
- keep Moodle permissions enforced on the server side
- never let the CLI become a parallel business-logic layer

Recommended first command groups:

- `context`
- `calendar`
- `courses`
- `quiz`
- `questions`
- `agent`

Recommended root flags:

- `--token`
- `--base-url`
- `--json`
- `--plain`
- `--results-only`
- `--select`
- `--dry-run`
- `--force`
- `--no-input`

Recommended write contract:

- every write command should pass `idempotency_key`
- every write command should support preview where possible
- every write command should surface audit information

## Design rule for us

When we build a new CLI capability, the order should be:

1. define the user or agent task
2. confirm the server-side API contract in `local_aiagentapi`
3. add the thin CLI command
4. generate or update the command catalog/schema
5. add automated tests

If we reverse that order and start from CLI syntax first, the design usually drifts.

## Scoring rubric

We can score a CLI command from 0 to 2 on each dimension:

- task fit
- discoverability
- machine-readability
- write safety
- auth/context clarity
- composability
- maintainability
- growth readiness

Interpretation:

- `14-16`: strong
- `10-13`: acceptable but needs refinement
- `0-9`: not ready

## Bottom line

A good CLI is:

- task-shaped
- self-describing
- machine-safe
- explicit about context
- safe for writes
- easy to script
- easy to extend

For our project specifically:

- `gogcli` is the main ergonomics reference
- `CLI-Anything` is the main methodology reference
- `gtypee` is a useful secondary reference
- the real source of truth must remain `/Users/wonder/Documents/moodle/public/local/aiagentapi`
