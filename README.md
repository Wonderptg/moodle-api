# Moodle AI Agent Stack

This repository is no longer just a vanilla Moodle checkout.

It is the working project for turning Moodle into an AI-agent-friendly learning platform with:

- `local_aiagentapi` as the canonical AI-friendly API layer
- `scripts/moodle_cli.py` as the thin agent-facing CLI
- OpenClaw / generic agent skills as the orchestration surface
- multiple local environments for development, clone testing, and upgrade rehearsal
- phone-login pluginization work to make legacy production changes upgrade-safe

## What is already working

The current project can already do all of the following through the AI-friendly API and CLI:

- read user context and API catalog
- read courses, outlines, activities, resources, due items, progress, grades, notifications
- generate and write study plans into Moodle calendar
- list quizzes and quiz attempts
- start quiz attempts, fetch attempt data, apply structured answers, submit attempts
- query question banks, search questions, pick random questions, render question HTML
- save assignment drafts, submit assignments, and interact with forums
- sync math knowledge points / question types / question mappings
- ingest math learning evidence and read student math mastery / due reviews
- run against seeded demo data and also against a real upgraded Moodle clone

We have also validated this on a real upgraded course copy:

- upgraded rehearsal site: `http://127.0.0.1:8003`
- real course tested: `course 92 / 2026四类综合`
- real study plan written to calendar
- real quiz lifecycle verified on `quiz 431 / 政治思想和职业道德`

## Repository role

Think of this repository as four layers:

1. Moodle core and plugins
2. `public/local/aiagentapi` as the canonical backend contract
3. `scripts/moodle_cli.py` as the CLI/tool surface for agents
4. skills and future frontend / OpenClaw integration on top

Architecture rule:

- business logic stays in `public/local/aiagentapi`
- CLI stays thin
- skills describe how to use capabilities
- future frontend should call an agent gateway, not Moodle directly

## Local environments

There are four important local environments.

### 1. Main development site

Use this for day-to-day development of `local_aiagentapi`, CLI, skills, and seeded regression.

- Code: `/Users/wonder/Documents/moodle`
- Dataroot: `/Users/wonder/Documents/moodle/moodledata`
- URL: `http://127.0.0.1:8000/`
- Version: `Moodle 5.1.2`
- Admin account: `admin`
- Admin password: `Admin123!ChangeMe`

Recommended startup command:

```bash
cd /Users/wonder/Documents/moodle
php -d max_input_vars=5000 \
  -d display_errors=0 \
  -d html_errors=0 \
  -d log_errors=1 \
  -d error_log=/tmp/moodle-php-server.error.log \
  -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
  -S 127.0.0.1:8000 -t public
```

### 2. Production clone (local copy of the real site)

Use this to inspect real content and legacy customizations without touching the main dev site.

- Code: `/Users/wonder/Documents/moodle-prod-clone`
- Dataroot: `/Users/wonder/Documents/moodle-prod-clone-data`
- URL: `http://127.0.0.1:8001/`
- Purpose: real data inspection, legacy behavior verification, phone-auth migration source

Recommended startup command:

```bash
cd /Users/wonder/Documents/moodle-prod-clone
php -d max_input_vars=5000 \
  -d display_errors=0 \
  -d html_errors=0 \
  -d log_errors=1 \
  -d error_log=/tmp/moodle-prod-clone.error.log \
  -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
  -S 127.0.0.1:8001 -t public
```

### 3. Clean 4.5 rehearsal copy

Use this as the isolated pre-upgrade rehearsal copy.

- Code: `/Users/wonder/Documents/moodle-upgrade-rehearsal`
- Dataroot: `/Users/wonder/Documents/moodle-upgrade-rehearsal-data`
- URL: `http://127.0.0.1:8002/`
- DB prefix: `mur_`
- Purpose: uninstall testing and upgrade rehearsal staging

### 4. Upgraded 5.1 rehearsal copy

Use this for real-content testing on the upgraded stack.

