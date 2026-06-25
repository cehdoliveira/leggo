# Plan 013: Make the migration runner concurrency-safe and idempotent

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git diff --stat 86e28f1..HEAD -- site/app/inc/lib/MigrationRunner.php manager/app/inc/lib/MigrationRunner.php migrations/`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (touches the migration pipeline that runs every 5 min via cron)
- **Depends on**: none
- **Category**: correctness / reliability (concurrency + idempotency)
- **Planned at**: commit `86e28f1`, 2026-06-25

## Why this matters

`run_migrations.php` runs every 5 minutes from cron with no overlap protection, and the runner
makes two unsafe assumptions:

1. **No locking → concurrent runs race.** If a migration (or the DB) is slow and a run exceeds
   5 minutes, the next cron tick starts a second process. Both read `isExecuted()=false` for the
   same pending migration and both execute it — duplicate DDL/DML, deadlocks, duplicate
   `migrations_log` write attempts. There is no `GET_LOCK`, `flock`, or PID guard.

2. **Seed `INSERT`s are not idempotent, and "transactional" DDL is a myth in MySQL.**
   `executeMigration()` wraps each file in `beginTransaction()/commit()/rollBack()`, but MySQL
   performs an **implicit commit** before/after every DDL statement (`CREATE TABLE`, `ALTER`).
   So if a migration's `CREATE TABLE` succeeds but its subsequent `INSERT` fails, the rollback
   is a no-op for the table — the migration is recorded `failed`, `isExecuted()` stays false,
   and the next tick re-runs the file. The seed `INSERT`s in `002/003/004` have **no**
   `INSERT IGNORE` / `ON DUPLICATE KEY` guard and `profiles.slug` has **no UNIQUE constraint**,
   so re-runs silently accumulate duplicate profile rows and role assignments.

3. **Failures to record state are swallowed.** `recordMigration()` and `createMigrationsTable()`
   catch and ignore errors. A migration that applied but failed to record as `success` will
   re-run forever.

Net effect: under any slowness or transient error, the migration system can duplicate seed data
or loop. The fix is a cheap advisory lock + idempotent seed DML + loud failure on state-write.

## Current state

`site/app/inc/lib/MigrationRunner.php` (identical to manager copy):

`run()` (~lines 58-104) loops files, checks `isExecuted`, runs, records — no lock around the loop.

`executeMigration()` (~lines 203-232) splits on `;` and wraps in a transaction:

```php
private function executeMigration(string $sql): void
{
    $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
    $this->pdo->beginTransaction();
    try {
        foreach ($statements as $stmt) { $this->pdo->exec($stmt); }
        $this->pdo->commit();
    } catch (\Throwable $e) {
        $this->pdo->rollBack();   // no-op for already-committed DDL
        throw $e;
    }
}
```

`createMigrationsTable()` (~lines 141-159) and `recordMigration()` swallow exceptions
(`catch (PDOException $e) { /* ignore */ }`).

The seed migrations, e.g. `migrations/003_create_table_profiles.sql`:

```sql
CREATE TABLE IF NOT EXISTS `profiles` (
  ...
  `slug` VARCHAR(255) NOT NULL,     -- no UNIQUE
  ...
);
INSERT INTO `profiles` (`name`, `slug`, ...) VALUES ('Admin','admin',...), ('User','user',...);
```

`docker/interface/crontab` runs `run_migrations.php` every 5 minutes with no `flock`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Confirm crontab cadence | `grep -n "run_migrations" docker/interface/crontab` | the 5-min line |
| Lint runner | `php -l site/app/inc/lib/MigrationRunner.php` | no syntax errors |
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (and manager) | exit 0 |
| Re-run safety check (Docker) | run migrations twice in a row, then `SELECT slug, COUNT(*) FROM profiles GROUP BY slug HAVING COUNT(*)>1;` | zero rows (no dups) |

## Scope

**In scope**:
- `site/app/inc/lib/MigrationRunner.php` + `manager/app/inc/lib/MigrationRunner.php` (identical).
- `migrations/003_create_table_profiles.sql` and `migrations/004_create_table_users_profiles.sql`
  and `migrations/002_create_table_users.sql` — make seed `INSERT`s idempotent.
- `docker/interface/crontab` — add `flock` as defense-in-depth.

**Out of scope**:
- Replacing the custom runner with Phinx/Doctrine (a much larger migration; note in Maintenance).
- Rewriting the naive `explode(';')` statement splitter — fine for the current simple SQL;
  note the footgun in Maintenance. Do not add procedural SQL (triggers/procedures) that would
  break it.
- Altering already-applied production schema beyond adding the idempotency guards.

## Git workflow

- Branch: `advisor/013-migration-runner-safety`
- Commit: `fix: migrations idempotentes + lock para evitar execucao concorrente`
- Group the runner, migration SQL, and crontab edits in one commit.

## Steps

### Step 1: Add a DB advisory lock around the whole run

In `run()`, before `createMigrationsTable()`, acquire a MySQL named lock and bail if another
run holds it; release in a `finally`:

```php
public function run(): array
{
    $results = ['executed' => [], 'skipped' => [], 'failed' => []];

    $got = $this->pdo->query("SELECT GET_LOCK('leggo_migrations', 0) AS l")->fetch(\PDO::FETCH_ASSOC);
    if (($got['l'] ?? '0') !== '1') {
        $this->log("Outro processo de migration está em execução — pulando este ciclo.");
        return $results;
    }
    try {
        $this->createMigrationsTable();
        // ... existing file loop ...
    } finally {
        $this->pdo->query("SELECT RELEASE_LOCK('leggo_migrations')");
    }
    return $results;
}
```

`GET_LOCK(..., 0)` returns immediately (timeout 0). A concurrent tick gets `0` and skips cleanly.

**Verify**: `grep -n "GET_LOCK\|RELEASE_LOCK" site/app/inc/lib/MigrationRunner.php` → both present.

### Step 2: Make seed `INSERT`s idempotent at the SQL level

The robust fix that does not depend on `migrations_log` integrity:

- In `migrations/003_create_table_profiles.sql`: add `UNIQUE` to `slug`
  (`` `slug` VARCHAR(255) NOT NULL UNIQUE, ``) and change the seed to
  `INSERT INTO \`profiles\` (...) VALUES (...) ON DUPLICATE KEY UPDATE \`name\` = VALUES(\`name\`);`
  (or `INSERT IGNORE`). Pick `INSERT IGNORE` if you want re-runs to be pure no-ops.
