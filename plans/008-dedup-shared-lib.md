# Plan 008: Shared framework code can no longer silently drift between environments

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done. **Read "Why this matters" carefully — the safe,
> required deliverable is the drift guard (Steps 1–3). The structural de-duplication
> (Step 4) is an OPTIONAL spike that must not be started without explicit operator approval.**
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/app/inc/lib manager/app/inc/lib site/app/inc/model manager/app/inc/model .githooks`
> On any change, re-read the relevant excerpts against live code first.

## Status

- **Priority**: P3
- **Effort**: L
- **Risk**: MED
- **Depends on**: 002 (verification baseline)
- **Category**: tech-debt
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

`manager/app/inc/lib/*` and `site/app/inc/lib/*` (and `.../model/*`) are **byte-for-byte
identical** today — `CommonFunctions.php`, `DOLModel.php`, `localPDO.php`, `RedisCache.php`,
`Dispatcher.php`, `MigrationRunner.php`, `EmailProducer.php`, `Logger.php`, `rootOBJ.php`, and
the three models. Every framework fix must be applied to both copies (every plan in this set
says so), and nothing currently *enforces* that. The two `auth_controller.php` files have
**already legitimately diverged** (manager added an admin-profile check) — proving the trees
do drift, and that "just symlink everything" is wrong: some files are intentionally per-env.

The maintaining team has decided (AGENTS.md: "Same code structure, different `kernel.php` and
routes") to keep two environments. So the goal is **not** to collapse them. The goal is to
stop the *shared* files from drifting **accidentally**, while leaving the *intentionally
divergent* files (controllers, routes, views, kernel) per-env.

The low-risk, high-value deliverable is an automated check that fails CI / the pre-commit hook
when a file that is supposed to be shared differs between the two trees. That catches the
exact failure mode (someone edits one copy, forgets the other) with near-zero risk. Actual
physical de-duplication (symlinks / shared include path / Composer package) is a larger,
riskier restructuring and is scoped here only as an optional, separately-approved spike.

## Current state

- Identical shared files (verified at commit `ccc4095` with `diff -rq manager/app/inc/lib
  site/app/inc/lib` and `... model` → no differences reported except none):
  - `lib/`: `CommonFunctions.php`, `DOLModel.php`, `localPDO.php`, `RedisCache.php`,
    `Dispatcher.php`, `MigrationRunner.php`, `EmailProducer.php`, `Logger.php`, `rootOBJ.php`
  - `model/`: `users_model.php`, `profiles_model.php`, `messages_model.php`
- **Intentionally per-env** (do NOT force these equal):
  - `controller/auth_controller.php` (manager has the admin-profile gate), `controller/site_controller.php`
  - `public_html/index.php` (routes differ), `app/inc/urls.php`, `kernel.php`, all `ui/` views.
- Hooks live in `.githooks/` (`pre-commit` runs PHPStan; `pre-push` runs PHPUnit). `pre-commit`
  is a bash script — read it before extending it.
- Test bootstraps differ trivially (`HTTP_HOST` string), so the `tests/` dir is shared in
  spirit but not byte-identical — **exclude `tests/` from the equality check.**

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| List shared-file diffs | `diff -rq manager/app/inc/lib site/app/inc/lib` | (no output = in sync) |
| Same for models | `diff -rq manager/app/inc/model site/app/inc/model` | (no output = in sync) |
| Run the new guard script | `bash bin/check-shared-sync.sh` | exit 0 when synced, non-zero + diff list when not |
| PHPStan (both) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (and manager) | exit 0 |

## Scope

**In scope (required deliverable, Steps 1–3)**:
- A new script `bin/check-shared-sync.sh` that diffs the shared `lib/` and `model/` trees and
  fails on any difference.
- `.githooks/pre-commit` — invoke the script so a one-sided edit is blocked at commit time.
- `AGENTS.md` — document the shared-vs-per-env file split and the guard.

**Out of scope unless Step 4 is explicitly approved**:
- Any symlink, shared include path, Composer-package extraction, or physical removal of a
  duplicated file.
- Touching `controller/`, `index.php`, `urls.php`, `kernel.php`, `ui/`, or `vendor/`.

## Git workflow

- Branch: `advisor/008-dedup-shared-lib`
- Commit (required part): `chore: adiciona guard que impede drift entre lib/model de manager e site`
- Do NOT push/PR unless instructed.

## Steps

### Step 1: Write the shared-sync guard script

Create `bin/check-shared-sync.sh`. It must compare the directories that are meant to be
identical and exit non-zero (printing the offending files) if any differ. Shape:

```bash
#!/bin/bash
# Falha se arquivos compartilhados entre manager/ e site/ divergirem.
# lib/ e model/ DEVEM ser identicos; controller/, index.php, kernel.php, ui/ NAO.
set -e
status=0
for sub in app/inc/lib app/inc/model; do
  if ! diff -rq "manager/$sub" "site/$sub" > /dev/null; then
    echo "DRIFT em $sub entre manager/ e site/:"
    diff -rq "manager/$sub" "site/$sub" || true
    status=1
  fi
done
exit $status
```

Mark it executable (`chmod +x bin/check-shared-sync.sh`).

**Verify**: `bash bin/check-shared-sync.sh; echo "exit=$?"` → `exit=0` on the current
(in-sync) tree.

**Verify (negative test)**: temporarily append a comment to one copy of `Logger.php`, run the
script → it exits non-zero and lists `Logger.php`. **Revert the temporary edit** before
proceeding.

### Step 2: Wire it into the pre-commit hook

Read `.githooks/pre-commit`. Add a call to `bin/check-shared-sync.sh` (before or after the
PHPStan loop) that blocks the commit if it fails, mirroring the existing red/green messaging
style in that file.

**Verify**: `grep -c "check-shared-sync" .githooks/pre-commit` → `1`.

### Step 3: Document the shared/per-env split

In `AGENTS.md`, add a short subsection stating which paths are shared (must stay identical:
`app/inc/lib`, `app/inc/model`) and which are intentionally per-env (`controller`, `index.php`,
`urls.php`, `kernel.php`, `ui/`), and that `bin/check-shared-sync.sh` enforces the former.

**Verify**: `grep -c "check-shared-sync" AGENTS.md` → at least `1`.

### Step 4 (OPTIONAL SPIKE — requires explicit operator approval, do NOT do by default)

Only if the operator says to proceed: investigate physically de-duplicating the shared `lib/`
and `model/` trees. Produce a written comparison (do not implement blindly) of at least:
- **Symlink** one tree to the other (simple, but breaks on some deploy targets / Windows checkouts).
- **Shared top-level dir** (e.g. `shared/`) referenced via the autoloader `m_autoload` in
  `CommonFunctions.php` (which currently resolves `cRootServer_APP . "/inc/{lib,model}/"`) and
  the test bootstrap — note the autoloader path change is the risky part.
- **Composer path/package** for the framework.
Each option: blast radius (files touched), Docker volume/mount implications, and how the test
bootstrap + `vendor/` autoload would need to change. End with a recommendation. Implementation,
if any, becomes its own follow-up plan — not this one.

**Verify**: a markdown findings doc exists (e.g. `plans/008-spike-notes.md`) with the
three options evaluated. No source files restructured under this plan.

## Test plan

- The guard itself is the test: Step 1's positive (in-sync → exit 0) and negative
  (introduce drift → non-zero, then revert) checks.
- No application behavior changes in the required deliverable, so PHPStan/PHPUnit must remain
  green (run them to confirm nothing was disturbed).

## Done criteria

Required (Steps 1–3) — ALL must hold:

- [ ] `bin/check-shared-sync.sh` exists, is executable, exits 0 on the in-sync tree
- [ ] Negative test confirmed: an introduced diff makes it exit non-zero (and was reverted)
- [ ] `.githooks/pre-commit` calls the script and blocks on failure
- [ ] `AGENTS.md` documents the shared vs per-env split and the guard
- [ ] Both `phpstan analyse` runs still exit 0; no `app/inc/` source changed
- [ ] `plans/README.md` status row updated

Optional Step 4 only if approved — a spike notes doc exists; no restructuring committed.

## STOP conditions

- `diff -rq` shows the shared trees are **already** divergent at the start → stop and report
  the differing files; reconcile (decide which copy is correct) before adding a guard that
  would otherwise block every commit.
- Any temptation to make `controller/auth_controller.php` identical between envs → STOP, that
  divergence is intentional (manager admin gate). The guard must NOT cover `controller/`.
- Step 4 work begins without explicit operator approval → stop; the required deliverable is
  Steps 1–3 only.

## Maintenance notes

- Once the guard exists, every future framework plan's "edit both copies" instruction is
  enforced automatically — the pre-commit hook fails if you forget one.
- If Step 4 ever proceeds and physically unifies the trees, retire `bin/check-shared-sync.sh`
  (it becomes meaningless) and update AGENTS.md.
- Reviewer: confirm the guard excludes `tests/` (bootstraps differ by `HTTP_HOST`) and
  `controller/` (intentional divergence).
