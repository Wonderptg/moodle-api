# Upgrade Rehearsal Inventory

Date: 2026-03-18

## Rehearsal copy

- Code: `/Users/wonder/Documents/moodle-upgrade-rehearsal`
- Dataroot: `/Users/wonder/Documents/moodle-upgrade-rehearsal-data`
- DB: local `test` database with table prefix `mur_`
- URL: `http://127.0.0.1:8002`

## First 5.1 attempt copy

- Code: `/Users/wonder/Documents/moodle/rehearsals/upgrade51-code`
- Dataroot: `/Users/wonder/Documents/moodle/rehearsals/upgrade51-data`
- DB: local `test` database with table prefix `m51_`
- URL: `http://127.0.0.1:8003`
- Result: upgraded successfully to `5.1.2 (Build: 20260209)`

## Active site settings

- Site theme: `moove`
- Registration auth: `phone`
- Enabled auth plugins: `email,phone`
- Force login: `0`

## Course theme overrides

- `(default)`: 65 courses

No courses currently override the site theme. This means the site-level theme decision is the only active theme path in course delivery.

## Installed non-core plugins seen in database

### Must keep for upgrade rehearsal

- `theme_moove`
  - Active site theme
  - Installed version: `2024100801`
- `auth_phone`
  - Active registration and login path
  - Installed version: `2026031700`
- `local_phoneauth`
  - Supports SMS signup flow for `auth_phone`
  - Installed version: `2026031700`
- `mod_offlinequiz`
  - Course instances: `41`
  - Installed version: `2025121955`
- `mod_hsuforum`
  - Course instances: `4`
  - Installed version: `2025011000`

### Installed, but currently not active in course delivery

- `theme_adaptable`
  - Installed version: `2024100505`
- `theme_boost_magnific`
  - Installed version: `2025030700`
- `theme_boost_union`
  - Installed version: `2024100759`
- `theme_degrade`
  - Installed version: `2025021901`
- `local_danzhao`
  - Installed version: `2025032500`
- `local_oauth2`
  - Installed version: `2024100701`
- `local_xp`
  - Installed version: `2025041301`
- `mod_attendance`
  - Installed version: `2024072401`
- `mod_wordcards`
  - Installed version: `2025102700`

These are still installed in the database, so Moodle upgrade will still inspect them if their code remains present.

## Module usage by real course instances

### In active use

- `resource`: `1440`
- `page`: `1235`
- `quiz`: `398`
- `url`: `305`
- `forum`: `47`
- `offlinequiz`: `41`
- `label`: `12`
- `folder`: `6`
- `hsuforum`: `4`
- `scorm`: `2`

### Installed but zero current instances

- `assign`
- `attendance`
- `bigbluebuttonbn`
- `book`
- `chat`
- `choice`
- `data`
- `feedback`
- `glossary`
- `h5pactivity`
- `imscp`
- `lesson`
- `lti`
- `subsection`
- `survey`
- `wiki`
- `wordcards`
- `workshop`

Zero current instances does not automatically mean safe to remove in production. It does mean these are candidates for uninstall testing in the isolated rehearsal copy.

## Practical upgrade posture

### Safe baseline

Keep these in the first upgrade rehearsal:

- `theme_moove`
- `auth_phone`
- `local_phoneauth`
- `mod_offlinequiz`
- `mod_hsuforum`

### Likely removable candidates for rehearsal-only cleanup

Only after explicit uninstall testing on the rehearsal copy:

- `theme_adaptable`
- `theme_boost_magnific`
- `theme_boost_union`
- `theme_degrade`
- `mod_attendance`
- `mod_wordcards`
- `mod_offlinequiz`

### Needs manual judgement

- `local_danzhao`
- `local_oauth2`
- `local_xp`

These have no direct course-module signal. They may still affect login, navigation, homepage, rewards, or external integrations.

## Manual-judgement plugin notes

### `local_danzhao`

- Installed version: `2025032500`
- Minimal structure only:
  - `/Users/wonder/Documents/moodle-upgrade-rehearsal/local/danzhao/index.php`
  - `/Users/wonder/Documents/moodle-upgrade-rehearsal/local/danzhao/sso.php`
  - `/Users/wonder/Documents/moodle-upgrade-rehearsal/local/danzhao/static/`
- Current role:
  - embeds a separate frontend app in an iframe
  - exposes an SSO-style redirect into that frontend