- In `migrations/004_create_table_users_profiles.sql`: add a `UNIQUE KEY` on the
  `(users_id, profiles_id)` pair and use `INSERT IGNORE`.
- In `migrations/002_create_table_users.sql`: `users.mail` already has a UNIQUE key — change the
  seed admin `INSERT` to `INSERT IGNORE` so a re-run does not throw on the duplicate mail.

**Important:** these files may already be applied in production (recorded `success`), so the
*table* changes only take effect on fresh installs. Adding the UNIQUE constraint to an existing
prod DB needs a **new** forward migration (e.g. `006_add_unique_constraints.sql`) that adds the
constraints with `ALTER TABLE ... ADD UNIQUE` guarded so it is safe if they already exist. Create
`006_*` rather than relying on the edited `003/004` re-running.

**Verify**: `grep -n "INSERT IGNORE\|ON DUPLICATE KEY\|UNIQUE" migrations/00{2,3,4}_*.sql migrations/006_*.sql`
shows the guards.

### Step 3: Stop swallowing state-write failures on the success path

In `recordMigration()`, do not silently ignore a failure to write a `success` row — a migration
that applied but wasn't recorded will re-run. Let the exception propagate (or log at `error` and
re-throw) so the operator sees inconsistent state:

```php
private function recordMigration(string $name, string $status, ?string $error): void
{
    $stmt = $this->pdo->prepare(
        "INSERT INTO migrations_log (migration_name, status, error_message)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), error_message = VALUES(error_message), executed_at = NOW()"
    );
    $stmt->execute([$name, $status, $error]);  // let failures surface
}
```

