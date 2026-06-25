# Plan 014: Stop persisting token-bearing email bodies to the `messages` table

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git diff --stat 86e28f1..HEAD -- site/app/inc/controller/auth_controller.php manager/app/inc/controller/auth_controller.php manager/app/inc/controller/site_controller.php`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW
- **Depends on**: none
- **Category**: security (sensitive data at rest, CWE-312)
- **Planned at**: commit `86e28f1`, 2026-06-25

## Why this matters

Every transactional email (verify-email, password-reset, new-admin-credentials) is rendered to
a `$body` HTML string and then **persisted whole** into the `messages` table as a send log. Those
bodies contain the **live verification/reset token URLs**. So anyone with read access to the
`messages` table — a DBA, a backup, a future "email log" admin view, or an attacker who gets a
read-only SQL foothold — obtains valid account-takeover tokens directly. The token in the DB is
as good as the token in the victim's inbox.

There are four identical persistence sites (site register/reset, manager register, manager user
action). The fix is to log **metadata** (recipient, subject, status, timestamp) and a
**redacted** body — never the token-bearing URL.

## Current state

`site/app/inc/controller/auth_controller.php` ~lines 164-175 (the same shape repeats at ~452,
at `manager/app/inc/controller/auth_controller.php` ~176, and
`manager/app/inc/controller/site_controller.php` ~125):

```php
try {
    $msgModel = new messages_model();
    $msgModel->populate([
        "to_mail" => $info["post"]["mail"],
        "subject" => $subject,
        "body"    => $body,          // <-- contains the live token URL
        "sent_at" => date("Y-m-d H:i:s"),
    ]);
    $msgModel->save();
} catch (Exception $e) {
    error_log("Erro ao salvar log de email: " . $e->getMessage());
}
```

`$body` is produced just above by `ob_get_clean()` over a `ui/mail/*.php` template that embeds
`$resetLink` / `$verifyLink` / `$setPasswordLink` (the token URL).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Find all persistence sites | `grep -rn '"body"\s*=>' --include="*.php" site manager \| grep -v vendor` | the four sites above |
| Find a home for the helper | `grep -n "function " site/app/inc/lib/CommonFunctions.php \| head` | confirm `CommonFunctions.php` is the shared util home |
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (and manager) | exit 0 |
| Unit suite (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` (and manager) | all pass |

## Scope

**In scope**:
- A new shared helper `redact_email_body(string $html): string` in **both**
  `CommonFunctions.php` copies (kept identical).
- The four persistence sites, each changed to store `redact_email_body($body)` instead of `$body`.
- A unit test for `redact_email_body` in both `CommonFunctionsTest.php` copies.

**Out of scope**:
- The `messages` table schema (migration 005) — no column change needed; we keep storing a body,
  just a redacted one.
- The email *sending* path (`EmailProducer`) — the real email still contains the token (it must).
- Building an admin reader UI for `messages` — separate direction item.

## Git workflow

- Branch: `advisor/014-redact-tokens-in-email-log`
- Commit: `fix: nao persiste tokens de email no log de mensagens (redacao)`
- All edits in one commit; keep both copies of each shared file identical.

## Steps

### Step 1: Add `redact_email_body` to both `CommonFunctions.php` copies

It must strip the query/path token from any URL in the body and neutralize raw token strings.
A pragmatic, robust approach: replace the value of any `href` whose URL path/query looks like a
token route, plus any long hex run, with a placeholder.

```php
/**
 * Remove tokens sensíveis (links de verificação/reset) do corpo do email
 * antes de persistir no log de mensagens. O email enviado ao usuário continua
 * com o token; apenas a cópia armazenada é redigida.
 */
function redact_email_body(string $html): string
{
  // Redige o caminho/token de qualquer href (verificar-email/<t>, redefinir-senha/<t>, etc.)
  $html = preg_replace('/(href=["\'][^"\']*\/(?:verificar-email|redefinir-senha|definir-senha|reset-senha)\/)[^"\']+/i', '$1[REDACTED]', $html);
  // Redige qualquer sequência hex longa solta (tokens de 32+ chars)
  $html = preg_replace('/\b[a-f0-9]{32,}\b/i', '[REDACTED]', $html);
  return $html ?? '';
}
```

Adjust the route slugs in the first regex to match the actual token routes in `urls.php`
(`verificar-email`, `redefinir-senha`, `definir-senha`, and the manager equivalents) — grep
`urls.php` to confirm the exact slugs before finalizing the pattern.

**Verify**: `grep -n "function redact_email_body" site/app/inc/lib/CommonFunctions.php` → one match;
`diff` of the two copies → no difference.

### Step 2: Wrap every persisted body

At each of the four sites, change `"body" => $body,` to `"body" => redact_email_body($body),`.

**Verify**: `grep -rn '"body"\s*=>\s*\$body' site manager | grep -v vendor` → **no matches**
(all four now wrapped); `grep -rn 'redact_email_body(\$body)' site manager | grep -v vendor` → four matches.

### Step 3: Unit test the redactor

In both `CommonFunctionsTest.php` copies:

```php
public function test_redact_email_body_strips_token_urls_and_hex(): void
{
    $html = '<a href="https://x.tld/redefinir-senha/abc123def456abc123def456abc123de">link</a> ref deadbeefdeadbeefdeadbeefdeadbeef';
    $out  = redact_email_body($html);
    $this->assertStringNotContainsString('abc123def456abc123def456abc123de', $out);
    $this->assertStringNotContainsString('deadbeefdeadbeefdeadbeefdeadbeef', $out);
    $this->assertStringContainsString('[REDACTED]', $out);
}
```

**Verify**: `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit --filter test_redact_email_body_strips_token_urls_and_hex` → passes.

### Step 4: Static analysis + full suite + both copies identical

Both PHPStan runs exit 0; full PHPUnit suite passes in Docker for both envs; `diff` of both
`CommonFunctions.php` copies and both `CommonFunctionsTest.php` copies → no difference.

## Test plan

- New `test_redact_email_body_strips_token_urls_and_hex` in both test files.
- The existing auth/email flows are not unit-tested end-to-end; manual confirmation (optional):
  trigger a password reset, then `SELECT body FROM messages ORDER BY id DESC LIMIT 1;` and confirm
  the stored body shows `[REDACTED]` where the token URL was, while the actual email delivered to
  the inbox still has the working link.

## Done criteria

ALL must hold:

- [ ] `redact_email_body` exists in both copies and is identical
- [ ] all four persistence sites store `redact_email_body($body)`; no site stores raw `$body`
- [ ] `grep -rn '"body"\s*=>\s*\$body' site manager | grep -v vendor` → no matches
- [ ] redactor unit test passes
- [ ] both PHPStan exit 0; full PHPUnit suite passes in Docker
- [ ] both `CommonFunctions.php` and both `CommonFunctionsTest.php` copies identical
- [ ] `plans/README.md` status row updated

## STOP conditions

- The token route slugs in `urls.php` differ from the regex assumptions and you cannot confirm
  them → STOP and report the actual slugs; a wrong regex would leave tokens unredacted.
- A persistence site no longer matches the excerpt → STOP and re-plan against live code.

## Maintenance notes

- Treat the `messages` table as sensitive regardless: restrict DB grants, and if an admin
  "email log" view is ever built (a noted direction item), render the already-redacted body —
  never re-fetch or reconstruct the token.
- Any new transactional email that embeds a token must route its logged body through
  `redact_email_body`. Add it to the email-sending checklist in `AGENTS.md`.
