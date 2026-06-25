# Plan 006: `load_data()` only issues the COUNT query when the caller needs it

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/app/inc/lib/DOLModel.php manager/app/inc/lib/DOLModel.php`
> On any change, re-read the excerpt below against live code before editing.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: 002 (verification baseline) — and ideally 004 (PK guard) lands first
- **Category**: perf
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

`DOLModel::load_data()` runs **two** queries on every call: the data SELECT, then a second
`SELECT count(...)` to populate `$this->recordset`. The recordset is only meaningful for
paginated listings, but the count is paid unconditionally — every `_current_data`,
every `attach`-driven single-row fetch, every existence check (`isset($users->data[0]["idx"])`
in the auth flows) doubles its DB round-trips. The dashboard, for example, loads all users and
then counts them in PHP anyway (`count($users)` in `site_controller::dashboard`), so the SQL
count is pure waste there.

Making the count opt-in roughly halves DB round-trips for the common single-row / full-list
reads, with no behavior change for code that actually reads `$model->recordset`.

This is MED risk because `load_data()` is the framework's hottest method and `recordset` may
be read in places not obvious from a grep (e.g. inside view templates). The plan therefore
**defaults to preserving today's behavior** and only skips the count when a caller explicitly
opts out — so nothing breaks unless a caller asks for the optimization.

## Current state

Both copies identical. `site/app/inc/lib/DOLModel.php` `load_data()` (lines 245-267) runs the
count in both the prepared and legacy branches — see the excerpt in
`plans/004-guard-loaddata-pk.md` for the full method. `recordset` is set via
`$this->set_recordset(...)` and read elsewhere as `$model->recordset` / via `return_data()`
(line 188-192) which returns `array($this->recordset, $this->data)`.

Callers that rely on the count (do NOT break these):
- `return_data()` returns `[$recordset, $data]` — any caller of `return_data()` expects a real count.
- Any listing/pagination view that prints a total.

Callers that clearly do NOT need it (the win):
- `_current_data()` (line 203) — single row, count irrelevant.
- The auth/controller existence checks that only read `$model->data[0]`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Full suite (Docker) site | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` | all pass |
| Full suite (Docker) manager | `docker exec leggo php /var/www/leggo/manager/app/inc/lib/vendor/bin/phpunit` | all pass |
| Find recordset readers | `grep -rn -E "recordset\|return_data" --include="*.php" site manager \| grep -v vendor` | review list |

## Scope

**In scope**:
- `site/app/inc/lib/DOLModel.php` and `manager/app/inc/lib/DOLModel.php` — `load_data()`
  signature + body; `_current_data()` to opt out of the count.
- `site/tests/UsersModelTest.php` and `manager/tests/UsersModelTest.php` — add coverage.

**Out of scope**:
- Changing `return_data()`'s contract (must still return a real recordset).
- Any controller/view change beyond what `_current_data` needs.
- Removing the count entirely — it must remain the default.

## Git workflow

- Branch: `advisor/006-optional-count-query`
- Commit: `perf: load_data() torna a query de count opcional (default mantem comportamento)`
- Edit both `DOLModel.php` copies identically in the same commit.

## Steps

### Step 1: Map every recordset reader before touching anything

Run the `recordset|return_data` grep (table above). Write the list into the PR description.
This is the blast radius; you must be sure the default path keeps these working.

**Verify**: you have an explicit list of every place `recordset` or `return_data()` is read.

### Step 2: Add an opt-out parameter to `load_data()`

Change the signature to default to current behavior:

```php
public function load_data(bool $withCount = true): void
```

Wrap each `set_recordset(...)` call so the count query is only executed when `$withCount` is
true. When `$withCount` is false, set the recordset to the number of rows already fetched
(cheap, no extra query):

```php
if ($withCount) {
    // ... existing count query ...
} else {
    $this->set_recordset(count($this->data));
}
```

Do this in **both** the `filterParams` branch and the legacy branch. Apply identically to the
manager copy.

**Verify**: `grep -n "withCount" site/app/inc/lib/DOLModel.php manager/app/inc/lib/DOLModel.php`
→ present in both; default `true`.

### Step 3: Opt the single-row helper out

In `_current_data()` (which fetches one row and never needs a global count), call
`$this->load_data(false);`.

**Verify**: `_current_data` calls `load_data(false)` in both copies.

### Step 4: Tests prove default unchanged + opt-out works

In both `UsersModelTest.php`, add:

```php
public function testLoadDataDefaultPopulatesRecordset(): void
{
    $model = new users_model();
    $model->set_field([" idx "]);
    $model->set_filter(["active = 'yes'"]);
    $model->set_paginate([5]);
    $model->load_data();                 // default: real count
    $this->assertIsInt($model->recordset);
}

public function testLoadDataWithoutCountUsesRowCount(): void
{
    $model = new users_model();
    $model->set_field([" idx "]);
    $model->set_filter(["active = 'yes'"]);
    $model->set_paginate([3]);
    $model->load_data(false);            // opt-out: recordset == rows fetched
    $this->assertSame(count($model->data), $model->recordset);
}
```

(Adjust property access to match how `recordset` is exposed — it is set via `set_recordset`
and read as `$model->recordset` elsewhere; confirm with the Step 1 grep.)

**Verify**: both full suites pass in Docker, including the two new tests.

### Step 5: Static analysis clean

Both PHPStan runs exit 0.

## Test plan

- New: `testLoadDataDefaultPopulatesRecordset` (default path still produces a count) and
  `testLoadDataWithoutCountUsesRowCount` (opt-out path returns fetched-row count, no second
  query) — in both env test files.
- Pattern: existing `UsersModelTest` (extends `DBTestCase`).
- Regression guard: the entire existing suite must stay green — any view or controller that
  reads `recordset`/`return_data()` exercises the default path, which is unchanged.

## Done criteria

ALL must hold:

- [ ] `load_data(bool $withCount = true)` in both copies; default preserves the count query
- [ ] `_current_data()` calls `load_data(false)` in both copies
- [ ] Two new tests present and passing in both env suites (Docker)
- [ ] Full PHPUnit suite green for both envs
- [ ] Both `phpstan analyse` runs exit 0
- [ ] `diff` of the two `DOLModel.php` copies shows no difference
- [ ] `plans/README.md` status row updated

## STOP conditions

- Step 1 reveals a `recordset` reader on a path that calls `_current_data()` (i.e. flipping it
  to `load_data(false)` would change a real count someone reads) → stop and report; that
  caller needs the count and the opt-out target is wrong.
- Any existing test fails after the change → the default path is not actually behavior-
  preserving; stop and report.
- The two `DOLModel.php` copies differ before you start → stop (drift); reconcile separately.

## Maintenance notes

- Interacts with plan 004 (`$countExpr` PK guard): if 004 has landed, keep its fallback inside
  the `$withCount` branch. If 004 has not landed, do not introduce the PK bug — keep using the
  same count expression that exists today.
- Future: callers doing paginated lists should pass `load_data(true)` explicitly (it is the
  default, so existing code is fine) and single-row/existence checks should pass `false`.
- Reviewer: scrutinize that no view template silently depended on `recordset` from a
  `_current_data()` result.
