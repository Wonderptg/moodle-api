# gogcli Method

This document explains the design method behind `gogcli`, based on the source code in `/Users/wonder/Documents/moodle/references/cli-research/gogcli`.

It is not a feature summary. The goal is to extract the design doctrine that makes `gogcli` feel technically strong and agent-friendly.

## Short version

`gogcli` treats the CLI as a real protocol surface, not a thin shell around miscellaneous scripts.

Its core method is:

1. define a strong root contract first
2. make machine mode first-class
3. separate output, auth, and UI concerns cleanly
4. make writes safe and explicit
5. expose discovery primitives for agents
6. keep command ergonomics aligned with likely user and agent intent

That is why it feels more disciplined than a typical utility CLI.

## 1. Root contract first

The most important design choice is that the root flags are treated as the product contract, not as incidental options.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/root.go`

At the root, `gogcli` standardizes:

- account selection
- client selection
- direct access token override
- command allowlisting
- output mode
- projection and result shaping
- dry-run
- confirmation bypass
- non-interactive mode
- verbosity

This is the key idea:

- every command inherits the same execution semantics
- commands do not reinvent output, auth, or automation behavior

This is strong taste because many CLIs do the opposite:
- they grow command by command
- each command adds its own output quirks
- automation behavior becomes inconsistent

## 2. Machine mode is not an afterthought

`gogcli` clearly assumes that another program may be calling it.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/root.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/outfmt/outfmt.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/agent_exit_codes.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/schema.go`

Its method here is:

- machine output is a first-class mode, not a hidden flag
- JSON and plain parseable text are both explicit modes
- JSON can be post-shaped with `--results-only` and `--select`
- stable exit codes are documented and queryable
- command schema is exported from the real parser tree

That means the CLI is not just "usable by scripts". It is intentionally inspectable by agents.

This is a very important distinction.

## 3. The CLI adapts to agent behavior

`gogcli` is not only machine-readable. It is agent-aware.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/root.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/name_resolution.go`

Examples:

- action-first shortcuts exist at the root, such as `send`, `ls`, `search`, `open`, `login`, `whoami`
- it rewrites guessed `--fields` into `--select` in most cases
- it can auto-enable JSON when stdout is not a TTY and `GOG_AUTO_JSON` is set

This is a subtle but important method:

- do not just document the “correct” command shape
- anticipate how agents and humans will actually guess commands
- absorb common guess patterns at the boundary

That is good product thinking, not just good coding.

## 4. Output, UI, and errors are separate systems

Another strong design choice is that `gogcli` does not let formatting concerns leak everywhere.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/outfmt/outfmt.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/ui/ui.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/errfmt/errfmt.go`

The method is:

- `outfmt` decides machine-vs-plain-vs-human output behavior
- `ui` owns color and terminal presentation
- `errfmt` turns internal errors into user-facing messages

That separation gives three benefits:

1. JSON mode stays clean
2. human output can improve without breaking automation
3. commands stay thinner because they are not each inventing their own print logic

This is one of the clearest signs of engineering maturity in the codebase.

## 5. Writes are explicit, previewable, and automation-safe

`gogcli` treats mutations as operations that need a safety model.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/dryrun.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/confirm.go`

Method:

- mutation commands call `dryRunExit()` early
- dry-run returns success and prints the intended operation plus request payload
- destructive confirmation is centralized
- non-interactive mode refuses destructive operations unless intent is explicit

This is very good CLI design for agents because it avoids two common failures:

1. hidden side effects during testing
2. automation hanging on prompts

The deeper principle is:

- write safety should be part of the platform contract, not a courtesy added by some commands

## 6. Auth is treated as a capability model, not only a login flow

This is one of the strongest parts of `gogcli`.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/auth_add.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/googleauth/service.go`

Method:

- auth is service-scoped
- scopes are computed from requested capabilities
- least-privilege variants are first-class: `--readonly`, `--drive-scope`, `--gmail-scope`
- remote/manual/headless flows are designed deliberately
- account/client separation is explicit

This means `gogcli` thinks in terms of:

- what operations are being authorized
- what minimum permissions are needed
- what execution environment the caller is in

That is much better than the common pattern of:

- one giant login command
- one giant token
- unclear scope boundaries

## 7. Restriction is a feature, not a limitation

`gogcli` includes command allowlisting.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/enabled_commands.go`

Method:

- callers can restrict the available top-level commands with `--enable-commands`
- the restriction is enforced centrally after parsing

This is especially important for agent use.

It means the author is thinking in terms of:

- controlled execution surfaces
- sandboxed operation
- reducing accidental or unsafe capability exposure

That is exactly the right mindset for skill-based and agent-based systems.

## 8. Stable failure semantics matter

`gogcli` has a stable exit code taxonomy.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/exit_codes.go`
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/agent_exit_codes.go`

Method:

- common failure classes are mapped to stable codes
- retryable, permission, auth, config, not-found, and cancellation are separated
- the CLI exposes those codes as a command so automation can inspect them

This is another sign that the CLI is being treated like an API.

The principle is:

- callers should branch on semantics, not scrape stderr text

## 9. Discovery is generated from the real command graph

The `schema` command is important.

See:
- `/Users/wonder/Documents/moodle/references/cli-research/gogcli/internal/cmd/schema.go`

Method:

- the CLI parser tree is used to build a machine-readable schema
- schema includes flags, aliases, types, requirements, and subcommands
- the schema is not separately hand-maintained

This avoids documentation drift and makes the CLI legible to agents.

This is likely one of the most reusable ideas for our project.

## 10. The real methodology

Putting it together, `gogcli` seems to follow this implicit method:

1. Define a global execution contract at the root.
2. Make machine mode equal in status to human mode.
3. Centralize output, errors, and UI instead of mixing them.
4. Treat auth as scoped capability selection.
5. Make writes previewable and safe in non-interactive use.
6. Provide discovery mechanisms for agents.
7. Adapt to common caller intent instead of insisting on perfect syntax.
8. Keep failure modes stable and classifiable.

That is the methodology.

## What is especially worth copying

For our Moodle work, the most reusable parts are:

1. root flags as a contract
2. strict JSON mode
3. `--results-only` and `--select`
4. stable exit codes
5. command schema export
6. centralized dry-run behavior
7. command allowlisting
8. explicit non-interactive safety

## What we should not copy blindly

We should not copy:

- the huge breadth of services
- every action-first shortcut
- product-specific auth details

For us, the right adaptation is:

- keep `local_aiagentapi` as the true backend contract
- build a thinner CLI on top
- borrow `gogcli`'s root execution model, not its exact command tree

## Bottom line

The reason `gogcli` feels good is not mainly that it has many commands.

It feels good because it is built as:

- a consistent execution protocol
- a safe automation surface
- a discoverable interface for agents

That is the real lesson.
