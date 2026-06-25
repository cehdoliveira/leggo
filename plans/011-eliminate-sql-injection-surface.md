# Plan 011: Eliminate the legacy unparameterized SQL path in `localPDO` / `DOLModel`

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done. This plan deletes code paths — be conservative and
> stop at the first sign that a path is still in use.
>
> **Drift check (run first)**:
> `git diff --stat 86e28f1..HEAD -- site/app/inc/lib/localPDO.php site/app/inc/lib/DOLModel.php manager/app/inc/lib/localPDO.php manager/app/inc/lib/DOLModel.php`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (deletes query helpers; must prove no live callers first)
- **Depends on**: none (do plan 002's `bin/test.sh` baseline first if not already in place)
- **Category**: security (SQL injection, CWE-89) + tech-debt
- **Planned at**: commit `86e28f1`, 2026-06-25

## Why this matters

The framework has **two** query paths. The safe one (`executePrepared` with bound `?`
params) is used by the current models. The legacy one builds SQL by string concatenation
with **zero escaping** and runs it via `PDO::query()`:

- `localPDO::select/insert/update/delete/replace` (each `sprintf`s fields/table/options
  into raw SQL) and `localPDO::my_query()` / `query()` execute whatever string they're handed.
- `localPDO::real_escape_string()` does `trim($pdo->quote($s), "'")` — a footgun that strips
  the outer quotes PDO added, so it does **not** safely escape values containing quotes.
- `DOLModel::load_data()` has an `else` branch (the no-`filterParams` case) that routes
  straight through `select()` with the raw `$this->filter` strings interpolated into the WHERE.

As long as these exist, any caller that does `set_filter(["idx = " . $_GET['id']])` (legacy
string mode, documented as "backward compat" in AGENTS.md) injects SQL, and the legacy
`select/insert/...` helpers are an open invitation to interpolate user input. The models in
the repo today happen to use the safe path — so the legacy path is **dead weight that only
adds risk**. Deleting it removes the framework's entire SQL-injection surface and roughly
halves `localPDO`.

This is the highest-leverage security move in the framework: it deletes a class of
vulnerability rather than patching instances.

## Current state

`site/app/inc/lib/localPDO.php` (identical to manager copy), lines ~78-160:

```php
public function real_escape_string(string $string): string
{
  return trim($this->pdo->quote($string), "'");
}

public function select(string $fields, string $table, string $options): \PDOStatement|false
{
  $res = $this->my_query(sprintf("SELECT %s FROM %s %s", $fields, $table, $options));
  return $res;
}
public function insert(string $fields, string $table, string $options): \PDOStatement|false { /* sprintf INSERT ... my_query */ }
public function replace(string $fields, string $table): \PDOStatement|false { /* sprintf REPLACE ... my_query */ }
public function update(string $fields, string $table, string $options): \PDOStatement|false { /* sprintf UPDATE ... my_query */ }
public function delete(string $table, string $options): \PDOStatement|false { /* sprintf DELETE ... my_query */ }

public function my_query(string $query): \PDOStatement
{
  try { return $this->pdo->query($query); }
  catch (PDOException $e) { /* rollback + Logger + throw RuntimeException */ }
}
public function query(string $query): \PDOStatement { return $this->my_query($query); }
```

`site/app/inc/lib/DOLModel.php`, `load_data()` lines ~245-267 — the `else` branch is the only
caller of `select()` left in model code:

```php
if (!empty($this->filterParams)) {
    $sql = sprintf("SELECT %s FROM %s %s %s %s %s", $ff, $this->table, $fi, $gp, $or, $pa);
    $r = $this->con->executePrepared($sql, $this->filterParams);
    $this->set_data($this->con->results($r));
    $countSql = sprintf("SELECT count( %s ) as q FROM %s %s %s",
        implode(",", $this->keys["pk"]), $this->table, $fi, $gp);
    $countStmt = $this->con->executePrepared($countSql, $this->filterParams);
    $this->set_recordset($this->con->result($countStmt, "q", 0));
} else {
    $r = $this->con->select($ff, $this->table, $fi . $gp . $or . $pa);   // <-- legacy raw path
    $this->set_data($this->con->results($r));
    $this->set_recordset($this->con->result($this->con->select(" count( " . implode(",", $this->keys["pk"]) . ") as q ", $this->table, $fi . $gp), "q", 0));
}
```

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Find callers of legacy helpers | `grep -rn -E "->(select\|insert\|update\|delete\|replace\|my_query\|query\|real_escape_string)\(" --include="*.php" site manager \| grep -v vendor \| grep -v executePrepared` | classify each (see Step 1) |
| Find raw `set_filter` (no params) | `grep -rn "set_filter(" --include="*.php" site manager \| grep -v vendor` | inspect each for user input concatenation |
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (and manager) | exit 0 |
| Unit suite (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` (and manager) | all pass |

## Scope

**In scope**:
- `site/app/inc/lib/localPDO.php` + `manager/app/inc/lib/localPDO.php` (identical edits).
- `site/app/inc/lib/DOLModel.php` + `manager/app/inc/lib/DOLModel.php` (identical edits) — collapse `load_data`'s dual path to the prepared path only.

**Out of scope**:
- Changing the public `set_filter(array $conditions, array $params = [])` signature. Keep it.
  This plan only removes the *unparameterized execution path*; static literal filters like
  `"active = 'yes'"` (no user data) still work because they go through `executePrepared` with
  an empty params array.
- `save()` / `remove()` — they already use `executePrepared`. Do not touch (plan 015 owns the
  `save()` INSERT/UPDATE heuristic separately).
- `attach()` / `join()` / `attach_son()` / `save_attach()` — they already bind values via `?`.
  Identifier hardening for those is a smaller, separate concern; note it in Maintenance, do not
  expand scope here.

## Git workflow

- Branch: `advisor/011-eliminate-sql-injection-surface`
- Commit: `refactor: remove caminho SQL legado nao parametrizado (localPDO/DOLModel)`
- Edit all four files in one commit.

## Steps

### Step 1: Prove the legacy helpers have no legitimate live callers — THIS GATES EVERYTHING

Run the caller grep (table above). Classify every hit:

- A call to `executePrepared` / `executeMigration` / `lastInsertId` / `results` / `result` /
  `fields_config` / `beginTransaction` / `commit` / `rollback` → **not** a legacy-path call, ignore.
- A call to `->select(`, `->insert(`, `->update(`, `->delete(`, `->replace(`, `->my_query(`,
  `->query(`, or `->real_escape_string(` from **application/model/controller code** → this is a
  live caller of the path you intend to delete.

**If any live application caller exists** (other than `DOLModel::load_data`'s own `else` branch,
which Step 3 removes): **STOP**. Each such caller must first be migrated to `executePrepared`
with bound params — that is a separate change the operator should scope. Do not delete a method
that is still called, and do not silently rewrite an unrelated controller in this plan.

Also inspect every `set_filter(` call for `. $_GET` / `. $_POST` / `. $request`-style
concatenation of user input into a filter string. If found, **STOP and report** — that call site
is an active SQL-injection bug that needs migration to the `?`+params form *before* this cleanup.

**Verify**: you have a written list proving the only caller of `select()` is `DOLModel::load_data`'s
`else` branch, and no `set_filter` concatenates request data.

### Step 2: Delete the legacy methods from `localPDO` (both copies)

Remove these methods entirely: `real_escape_string`, `select`, `insert`, `replace`, `update`,
`delete`, `my_query`, and the thin `query` alias.

Keep everything else: the connection/transaction control, `executePrepared`, `results`, `result`,
`recordcount`, `fields_config`, `lastInsertId`. If `executePrepared`'s error handling duplicated
the `my_query` catch block, ensure `executePrepared` still has its own try/catch (it does — verify).

**Verify**: `grep -nE "function (select|insert|update|delete|replace|my_query|real_escape_string)\b" site/app/inc/lib/localPDO.php` → no matches.

### Step 3: Collapse `DOLModel::load_data` to the prepared path only

Replace the whole `if (!empty($this->filterParams)) { ... } else { ... }` block with the
single prepared-statement path. `executePrepared` with an empty params array runs static
filters fine, so the `else` branch is no longer needed:

```php
public function load_data(): void
{
    $ff = isset($this->field) ? implode(",", $this->field) : " * ";
    $fi = isset($this->filter) ? " where " . implode(" and ", $this->filter) . " " : "";
    $or = isset($this->order) ? " order by " . implode(" , ", $this->order) . " " : "";
    $gp = isset($this->group) ? " group by " . implode(" , ", $this->group) . " " : "";
    $pa = isset($this->paginate) ? " limit " . implode(" , ", $this->paginate) . " " : "";

    $sql = sprintf("SELECT %s FROM %s %s %s %s %s", $ff, $this->table, $fi, $gp, $or, $pa);
    $r = $this->con->executePrepared($sql, $this->filterParams);
    $this->set_data($this->con->results($r));

    $countSql = sprintf("SELECT count( %s ) as q FROM %s %s %s",
        implode(",", $this->keys["pk"]), $this->table, $fi, $gp);
    $countStmt = $this->con->executePrepared($countSql, $this->filterParams);
    $this->set_recordset($this->con->result($countStmt, "q", 0));
}
```

Note: `$this->filterParams` defaults to `[]` (see `rootOBJ`), so the prepared call is always valid.
This also fixes the latent `$this->keys["pk"]` access — leave that as-is here; plan 004 owns the
missing-PK guard and rebases on this.

**Verify**: `grep -n "->select(" site/app/inc/lib/DOLModel.php` → no matches.

### Step 4: Keep both copies identical

`diff site/app/inc/lib/localPDO.php manager/app/inc/lib/localPDO.php` → no difference.
`diff site/app/inc/lib/DOLModel.php manager/app/inc/lib/DOLModel.php` → no difference.

### Step 5: Static analysis + full suite

Both PHPStan runs exit 0. Full PHPUnit suite passes in Docker for both envs. Pay special
attention to `UsersModelTest` and `DBTestCase`-based tests — they exercise `load_data`.

## Test plan

- The existing `UsersModelTest` already drives `load_data` through models; it must still pass
  unchanged — that is the regression gate.
- Add one test to `UsersModelTest` (both copies) that loads with a **static** filter
  (`set_filter(["active = 'yes'"])`, no params) and asserts rows return — proving the collapsed
  single-path `load_data` still serves the legacy static-filter case after the `else` branch is gone.
- No test should be added for the deleted `select/my_query/...` methods.

## Done criteria

ALL must hold:

- [ ] Step 1 produced a written caller inventory and found no live application caller of the deleted methods
- [ ] `grep -nE "function (select|insert|update|delete|replace|my_query|real_escape_string)\b" site manager | grep -v vendor` → no matches
- [ ] `grep -rn "->select(\|->my_query(\|->real_escape_string(" site manager | grep -v vendor` → no matches
- [ ] `load_data` has a single prepared path (no `else` raw branch)
- [ ] Both `localPDO.php` copies identical; both `DOLModel.php` copies identical
- [ ] Both `phpstan analyse` exit 0
- [ ] Full PHPUnit suite passes in Docker for both envs
- [ ] `plans/README.md` status row updated

## STOP conditions

- Any live application/controller/model caller of `select/insert/update/delete/replace/my_query/query/real_escape_string` exists → STOP; migration of that caller is out of scope.
- Any `set_filter(...)` concatenates `$_GET`/`$_POST`/request data → STOP and report (active injection bug to fix first).
- A PHPUnit test fails after the `load_data` collapse → STOP; the dual path was load-bearing in a way this plan missed. Report the failing test.
- The two copies of either file differ before you start → STOP (pre-existing drift).

## Maintenance notes

- After this lands, the **only** way to run SQL is `executePrepared` / `execute_raw_prepared`
  (bound params) — keep it that way. Reject any future PR that reintroduces a raw-`query` helper.
- `attach()`/`join()`/`attach_son()`/`save_attach()` interpolate **identifiers**
  (`$this->table`, `$class`, junction names) into SQL. Values are bound, so this is safe **only
  while class/table names come from code, never request input**. If a future feature passes a
  user-supplied entity name into those methods, add an `assertIdentifier()` allowlist
  (`^[A-Za-z_][A-Za-z0-9_]*$`, throw on miss) before interpolation. Flagged here so a reviewer
  watches for it; not in scope now.
