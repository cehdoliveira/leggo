# Thermo-Nuclear Code-Quality Review — Leggo

**Date:** 2026-06-25 · **Commit:** `86e28f1` · **Scope:** whole codebase (81 PHP files, ~11.6k LOC)
**Method:** 4 parallel read-only auditors (framework core · auth/security · async-infra/migrations ·
duplication/views), then per-finding vetting against live code. Strict implementation-quality lens:
ambitious structural simplification, not cosmetic nits.

This is the audit narrative. Actionable, self-contained executor plans live alongside as
`010`–`015` (see [README.md](README.md)). Findings already covered by the first-pass plans
(`003`–`009`) are not repeated here.

---

## Headline structural finding

**~50% of the codebase is verbatim duplication.** 32 files are byte-for-byte identical between
`site/` and `manager/`, including the **entire framework lib** (`app/inc/lib/*` — DOLModel,
localPDO, Dispatcher, rootOBJ, CommonFunctions, EmailProducer, Logger, MigrationRunner,
RedisCache ≈ 3,500 LOC), all models, all tests, both cgi-bin workers, and all tooling config.
Every fix must be applied twice or the trees silently drift — and drift has already happened
(footer PII/version, theme-toggle anti-flash script, dead POST flags). This is the root multiplier
behind most other findings. It is **intentional whitelabel structure** (AGENTS.md), so the remedy
is to de-duplicate the shared lib behind one source of truth (Composer path repo recommended),
**not** to merge the two environments. Tracked as plan `008` (optional, MED-risk).

---

## Confirmed high-severity findings → plans

| Sev | Finding | Evidence | Plan |
|-----|---------|----------|------|
| CRITICAL | **CSV formula injection** — user-set `name`/`mail`/`login` written raw to CSV; admin's Excel executes `=`/`@`/`+`/`-` cells | `CommonFunctions.php:855-862` (`array_to_csv` → `fputcsv` no neutralization); manager export-csv action | **010** |
| CRITICAL | **Legacy unparameterized SQL path** — `select/insert/update/delete/replace/my_query/real_escape_string` build SQL by `sprintf` with no escaping; `load_data` `else` branch + raw `$filter` strings are an open injection channel | `localPDO.php:78-160`, `DOLModel.php:263-265`, `set_filter` legacy string mode | **011** |
| CRITICAL | **Email worker silently drops mail** — `enable.auto.commit=true`; on send failure offset commits anyway → message lost forever; no DLQ | `kafka_email_worker.php:200-205,295-302` | **012** |
| HIGH | **Worker crash on idle + poison input** — `switch ($message->err)` deref'd when `$message` is `null` (no `continue`); `Throwable` from a malformed payload escapes `catch (Exception)` and kills the loop (no supervisor) | `kafka_email_worker.php:259-266`, `:281-302` | **012** |
| CRITICAL | **Migrations not concurrency-safe / not idempotent** — 5-min cron with no lock races on overlap; seed `INSERT`s lack `IGNORE`/`ON DUPLICATE`; `profiles.slug` has no UNIQUE → duplicate rows on re-run; DDL auto-commit defeats the transaction wrapper; `recordMigration` swallows failures | `MigrationRunner.php:58-104,141-159,203-232`; `migrations/002-004*.sql`; `docker/interface/crontab` | **013** |
| HIGH | **Reset/verify tokens persisted in clear** — full email body (incl. token URL) saved to `messages` table at 4 sites; DB read = account-takeover tokens | `auth_controller.php:164-175,452`, `manager/.../auth_controller.php:176`, `manager/.../site_controller.php:125` | **014** |
| HIGH | **Host-header link poisoning** — `canonical_url()` fails open to `cFrontend` (from `HTTP_HOST`) when unconfigured → attacker-controlled reset links | `CommonFunctions.php:456-472`; shipped-empty `ALLOWED_HOSTS`/`*_CANONICAL_URL` | **015** |

All seven were re-opened in live code and confirmed during vetting (line numbers above are
post-vet). The auth audit additionally surfaced per-IP-only/fail-open rate limiting, the CSRF
10s replay grace, lazy MD5-on-login migration, and weak password policy — real but lower-leverage
hardening, listed as follow-ups in plan `015`'s maintenance notes rather than planned now.

---

## Smaller confirmed defects (covered by first-pass plans or deferred)

- `set_url()` query parser breaks on valueless params / loses `=` — **plan 003**.
- `generate_slug` uses `[0-9A-z]` (matches `[ \ ] ^ _ \``) — fold into a small-fixes pass; noted.
- Dead assignment cascade in `Dispatcher::get_path_info` (`:40-41` overwritten immediately).
- `populate()` drops empty-string values (`!== ''`) → can't clear a field — correctness bug, deferred.
- `CommonFunctions.php` is an 897-line god-file (autoload + crypto + debug + URL + upload + CSV +
  image + CSRF + rate-limit) with side-effecting top-level code — decomposition candidate, deferred.

## Considered and rejected (do not "fix")

- **`localPDO` global-transaction + `__destruct` rollback** — documented intentional design
  (AGENTS.md: `basic_redir()` is the commit gate). Not a defect.
- **`save()` filter-string sniff** and **`rootOBJ::__call` magic** — real tech-debt, **deferred**
  (need careful API changes; lower leverage than the P1 security set).
- **Merging site/manager into one app** — would trade duplication for `if ($isManager)` spaghetti;
  routes, the manager `adm` auth gate, and views genuinely differ. Reduce duplication (008), don't merge.

---

## What was NOT audited

- Runtime config (`kernel.php` is gitignored — assessed only via `.example` + behavior).
- JS beyond the Alpine auth controllers; CSS; the Docker/nginx TLS layer.
- No dynamic/runtime testing (static + manual-reproduction reasoning only).
- Dependency CVE scan (`composer audit`) — recommended as a follow-up gate.
