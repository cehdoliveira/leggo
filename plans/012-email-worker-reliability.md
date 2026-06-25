# Plan 012: Fix data loss and crashes in the Kafka email worker

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git diff --stat 86e28f1..HEAD -- site/cgi-bin/kafka_email_worker.php manager/cgi-bin/kafka_email_worker.php`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (changes consumer commit semantics; test against a live Kafka)
- **Depends on**: none
- **Category**: correctness / reliability (data loss + crash loop)
- **Planned at**: commit `86e28f1`, 2026-06-25

## Why this matters

`kafka_email_worker.php` is the consumer that actually sends queued email. It has three
defects that combine into silent, permanent email loss:

1. **Lost mail on send failure (data loss).** The consumer runs with `enable.auto.commit=true`.
   When `sendEmailViaPHPMailer()` returns `false` (SMTP down, transient network error,
   throttling), the code logs the failure and does nothing — the offset auto-commits ~1s later
   and the message is **gone forever**. A code comment even acknowledges "Para implementar
   retry, usar dead letter queue", but none exists. Any SMTP hiccup drops every email queued
   during the outage.

2. **Null-pointer crash every idle cycle.** `consume()` can return `null`. The code checks
   `$message === null` for the heartbeat counter, then immediately does `switch ($message->err)`
   **without `continue`** — dereferencing `$message` when it is `null` → "Attempt to read
   property err on null". On a real `null` this throws.

3. **Uncaught `Throwable` kills the loop.** A structurally-valid-but-malformed message (e.g.
   missing `to`) reaches `sendEmailViaPHPMailer`, which can raise a `TypeError`/`Error`. The
   `catch (Exception ...)` blocks do not catch `Error`/`TypeError`, so the worker process dies.
   There is no supervisor restart (it is backgrounded in the entrypoint), so email processing
   stops until the container is restarted.

Fixing these makes the email pipeline at-least-once instead of lossy, and stops the worker
from crashing on bad input or idle cycles.

## Current state

`site/cgi-bin/kafka_email_worker.php` (identical to manager copy). Consumer config around
line 200-205 sets `enable.auto.commit => 'true'`. The consume loop, ~lines 255-305:

```php
while (true) {
    $message = $consumer->consume(30 * 1000);

    if ($message === null || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
        $messageCount++;
        if ($messageCount % 20 === 0) {
            log_message("Heartbeat: ...");
        }
    }

    switch ($message->err) {                       // <-- (2) $message may be null here
        case RD_KAFKA_RESP_ERR_NO_ERROR:
            $messageCount = 0;
            // ... logging ...
            $emailData = json_decode($message->payload, true);
            if ($emailData === null) {
                log_message("Mensagem inválida (JSON malformado)", 'WARNING');
                $consumer->commit($message);
                continue 2;
            }
            $success = sendEmailViaPHPMailer($emailData);   // <-- (3) can throw Error/TypeError
            if ($success) {
                log_message("Email processado e enviado com sucesso!");
                // Não precisa commit manual - auto commit está ativo
            } else {
                log_message("Falha ao processar email", 'ERROR');
                // (1) nothing happens -> auto-commit drops the message
            }
            break;
        // ... other RD_KAFKA_RESP_ERR_* cases ...
    }
}
```

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Confirm auto-commit setting | `grep -n "auto.commit" site/cgi-bin/kafka_email_worker.php` | shows `'true'` today |
| Lint the file (syntax) | `php -l site/cgi-bin/kafka_email_worker.php` | `No syntax errors detected` |
| PHPStan site/manager | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` (and manager) | exit 0 |
| Manual integration (Docker) | publish a test message to the topic and watch the worker log | message sent → offset advances; SMTP failure → message redelivered |

## Scope

**In scope**:
- `site/cgi-bin/kafka_email_worker.php` + `manager/cgi-bin/kafka_email_worker.php` (identical edits).

**Out of scope**:
- Moving workers out of the entrypoint into supervised compose services — that is a separate,
  larger infra plan (note it in Maintenance). This plan makes the *worker itself* correct.
- The `EmailProducer` side (sync-fallback semantics) — separate concern.
- Building a full dead-letter-queue topic — this plan uses the simpler, robust "don't commit
  on failure → redelivery" approach. A bounded DLQ can come later.

## Git workflow

- Branch: `advisor/012-email-worker-reliability`
- Commit: `fix: worker de email nao perde mensagens em falha e nao quebra em ciclo ocioso`
- Edit both copies in one commit.

## Steps

### Step 1: Switch to manual offset commits

Change the consumer config `enable.auto.commit` from `'true'` to `'false'`. Keep
`auto.offset.reset => 'earliest'`.

**Verify**: `grep -n "enable.auto.commit" site/cgi-bin/kafka_email_worker.php` → `'false'`.

### Step 2: Guard the null/timeout case before dereferencing `$message`

Replace the heartbeat `if` so that a `null` or timed-out message `continue`s the loop and never
falls through to `switch ($message->err)`:

```php
if ($message === null || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
    $messageCount++;
    if ($messageCount % 20 === 0) {
        log_message("Heartbeat: Worker ativo, aguardando mensagens... (ciclo #{$messageCount})");
    }
    pcntl_signal_dispatch();   // keep signal handling responsive while idle
    continue;
}
```

Now `switch ($message->err)` is only reached with a non-null `$message`.

