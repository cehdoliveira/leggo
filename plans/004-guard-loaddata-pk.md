# Plan 004: `load_data()` no longer assumes a primary key exists

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/app/inc/lib/DOLModel.php manager/app/inc/lib/DOLModel.php`
> On any change, re-read the excerpt below against live code before editing.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

`DOLModel::load_data()` builds a `count(...)` query using `implode(",", $this->keys["pk"])`.
`$this->keys["pk"]` is only populated when the table's `SHOW COLUMNS` reports a `PRI` key
(see the constructor). A table with **no** primary key (a junction table defined without one,
a view, a future schema mistake) leaves `$this->keys["pk"]` **unset**, so `implode()` receives
`null` → TypeError / SQL error on an otherwise valid read.

All current tables have an `idx` primary key, so this is latent — but it is a sharp edge in
the framework's single most-called method, and the guard is cheap. PHPStan at a higher level
(plan 002) would also flag the unguarded array access.

## Current state

Both copies identical. `site/app/inc/lib/DOLModel.php` `load_data()`, lines 245-267:

```php
public function load_data(): void
{
    $ff = isset($this->field) ? implode(",", $this->field) : " * ";
    $fi = isset($this->filter) ? " where " . implode(" and ", $this->filter) . " " : "";
    $or = isset($this->order) ? " order by " . implode(" , ", $this->order) . " " : "";
    $gp = isset($this->group) ? " group by " . implode(" , ", $this->group) . " " : "";
    $pa = isset($this->paginate) ? " limit " . implode(" , ", $this->paginate) . " " : "";

    if (!empty($this->filterParams)) {
        $sql = sprintf("SELECT %s FROM %s %s %s %s %s", $ff, $this->table, $fi, $gp, $or, $pa);
        $r = $this->con->executePrepared($sql, $this->filterParams);
        $this->set_data($this->con->results($r));

        $countSql = sprintf("SELECT count( %s ) as q FROM %s %s %s",
            implode(",", $this->keys["pk"]), $this->table, $fi, $gp);   // <-- pk assumed
        $countStmt = $this->con->executePrepared($countSql, $this->filterParams);
        $this->set_recordset($this->con->result($countStmt, "q", 0));
    } else {
        $r = $this->con->select($ff, $this->table, $fi . $gp . $or . $pa);
        $this->set_data($this->con->results($r));
        $this->set_recordset($this->con->result($this->con->select(" count( " . implode(",", $this->keys["pk"]) . ") as q ", $this->table, $fi . $gp), "q", 0));   // <-- pk assumed
    }
}
```

The constructor (lines 24-33) builds `$this->keys`:
```php
$keys = array();
foreach ($this->schema as $key => $value) {
    if (isset($value["PK"])) { $keys["pk"][] = $key; }
    if (isset($value["UNI"])) { $keys["UNI"][] = $key; }
}
$this->set_keys($keys);
```
So `$keys["pk"]` is absent when no column is `PRI`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Model tests (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit --filter UsersModel` | all pass |
| Same, manager | `docker exec leggo php /var/www/leggo/manager/app/inc/lib/vendor/bin/phpunit --filter UsersModel` | all pass |

## Scope

**In scope**:
- `site/app/inc/lib/DOLModel.php` — `load_data()` count expression
- `manager/app/inc/lib/DOLModel.php` — identical edit (shared file — both copies!)

**Out of scope**:
- Changing the count semantics for tables that *do* have a PK (current behavior must be byte-identical for `users`).
- Plan 006 (making the count optional) — that is a separate change; keep this one minimal.

## Git workflow

- Branch: `advisor/004-guard-loaddata-pk`
- Commit: `fix: load_data() usa count(*) quando tabela nao tem primary key`
- Edit both copies in the same commit.

## Steps

### Step 1: Compute a safe count expression once

At the top of `load_data()`, derive a count target that falls back to `*` when no PK exists:

```php
$countExpr = (isset($this->keys["pk"]) && !empty($this->keys["pk"]))
    ? implode(",", $this->keys["pk"])
    : "*";
```

Then replace both `implode(",", $this->keys["pk"])` occurrences with `$countExpr`. For a table
that has a PK, `count(idx)` and the new value are identical, so behavior is unchanged for all
existing tables. For a PK-less table, it becomes `count(*)` instead of erroring.

Apply the identical change to the manager copy.

**Verify**: `grep -c 'keys\["pk"\]' site/app/inc/lib/DOLModel.php` → the only remaining hit
is inside the new `$countExpr` guard (the two raw `implode` uses are gone). Same for manager.

### Step 2: Regression — existing model reads unchanged

Run the `UsersModel` filtered suite for both environments (table above). `users` has a PK, so
recordset counts must be exactly as before.

**Verify**: both `--filter UsersModel` runs pass.

### Step 3: Static analysis clean

Run both PHPStan commands → exit 0.

## Test plan

- Primary verification is the existing `UsersModelTest` (it exercises `load_data()` with and
  without `filterParams`, via `testModelCanLoadData`, `testModelDefaultFilterOnlyActive`,
  `testSetFilterWithParams`) — these must stay green, proving no regression for PK tables.
- A dedicated PK-less-table test is **not** required (it would need a throwaway table the test
  harness doesn't provide). If you want one, it must create a `TEMPORARY TABLE` without a PK
  inside a `DBTestCase` and assert `load_data()` does not throw — only add this if it runs
  cleanly in Docker; otherwise rely on the guard + PHPStan.

## Done criteria

ALL must hold:

- [ ] Both `DOLModel.php` copies compute `$countExpr` with the PK-empty fallback to `"*"`
- [ ] Neither copy contains a raw `implode(",", $this->keys["pk"])` in `load_data()`
- [ ] `--filter UsersModel` passes for site and manager in Docker
- [ ] Both `phpstan analyse` runs exit 0
- [ ] `diff site/app/inc/lib/DOLModel.php manager/app/inc/lib/DOLModel.php` shows no difference
- [ ] `plans/README.md` status row updated

## STOP conditions

- The two `DOLModel.php` copies differ before you start → stop and report (drift).
- `UsersModelTest` recordset assertions change value after your edit → the count semantics
  shifted for PK tables; stop and report (the fallback must be a no-op when a PK exists).

## Maintenance notes

- Plan 006 will make this count query opt-in; when it lands, `$countExpr` should move with the
  count logic. Note the interaction for whoever does 006.
- Reviewer: confirm both env copies changed identically.