- Practical reading:
  - not part of core Moodle login
  - not part of course delivery
  - likely safe to exclude from the first Moodle 5.1 upgrade rehearsal unless that external system must also be tested

### `local_oauth2`

- Installed version: `2024100701`
- Config present:
  - `access_token_lifetime = 3600`
  - `refresh_token_lifetime = 604800`
- Practical reading:
  - likely an API/auth support plugin
  - not enough evidence yet that it is required for the first content-and-login-focused rehearsal
  - keep only if we plan to test external API clients during the upgrade rehearsal

### `local_xp`

- Installed version: `2025041301`
- Config indicates gamification features only:
  - group ladder / progress / points settings
- Practical reading:
  - not part of core login
  - not part of required course rendering
  - likely optional for the first upgrade rehearsal

## Suggested next steps

1. Freeze the required set for the first rehearsal:
   - `theme_moove`
   - `auth_phone`
   - `local_phoneauth`
   - `mod_offlinequiz`
   - `mod_hsuforum`
2. Decide whether to uninstall clearly unused plugins in the `8002` rehearsal copy before upgrading.
3. Build a Moodle 5.1 target code tree containing:
   - core 5.1
   - required plugins above
   - optional plugins only if we choose not to uninstall them first
4. Run `admin/cli/upgrade.php` against the isolated rehearsal copy and inspect blockers.

## What was actually removed in the 8002 cleanup

These were successfully uninstalled from the isolated rehearsal copy before the first 5.1 attempt:

- `mod_offlinequiz`
- `mod_attendance`
- `mod_wordcards`
- `theme_adaptable`
- `theme_boost_magnific`
- `theme_boost_union`
- `theme_degrade`

## First 5.1 rehearsal outcome

### First blocker

- `local_xp` failed dependency checks because it declares a dependency on `block_xp`
- Resolution:
  - uninstalled `local_xp` from the `m51_` attempt database
  - removed `public/local/xp` from the `8003` target code tree and parked it at:
    - `/Users/wonder/Documents/moodle/rehearsals/skipped-plugins/local/xp`

### Successful retained set in the upgraded 5.1 copy

- `theme_moove`
- `auth_phone`
- `local_phoneauth`
- `mod_hsuforum`
- `local_danzhao`
- `local_oauth2`
- `local_aiagentapi`

### Post-upgrade smoke checks

- homepage on `http://127.0.0.1:8003/`: `200 OK`
- login page on `http://127.0.0.1:8003/login/index.php`: renders phone-based login UI
- signup page on `http://127.0.0.1:8003/login/signup.php`: `200 OK`, renders phone signup fields

## 8003 smoke-test results

Date: `2026-03-18`

### AI API / CLI

Passed:

- `context get`
- `catalog get`
- `courses list`
- `activities list --course-id 37`
- `calendar publish-plan --dry-run`
- `calendar publish-plan --force`

Observed details:

- `context get` returned upgraded-site admin user context successfully
- `courses list` returned real production-derived course data
- `activities list --course-id 37` returned a large real activity set from `2026单招四类职测课程`
- `calendar publish-plan --force` created user calendar event:
  - event id: `49`
  - title: `Upgrade51 CLI smoke`

Resolved during follow-up verification:

- `calendar list` now returns the newly created user event correctly
- direct REST call and CLI both returned event `49`
- the earlier empty result happened before the upgraded copy had both web services and the REST protocol fully enabled

### Phone auth

Passed:

- signup page rendered in upgraded 5.1 copy
- demo SMS request worked when `local_phoneauth/demo_mode` was temporarily enabled
- full phone signup completed successfully
- created smoke-test user:
  - user id: `6819`
  - username / phone: `13900008003`
  - auth plugin: `phone`

Additional verification:

- direct Moodle auth call using `authenticate_user_login()` succeeded for the smoke-test user and password

Known issue discovered:

- multiple `curl`-based form-post login attempts returned `登录无效，请重试`
- direct `authenticate_user_login()` succeeded for the same phone/password
- practical reading:
  - server-side phone authentication works
  - the remaining uncertainty is in fully reproducing Moodle's browser login flow with plain `curl`
  - if we need a definitive browser-level login proof, use a real browser or Playwright-style automation rather than raw `curl`

Cleanup performed:

- `local_phoneauth/demo_mode` was turned back to `0` after the smoke test
