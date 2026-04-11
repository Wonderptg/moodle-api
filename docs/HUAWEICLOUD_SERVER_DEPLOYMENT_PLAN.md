# Huawei Cloud Server Deployment Plan

This document describes the recommended deployment model for:

- the upgraded Moodle site
- `public/local/aiagentapi`
- `scripts/moodle_cli.py`
- server-side smoke testing after deploy

It is written for the current repository layout and the current project rule:

- backend contract lives in `public/local/aiagentapi`
- CLI stays thin
- secrets stay server-side
- frontend/agent layers should call an agent gateway, not Moodle directly

## Short answer

Yes, using GitHub as the code distribution path is reasonable.

But GitHub should only manage **code**.

These must stay outside git and be managed directly on the server:

- Moodle database
- `moodledata`
- `config.php`
- webservice tokens
- `.env` files
- nginx / php-fpm / systemd configuration

Think of production as four independent parts:

1. code from GitHub
2. database
3. `moodledata`
4. secrets and server config

## Final architecture for this project

For the current phase of this project, the recommended production architecture is:

- Huawei Cloud server runs:
  - Moodle site
  - `public/local/aiagentapi`
  - custom Moodle plugins
  - database
  - `moodledata`
  - webservice tokens and external service registration

- Your local machine runs:
  - OpenClaw
  - the model runtime
  - `scripts/moodle_cli.py`
  - any frontend or agent UI

That means:

- the server is just a clean Moodle website plus server-side AI-friendly API functions
- the agent stays off-server
- the CLI talks to the server remotely through Moodle web services

This is the preferred first production model because it is simpler and safer:

- server responsibilities stay small
- model/provider changes do not require redeploying the website
- agent experiments stay on your machine
- production Moodle remains closer to a normal web application

## What gets updated on the server

When you say "update the server", for this architecture it means:

- update Moodle code
- update `public/local/aiagentapi`
- update custom Moodle plugins
- run Moodle upgrade
- re-register external service functions if needed

It does **not** mean:

- updating OpenClaw on the server
- updating model provider config on the server
- deploying Codex CLI or local model tooling to the server

Those stay on your local machine unless you later decide to host the agent separately.

## Recommended production topology

Use one Huawei Cloud ECS instance with this layout:

```text
/srv/moodle/
  current/                  -> checked-out application code
  shared/
    moodledata/             -> persistent Moodle dataroot
    env/
      moodle-cli.env        -> CLI env file
      php-fpm.env           -> optional service env
    backups/
```

Recommended service split:

- `nginx` -> serves the site
- `php-fpm` -> runs Moodle PHP
- `mysql` or `mariadb` -> Moodle database
- optional `redis` -> sessions/cache later if needed

## What should come from GitHub

These are good candidates for git-based deployment:

- Moodle code under `public/`
- `public/local/aiagentapi`
- custom plugins such as `public/auth/phone` and `public/local/phoneauth`
- CLI scripts under `scripts/`
- skills and docs if you also want them on the server

These should **not** come from GitHub:

- `.env.local`
- `.env.upgrade51.local`
- `.env.upgrade51.student.local`
- any real token files
- server `config.php` with production credentials

## Branch and release strategy

Do not deploy arbitrary local commits directly.

Recommended flow:

1. develop and verify locally
2. push to GitHub
3. deploy a tagged commit or a dedicated production branch

Suggested branches:

- `main` -> ongoing development
- `prod` -> production deployment branch

Or use tags:

- `release/2026-04-07-01`
- `release/2026-04-10-02`

Tags are safer because they make rollback easier.

## Server preparation

On the Huawei Cloud server, prepare:

1. PHP version compatible with your upgraded Moodle
2. nginx + php-fpm
3. database service
4. writable persistent `moodledata`
5. git access to your GitHub repository

Suggested directories:

```bash
sudo mkdir -p /srv/moodle/current
sudo mkdir -p /srv/moodle/shared/moodledata
sudo mkdir -p /srv/moodle/shared/env
sudo mkdir -p /srv/moodle/shared/backups
```

## If the server already hosts a site

If this ECS instance is **not** a clean machine and already runs an old Moodle or another PHP site, do not deploy blindly.

Before changing anything, identify:

- which web server is active (`nginx`, `apache`, or both)
- the real document root
- the active virtual host / site config file
- the current Moodle `config.php`
- the current `moodledata` path
- the current database host, name, and user

Useful discovery commands:

```bash
sudo nginx -T 2>/dev/null | sed -n '1,220p'
sudo apachectl -S 2>/dev/null
sudo grep -Rni "DocumentRoot\\|root\\s\\|server_name\\|VirtualHost" /etc/nginx /etc/httpd /etc/apache2 2>/dev/null | sed -n '1,220p'
sudo find /srv /var/www /usr/share/nginx/html /opt -type f -name config.php 2>/dev/null | sed -n '1,220p'
```

Capture this information before deploy:

- the domain actually serving Moodle
- the active docroot on disk
- the current `wwwroot` from `config.php`
- the current `dataroot`
- whether the existing site should be upgraded in place or replaced with a new deployment path