- Code: `/Users/wonder/Documents/moodle/rehearsals/upgrade51-code`
- Dataroot: `/Users/wonder/Documents/moodle/rehearsals/upgrade51-data`
- URL: `http://127.0.0.1:8003/`
- DB prefix: `m51_`
- Purpose: real-course AI API / CLI validation after upgrade

Recommended startup command:

```bash
cd /Users/wonder/Documents/moodle/rehearsals/upgrade51-code
php -d max_input_vars=5000 \
  -d display_errors=0 \
  -d html_errors=0 \
  -d log_errors=1 \
  -d error_log=/tmp/moodle-upgrade51.error.log \
  -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
  -S 127.0.0.1:8003 -t public
```

## Current credentials and local-only notes

Keep tokens and sensitive values out of git.

Useful local-only references:

- `/Users/wonder/Documents/moodle/docs/AIAGENTAPI_LOCAL_CONFIG.md`
- `/Users/wonder/Documents/moodle/docs/AIAGENTAPI_LOCAL_SECRETS.local.md`

Current local test user for upgraded rehearsal site:

- URL: `http://127.0.0.1:8003/login/index.php`
- Phone login user: `13900008003`
- Password: `Aiagent8003!`

Important:

- this test user can now see `course 92` and its synced study plan in the upgraded rehearsal site
- a separate CLI token file exists for student-view experiments: `/Users/wonder/Documents/moodle/.env.upgrade51.student.local`
- student-view webservice ACL is not fully cleaned up yet; manager/local CLI token remains the most stable test path

## Main project entrypoints

### Backend contract

- `public/local/aiagentapi`

### Agent-facing CLI

- `/Users/wonder/Documents/moodle/scripts/moodle_cli.py`
- `/Users/wonder/Documents/moodle/bin/moodle`
- Detailed guide: `/Users/wonder/Documents/moodle/docs/MOODLE_CLI.md`

## CLI 快速使用（每天按这个流程走）

统一入口命令：`bin/moodle`。

### 1) 新机器首次配置

```bash
cd /Users/wonder/Documents/moodle
bin/moodle config init --name prod --base-url http://dzexam.cn --activate
```

登录（推荐：浏览器/device flow）：

```bash
bin/moodle auth login --name prod
```

非阻塞启动（稍后在浏览器完成）：

```bash
bin/moodle auth login --name prod --no-wait
```

默认会直接打印授权链接（`verification_url`）和恢复命令，体验对齐飞书 device flow：

```bash
Open this URL to approve CLI login:
http://dzexam.cn/local/aiagentapi/device_verify.php?user_code=XXXX-XXXX
Resume command: moodle auth login --device-code <DEVICE_CODE> --name prod
```

账号密码兜底方式：

```bash
bin/moodle auth login --name prod --username wonderhow --password '***'
```

### 2) 验证登录状态

```bash
bin/moodle doctor
bin/moodle --json auth status
bin/moodle --json context get
```

### 3) 常用查询命令

```bash
bin/moodle --json courses list
bin/moodle --json courses list --limit 20 --offset 20
bin/moodle --json courses get --course-id 108
bin/moodle --json courses outline --course-id 108
bin/moodle --json search course --course-id 108 --query 测试 --limit 20
bin/moodle --json search course --course-id 108 --query 测试 --limit 20 --offset 20
bin/moodle --json search global --query 试听 --kind course --limit 20
bin/moodle --json activities list --course-id 108
bin/moodle --json activities get --cmid 7689
bin/moodle --json resources list --course-id 103
bin/moodle --json resources get --course-id 103 --cmid 7358
bin/moodle --json assignments list --course-id 108
bin/moodle --json assignments get --course-id 108 --assign-id 401
bin/moodle --json questions search --course-id 108 --query 测试 --limit 20 --offset 20
bin/moodle --json forum discussions --course-id 108 --limit 20 --offset 20
bin/moodle --json notifications list --limit 20 --offset 20
bin/moodle --json quiz list --course-id 108
bin/moodle --json quiz get --course-id 108 --quiz-id 666
bin/moodle --json grades overview --course-id 108
bin/moodle --json progress course --course-id 108
bin/moodle --json mathstate student-summary --course-id 108 --user-id 2
bin/moodle --json mathstate reviews-due --course-id 108 --user-id 2 --limit 20
```

