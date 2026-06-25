# Plan 015: Make `canonical_url()` fail closed to block Host-header link poisoning

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git diff --stat 86e28f1..HEAD -- site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php site/app/inc/kernel.php.example manager/app/inc/kernel.php.example`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED (a misconfigured deploy will get 500s on email links instead of poisoned links — that is the intended safe failure, but it must be communicated)
- **Depends on**: none
- **Category**: security (Host-header injection → password-reset poisoning, CWE-601/CWE-644)
- **Planned at**: commit `86e28f1`, 2026-06-25

## Why this matters

Email links (verify, reset, set-password) are built with `canonical_url('SITE_CANONICAL_URL')`
/ `canonical_url('MANAGER_CANONICAL_URL')`. The helper has a **fail-open** fallback: when neither
the canonical constant nor `ALLOWED_HOSTS` is configured, it returns `cFrontend` — which is
derived from the attacker-controllable `HTTP_HOST` header — after only logging a warning.

Consequence: with the shipped-empty defaults (`ALLOWED_HOSTS=""`, `*_CANONICAL_URL=""` in
`kernel.php.example`), an attacker submits a password reset for a victim with
`Host: evil.com`. The victim receives a genuine reset email whose link points at
`https://evil.com/redefinir-senha/<valid-token>`. Clicking it ships the token to the attacker →
account takeover.

The deployed `kernel.php` is gitignored, so the runtime value can't be fixed in the repo — but
two repo-level changes close the hole: (1) make `canonical_url()` **fail closed** (throw) instead
of silently trusting `HTTP_HOST`, so a misconfigured deploy breaks loudly instead of becoming
exploitable; (2) ship safer, clearly-marked defaults + guidance in `kernel.php.example`.

## Current state

`site/app/inc/lib/CommonFunctions.php` ~lines 456-472 (identical in manager copy):

```php
function canonical_url(string $canonicalConstant): string
{
  if (defined($canonicalConstant) && constant($canonicalConstant) !== '') {
    return rtrim(constant($canonicalConstant), '/');
  }

  if (defined('ALLOWED_HOSTS') && constant('ALLOWED_HOSTS') !== '') {
    return rtrim(constant('cFrontend'), '/');
  }

  Logger::getInstance()->warning("Canonical URL falling back to cFrontend without ALLOWED_HOSTS", [
    "constant" => $canonicalConstant,
    "host"     => $_SERVER['HTTP_HOST'] ?? 'unknown',
  ]);

  return rtrim(constant('cFrontend'), '/');   // <-- fail-open: trusts HTTP_HOST
}
```

