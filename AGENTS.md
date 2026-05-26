# AGENTS.md — Leggo

PHP 8.4 + MySQL 8.0 whitelabel starter. Custom framework (not Laravel/Symfony), Docker-based.
Two environments: **manager** (admin panel) and **site** (public frontend). Same code structure,
different `kernel.php` constants and routes.

## Setup

- `kernel.php` is **gitignored** — copy from `.example` before anything works:
  ```
  cp manager/app/inc/kernel.php.example manager/app/inc/kernel.php
  cp site/app/inc/kernel.php.example site/app/inc/kernel.php
  ```
  Default Docker DB credentials in the `.example` files work out of the box.

- Composer lives in `app/inc/lib/`, not project root. The entrypoint runs `composer install` automatically on startup, but you can also run it manually inside the container:
  ```
  docker exec leggo composer install -d /var/www/leggo/site/app/inc/lib
  docker exec leggo composer install -d /var/www/leggo/manager/app/inc/lib
  ```

- Start the stack: `docker compose -f docker/docker-compose.yml up -d --build`

- Pre-commit hooks (PHPStan + PHPUnit before each commit):
  ```
  git config core.hooksPath .githooks
  ```

## Commands

```bash
# Static analysis — PHPStan level 3
cd manager && php app/inc/lib/vendor/bin/phpstan analyse
cd site && php app/inc/lib/vendor/bin/phpstan analyse

# Tests — run from manager/ or site/ directory (needs kernel.php + DB)
cd manager && php app/inc/lib/vendor/bin/phpunit
cd site && php app/inc/lib/vendor/bin/phpunit

# Single test
php app/inc/lib/vendor/bin/phpunit --filter testMethodName

# Run migrations manually
docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php

# Rebuild after Dockerfile changes
docker compose -f docker/docker-compose.yml up -d --build --no-cache
```

## Architecture

```
manager/  ← Admin panel (manager.leggo.local)
site/     ← Public site (leggo.local)
  Both contain: app/inc/{controller,lib,model,kernel.php}, public_html, tests, cgi-bin

migrations/  ← Shared SQL files (auto-run every 5 min via cron)
docker/      ← Dockerfile, nginx vhosts, php.ini, entrypoint
```

**Key constants that differ between environments** (defined in each `kernel.php`):
| Constant | Manager | Site |
|---|---|---|
| `cAppKey` | `leggo_manager_session` | `leggo_site_session` |
| `REDIS_PREFIX` | `leggo:manager:` | `leggo:site:` |
| `KAFKA_TOPIC_EMAIL` | `leggo_manager_emails` | `leggo_site_emails` |

Both share the same MySQL database and Redis instance. Kafka topics are separate.

## Framework conventions

- **Models** extend `DOLModel`. Define `$field` (columns to SELECT) and `$filter` (WHERE clauses) as arrays of raw SQL strings. Use `populate($data)` to set values, `save()` to INSERT/UPDATE, `remove()` to soft-delete, `load_data()` to query.
  ```php
  $model->set_field([" idx ", " name ", " mail "]);
  $model->set_filter(["active = 'yes'", "login = 'foo'"]);
  $model->load_data();
  ```

- **Soft-delete universal**: `active = 'yes'/'no'`. Never use `DELETE FROM`.

- **SQL escaping**: use `set_filter()` with bound params for user input. Pass conditions as array, values as optional second param:
  ```php
  // Static filters (strings literais — backward compat)
  $model->set_filter(["active = 'yes'"]);
  // Dynamic filters (prepared statement via ? placeholders)
  $model->set_filter(["active = 'yes'", "mail = ? OR login = ?"], [$mail, $login]);
  ```
  `populate()` + `save()` also use prepared statements internally — `real_escape_string()` is no longer needed for user data. Direct queries use `execute_raw_prepared()`.

- **Routes** registered via `$dispatcher->add_route("GET", "/path", "controller:method", $guard, $params)`.
  The Dispatcher only accepts `GET` and `POST`. PUT/PATCH/DELETE are silently ignored.

- **CSRF tokens are one-time-use**. `validate_csrf()` consumes the token; it's regenerated on next page load.

- **Auth guard**: `fn() => auth_controller::check_login()`. Checks `$_SESSION[cAppKey]["credential"]["idx"]`.

- **Sessions**: keyed by `cAppKey` constant (different per environment to avoid cross-env collisions).

- **Redis**: fail-open. Used for rate limiting (`login_attempts:{ip}`, 5/60s) and forgot-password (`forgot_pwd:{ip}`, 3/300s). If Redis is down, the app runs without rate limiting.

- **Email**: async via Kafka (`EmailProducer`). Falls back to sync if rdkafka extension is missing. Workers (`kafka_email_worker.php`) start as background processes in the entrypoint.

- **Passwords**: bcrypt (`password_hash`/`password_verify`). MD5 legacy passwords are auto-migrated to bcrypt on login.

- **Logging**: use `Logger::getInstance()` for structured JSON logs:
  ```php
  Logger::getInstance()->info("User logged in", ["id" => $userId]);
  Logger::getInstance()->error("SQL failed", ["query" => $sql, "error" => $e->getMessage()]);
  ```
  Levels: `debug`, `info`, `warning`, `error`. Minimum level controlled by `LOG_LEVEL` in `kernel.php`.

## Testing

- Tests live in `manager/tests/` and `site/tests/`. They are identical between environments.

- Bootstrap (`tests/bootstrap.php`) manually loads `kernel.php`, so **tests need a valid database** (and optionally Redis/Kafka). Define real DB constants in kernel.php or tests will fail to connect.

- PHPUnit 11 via Composer (`app/inc/lib/vendor/bin/phpunit`). Config at `phpunit.xml` in each env root.

- **Test isolation via transactions:** `DBTestCase` (in `tests/DBTestCase.php`) wraps each test in a DB transaction and rolls back on tearDown. Extend it for any test that touches the database. Tests that don't touch the DB extend plain `TestCase`.
  ```php
  final class MyModelTest extends DBTestCase { ... }
  ```
  This eliminates test ordering dependencies and enables future parallelism.

## Migrations

- Files in `migrations/` named numerically: `006_description.sql`.
- Auto-run via cron (`docker/interface/crontab`) every 5 minutes against the site environment.
- Idempotent — already-executed migrations are tracked in `migrations_log` table and skipped.
- Manual run: `docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php`

## Files to know

| File | Purpose |
|---|---|---|
| `manager/app/inc/kernel.php.example` | Manager config template (DB, Redis, Kafka, SMTP, app keys) |
| `site/app/inc/kernel.php.example` | Site config template |
| `manager/app/inc/lib/composer.json` | Composer deps + autoload config (same for both envs) |
| `manager/phpunit.xml` | PHPUnit config (bootstrap: `tests/bootstrap.php`) |
| `manager/phpstan.neon` | PHPStan config (level 3, excludes vendor) |
| `docker/docker-compose.yml` | All services: leggo (nginx+fpm), mysql, redis, kafka, kafka-ui |
| `docker/interface/entrypoint.sh` | Startup: composer install, cron, kafka workers, nginx |
| `docker/interface/default.conf` | Nginx vhosts for `manager.leggo.local` and `leggo.local` |

