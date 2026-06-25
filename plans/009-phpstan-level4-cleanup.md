# Plan 009: Codebase passes PHPStan level 4, then the level is raised

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/app/inc manager/app/inc site/phpstan.neon manager/phpstan.neon`
> On any change, regenerate the level-4 error list (Step 1) before trusting the inventory below.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED
- **Depends on**: none (but coordinate with 006 — both edit `DOLModel.php`)
- **Category**: tech-debt
- **Planned at**: commit `ccc4095`, 2026-06-22 (created from plan 002's first execution)

## Why this matters

Plan 002 tried to raise PHPStan from level 3 to 4 and found the codebase is **not clean at
level 4**: 53 errors (site) / 39 (manager) — the two trees share framework code, so the sets
overlap. The errors are benign but real, and they block the stronger static-analysis gate that
plans 006/008 would benefit from. Cleaning them up is mechanical and makes level 4 (then 5/6)
reachable. This plan fixes the dead code / redundant checks, **then** raises the level.

The dominant category is `deadCode.unreachable`: controllers call `basic_redir(...)` — which is
typed `: never` — immediately followed by `exit();`. The `exit()` is provably unreachable. The
fix is to remove the now-redundant `exit();` lines (and any other unreachable trailing code).

## Current state (level-4 error inventory, captured at commit `be54f0a`)

Run Step 1 to regenerate the authoritative list. As captured, the errors cluster as:

- **`app/inc/controller/auth_controller.php`** — ~30 `deadCode.unreachable` (site lines incl.
  40, 48, 87, 113, 127, 180, 185, 189, 195, 206, 229 … 588), each an `exit();` after a
  `basic_redir(...)` (`: never`) call. Plus `isset.offset` at site:143 / manager:157
  (`isset($x['idx'])` where the offset always exists and is non-nullable).
- **`app/inc/controller/site_controller.php`** (manager) — `deadCode.unreachable` at 58, 65.
- **`app/inc/lib/CommonFunctions.php`** — `identical.alwaysTrue` @37, `deadCode.unreachable`
  @44 and @447, `function.alreadyNarrowedType` @266, `identical.alwaysFalse` @374,
  `booleanAnd.alwaysFalse` + `notIdentical.alwaysFalse` @462.
- **`app/inc/lib/DOLModel.php`** — 7× `isset.property` / `booleanAnd.rightAlwaysTrue` at
  58, 80, 124, 167, 247, 248 (`isset()` on `rootOBJ` properties typed non-nullable `array`).
- **`app/inc/lib/Dispatcher.php:125`** — `function.alreadyNarrowedType` (`is_array` on a value
  already a non-empty-list).
- **`app/inc/lib/EmailProducer.php:246`** — `notIdentical.alwaysTrue` (`!== null` on a value
  already typed object).
- **`app/inc/lib/MigrationRunner.php`** — `property.unusedType` @7, `booleanAnd.leftAlwaysTrue`
  @12, `if.alwaysTrue` @14.

`basic_redir` is declared `function basic_redir(...): never` in `CommonFunctions.php:172` — this
is why the trailing `exit();` calls are unreachable. Confirm before deleting any `exit()`.

**Reminder**: `app/inc/lib/*` and `app/inc/model/*` are byte-identical between `manager/` and
`site/` — every lib fix must be applied to **both** copies. Controllers differ between envs, so
fix each controller in its own tree.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Setup (gitignored) | `cp site/app/inc/kernel.php.example site/app/inc/kernel.php` (+manager); `composer install -d site/app/inc/lib --ignore-platform-req=ext-gd` (+manager) | tooling present |
| Level-4 error list (site) | `cd site && php app/inc/lib/vendor/bin/phpstan analyse --level 4 --no-progress --error-format=raw` | full list |
| Level-4 error list (manager) | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse --level 4 --no-progress --error-format=raw` | full list |
| Level-3 still green | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (+manager) | `[OK] No errors` |
| PHPUnit (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` (+manager) | all pass |

## Scope

**In scope**:
- `site/app/inc/controller/auth_controller.php`, `manager/app/inc/controller/auth_controller.php`,
  `manager/app/inc/controller/site_controller.php` — remove unreachable code only.
- `site/app/inc/lib/*` + `manager/app/inc/lib/*` (the files named above) — remove dead
  `isset()`/redundant type checks only.
- `site/phpstan.neon`, `manager/phpstan.neon` — level bump (final step).

**Out of scope**:
- Any behavioral change. Removing an unreachable `exit()` after a `never`-returning call does
  not change runtime behavior — that is the whole point. Do **not** alter control flow,
  conditionals that have side effects, or anything that changes what the code *does*.
- `DOLModel.php` changes that conflict with plan 006 — if 006 has landed, rebase on it; if not,
  keep edits limited to the dead `isset()` lines and leave `load_data` structure alone.

## Git workflow

- Branch: harness worktree branch you are on.
- Commit (can be one or grouped by file): `refactor: remove codigo morto sinalizado pelo PHPStan e eleva para nivel 4`
- Edit both copies of each shared lib file identically.

## Steps

### Step 1: Regenerate the authoritative error list

Run the two `--level 4 ... --error-format=raw` commands. Save the output. This is your work
list — the inventory above is a guide, but the live list is truth (the codebase may have moved).

**Verify**: you have the full file:line:identifier list for both envs.

### Step 2: Remove unreachable `exit()` after `basic_redir(...)`

For each `deadCode.unreachable` on an `exit();` line that immediately follows a
`basic_redir(...)` call: delete the `exit();`. Do this in `auth_controller.php` (both env
copies — they differ in content, so handle each) and `site_controller.php` (manager).

Do **not** delete an `exit()` that does **not** follow a `never`-returning call — re-read the
two or three lines above each one to confirm. If an unreachable statement is something other
than a bare `exit();` (e.g. real logic), STOP and report it — that needs human judgment.

**Verify**: after this step, `deadCode.unreachable` count in the level-4 list drops to (near)
zero; no new errors introduced.

### Step 3: Remove dead `isset()` / redundant type checks in lib

For each `isset.property` / `isset.offset` / `*.alwaysTrue` / `*.alwaysFalse` /
`alreadyNarrowedType` / `property.unusedType`: make the minimal edit PHPStan asks for —
typically removing an `isset()` guard on a non-nullable property, or a redundant `is_array` /
`!== null`. Apply lib edits to **both** env copies identically.

For each one, confirm the guarded code path is genuinely always-taken (PHPStan says so, but
verify the property's declared type in `rootOBJ.php`) before removing a guard. If removing a
guard would change behavior when a property is unset at runtime (e.g. dynamic property the type
doesn't capture), STOP and report — do not weaken a real runtime guard to satisfy the analyzer.

**Verify**: level-4 list is empty for both envs.

### Step 4: Raise the level

Set `level: 4` in **both** `phpstan.neon` files. Run the plain `phpstan analyse` for both.

**Verify**: both exit 0, `[OK] No errors`, at level 4.

### Step 5: Behance unchanged — run the suite

Run both PHPUnit suites in Docker. Removing unreachable code and dead guards must not change
any test outcome.

**Verify**: both suites pass (same results as before this plan).

## Test plan

- No new tests — this is dead-code removal. The guarantee is: PHPUnit results are identical
  before and after (Step 5), and PHPStan is clean at the new level (Step 4).
- If a removed `isset()` guard turns out to have masked a real edge case, an existing test
  would fail in Step 5 — that is the safety net; treat any new failure as a STOP.

## Done criteria

ALL must hold:

- [ ] Level-4 error list is empty for both site and manager
- [ ] Both `phpstan.neon` at `level: 4`; both `phpstan analyse` exit 0
- [ ] Both PHPUnit suites pass in Docker (unchanged from before)
- [ ] `diff` of each shared lib file's two copies shows no difference
- [ ] Only files in the in-scope list modified; no behavioral/control-flow change
- [ ] `plans/README.md` status row updated

## STOP conditions

- An unreachable statement is not a bare `exit();` after a `never` call (it is real logic) → stop, report.
- Removing a dead `isset()` would change runtime behavior for an unset/dynamic property → stop, report.
- A PHPUnit test fails after the cleanup → stop, report (behavior changed — not acceptable).
- Level 4 still shows errors you cannot resolve without a behavioral change → stop, report the
  residual list; the operator decides whether to baseline them.

## Maintenance notes

- Once level 4 is clean, levels 5–6 are likely cheap follow-ups — consider a sequel plan.
- Coordinate with plan 006 on `DOLModel.php` to avoid merge conflicts; whichever lands second rebases.
- Reviewer: the key invariant is "no behavioral change" — scrutinize that every deletion is
  genuinely unreachable/dead, not a guard that fires under inputs the type system doesn't model.
