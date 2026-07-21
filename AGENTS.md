# AGENTS.md — Leggo

PHP 8.4 + MySQL 8.0 whitelabel starter. Custom framework (not Laravel/Symfony), Docker-based.
Two environments: **manager** (admin panel) and **site** (public frontend). Same code structure,
different `kernel.php` constants, controllers, routes, and views.

> **This is the versioned, authoritative project reference.** `CLAUDE.md` and other `*.md`
> files are gitignored (see `.gitignore` — only `README.md`, `AGENTS.md`, `plans/*.md` are
> tracked). If a local `CLAUDE.md` conflicts with this file on project facts, **AGENTS.md wins**
> (e.g. CLAUDE.md has been seen to lag, stating "PHPStan level 3" when the actual level is 4).

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

- **First run** — run migrations, then activate the admin (the seed admin is disabled and has
  no usable password after migration `007_rotate_seed_admin.sql`):
  ```
  docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php
  echo 'sua-senha' | docker exec -i leggo php /var/www/leggo/manager/cgi-bin/set_admin_password.php
  ```
  `set_admin_password.php` reads the password from **STDIN** (never argv — avoids `ps`/shell
  history exposure) and sets `$_SERVER["HTTP_HOST"]` itself so it can load `kernel.php`.

- Hooks: pre-commit runs PHPStan + shared-sync guard; pre-push runs PHPUnit (in Docker,
  skipped if the container isn't up):
  ```
  git config core.hooksPath .githooks
  ```

## Commands

```bash
# Static analysis — PHPStan level 4 (runs on host; scans app/inc/{controller,lib,model})
cd manager && php app/inc/lib/vendor/bin/phpstan analyse
cd site && php app/inc/lib/vendor/bin/phpstan analyse

# Tests — PHPUnit 11, run from manager/ or site/ (needs kernel.php + live DB).
# The bootstrap sets $_SERVER["HTTP_HOST"] itself, so no external env var is needed.
cd manager && php app/inc/lib/vendor/bin/phpunit
cd site && php app/inc/lib/vendor/bin/phpunit

# Single test
php app/inc/lib/vendor/bin/phpunit --filter testMethodName

# Run migrations manually (also auto-run every 5 min via cron with flock + DB GET_LOCK)
docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php

# Rebuild after Dockerfile changes
docker compose -f docker/docker-compose.yml up -d --build --no-cache
```

```bash
# Full verification (PHPStan host + PHPUnit Docker, both envs) — run before merging framework changes
bin/test.sh
```

**CI** (`.github/workflows/ci.yml`, runs on push to `main` and PRs): `bin/check-shared-sync.sh`
→ PHPStan (both envs) → PHPUnit (both envs) with a MySQL 8.0 service. CI copies `kernel.php`
from `.example` and patches DB creds to the service container, then runs migrations on the
runner. Use `bin/test.sh` locally to mirror it.

**Rebranding a whitelabel** — `bin/init-whitelabel.sh` generates both `kernel.php` (site +
manager) from a brand name and production URLs (`--name`, `--site-url`, `--manager-url`;
interactive if no flags). It never overwrites an existing `kernel.php` (use `--force`) and
leaves `DB_PASS`/SMTP creds as placeholders. Full rebrand touchpoint inventory (color tokens,
logo, theme-color, legal placeholders, email hex palette) lives in `README.md` and
`plans/029-DESIGN.md`.

## Architecture

```
manager/  ← Admin panel (manager.leggo.local)
site/     ← Public site (leggo.local)
  Both contain: app/inc/{controller,lib,model,kernel.php,main.php,urls.php,lists.php},
                public_html/{index.php,ui,assets}, tests, cgi-bin, phpstan.neon, phpunit.xml

migrations/  ← Shared SQL files (auto-run every 5 min via cron)
docker/      ← Dockerfile, nginx vhosts, php.ini, entrypoint, crontab
bin/         ← test.sh, check-shared-sync.sh, init-whitelabel.sh
```

`public_html/index.php` is the front controller → requires `app/inc/main.php` (which loads
`kernel.php`, composer autoload, `lists.php`, `CommonFunctions.php`, `urls.php`, and seeds
the CSRF token).

**Key constants that differ between environments** (defined in each `kernel.php`):
| Constant | Manager | Site |
|---|---|---|
| `cAppKey` | `leggo_manager_session` | `leggo_site_session` |
| `REDIS_PREFIX` | `leggo:manager:` | `leggo:site:` |
| `KAFKA_TOPIC_EMAIL` | `leggo_manager_emails` | `leggo_site_emails` |
| `ALLOWED_HOSTS` | `manager.leggo.local` | `leggo.local` |
| `*_CANONICAL_URL` | `MANAGER_CANONICAL_URL` | `SITE_CANONICAL_URL` |

Both share the same MySQL database and Redis instance. Kafka topics are separate. Each
`kernel.php` validates `$_SERVER["HTTP_HOST"]` against `ALLOWED_HOSTS` and `exit('Invalid host
header')` on mismatch — CLI scripts pass an empty host (bypass) or set it themselves (e.g.
`set_admin_password.php`).

### Código compartilhado vs. por ambiente

O código de framework é mantido em **duas cópias byte-a-byte idênticas** (uma em `manager/`,
outra em `site/`). Toda correção de framework precisa ser aplicada nas duas.

| Caminho | Regra | Motivo |
|---|---|---|
| `app/inc/lib/` | **Compartilhado — DEVE ser idêntico** entre `manager/` e `site/` | Framework comum (CommonFunctions, DOLModel, localPDO, etc.) |
| `app/inc/model/` | **Compartilhado — DEVE ser idêntico** | Models de framework (users, profiles, messages) |
| `app/inc/controller/` | **Por ambiente** — pode divergir | Ex.: `auth_controller.php` do manager tem o gate de admin |
| `app/inc/{main,urls,lists,kernel}.php` | **Por ambiente** | Rotas, listas, config diferem |
| `public_html/index.php`, `ui/` | **Por ambiente** | Rotas e templates diferem |
| `tests/` | Idêntico em espírito, **não** byte-a-byte | Bootstrap difere apenas pelo `HTTP_HOST` |

O guard `bin/check-shared-sync.sh` faz `diff -rq` de `app/inc/lib` e `app/inc/model` entre os
dois ambientes (ignorando `vendor/` e `tests/`) e falha com exit não-zero listando os arquivos
divergentes. Ele roda automaticamente no hook `.githooks/pre-commit`, então esquecer de editar
uma das cópias bloqueia o commit. Rode manualmente com `bash bin/check-shared-sync.sh`.

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
  `populate()` + `save()` also use prepared statements internally — `real_escape_string()` is no longer needed for user data. Direct queries use `executePrepared()` / `execute_raw_prepared()`.

- **`select($fields, $where, $params)` / `update($fields, $where, $params)`**: stateless helpers for one-off queries against the model's table, sibling to `execute_raw_prepared()`. `select()` returns the `\PDOStatement` directly. `update()` requires a non-empty `$where` (throws `InvalidArgumentException` otherwise — use `"WHERE 1=1"` to affect all rows intentionally) and auto-injects `modified_at = now()` / `modified_by = ?` at the start of the `SET` clause from the session user.
  ```php
  $stmt = $model->select([" idx ", " name "], "WHERE active = 'yes'", []);
  $model->update(["name = ?"], "WHERE idx = ?", ["novo nome", $id]);
  ```

- **Routes** registered via `$dispatcher->add_route("GET", "/path", "controller:method", $guard, $params)`.
  The Dispatcher only accepts `GET` and `POST` — PUT/PATCH/DELETE are silently ignored.
  **URL patterns are regex, anchored with `^...$`** (`preg_match("/^".$pattern."$/", $path, $matches)`);
  capture groups arrive in `$matches` alongside `server_uri`. Example: `"/definir-senha/([a-zA-Z0-9]+)"`.

- **CSRF tokens**: `validate_csrf()` consome o token com grace period de 10 segundos (armazenado em `_csrf_used` com timestamp). Tokens expirados são limpos automaticamente. Isso evita erro de "Requisição inválida" no F5 após submit. Required on every POST route, including logout.

- **CSP nonce per request**: `public_html/index.php` emits `Content-Security-Policy` with
  `script-src 'self' 'nonce-<token>'` (no `unsafe-inline`) and stores the nonce in
  `$GLOBALS["cspNonce"]`. Views that include inline `<script>` must reference that nonce, or
  use external files / `unsafe-eval` (already allowed). `style-src` allows `unsafe-inline`.

- **Transaction management**: `localPDO::getInstance()` auto-inicia uma transação global por request. `basic_redir()` é o gate de commit/rollback — `basic_redir($url)` commita, `basic_redir($url, rollback: true)` reverte. O `__destruct()` do `localPDO` faz safety rollback se nenhum redirect explícito ocorrer. Controllers não precisam chamar `commit()`/`rollback()` manualmente.

- **Canonical URLs**: use `canonical_url('SITE_CANONICAL_URL')` ou `canonical_url('MANAGER_CANONICAL_URL')` para compor links em emails. O helper valida contra `ALLOWED_HOSTS` e lança exceção (fail closed) se nem `*_CANONICAL_URL` nem `ALLOWED_HOSTS` estiverem definidos — previne Host-header poisoning.

- **Utility helpers** (em `CommonFunctions.php`):
  - `generate_slug($text)` — slug URL-safe a partir de texto (translitera acentos)
  - `sanitize_string($value, $digitsOnly)` — sanitização; `$digitsOnly=true` extrai só dígitos
  - `time_ago($datetime)` — exibe data relativa em PT-BR ("há 5 minutos", "ontem às 14:30")
  - `str_limit($value, $limit)` — trunca texto com "..." (usa `mb_substr`)
  - `old($key, $default)` — repopula campo de formulário com `htmlspecialchars` automático
  - `array_to_csv($data, $filename, $headers)` — exporta array para CSV (delimitador `;`)
  - `json_response($data, $code)` — resposta JSON padronizada com headers e http status code
  - `random_token($bytes)` — alias para `bin2hex(random_bytes())`, default 64 caracteres hex
  - `handle_upload($file, $subDir, $options)` — upload com validação MIME, resize e conversão WebP/AVIF
  - `redact_email_body($html)` — redige tokens/senhas de corpos de email antes de persistir no log de mensagens
  - `basic_redir($url, $code, $use_html, $rollback)` — redirect terminal (`: never`); gate de txn

- **Auth guard**: `fn() => auth_controller::check_login()`. Checks `$_SESSION[cAppKey]["credential"]["idx"]`.

- **Sessions**: keyed by `cAppKey` constant (different per environment to avoid cross-env collisions).

- **Redis**: fail-open with file fallback. Used for rate limiting (`login_attempts:{ip}`, 5/60s) and forgot-password (`forgot_pwd:{ip}`, 3/300s) via `check_and_increment_rate_limit()`. If Redis is down, rate limiting falls back to file-based locks in `ratelimit_fallback_dir()` (default `sys_get_temp_dir()/leggo_ratelimit`, override with `RATELIMIT_FALLBACK_DIR`). Only fails open (no limiting) if both Redis AND the file fallback are unavailable — a warning is logged so operators can detect total bypass.

- **Email**: async via Kafka (`EmailProducer`). Falls back to sync if rdkafka extension is missing. The entrypoint starts **two** workers — `manager/cgi-bin/kafka_email_worker.php` and `site/cgi-bin/kafka_email_worker.php` — as background processes.

- **Passwords**: bcrypt (`password_hash`/`password_verify`). MD5 legacy passwords are auto-migrated to bcrypt on login.

- **Logging**: use `Logger::getInstance()` for structured JSON logs:
  ```php
  Logger::getInstance()->info("User logged in", ["id" => $userId]);
  Logger::getInstance()->error("SQL failed", ["query" => $sql, "error" => $e->getMessage()]);
  ```
  Levels: `debug`, `info`, `warning`, `error`. Minimum level controlled by `LOG_LEVEL` in `kernel.php`. SQL logs omit full queries to avoid leaking PII.

## Style

- **PHP 8.4**. Classes `PascalCase`, files `snake_case`, variables `snake_case`.
- **Indentation**: tabs for `.php`/`.sh`, spaces for `.yml`/`.json`/`.neon`/`.sql`/`.md`/`.xml` (see `.editorconfig`).
- Whitelabel class prefixes `ss-*` (site) and `leggo-*` (manager) are stable legacy conventions — do **not** rename when cloning.

## Testing

- Tests live in `manager/tests/` and `site/tests/`. They are identical between environments.

- Bootstrap (`tests/bootstrap.php`) sets `$_SERVER["HTTP_HOST"]` to the env's allowed host, then manually loads `kernel.php`, autoload, and `lists.php` — so **tests need a valid database** (and optionally Redis/Kafka). Define real DB constants in kernel.php or tests will fail to connect.

- PHPUnit 11 via Composer (`app/inc/lib/vendor/bin/phpunit`). Config at `phpunit.xml` in each env root.

- **Test isolation via transactions:** `DBTestCase` (in `tests/DBTestCase.php`) wraps each test in a DB transaction and rolls back on tearDown. Extend it for any test that touches the database. Tests that don't touch the DB extend plain `TestCase`.
  ```php
  final class MyModelTest extends DBTestCase { ... }
  ```
  This eliminates test ordering dependencies and enables future parallelism.

## Migrations

- Files in `migrations/` named numerically: `006_description.sql`. One DB transaction per file.
- Auto-run via cron (`docker/interface/crontab`) every 5 minutes against the site environment, guarded by `flock -n` (skip overlapping tick) plus a `GET_LOCK` inside `MigrationRunner` (defense in depth).
- Idempotent — already-executed migrations are tracked in the `migrations_log` table and skipped.
- Manual run: `docker exec leggo php /var/www/leggo/site/cgi-bin/run_migrations.php`

## Releases

- Bumping `VERSION` also requires updating `APP_VERSION` in **both** `kernel.php.example` files — it drives the `?v=` asset cache-bust in `foot.php`. Keep them in sync.

## Files to know

| File | Purpose |
|---|---|
| `manager/app/inc/kernel.php.example` | Manager config template (DB, Redis, Kafka, SMTP, app keys, `ALLOWED_HOSTS`, `MANAGER_CANONICAL_URL`) |
| `site/app/inc/kernel.php.example` | Site config template (`SITE_CANONICAL_URL`, etc.) |
| `manager/app/inc/lib/composer.json` | Composer deps + autoload (phpmailer ^6.9, phpstan ^2.0, phpunit ^11.0; same for both envs) |
| `manager/app/inc/main.php` | Loader required by `index.php`: kernel + autoload + lists + CommonFunctions + urls + CSRF seed |
| `manager/public_html/index.php` | Front controller: session, CSP nonce, route registration (per-env) |
| `manager/phpstan.neon` | PHPStan config (level 4, scans `app/inc/{controller,lib,model}`, scanFiles include `kernel.php`/`urls.php`/`lists.php`) |
| `manager/phpunit.xml` | PHPUnit config (bootstrap: `tests/bootstrap.php`) |
| `manager/cgi-bin/set_admin_password.php` | CLI to activate/reset the admin password (STDIN); required after migration 007 |
| `bin/test.sh` | Full verification: PHPStan (host) + PHPUnit (Docker), both envs |
| `bin/check-shared-sync.sh` | Drift guard for `lib/` and `model/` between envs (runs in pre-commit + CI) |
| `bin/init-whitelabel.sh` | Generates both `kernel.php` for a new whitelabel brand |
| `docker/docker-compose.yml` | Services: leggo (nginx+fpm), mysql (`--sql_mode=""`), redis, kafka, kafka-ui (port 8080) |
| `docker/interface/entrypoint.sh` | Startup: composer install (both envs), cron, two kafka workers, nginx |
| `docker/interface/default.conf` | Nginx vhosts for `manager.leggo.local` and `leggo.local` + security headers |
| `docker/interface/crontab` | Migrations every 5 min with `flock` |
| `.github/workflows/ci.yml` | CI: sync-guard + PHPStan + PHPUnit (MySQL 8.0 service) |
| `.editorconfig` | Indentation/style rules per file type |
