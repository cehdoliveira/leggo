# Plan 005: Dead and risky helper functions are removed

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

`CommonFunctions.php` carries three helpers that are unused by the application and carry
either security risk or production-hygiene smell:

- `utf8_unserialize()` (lines 317-322) wraps **`unserialize()`**. PHP object deserialization
  is a classic RCE/object-injection sink. It has no callers in the app (only its own
  definition appears in a grep). A live `unserialize` helper sitting in a shared lib invites a
  future caller to wire user input into it.
- `print_pre()` (lines 61-69) and `var_pre()` (lines 75-83) dump `print_r` / `var_dump`
  output wrapped in `<pre>` with an optional `exit()`. These are debug aids; if ever reached
  in production they leak internal state unescaped.

Removing them shrinks the attack/maintenance surface. **First** prove they are unused
(Step 1) — if a caller exists, this becomes a STOP, not a deletion.

## Current state

Both `CommonFunctions.php` copies are identical. Relevant excerpts from
`site/app/inc/lib/CommonFunctions.php`:

```php
// lines 61-69
function print_pre(mixed $data, bool $stop = false): void
{
  print("<pre>");
  print_r($data);
  print("</pre>");
  if ($stop) { exit(); }
}

// lines 75-83
function var_pre(mixed $data, bool $stop = false): void
{
  print("<pre>");
  var_dump($data);
  print("</pre>");
  if ($stop) { exit(); }
}

// lines 317-322
function utf8_unserialize(string $data): mixed
{
  return unserialize(preg_replace_callback('/s:([0-9]+):\"(.*?)\";/', function ($matches) {
    return "s:" . strlen($matches[2]) . ':"' . $matches[2] . '";';
  }, $data));
}
```

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Find callers (run first) | `grep -rn -E "utf8_unserialize\|print_pre\|var_pre" --include="*.php" site manager \| grep -v vendor` | only the definitions in `CommonFunctions.php` |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Full unit suite (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` | all pass |

## Scope

**In scope** (delete three functions from each copy):
- `site/app/inc/lib/CommonFunctions.php`
- `manager/app/inc/lib/CommonFunctions.php`

**Out of scope**:
- Every other function in the file.
- The `$GLOBALS["..._lists"]` encoding helpers (`html_accents`, `remove_accents`, etc.) — keep.

## Git workflow

- Branch: `advisor/005-remove-dead-helpers`
- Commit: `chore: remove helpers nao utilizados (utf8_unserialize, print_pre, var_pre)`
- Edit both copies in the same commit.

## Steps

### Step 1: Prove the three functions are unused

Run the caller grep (table above). The **only** acceptable output is the function definitions
themselves inside `CommonFunctions.php` (and possibly this plan file / docs).

**Verify**: no call site exists in `site/` or `manager/` application/controller/model/view code.

If any real caller exists → **STOP** (see STOP conditions). Do not delete a function that is
used.

### Step 2: Delete the three functions

Remove `print_pre`, `var_pre`, and `utf8_unserialize` (function body + its doc comment) from
**both** copies.

**Verify**: `grep -rn -E "function (print_pre|var_pre|utf8_unserialize)" site manager | grep -v vendor`
→ no matches.

**Verify**: `diff site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php`
→ no difference.

### Step 3: Static analysis + suite clean

Run both PHPStan commands (exit 0) and the full unit suite in Docker (all pass). Removing
unused functions must not break analysis or tests.

## Test plan

- No new tests (deleting dead code). Verification is: caller grep is clean, PHPStan exits 0,
  and the existing PHPUnit suites still pass in Docker.
- Confirm `CommonFunctionsTest` still passes — it does **not** reference any of the three
  removed functions (it covers `generate_key`, `generate_slug`, accents, `sanitize_string`,
  `set_url`).

## Done criteria

ALL must hold:

- [ ] `grep -rn -E "function (print_pre|var_pre|utf8_unserialize)" site manager | grep -v vendor` returns nothing
- [ ] `grep -rn "unserialize(" site/app manager/app | grep -v vendor` returns nothing
- [ ] Both `phpstan analyse` runs exit 0
- [ ] Full PHPUnit suite passes in Docker for both envs
- [ ] `diff` of the two `CommonFunctions.php` copies shows no difference
- [ ] `plans/README.md` status row updated

## STOP conditions

- Step 1 grep finds a real caller of any of the three functions → stop and report which one
  and where; that function is not dead and the operator must decide.
- The two `CommonFunctions.php` copies differ before you start → stop (drift).

## Maintenance notes

- If structured debug output is ever wanted, route it through `Logger::getInstance()` (already
  in the codebase) rather than reintroducing `print_pre`/`var_pre`.
- If a UTF-8-safe unserialize is genuinely needed later, use JSON (`json_encode`/`json_decode`)
  for any data that could be attacker-influenced — never `unserialize()` on untrusted input.