If the machine already hosts a production Moodle, do this before any code switch:

1. backup the database
2. backup `moodledata`
3. copy the current `config.php`
4. record the current git commit or deployed tarball version
5. only then proceed with the deployment steps below

## First deployment

### 1. Clone the repo

```bash
cd /srv/moodle
git clone <YOUR_GITHUB_REPO_URL> current
```

If this repository continues to track upstream Moodle directly, it is better to deploy from your own fork or your own deployment repo instead of `moodle/moodle`.

### 2. Create production `config.php`

Your production `config.php` should point to:

- production database
- `/srv/moodle/shared/moodledata`
- production `wwwroot`

Do not keep this file in GitHub.

### 3. Create CLI env file

Create:

`/srv/moodle/shared/env/moodle-cli.env`

Example:

```bash
export MOODLE_BASE_URL="https://your-domain.example.com"
export MOODLE_WS_TOKEN="YOUR_PRODUCTION_WS_TOKEN"
```

### 4. Fix permissions

Make sure the web server user can write to `moodledata`.

### 5. Register service functions

After first install or plugin changes:

```bash
cd /srv/moodle/current
php scripts/register_aiagentapi_service_functions.php
```

## Update deployment from GitHub

Yes, this can be done through GitHub plus server pull.

Recommended deployment sequence:

```bash
cd /srv/moodle/current
git fetch --all --tags
git checkout <prod-branch-or-tag>
git pull --ff-only
```

Then run the Moodle upgrade flow if needed.

## Required post-pull steps

After any code update that changes Moodle core or plugins:

### 1. Backup first

Always backup:

- database
- `moodledata`
- current checked-out code reference or tag

### 2. Run Moodle upgrade

```bash
cd /srv/moodle/current
php public/admin/cli/upgrade.php --non-interactive
```

If permissions need repair:

```bash
php public/admin/cli/purge_caches.php
```

### 3. Re-register `local_aiagentapi` functions

```bash
php scripts/register_aiagentapi_service_functions.php
```

This is especially important when `db/services.php` changes.

### 4. Smoke test the CLI

```bash
cd /srv/moodle/current
python3 scripts/moodle_cli.py --env-file /srv/moodle/shared/env/moodle-cli.env --json context get
python3 scripts/moodle_cli.py --env-file /srv/moodle/shared/env/moodle-cli.env --json catalog get
python3 scripts/moodle_cli.py --env-file /srv/moodle/shared/env/moodle-cli.env --json courses list
```

### 5. Smoke test one real course

Use a known real course id such as `92` if that exists in production:

```bash
python3 scripts/moodle_cli.py --env-file /srv/moodle/shared/env/moodle-cli.env --json courses outline --course-id 92
python3 scripts/moodle_cli.py --env-file /srv/moodle/shared/env/moodle-cli.env --json quiz list --course-id 92
```

### 6. Smoke test one write path

First dry run:

```bash
python3 scripts/moodle_cli.py --env-file /srv/moodle/shared/env/moodle-cli.env --json --dry-run \
  calendar upsert-plan --idempotency-key deploy-check-1 --plan-key deploy-check \
  --item-json '{"item_key":"deploy-check-1","name":"Deploy smoke check","description":"dry run","timestart":1773833400,"timeduration":1800}'
```

Then real write only if you explicitly want to validate writes in production.

## Best deployment model for this project

For this project, the safest production model is:

1. local development in this repository
2. local validation against `8000`
3. real-content rehearsal against `8003`
4. push verified code to GitHub
5. deploy the verified commit to Huawei Cloud
6. run server-side upgrade and smoke checks

That means:

- `8003` stays your rehearsal gate
- Huawei Cloud stays the real production runtime

## Recommended test checklist after production deploy

Use this exact sequence:

1. Web homepage loads
2. Login works
3. One real course opens in browser
4. `context get`
5. `catalog get`
6. `courses list`
7. one `courses outline`
8. one `quiz list`
9. one calendar dry-run write

If all nine pass, the deployment is basically healthy for the current AI stack.

## Rollback strategy

Do not deploy without a rollback plan.

Minimum rollback strategy:

1. keep previous git tag or commit
2. keep pre-deploy database backup
3. keep pre-deploy `moodledata` backup snapshot

Rollback order:

1. switch code back to previous tag
2. restore database if schema changes broke production
3. restore `moodledata` only if file/data corruption happened

## What I recommend you do next

For your current stack, the practical next move is:

1. confirm the exact Huawei Cloud server access mode
   - ssh user
   - repo URL
   - domain
   - php/nginx/mysql already installed or not
2. create a dedicated deployment branch or release tag
3. create a production `config.php`
4. create `/srv/moodle/shared/env/moodle-cli.env`
5. do one rehearsal deployment on the server
6. run the CLI smoke checklist

## Decision

Yes:

- deploying through GitHub is reasonable
- pulling updates on the Huawei Cloud server is reasonable

But only if you treat:

- code as git-managed
- database, `moodledata`, and secrets as server-managed

That is the correct model for this Moodle + AI CLI project.