说明：

- `resources get` 默认会尝试补齐资源内容字段（`content.summary_html` / `content.content_html`），更接近飞书 `fetch` 的读取体验。
- 如果只想快速拿列表层字段，可加 `--no-content`。
- `search course/global` 支持 `--offset` 翻页；返回体里有 `meta.next_offset`、`meta.has_more`、`meta.total_count`。
- `courses/activities/resources/assignments/quiz list` 也支持 `--offset` + `--limit`；`--limit 0` 表示从 offset 开始返回全部。
- `questions search`、`forum discussions`、`notifications list` 也支持统一分页；通知命令里 `--offset` 等价于 `--limit-from`（推荐用 `--offset`）。

### 3.1) Mathstate 命令

`mathstate` 对应 `public/local/mathstate` 插件，负责题库知识点、题型、题目映射和学习证据的状态同步。

```bash
bin/moodle --json mathstate kp-upsert \
  --item-json '{"kg_id":"kp.demo.1","name":"集合概念"}'

bin/moodle --json mathstate qtype-upsert \
  --item-json '{"qg_id":"qg.demo.1","name":"集合概念题","knowledge_points":["kp.demo.1"]}'

bin/moodle --json mathstate question-map-upsert \
  --item-json '{"questionid":1,"qg_id":"qg.demo.1","kg_ids":["kp.demo.1"],"mapping_source":"manual","mapping_confidence":95}'

bin/moodle --json mathstate evidence-ingest \
  --item-json '{"userid":2,"courseid":108,"questionid":1,"result":"wrong","score":0,"maxscore":1}'

bin/moodle --json mathstate student-summary --course-id 108 --user-id 2
bin/moodle --json mathstate reviews-due --course-id 108 --user-id 2 --limit 20
```

说明：

- `kp-upsert / qtype-upsert / question-map-upsert / evidence-ingest` 都支持 `--input <json文件>` 或重复传 `--item-json`。
- `student-summary` 默认返回 `kp_states`、`qtype_states`、`due_tasks`。
- `reviews-due` 默认按到期时间和优先级返回复习任务。

### 4) 输出模式

自动化场景建议固定用 `--json`。

人工查看可用：

```bash
bin/moodle --format table courses list
bin/moodle --format csv notifications list --limit 20
bin/moodle --format ndjson activities due --course-id 108 --limit 20
```

### 5) 安全写入流程（强烈建议）

先 `--dry-run` 预览，再用 `--force` 真正执行。

```bash
bin/moodle --json --dry-run calendar upsert-plan \
  --idempotency-key demo-preview \
  --plan-key demo-plan \
  --item-json '{"item_key":"task-1","name":"Preview","description":"preview","timestart":1773833400,"timeduration":1800}'
```

确认无误后，把同一条命令改为 `--force` 再执行。

### 6) 换电脑能不能直接用？

可以。流程就是：

1. 克隆仓库（或安装 `scripts/moodle_cli.py` + `bin/moodle`）
2. 执行 `config init`
3. 执行 `auth login`
4. 执行 `doctor` 和 `auth status`

不需要手动拷 token 文件；登录后会在本机生成凭据（优先 keychain，失败时文件回退）。

### Seed + service registration

- `/Users/wonder/Documents/moodle/scripts/seed_moodle_test_data.php`
- `/Users/wonder/Documents/moodle/scripts/register_aiagentapi_service_functions.php`

### Regression

- `/Users/wonder/Documents/moodle/scripts/test_moodle_cli.py`
- `/Users/wonder/Documents/moodle/scripts/test_moodle_live_cli.py`

### Skills

- Canonical skill: `/Users/wonder/Documents/moodle/agent-skills/moodle-aiagent-stack/SKILL.md`
- OpenClaw adapter: `/Users/wonder/Documents/moodle/skills/moodle-aiagent-cli/SKILL.md`
- Claude adapter: `/Users/wonder/Documents/moodle/.claude/skills/moodle-aiagent-cli/SKILL.md`