Keep `createMigrationsTable()` tolerant of "already exists" but log other errors via `Logger`
instead of an empty catch.

**Verify**: `recordMigration` no longer has an empty `catch`.

### Step 4: Honest comment on DDL atomicity

Replace the misleading "transaction makes this atomic" expectation. Keep the transaction wrapper
(it still helps for pure-DML migrations) but add a comment documenting that DDL auto-commits in
MySQL, so each migration must be independently idempotent (which Step 2 now guarantees for the
seeds). Do not claim atomicity the engine doesn't provide.

### Step 5: Defense-in-depth `flock` in crontab

In `docker/interface/crontab`, wrap the migration invocation:

```
*/5 * * * * flock -n /tmp/leggo_migrate.lock php /var/www/leggo/site/cgi-bin/run_migrations.php >> /var/log/...
```

`flock -n` makes the OS skip the tick if a previous run is still holding the file — a second
guard independent of the DB lock.

**Verify**: `grep -n "flock" docker/interface/crontab` → present on the migration line.

### Step 6: Keep copies identical, lint, analyze, re-run test

- `diff site/app/inc/lib/MigrationRunner.php manager/app/inc/lib/MigrationRunner.php` → no difference.
- `php -l` clean; both PHPStan exit 0.
- In Docker: run `run_migrations.php` twice back-to-back, then run the dup-check query
  (commands table) → zero duplicate rows. Run a third time → all migrations `skipped`.

## Test plan

- The runner is exercised via the CLI script, not PHPUnit. Verification is the Step 6
  run-twice + dup-check, plus `php -l` and PHPStan.
- If you extract any pure logic (e.g. statement splitting) into a testable method, add a unit
  test; otherwise none required.

## Done criteria

ALL must hold:

- [ ] `GET_LOCK`/`RELEASE_LOCK` wrap the run; concurrent run skips cleanly
- [ ] Seed `INSERT`s use `INSERT IGNORE`/`ON DUPLICATE KEY`; `profiles.slug` + `users_profiles(users_id,profiles_id)` have UNIQUE (via new `006_*` for existing DBs)
- [ ] `recordMigration` failures surface (no empty catch on the success path)
- [ ] `flock -n` guards the crontab migration line
- [ ] both runner copies identical; `php -l` clean; both PHPStan exit 0
- [ ] running migrations twice produces zero duplicate seed rows; third run = all skipped
- [ ] `plans/README.md` status row updated

## STOP conditions

- You cannot run migrations against a live MySQL to do the Step 6 dup-check → STOP after Step 5
  and hand the verification to the operator; do not claim idempotency verified.
- A seed migration is already recorded `success` in a prod DB you must not alter → STOP and
  confirm the `006_*` forward-migration approach with the operator before adding constraints to
  live tables (adding UNIQUE will fail if duplicates already exist — dedupe first).
- The runner no longer matches the excerpt → STOP and re-plan.

## Maintenance notes

- `executeMigration` splits on raw `;` — any future migration containing `;` inside a string
  literal, trigger, or stored-procedure body will break. Keep migrations to plain DDL/DML, or
  replace the splitter with a real one / run files through the `mysql` client.
- If migration complexity grows, adopt Phinx or Doctrine Migrations and delete this ~322-line
  custom runner — but that is a deliberate, separate decision.
- The `messages` table (migration `005`) already provides a DB-backed store; a future
  simplification could replace the Kafka email path with a DB outbox + the existing cron,
  removing the producer/consumer pair entirely. Direction note only.
