# CLI Reference Research

This document tracks external CLI projects we downloaded under `/Users/wonder/Documents/moodle/references/cli-research` and the parts of their design that are useful for our AI-friendly Moodle API and future skills.

## Downloaded repositories

- `gogcli`: `/Users/wonder/Documents/moodle/references/cli-research/gogcli`
- `gcalcli`: `/Users/wonder/Documents/moodle/references/cli-research/gcalcli`
- `gtypee`: `/Users/wonder/Documents/moodle/references/cli-research/gtypee`
- `nb`: `/Users/wonder/Documents/moodle/references/cli-research/nb`
- `notes-cli`: `/Users/wonder/Documents/moodle/references/cli-research/notes-cli`
- `CLI-Anything`: `/Users/wonder/Documents/moodle/references/cli-research/CLI-Anything`

## What matters for us

We are not copying these tools. We are studying how mature CLIs solve:

- command discovery
- machine-readable output
- auth and multi-account setup
- safe write operations
- extension/plugin mechanisms
- whether the CLI is human-first or agent-first

## Per-project summary

### `gogcli`

Why it matters:
- This is the closest reference to an agent-ready CLI.
- It already assumes scripting, automation, multi-account auth, and restricted command surfaces.

Observed patterns:
- Go binary with a centralized root command in `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/root.go`
- Strong global flags: `--json`, `--plain`, `--results-only`, `--select`, `--dry-run`, `--force`, `--no-input`
- Has an explicit command allowlist: `--enable-commands`
- Includes machine-readable helpers: `schema`, `agent`, `exit-codes`
- Supports auto-JSON mode when stdout is not a TTY
- Error formatting is deliberate and stable

What we should learn:
- Every write command should support `dry_run`
- Agent mode needs stable exit codes and predictable stdout/stderr behavior
- A command allowlist is a very good idea for sandboxed skill execution
- Schema export should come from the real command registry, not hand-written docs

What not to copy blindly:
- This is still a large monolithic CLI. For Moodle we should keep the external surface smaller and let `local_aiagentapi` stay the source of truth.

### `gcalcli`

Why it matters:
- Mature Google Calendar CLI focused on one domain
- Good reference for calendar-specific UX and config layering

Observed patterns:
- Python CLI with `argparse` subcommands
- Config is layered from CLI args, rc files, and `config.toml`
- Has `--json`, but much of the UX is still human-oriented
- Supports flag files with `@file`
- Good reminder/import/calendar-specific workflows

What we should learn:
- Domain CLIs benefit from focused nouns and verbs instead of a giant generic surface
- Config file + env override is useful for local testing
- Calendar-specific filters and time-window defaults deserve first-class options

What not to copy blindly:
- `gcalcli` mixes parseable and presentation-heavy modes. For agent use, we should keep human formatting and machine formatting more strictly separated.

### `gtypee`

Why it matters:
- Very close to `gogcli` in spirit, but simpler and written in TypeScript
- Good reference for a modern “Google admin + automation” CLI

Observed patterns:
- Commander-based command tree in `/Users/wonder/Documents/moodle/references/cli-research/gtypee/src/cmd/root.ts`
- Global options mirror what agents need: `--json`, `--plain`, `--results-only`, `--select`, `--dry-run`, `--force`, `--no-input`
- Separates command registration from runtime dependency construction
- Supports multiple auth modes: user OAuth and service account impersonation
- Warns that dev-mode output can break JSON parsing

What we should learn:
- Separate command definition from runtime wiring
- Keep JSON mode strict enough that no debug/dev noise leaks into stdout
- Build a small execution context once at the root and pass it down

What not to copy blindly:
- The surface area is still broad. For our Moodle work, the first version should stay narrower and map to actual AI tasks, not every possible LMS function.

### `nb`

Why it matters:
- Strong reference for extensibility, portability, and plugin-based growth
- Useful when we think about skills or local helper CLIs around our API

Observed patterns:
- Main product is one portable shell script: `/Users/wonder/Documents/moodle/references/cli-research/nb/nb`
- Heavy use of environment variables for configuration
- Built-in plugin/theme mechanism
- Excellent portability and progressive enhancement strategy
- Stores user data in plain files and uses Git for history/sync

What we should learn:
- Extension points matter more than perfect abstraction early on
- Plain, inspectable storage and config are good for debugging
- A local helper CLI around our API could stay thin and composable

What not to copy blindly:
- Shell-script monoliths are flexible but harder to type-check and maintain for complex API contracts
- `nb` is user-first, not machine-first

### `notes-cli`

Why it matters:
- Small, composable CLI with very clean scope
- Good example of “do a few things well, let Unix tools do the rest”

Observed patterns:
- Go CLI with explicit subcommand structs in `/Users/wonder/Documents/moodle/references/cli-research/notes-cli/cmd.go`
- Lets users extend behavior via external subcommands (`notes foo` -> `notes-foo`)
- Works well with `rg`, `grep`, `fzf`, editors, and Git
- Minimal internal abstraction, clear dispatch