### Skill sync

- `/Users/wonder/Documents/moodle/scripts/sync_agent_skills.py`

## How to debug the project

### 1. Sanity check the current API surface

```bash
cd /Users/wonder/Documents/moodle
python3 scripts/moodle_cli.py --env-file .env.local --json context get
python3 scripts/moodle_cli.py --env-file .env.local --json catalog get
```

For the upgraded rehearsal site:

```bash
cd /Users/wonder/Documents/moodle
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json context get
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json courses list
```

### 2. Run the seeded regression

```bash
cd /Users/wonder/Documents/moodle
php scripts/seed_moodle_test_data.php
php scripts/register_aiagentapi_service_functions.php
python3 scripts/test_moodle_live_cli.py --env-file .env.local --seed
```

### 3. Inspect a real upgraded course

```bash
cd /Users/wonder/Documents/moodle
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json courses outline --course-id 92
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json quiz list --course-id 92
```

### 4. Test study plan writeback

```bash
cd /Users/wonder/Documents/moodle
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json --dry-run \
  calendar upsert-plan --idempotency-key demo-preview --plan-key demo-plan \
  --item-json '{"item_key":"task-1","name":"Preview","description":"preview","timestart":1773833400,"timeduration":1800}'
```

Then rerun with `--force`.

### 5. Test real quiz flow

A real upgraded-course quiz chain already validated:

- course: `92 / 2026四类综合`
- quiz: `431 / 政治思想和职业道德`

Useful commands:

```bash
cd /Users/wonder/Documents/moodle
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json quiz list --course-id 92
python3 scripts/moodle_cli.py --env-file .env.upgrade51.local --json --force quiz start --idempotency-key demo-q --quiz-id 431 --force-new
```

## Key documents

These are the documents worth reading first.

- `/Users/wonder/Documents/moodle/docs/MOODLE_CLI.md`
- `/Users/wonder/Documents/moodle/docs/AIAGENTAPI_LOCAL_CONFIG.md`
- `/Users/wonder/Documents/moodle/docs/UPGRADE_REHEARSAL_INVENTORY.md`
- `/Users/wonder/Documents/moodle/docs/PHONE_AUTH_MIGRATION.md`
- `/Users/wonder/Documents/moodle/docs/PLUGINS.md`
- `/Users/wonder/Documents/moodle/docs/CLI_REFERENCES.md`
- `/Users/wonder/Documents/moodle/docs/GOOD_CLI_SPEC.md`
- `/Users/wonder/Documents/moodle/docs/GOGCLI_METHOD.md`
- `/Users/wonder/Documents/moodle/docs/OPENCLAW_FRONTEND_ARCHITECTURE.md`
- `/Users/wonder/Documents/moodle/docs/COURSE_92_STUDY_PLAN.md`
- `/Users/wonder/Documents/moodle/docs/NEXT_STEPS.md`

## Known pitfalls and lessons learned

### 1. PHP 8.5 warnings can break Moodle responses

If `E_DEPRECATED` output leaks into HTTP responses, it can break:

- JSON webservice responses
- session startup
- login flows

Use the recommended startup flags above.

### 2. `moodlelib.php` used deprecated backticks for `uptime`

This had to be patched to avoid PHP 8.5 deprecation noise.

We fixed it in the local clones by replacing the backtick form with `shell_exec('/usr/bin/uptime')`.

### 3. Moodle webservices are easy to configure wrongly

The correct model is:

- many `local_aiagentapi_*` functions
- one unified external service: `local_aiagentapi`
- per-user tokens attached to that service

Do not create one service per function.

### 4. Local MariaDB root auth is not the same as app DB auth

We repeatedly hit this:

- `mysql -u root` can fail locally depending on auth mode
- the app-level DB user (`moodle`) is often the more reliable path for scripted DB inspection

### 5. `calendar list` looked broken before REST + webservices were fully enabled

The bug was environmental, not in `local_aiagentapi`.

Once `enablewebservices=1` and `webserviceprotocols=rest` were correctly enabled in the upgraded copy, the same calendar reads worked.