The middle branch (canonical empty but `ALLOWED_HOSTS` set) is defensible: `cFrontend` was already
validated against `ALLOWED_HOSTS` at bootstrap (`kernel.php`). The final branch is the hole.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Find callers | `grep -rn "canonical_url(" --include="*.php" site manager \| grep -v vendor` | the email-link builders |
| Confirm example defaults | `grep -n "ALLOWED_HOSTS\|CANONICAL_URL" site/app/inc/kernel.php.example manager/app/inc/kernel.php.example` | currently empty strings |
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (and manager) | exit 0 |
| Unit suite (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` (and manager) | all pass |

## Scope

**In scope**:
- `site/app/inc/lib/CommonFunctions.php` + `manager/app/inc/lib/CommonFunctions.php` — make the
  final fallback fail closed.
- `site/app/inc/kernel.php.example` + `manager/app/inc/kernel.php.example` — set safe local-dev
  defaults and an explicit comment that production MUST set these.

**Out of scope**:
- The gitignored runtime `kernel.php` (cannot and must not be committed; instruct the operator
  to set it — see Maintenance).
- The bootstrap `ALLOWED_HOSTS` Host-validation block in `kernel.php` itself — keep as-is.
- The rate-limiting / CSRF / session findings from the security audit — separate items.

## Git workflow

- Branch: `advisor/015-canonical-url-fail-closed`
- Commit: `fix: canonical_url falha fechado para impedir poisoning via Host header`
- Edit all four files in one commit.

## Steps

### Step 1: Fail closed in `canonical_url`

Replace the final fail-open `return` with a thrown exception. Keep the two safe branches:

```php
function canonical_url(string $canonicalConstant): string
{
  if (defined($canonicalConstant) && constant($canonicalConstant) !== '') {
    return rtrim(constant($canonicalConstant), '/');
  }

  // cFrontend só é confiável se já tiver sido validado contra ALLOWED_HOSTS no bootstrap.
  if (defined('ALLOWED_HOSTS') && constant('ALLOWED_HOSTS') !== '') {
    return rtrim(constant('cFrontend'), '/');
  }

  // Fail closed: sem URL canônica e sem ALLOWED_HOSTS, cFrontend deriva de HTTP_HOST
  // (controlável pelo atacante) — recusar em vez de gerar link envenenável.
  Logger::getInstance()->error("canonical_url sem configuração segura — recusando", [
    "constant" => $canonicalConstant,
    "host"     => $_SERVER['HTTP_HOST'] ?? 'unknown',
  ]);
  throw new RuntimeException(
    "URL canônica não configurada: defina {$canonicalConstant} ou ALLOWED_HOSTS no kernel.php"
  );
}
```

**Verify**: `grep -n "throw new RuntimeException" site/app/inc/lib/CommonFunctions.php` includes
the canonical_url case; the function no longer returns `cFrontend` in the unconfigured branch.

### Step 2: Confirm callers handle the throw sanely

Email-link builders run inside the register/forgot-password actions, which already wrap email
work in `try/catch` (see the `messages_model` save blocks). Read each `canonical_url(` caller and
confirm a thrown exception results in a user-facing "could not send email, contact support"
message and a rolled-back request — **not** a white-screen with a stack trace (PHP `display_errors`
is already off per `index.php`). If any caller would leak the exception to the user, note it but
do not expand scope — the safe failure (no email) is still strictly better than a poisoned link.

**Verify**: written confirmation that a throw degrades to "email not sent", not a fatal leak.

### Step 3: Safer example defaults + explicit warning

In both `kernel.php.example` files, set local-dev-correct canonical URLs and a loud comment:

```php
// PRODUÇÃO: defina obrigatoriamente. Se vazio E ALLOWED_HOSTS vazio, canonical_url() lança
// exceção (fail closed) para impedir poisoning via Host header.
define("ALLOWED_HOSTS", "leggo.local");                       // site (manager: manager.leggo.local)
define("SITE_CANONICAL_URL", "http://leggo.local");           // site
// define("MANAGER_CANONICAL_URL", "http://manager.leggo.local"); // manager copy
```

Match each env's existing host names (grep the example for `leggo.local` / `manager.leggo.local`).
Do not invent hosts.

**Verify**: `grep -n "CANONICAL_URL\|ALLOWED_HOSTS" *kernel.php.example` shows non-empty,
env-appropriate defaults with the warning comment.

### Step 4: Test the fail-closed behavior

Add a unit test (both `CommonFunctionsTest.php` copies) that defines an empty canonical constant
and empty `ALLOWED_HOSTS` and asserts `canonical_url` throws. Because constants can't be redefined
mid-test, test the simplest provable branch: with the canonical constant **defined and non-empty**
it returns that value; document that the throw path is covered by the integration check in Step 2
if constant setup makes a pure unit test impractical.

```php
public function test_canonical_url_uses_configured_constant(): void
{
    if (!defined('SITE_CANONICAL_URL')) {
        define('SITE_CANONICAL_URL', 'http://leggo.local');
    }
    $this->assertSame('http://leggo.local', canonical_url('SITE_CANONICAL_URL'));
}
```

**Verify**: test passes in Docker.

### Step 5: Static analysis + full suite + copies identical

Both PHPStan runs exit 0; full PHPUnit suite passes in Docker; `diff` of both `CommonFunctions.php`
copies → no difference (the example files legitimately differ per env — that is expected, do not
force them identical).

## Test plan

- `test_canonical_url_uses_configured_constant` in both test files.
- Manual (recommended): with `SITE_CANONICAL_URL` set, request a password reset with a forged
  `Host: evil.com` header and confirm the emailed link uses the configured canonical host, not
  `evil.com`. With both constants empty, confirm the action fails gracefully ("email not sent")
  rather than emailing an `evil.com` link.

## Done criteria

ALL must hold:

- [ ] `canonical_url` throws (fail closed) when neither the canonical constant nor `ALLOWED_HOSTS` is set
- [ ] both `CommonFunctions.php` copies identical and updated
- [ ] both `kernel.php.example` files have non-empty, env-appropriate canonical/ALLOWED_HOSTS defaults + the warning comment
- [ ] Step 2 confirms callers degrade to "email not sent", not a leaked fatal
- [ ] `test_canonical_url_uses_configured_constant` passes; both PHPStan exit 0; full suite passes
- [ ] `plans/README.md` status row updated

## STOP conditions

- A `canonical_url` caller is **not** wrapped such that a throw degrades safely (would white-screen
  or leak a stack trace to the user) → note it and STOP before changing example defaults; the
  caller needs a guard first, which the operator should scope.
- `canonical_url` no longer matches the excerpt → STOP and re-plan.

## Maintenance notes

- **Operator action (cannot be done in the repo):** the gitignored production `kernel.php` MUST
  set `ALLOWED_HOSTS` and `SITE_CANONICAL_URL` / `MANAGER_CANONICAL_URL` to the real hosts.
  After this plan, a deploy that forgets them will fail loudly on the first email link (safe)
  instead of sending poisoned links (exploitable).
- Related hardening surfaced by the security audit but **not** in this plan: per-account login
  rate limiting (the current limiter is per-IP only and fail-open), reducing the CSRF 10s grace
  window, and forcing MD5-legacy password resets rather than lazy on-login migration. Each is a
  separate plan if the operator wants them.