**Verify**: there is a `continue;` between the null/timeout check and the `switch`.

### Step 3: Commit only after a confirmed successful send; wrap the send in `Throwable`

In the `RD_KAFKA_RESP_ERR_NO_ERROR` case, restructure so the offset is committed **only** on
success, and a thrown `Error`/`Exception`/`TypeError` is caught (so a poison message can't kill
the loop):

```php
$emailData = json_decode($message->payload, true);

if (!is_array($emailData) || empty($emailData['to']) || empty($emailData['subject'])) {
    // Truly malformed/poison: cannot ever succeed -> commit to skip (drop), but log loudly.
    log_message("Mensagem inválida/poison descartada (JSON ou campos ausentes)", 'WARNING');
    $consumer->commit($message);
    break; // leaves the switch; loop continues
}

try {
    $success = sendEmailViaPHPMailer($emailData);
} catch (\Throwable $e) {
    log_message("Erro inesperado ao enviar email: " . $e->getMessage(), 'ERROR');
    $success = false;
}

if ($success) {
    $consumer->commit($message);                 // advance offset ONLY on success
    log_message("Email processado e enviado com sucesso!");
} else {
    // Do NOT commit: the message will be redelivered on the next poll (at-least-once).
    log_message("Falha no envio — offset NÃO comitado, será reprocessado", 'ERROR');
    sleep(2); // small backoff so a hard SMTP outage doesn't hot-loop
}
```

Decision rationale to preserve in the code comments:
- **Malformed/poison** (cannot ever succeed) → commit + drop, logged loudly. Otherwise it
  would redeliver forever and block the partition.
- **Transient send failure** (valid message, SMTP/network) → do not commit → redelivery.

**Verify**: `grep -n "commit(\$message)" site/cgi-bin/kafka_email_worker.php` appears in exactly
two places (malformed-drop and success), and the failure branch has no commit.

### Step 4: Make signal handling responsive (graceful shutdown)

Near the top of the worker bootstrap (where `pcntl_signal` handlers are registered), add:

```php
pcntl_async_signals(true);
```

so `SIGTERM` from `docker stop` is honored promptly instead of waiting up to the 30s consume
timeout (which otherwise causes `SIGKILL` mid-send). Keep the existing handler that sets the
loop-exit flag.

**Verify**: `grep -n "pcntl_async_signals" site/cgi-bin/kafka_email_worker.php` → one match.

### Step 5: Keep copies identical, lint, analyze

- `diff site/cgi-bin/kafka_email_worker.php manager/cgi-bin/kafka_email_worker.php` → no difference.
- `php -l` on both → no syntax errors.
- Both PHPStan runs exit 0.

### Step 6: Manual integration check (Docker, with Kafka up)

1. Bring the stack up. Tail the worker log.
2. Publish a valid email message to the topic (use kafka-ui or `EmailProducer`). Confirm it
   sends and the offset advances (it is not re-consumed on restart).
3. Temporarily break SMTP (e.g. wrong `mail_from_host`), publish a message, confirm the log
   shows "offset NÃO comitado" and the message is **redelivered** (still pending) — then restore
   SMTP and confirm it eventually sends.
4. Publish a malformed payload (`{"foo":1}`); confirm it is logged as poison, committed, and
   does **not** crash the worker.

If you cannot run a live Kafka, **STOP after Step 5** and hand off Step 6 to the operator with
these exact instructions — do not claim the reliability fix verified without the redelivery test.

## Test plan

- This worker is a long-running CLI process not covered by PHPUnit; verification is the Step 6
  manual integration checklist plus `php -l` and PHPStan.
- If `sendEmailViaPHPMailer`'s input validation is extracted into a pure helper during this work,
  add a unit test for it; otherwise no unit test is required.

## Done criteria

ALL must hold:

- [ ] `enable.auto.commit` is `'false'`
- [ ] null/timeout branch `continue`s before any `$message->err` deref
- [ ] offset committed only on success or on poison-drop; transient failure does not commit
- [ ] send wrapped in `catch (\Throwable)`
- [ ] `pcntl_async_signals(true)` present
- [ ] both copies identical; `php -l` clean on both; both PHPStan exit 0
- [ ] Step 6 manual redelivery test passed (or explicitly handed to operator with checklist)
- [ ] `plans/README.md` status row updated

## STOP conditions

- The worker no longer matches the excerpt (it was rewritten) → STOP and re-plan.
- You cannot stand up Kafka+SMTP to run Step 6 → STOP after Step 5; do not mark reliability verified.
- `sendEmailViaPHPMailer` already commits offsets internally → STOP; the commit model differs from this plan's assumption.

## Maintenance notes

- **Follow-up infra plan (not here):** move `kafka_email_worker.php` out of `entrypoint.sh`
  backgrounding into first-class compose services with `restart: always`, and add a
  "recycle after N messages / M minutes" graceful exit to bound memory in the long-running
  process. The in-process `while(true)` restart loop should then be removed.
- The worker currently has its own `log_message()` logging alongside the structured `Logger`
  singleton. Consolidating on `Logger` (gated by `LOG_LEVEL`) is a worthwhile follow-up but is
  out of scope here.
- With manual commits, watch consumer lag: a persistent SMTP outage now correctly *holds*
  messages (lag grows) instead of silently dropping them. That is the intended trade-off.
