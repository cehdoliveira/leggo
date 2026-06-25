# Plan 001: Public web-accessible migration runner is removed

> **Executor instructions**: Follow this plan step by step. Run every verification
> command and confirm the expected result before moving to the next step. If anything in
> "STOP conditions" occurs, stop and report — do not improvise. When done, update the
> status row for this plan in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/public_html/migrations.php manager/public_html/migrations.php docker/interface/default.conf`
> If any in-scope file changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

`site/public_html/migrations.php` and `manager/public_html/migrations.php` sit inside the
web roots and are served directly by nginx (the `location ~ \.php` block hands every `.php`
file to PHP-FPM). They have **no authentication, no CSRF, no rate limit**:

- `GET /migrations.php` renders a table of every migration filename and its executed/pending
  status — schema/feature disclosure to anyone on the network.
- `GET /migrations.php?run=1` **executes all pending SQL migrations** against the production
  database — an unauthenticated state-changing operation.
- On error it echoes `htmlspecialchars($e->getMessage())` — leaks DB/driver error detail.

This capability is fully redundant: migrations already run automatically via cron
(`docker/interface/crontab` → `*/5 * * * * php /var/www/leggo/site/cgi-bin/run_migrations.php`)
and can be run manually with the CLI runner at `site/cgi-bin/run_migrations.php` (outside the
web root). Deleting the two public files removes the exposure with zero loss of function.

## Current state

- `site/public_html/migrations.php` — 333-line web UI. Lines 19-36 instantiate
  `localPDO` + `MigrationRunner` and call `$runner->run()` when `$_GET['run'] === '1'`;
  no auth/session check anywhere in the file.
- `manager/public_html/migrations.php` — same file, manager copy.
- `docker/interface/default.conf` — both `server {}` blocks route all PHP via:
  ```
  location ~ [^/]\.php(/|$) {
      fastcgi_split_path_info ^(.+\.php)(/.*)$;
      fastcgi_pass 127.0.0.1:9000;
      ...
  }
  ```
  There is **no** `location = /migrations.php { deny all; }`, so the file is reachable.
- The CLI runner that stays: `site/cgi-bin/run_migrations.php` (and the manager copy) — these
  live in `cgi-bin/`, which is **not** under `public_html/`, so they are not web-served.
- `MigrationRunner` (`site/app/inc/lib/MigrationRunner.php`) is unaffected — it is still used
  by the CLI runner and the cron job.

## Commands you will need

| Purpose   | Command | Expected on success |
|-----------|---------|---------------------|
| Drift check | `git diff --stat ccc4095..HEAD -- site/public_html/migrations.php manager/public_html/migrations.php` | (empty, or you reconcile) |
| Confirm files gone | `ls site/public_html/migrations.php manager/public_html/migrations.php 2>&1` | "No such file or directory" for both |
| Confirm CLI runner still present | `ls site/cgi-bin/run_migrations.php manager/cgi-bin/run_migrations.php` | both listed |
| PHPStan (site) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, no errors |
| PHPStan (manager) | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, no errors |

## Scope

**In scope** (delete these two files):
- `site/public_html/migrations.php`
- `manager/public_html/migrations.php`

**Optional defense-in-depth** (only if Step 2 is approved — see STOP note): add an nginx
deny rule for `migrations.php` in `docker/interface/default.conf`.

**Out of scope** (do NOT touch):
- `site/cgi-bin/run_migrations.php`, `manager/cgi-bin/run_migrations.php` — the legitimate CLI runners; keep them.
- `site/app/inc/lib/MigrationRunner.php` (and manager copy) — still used by cron/CLI.
- `docker/interface/crontab` — the cron entry stays.
- The `migrations/` directory and any `.sql` files.

## Git workflow

- Branch: `advisor/001-remove-public-migration-runner`
- Single commit. Message (PT-BR conventional commits, matching `git log`):
  `fix: remove runner de migrations exposto no web root (redundante com cron/CLI)`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Delete both public migration files

Delete `site/public_html/migrations.php` and `manager/public_html/migrations.php`.

**Verify**: `ls site/public_html/migrations.php manager/public_html/migrations.php 2>&1`
→ both report "No such file or directory".

**Verify**: `ls site/cgi-bin/run_migrations.php manager/cgi-bin/run_migrations.php`
→ both still listed (you did not delete the CLI runners).

### Step 2 (optional, only with operator approval): Add nginx deny rule

If the operator wants belt-and-suspenders against a future re-introduction, add to **each**
`server {}` block in `docker/interface/default.conf`, immediately after the existing
`location ~ /\. { deny all; }` block:

```
    # Bloqueia runner de migrations caso seja reintroduzido no web root
    location = /migrations.php { deny all; }
```

**Verify**: `grep -c "location = /migrations.php" docker/interface/default.conf` → `2`.

If Step 2 is not approved, skip it — Step 1 alone fully resolves the finding.

### Step 3: Static analysis still clean

Run PHPStan for both environments (commands above). Deleting unreferenced web entrypoints
must not introduce analysis errors.

**Verify**: both PHPStan runs exit 0.

## Test plan

- No new unit tests: the deleted files are standalone web entrypoints with no callers in the
  PHP codebase (verify: `grep -rn "migrations.php" --include=*.php site manager | grep -v vendor`
  returns nothing meaningful — comments only).
- Manual smoke (if a running stack is available): `curl -s -o /dev/null -w "%{http_code}" http://leggo.local/migrations.php`
  should return `403` (with Step 2) or `404` (Step 1 only). Document the result in the PR.
- Verification: both PHPStan runs exit 0; existing PHPUnit suites unchanged and still pass
  inside Docker (`docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit`).

## Done criteria

ALL must hold:

- [ ] `ls site/public_html/migrations.php manager/public_html/migrations.php 2>&1` shows neither file exists
- [ ] `ls site/cgi-bin/run_migrations.php manager/cgi-bin/run_migrations.php` shows both still exist
- [ ] `cd site && php app/inc/lib/vendor/bin/phpstan analyse` exits 0
- [ ] `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` exits 0
- [ ] No files outside the in-scope list modified (`git status`) — unless Step 2 approved, in which case only `docker/interface/default.conf` is additionally changed
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- `grep -rn "migrations.php" --include=*.php site manager | grep -v vendor` reveals that PHP
  code actually `include`s or links to the public `migrations.php` (the "Current state"
  assumption that it is a standalone entrypoint would be false).
- The cron job or CLI runner turns out to depend on the public file in any way.
- PHPStan fails after deletion (it should not — there are no inbound references).

## Maintenance notes

- For a reviewer: confirm the cron entry (`docker/interface/crontab`) and CLI runner remain,
  so automatic migrations still happen every 5 minutes.
- If a web-based migration dashboard is ever genuinely wanted, it must live behind the
  manager `$authGuard` **and** an admin-profile check **and** POST+CSRF (mirror
  `site_controller::users_action` in `manager/app/inc/controller/site_controller.php`),
  never as an unauthenticated GET in the web root.
