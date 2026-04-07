# Phone Auth Migration

## Context

The production clone at `/Users/wonder/Documents/moodle-prod-clone` contains historical phone-login changes made directly in Moodle core.

To make future upgrades safer, we should move these changes into plugins before attempting an in-place upgrade of the real site.

## Exact upstream baseline used

Official upstream reference downloaded locally:

- `/Users/wonder/Documents/moodle/references/moodle-official-v452`
- Version: `4.5.2 (Build: 20250210)`

Production clone version:

- `/Users/wonder/Documents/moodle-prod-clone/version.php`
- Version: `4.5.2+ (Build: 20250214)`

This is close enough to isolate the custom changes accurately.

## Actual customizations found

Files with meaningful custom changes:

- `auth/email/auth.php`
- `login/index.php`
- `login/signup.php`
- `login/signup_form.php`
- `login/send_sms.php` (new file in core login path)

Files checked but not meaningfully customized in the production clone:

- `lib/authlib.php`
- `user/edit.php`

## What the customizations do

### 1. Phone login inside `auth/email`

In `auth/email/auth.php`:

- If username lookup fails, login falls back to `user.phone1`
- Signup writes `phone` into `phone1`
- Signup bypasses email confirmation by forcing `confirmed = 1`
- Signup immediately logs the user in

### 2. Core login page confirmation bypass

In `login/index.php`:

- The standard block for unconfirmed accounts was removed
- This works only because signup now auto-confirms users

### 3. Core signup page rewritten for phone flow

In `login/signup.php`:

- Verifies SMS code from session
- Forces `username = phone`
- Forces `phone1 = phone`
- Adds server-side checks for firstname, lastname, email, and phone uniqueness
- Keeps auth plugin signup flow, but with custom pre-processing

### 4. Core signup form rewritten

In `login/signup_form.php`:

- Replaces username field with phone + SMS code
- Adds firstname, lastname, email fields
- Adds JS that POSTs to `/login/send_sms.php`

### 5. Core SMS endpoint added

In `login/send_sms.php`:

- Generates 6-digit code
- Stores code in session
- Sends code via Aliyun SMS API
- Uses hardcoded provider credentials in source code

## Migration target

Use plugins instead of core hacks:

1. `auth/phone`
- phone-aware authentication
- bridges phone input to a Moodle username before normal auth proceeds

2. `local/phoneauth`
- SMS sending configuration and code storage/verification
- public signup page and signup form
- provider integration without hardcoded secrets in core

## Why this split

- `auth/phone` should stay focused on authentication behavior
- SMS delivery and public registration flow are application logic, not core auth behavior
- This keeps the upgrade surface smaller

## Minimum safe migration path

1. Install `auth/phone`
2. Install `local/phoneauth`
3. Configure SMS provider secrets in plugin settings
4. Point signup entry to `/local/phoneauth/signup.php`
5. Test signup and login on 4.5.2+
6. Restore core files to upstream equivalents
7. Re-test signup and login
8. Only then attempt 5.1.x upgrade rehearsal

## Important security cleanup

Do not keep SMS provider credentials in code.

Move these into plugin settings:

- access key id
- access key secret
- sign name
- template code

Also add:

- send throttling
- verification expiry
- optional demo mode for local testing only

## Current implementation status

Scaffolded in the dev repo:

- `public/auth/phone`
- `public/local/phoneauth`

These are the plugin targets we will use for the migration.
