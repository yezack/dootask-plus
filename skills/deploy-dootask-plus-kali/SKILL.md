---
name: deploy-dootask-plus-kali
description: Inspect, install, deploy, update, configure, troubleshoot, verify, or roll back the private yezack/dootask-plus project on a Kali or Debian-family Docker host. Use for DooTask Plus production deployment, origin/pro updates, ./cmd operations, Docker Compose health failures and 502s, SCIM/UniAuthSync configuration rollout, private GitHub authentication, database backup, or production verification while preserving the existing DooTask architecture and database schema.
---

# Deploy DooTask Plus on Kali

Treat the remote host as production. Preserve `.env`, uploads, application data, MariaDB data, Redis data, licenses, secrets, and unrelated changes. Work in observable stages and keep a rollback point.

## Project Rules

- Read the repository `AGENTS.md` and `CLAUDE.md` before changing deployment files or code.
- Use the project `./cmd` wrapper for PHP, Artisan, Composer, service, update, backup, and restart operations.
- Do not run `./cmd dev`, `./cmd prod`, or `./cmd build` unless the user explicitly requests that exact build action.
- Do not add database tables, columns, migrations, or replacement infrastructure merely to simplify deployment.
- Use `config()` in application code; never add direct business-code `env()` reads.
- Preserve the existing DooTask Compose topology unless the user explicitly requests an infrastructure redesign.
- Never commit, push, force-update, reset, uninstall, restore a database, or rewrite history without explicit authorization.

## Expected Project Shape

The repository is the private GitHub project `yezack/dootask-plus`, normally deployed from branch `pro`.

The existing Compose stack contains these services:

- `php`: Laravel 13 with LaravelS/Swoole and a `/health` healthcheck
- `nginx`: public HTTP/HTTPS entry point and `/health` healthcheck
- `mariadb`: project database stored under `docker/mysql/data`
- `redis`: cache and runtime state
- `appstore`: DooTask application manager

Container names include the instance-specific `APP_ID`; do not hard-code container names. Prefer `docker compose` and `./cmd` from the deployment root.

## Discover the Remote Environment

Prefer a configured SSH connector. If none exists, use native `ssh` only after obtaining any required approval. Do not assume an IP, username, port, or deployment directory.

Start with read-only checks:

```bash
whoami
hostname
uname -a
docker version
docker compose version
pwd
df -h
docker compose ps 2>/dev/null || docker ps -a
git status --short --branch 2>/dev/null || true
git remote -v 2>/dev/null || true
```

Locate the deployment root using bounded checks. Confirm it contains `cmd`, `compose.yaml` or `docker-compose.yml`, `app/`, and `.env`. Avoid unrestricted filesystem scans.

Once located, set it as the working directory and inspect without exposing secret values:

```bash
git branch --show-current
git rev-parse HEAD
git remote get-url origin
git status --short
docker compose config --services
docker compose ps
```

Confirm the origin belongs to `yezack/dootask-plus` and the intended branch is `pro`. If the remote differs, stop and ask before changing it.

## Preflight Every Deployment

1. Confirm the requested operation: inspect, fresh install, code update, configuration rollout, SCIM verification, or rollback.
2. Record the deployment root, current branch, current commit, Compose status, and health status.
3. Check disk space and the latest database backup.
4. Check for tracked modifications and untracked deployment files.
5. Fetch remote metadata without changing the working tree:

```bash
git fetch origin pro
git log --oneline --decorate HEAD..origin/pro
git diff --stat HEAD..origin/pro
git diff --name-only HEAD..origin/pro -- database migrations
```

Stop before update when any of these are true:

- The production worktree is dirty and the changes are not understood.
- Incoming commits contain database migrations or destructive data operations that the user has not approved.
- The remote or branch is unexpected.
- Required `.env`, database data, uploads, or license files are missing.
- The update would require `--force`.

Never solve a dirty worktree by automatically running `git reset --hard`, `git clean`, or `./cmd update --force`.

## Back Up Before Mutation

For an existing installation, create a database backup before code update, migration, rollback, or configuration changes with meaningful production risk:

```bash
./cmd mysql backup
```

Record the resulting backup filename without printing database credentials. Also record the current commit:

```bash
git rev-parse HEAD
```

Do not copy `docker/mysql/data` while MariaDB is running as a substitute for a logical backup.

## Update an Existing Installation

Use the repository's built-in update workflow after preflight and backup:

```bash
./cmd update --branch pro
```

This command manages Composer dependencies, Laravel migrations, and service recreation. Do not duplicate those steps manually unless diagnosing a specific failure.