### 6. Real production login had historical core hacks

The production clone originally had phone login hardcoded into core files such as:

- `auth/email/auth.php`
- `login/index.php`
- `login/signup.php`
- `login/signup_form.php`
- `login/send_sms.php`

We migrated this into plugins:

- `public/auth/phone`
- `public/local/phoneauth`

This was necessary before safe upgrade rehearsal.

### 7. Upgrading the real clone without plugin triage is risky

The first successful 5.1 upgrade path depended on:

- trimming clearly non-essential plugins from the isolated rehearsal copy
- keeping only the required login/content path plugins
- deferring some plugins such as `local_xp`

See `/Users/wonder/Documents/moodle/docs/UPGRADE_REHEARSAL_INVENTORY.md`.

### 8. Real quiz failures are not always CLI failures

Example from `course 92`:

- `quiz 681 / 1.细胞学说` failed to start because the quiz's random-question category did not have enough usable questions

That is a real content/configuration issue in the course copy, not a CLI issue.

### 9. Not all real question types are fully adapted yet

Current state:

- multichoice flows are working well
- some `truefalse` questions can be read but are not yet fully mapped in the structured `quiz answer` path

### 10. Student-view webservice access still needs cleanup

The upgraded rehearsal student account can:

- log in on the site
- view course 92
- view the synced study plan

But its direct WS token path still hits Moodle webservice ACL friction.

For CLI regression, `.env.upgrade51.local` remains the stable path for now.

## Reference plugins and research inputs

Reference plugins live under:

- `/Users/wonder/Documents/moodle/references`

Plugin analysis and extracted lists live in:

- `/Users/wonder/Documents/moodle/docs/PLUGINS.md`

CLI and skill research references live in:

- `/Users/wonder/Documents/moodle/docs/CLI_REFERENCES.md`
- `/Users/wonder/Documents/moodle/docs/GOGCLI_METHOD.md`
- `/Users/wonder/Documents/moodle/docs/GOOD_CLI_SPEC.md`

## Skills layout

Skill packaging layout:

- `agent-skills/moodle-aiagent-stack/`: canonical platform-neutral skill source
- `.claude/skills/moodle-aiagent-cli/`: project-local Claude adapter
- `plugins/moodle-aiagent-stack/`: Claude Code plugin/marketplace adapter
- `skills/moodle-aiagent-cli/`: OpenClaw-oriented adapter

Skill maintenance flow:

1. edit canonical content first: `agent-skills/moodle-aiagent-stack/`
2. sync adapters after changes:

```bash
cd /Users/wonder/Documents/moodle
python3 scripts/sync_agent_skills.py
```

## Next-step plan

- Immediate development plan: `/Users/wonder/Documents/moodle/docs/NEXT_STEPS.md`

## Frontend direction

The intended product direction is:

- user-facing frontend
- OpenClaw as the agent runtime
- the agent calling `moodle_cli.py`
- Moodle staying the system of record

See:

- `/Users/wonder/Documents/moodle/docs/OPENCLAW_FRONTEND_ARCHITECTURE.md`

## AI API implementation method (policy)

All new AI-facing functionality must follow this workflow:

1. Define the feature precisely: read/write scope, permissions, context boundaries, batch size, and side effects.
2. Check Moodle core WS first, then plugin WS for similar functionality.
3. Decide reuse vs. re-implement:
   - If a WS function is safe and suitable, wrap it in `local_aiagentapi`.
   - If not safe or not suitable, study the plugin logic and re-implement the behavior in `local_aiagentapi`.
4. All external access must go through `local_aiagentapi` with centralized auth, idempotency, audit, and stable schemas.

Plugins are references, not drop-in APIs.

## Upstream Moodle

This repository still contains upstream Moodle. Useful upstream links:

- Website: [moodle.org](https://moodle.org)
- User docs: [docs.moodle.org](https://docs.moodle.org/)
- Developer docs: [moodledev.io](https://moodledev.io)
- Download: [download.moodle.org](https://download.moodle.org)
- License: [GNU GPL v3](https://moodledev.io/general/license)