What we should learn:
- Keep the command surface intentionally small
- Extension via external commands is a good pattern for experimental capabilities
- Composability beats feature bloat

What not to copy blindly:
- It is not built around JSON-first automation
- Error/output behavior is simpler than what agent integration usually needs

### `CLI-Anything`

Why it matters:
- This is the most directly relevant reference for our long-term direction.
- It is explicitly about making existing software "agent-native" through a generated CLI harness.

Observed patterns:
- Repository root: `/Users/wonder/Documents/moodle/references/cli-research/CLI-Anything`
- Core framing is methodology-first, not library-first
- Targets Claude Code, OpenCode, and Codex integrations out of the box
- Uses a repeatable 7-phase pipeline: analyze, design, implement, test-plan, test, document, publish
- Generated harnesses are Python + Click based
- Generated CLIs consistently support:
  - `--json`
  - REPL mode
  - session state
  - undo/redo
  - test suites
  - installable command packaging
- Example harnesses are software-specific and live under paths like:
  - `/Users/wonder/Documents/moodle/references/cli-research/CLI-Anything/anygen/agent-harness/cli_anything/anygen/README.md`
  - `/Users/wonder/Documents/moodle/references/cli-research/CLI-Anything/libreoffice/agent-harness/cli_anything/libreoffice/README.md`

What we should learn:
- Agent-facing tooling benefits from a formal generation methodology, not just ad hoc command additions
- JSON mode, REPL mode, and testability should be designed together
- A software-specific SOP or capability map is valuable before implementing commands
- The generated CLI should be a thin operational layer around a stable underlying model

What is especially relevant to Moodle:
- Their "harness" idea maps well to our planned structure:
  - `local_aiagentapi` as the stable capability layer
  - a future thin CLI/skill wrapper as the harness
  - task-shaped commands for agent use
- Their per-software subpackage pattern suggests we can later split Moodle domains cleanly:
  - calendar
  - courses
  - quiz
  - questions
  - assignments

What not to copy blindly:
- CLI-Anything is optimized for wrapping GUI applications. Moodle is already a server application with its own permission model and Web Services layer.
- For us, the correct source of truth is still Moodle-side APIs, not a generated local CLI alone.
- We should borrow the methodology, but not replace `local_aiagentapi` with a pure harness-first architecture.

## Cross-project conclusions

### Primary reference

If we keep one primary CLI ergonomics reference for now, it should be `gogcli`.

Why:

- it is the most agent-aware of the downloaded CLIs
- its root flags are already close to what we need
- it treats machine output, auth, and command restriction as first-class concerns
- it includes concepts that matter directly for skill execution:
  - JSON-first automation
  - command allowlisting
  - stable exit behavior
  - schema-oriented discovery

Working decision:

- use `gogcli` as the main CLI ergonomics reference
- use `CLI-Anything` as the main harness/methodology reference
- use the others as secondary, domain-specific references

### Best patterns to reuse

1. JSON-first output for automation
2. Separate human output from machine output
3. Global `dry_run`, `force`, `no_input`, and account/context flags
4. Command schema export from the real registry
5. Multi-account auth as a first-class design concern
6. Narrow, task-shaped commands instead of raw backend mirrors
7. Capability mapping and test planning before broad CLI expansion

### Patterns we should avoid

1. Mixing decorative terminal output into machine mode
2. Letting dev tooling print into stdout in JSON mode
3. Exposing every backend function directly as a public command
4. Building a giant CLI before the API contract is stable

## What this means for our Moodle architecture

Recommended layering:

1. `local_aiagentapi` stays the canonical API layer
2. Future CLI or skill wrappers should stay thin and call that API
3. The CLI should expose only task-shaped operations, for example:
   - get current learner context
   - list my courses
   - read upcoming calendar items
   - publish a study plan
   - pick random questions
   - render questions to HTML
4. Writes should require:
   - `dry_run`
   - `idempotency_key`
   - audit metadata
   - stable error envelopes

In other words:

- `CLI-Anything` gives us a good harness methodology
- `gogcli` is our main CLI ergonomics reference
- `gtypee` is a useful secondary ergonomics reference
- our Moodle implementation should combine both, but keep the Moodle plugin API as the real backend contract

## Proposed CLI design principles for us

If we later build a dedicated Moodle CLI or skill wrapper, it should have:

- strict JSON mode by default for agent use
- a human mode for manual debugging
- a generated command/schema catalog from `local_aiagentapi`
- root flags for auth token, dry run, select fields, and no-input
- very small top-level groups, likely:
  - `context`
  - `calendar`
  - `courses`
  - `quiz`
  - `questions`
  - `agent`

## Next research additions

Likely good next references to download later:

- more calendar-first CLIs
- note/knowledge-base CLIs with strong JSON support
- agent-oriented CLIs that expose OpenAPI or command schema directly