If the production checkout intentionally receives already-uploaded local code, use `./cmd update --local` only when the user explicitly chooses that deployment mode. Do not silently upload or deploy an uncommitted local worktree.

If private GitHub authentication fails:

- Prefer a repository deploy key or an authenticated GitHub CLI/device session.
- Never paste a PAT into the Skill, repository, remote URL, logs, or chat output.
- Never change the remote to a credential-bearing URL.

## Fresh Installation

For a genuinely new host or empty target directory:

1. Confirm Docker Engine and Docker Compose are available.
2. Clone `yezack/dootask-plus` branch `pro` using approved private-repository credentials.
3. Create `.env` from the project's template and obtain secrets through a secure user-controlled channel.
4. Review ports, URL, database settings, `APP_ID`, and storage paths without printing secret values.
5. Run the project installer only after the user confirms installation:

```bash
./cmd install
```

Do not replace the project stack with the UniAuthSync Skill's split Nginx/MariaDB topology. Do not share or move database volumes unless the user explicitly requests a migration.

## Roll Out SCIM / UniAuthSync Configuration

Keep SCIM as an application configuration change. Do not add SCIM-specific database fields.

Relevant variables are:

```text
SCIM_SERVER_URL
SCIM_ISSUER
SCIM_CLIENT_ID
SCIM_CLIENT_SECRET
SCIM_POLL_INTERVAL
SCIM_LOCK_SECONDS
SCIM_WEBHOOK_SECRET
SCIM_DEFAULT_PASSWORD_PREFIX
SCIM_SYNC_DEPARTMENT
SCIM_REPLACE_DEPARTMENTS
SCIM_REACTIVATE_USERS
```

Preserve existing values. Never echo secret values. Ask the user to enter new secrets directly on the host or use an approved secret mechanism. After configuration changes, restart LaravelS/Swoole:

```bash
./cmd php restart
```

Do not run a production SCIM full sync merely as a health check. `./cmd artisan scim:sync` creates, updates, or disables users and therefore requires explicit user authorization.

For webhook rollout, verify that UniAuthSync points to:

```text
https://<dootask-host>/api/scim/webhook
```

Verify reachability and expected authentication failures without sending a fabricated user event. A request without a valid signature should fail closed.

## Diagnose Services

Use read-only evidence first:

```bash
docker compose ps
docker compose logs --tail=150 php nginx mariadb redis appstore
docker compose config --services
curl -fsS http://127.0.0.1:${APP_PORT}/health
```

Read `APP_PORT` without displaying the rest of `.env`. If the service is behind another proxy, test both the local DooTask Nginx endpoint and the public hostname.

For a 502, inspect in this order:

1. `docker compose ps`
2. PHP health and recent PHP logs
3. Nginx logs and upstream resolution
4. MariaDB and Redis health
5. Disk space and file ownership

Use the documented recovery command only after collecting evidence:

```bash
./cmd reup
```

Prefer `./cmd restart php`, `./cmd restart nginx`, or `./cmd php restart` when only one component needs restarting. Do not use `docker compose down -v`.

## Verify After Change

Run proportionate checks after every deployment or restart:

```bash
git status --short --branch
git rev-parse HEAD
docker compose ps
curl -fsS http://127.0.0.1:${APP_PORT}/health
docker compose logs --tail=80 php nginx
```

Also verify:

- The public URL returns the expected HTTP status.
- Login remains available, including the local administrator fallback if configured.
- Uploads and existing user/project data remain present.
- No container is repeatedly restarting or unhealthy.
- The deployed commit matches the intended `origin/pro` commit.
- SCIM configuration changes did not expose credentials in logs or shell history.

Run project quality checks before publishing code when the environment supports them:

```bash
./cmd composer stan
./cmd php vendor/bin/phpunit
```

Do not run frontend `prod/build` as a verification shortcut.

## Rollback

Do not roll back automatically. Report the previous commit, current commit, backup path, and failure evidence, then obtain explicit approval.

For an approved code-only rollback, return to the recorded commit using a non-destructive, reviewable Git operation and run the local update workflow. Avoid `git reset --hard` unless the user explicitly commands it.

Restore the database only when a migration or data mutation actually requires it and the user explicitly approves the selected backup. Database restoration can discard newer production data.

## Handoff

Report:

- Host and deployment root
- Previous and deployed commit IDs
- Branch and origin
- Backup filename
- Commands that changed production state
- Compose service and health results
- Public health/login result
- SCIM status without secret values
- Any skipped checks, warnings, or required manual actions

Remove only temporary archives or extraction directories created during the task. Retain backups, `.env`, uploads, database data, and operational logs.
