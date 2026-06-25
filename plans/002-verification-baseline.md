# Plan 002: A one-command verification baseline is documented for both environments

> **Executor instructions**: Follow this plan step by step. Run every verification command
> and confirm the expected result before moving on. If anything in "STOP conditions" occurs,
> stop and report. When done, update the status row in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/phpstan.neon manager/phpstan.neon AGENTS.md bin/`
> On any change to these paths, re-read the relevant "Current state" excerpt against live code first.
>
> **History note (2026-06-22)**: A first execution of this plan attempted to also raise PHPStan
> from level 3 → 4. Level 4 surfaced 53 errors (site) / 39 (manager) — overwhelmingly
> `deadCode.unreachable` (every `basic_redir(...); exit();` pair, because `basic_redir` is typed
> `: never`) plus dead `isset()` checks. Those require editing `app/inc/` source, which is out of
> scope here. **The level bump has been moved to plan 009.** This plan now delivers only the
> level-3 baseline documentation + a convenience script — both DB-independent and safe.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: dx
- **Planned at**: commit `ccc4095`, 2026-06-22 (revised after first execution)

## Why this matters

Risky refactors (plans 006 and 008 touch shared framework code) need a cheap, repeatable way
to know the code still works. Today the safety net is undocumented: PHPStan runs at level 3,
and PHPUnit can only run with a live MySQL connection inside the Docker `leggo` container —
there is no single documented command that says "prove the build is green." This plan writes
that command down and adds a runnable wrapper, so every later plan can point at one gate.

It does **not** change any analysis level or test (see History note — that is plan 009).

## Current state

- `site/phpstan.neon` / `manager/phpstan.neon` — both at `level: 3`, paths
  `app/inc/{controller,lib,model}`, `scanFiles` include `app/inc/kernel.php` (which is
  **gitignored** — must be copied from `.example` before PHPStan can run). Both pass clean at
  level 3 (verified: `[OK] No errors`).
- `site/tests/` and `manager/tests/` — identical test sets; `tests/bootstrap.php` loads
  `kernel.php` and `DBTestCase` opens a real `localPDO`, so the suite needs the Docker DB.
- Docker app service is named `leggo`; it bind-mounts the project tree. `AGENTS.md` has a
  `## Commands` section already listing phpstan/phpunit invocations.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Setup (gitignored, not committed) | `cp site/app/inc/kernel.php.example site/app/inc/kernel.php` (+ manager) | files exist |
| Setup vendor (gitignored) | `composer install -d site/app/inc/lib --ignore-platform-req=ext-gd` (+ manager) | phpstan present |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0, `[OK] No errors` |
| Script syntax check | `bash -n bin/test.sh` | exit 0 |

## Scope

**In scope**:
- `AGENTS.md` — add the canonical "Full verification" command block.
- `bin/test.sh` (create) — runs both PHPStan + both PHPUnit suites; exits non-zero on any failure.

**Out of scope** (do NOT touch):
- `site/phpstan.neon`, `manager/phpstan.neon` — leave at level 3 (the bump is plan 009).
- Any file under `app/inc/`, `tests/`, or Docker config.
- Rewriting `bootstrap.php`/`DBTestCase`.

## Git workflow

- Branch: the harness-provided worktree branch you are already on (do not create a divergent branch).
- Commit message: `chore: documenta baseline de verificacao e adiciona bin/test.sh`
- Do NOT push/PR.

## Steps

### Step 1: Document the canonical verification command

In `AGENTS.md`, under the existing `## Commands` section, add a clearly labeled block:

```bash
# Full verification (run before merging framework changes)
cd site && php app/inc/lib/vendor/bin/phpstan analyse
cd manager && php app/inc/lib/vendor/bin/phpstan analyse
docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit
docker exec leggo php /var/www/leggo/manager/app/inc/lib/vendor/bin/phpunit
```

**Verify**: `grep -c "Full verification" AGENTS.md` → `1`.

### Step 2: Add the convenience script

Create `bin/test.sh` that runs the four commands above and exits non-zero if any fail. Shape:

```bash
#!/bin/bash
# Verificacao completa: PHPStan (host) + PHPUnit (Docker) para manager e site.
set -e
( cd site && php app/inc/lib/vendor/bin/phpstan analyse )
( cd manager && php app/inc/lib/vendor/bin/phpstan analyse )
docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit
docker exec leggo php /var/www/leggo/manager/app/inc/lib/vendor/bin/phpunit
echo "Verificacao completa OK"
```

Mark it executable (`chmod +x bin/test.sh`). Reference it in `AGENTS.md` (one line under the block).

**Verify**: `bash -n bin/test.sh` exits 0; `test -x bin/test.sh && echo executable` prints `executable`.

(Do **not** actually run `bin/test.sh` — it invokes Docker PHPUnit, which is not reachable from
the worktree. Syntax check is sufficient.)

### Step 3: Confirm nothing else changed

**Verify**: `git status --short` shows only `AGENTS.md` (modified) and `bin/test.sh` (new) —
`phpstan.neon` files unchanged; `kernel.php`/`vendor/` are gitignored and must not appear.

## Test plan

- No test cases authored. Verification = the doc block exists, the script passes `bash -n` and
  is executable, and `git status` shows only the two intended files.

## Done criteria

ALL must hold:

- [ ] `AGENTS.md` contains the "Full verification" command block
- [ ] `bin/test.sh` exists, is executable, passes `bash -n`
- [ ] `site/phpstan.neon` and `manager/phpstan.neon` are unchanged (still `level: 3`)
- [ ] `git status --short` shows only `AGENTS.md` and `bin/test.sh`
- [ ] Commit made with the specified message
- [ ] `plans/README.md` status row updated

## STOP conditions

- `phpstan.neon` already differs from `level: 3` on HEAD → stop and report (plan 009 territory).
- php/composer unavailable so you cannot even confirm the level-3 baseline → report plainly.

## Maintenance notes

- Plan 009 raises the level once the dead-code is cleaned; when it lands, no change to this
  plan's deliverables is needed (the documented commands are level-agnostic).
- A worthwhile follow-up: make `bootstrap.php` tolerate a missing DB so pure-unit tests run on
  the host without MySQL (would let `bin/test.sh` degrade gracefully off-Docker).
