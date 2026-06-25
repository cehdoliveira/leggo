# Plan 003: `set_url()` parses query strings without warnings or data loss

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php site/tests/CommonFunctionsTest.php manager/tests/CommonFunctionsTest.php`
> On any change, compare the excerpt below to live code before editing.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

`set_url()` rebuilds a URL's query string. It splits each existing pair with
`explode("=", $segment)` and destructures into `list($kp, $vp)`. Two defects:

1. A segment with no `=` (e.g. the `foo` in `?foo&bar=1`) yields a one-element array, so
   `$vp` is **undefined** → PHP warning, and the valueless flag is dropped.
2. `explode` has no limit, so a value containing `=` (e.g. `?redirect=a=b`) splits into 3
   parts and only `a` is kept — the value is **truncated**.

Low blast radius today (only the test suite calls `set_url`), but it is a latent correctness
bug in a shared helper, and the fix is small and well-testable.

## Current state

Both copies are identical. `site/app/inc/lib/CommonFunctions.php` lines 137-162:

```php
function set_url(string $url = "", array $params = []): string
{
  $tmp = preg_split('/\?/', $url);
  if ($tmp === false) {
    return $url;
  }
  $url = $tmp[0];
  $p = "";
  if (isset($tmp[1])) {
    $p .= "?";
    foreach (explode("&", $tmp[1]) as $tmp_params) {
      list($kp, $vp) = explode("=", $tmp_params);     // <-- bug: no limit, no missing-value guard
      if (! in_array($kp, $params)) {
        $p .= $kp . "=" . $vp . "&";
      }
    }
  }
  foreach ($params as $kp => $vp) {
    if ($p == "") {
      $p = "?";
    }
    $p .= $kp . "=" . $vp . "&";
  }
  $p = preg_replace("/\&$/", "", $p);
  return $url . $p;
}
```

Note the existing `in_array($kp, $params)` check compares a string key against `$params`,
whose keys are the param names — this is pre-existing odd behavior; **do not "fix" it** in
this plan (the existing tests pin current behavior and it is out of scope). Only fix the
`explode` destructuring.

Existing tests, `site/tests/CommonFunctionsTest.php` lines 71-82 (manager copy identical):

```php
public function testSetUrlAddsParams(): void
{
    $url = set_url("http://example.com", ["page" => "2"]);
    $this->assertStringContainsString("page=2", $url);
}

public function testSetUrlPreservesExistingParams(): void
{
    $url = set_url("http://example.com?a=1", ["b" => "2"]);
    $this->assertStringContainsString("a=1", $url);
    $this->assertStringContainsString("b=2", $url);
}
```

This file extends `PHPUnit\Framework\TestCase` (no DB needed) — use it as the pattern.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Run the targeted tests (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit --filter SetUrl` | all pass |
| Same, manager | `docker exec leggo php /var/www/leggo/manager/app/inc/lib/vendor/bin/phpunit --filter SetUrl` | all pass |

## Scope

**In scope**:
- `site/app/inc/lib/CommonFunctions.php` — `set_url()` only
- `manager/app/inc/lib/CommonFunctions.php` — identical edit (shared file — both copies!)
- `site/tests/CommonFunctionsTest.php`, `manager/tests/CommonFunctionsTest.php` — add tests

**Out of scope**:
- The `in_array($kp, $params)` filtering logic (pre-existing, separately questionable).
- Any other function in `CommonFunctions.php`.

## Git workflow

- Branch: `advisor/003-fix-set-url-parsing`
- Commit: `fix: set_url() trata segmentos sem '=' e valores com '='`
- Apply the source edit to **both** `CommonFunctions.php` copies in the same commit.

## Steps

### Step 1: Make the destructuring safe

Replace the `list($kp, $vp) = explode("=", $tmp_params);` line (and its use) with a version
that limits the split to 2 and supplies a default for a missing value. Target shape:

```php
foreach (explode("&", $tmp[1]) as $tmp_params) {
  if ($tmp_params === "") {
    continue;
  }
  $parts = explode("=", $tmp_params, 2);   // limit 2: values may contain '='
  $kp = $parts[0];
  $vp = $parts[1] ?? "";                    // valueless flags preserved as key=
  if (! in_array($kp, $params)) {
    $p .= $kp . "=" . $vp . "&";
  }
}
```

Apply the identical change to the manager copy.

**Verify**: `grep -n 'explode("=", $tmp_params, 2)' site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php`
→ one hit in each file.

### Step 2: Add regression tests

In **both** `CommonFunctionsTest.php` files, add two cases:

```php
public function testSetUrlPreservesValueWithEquals(): void
{
    $url = set_url("http://example.com?redirect=a=b", ["page" => "1"]);
    $this->assertStringContainsString("redirect=a=b", $url);
}

public function testSetUrlHandlesValuelessSegment(): void
{
    // Must not emit a warning and must keep the flag
    $url = set_url("http://example.com?debug&x=1", ["y" => "2"]);
    $this->assertStringContainsString("debug=", $url);
    $this->assertStringContainsString("x=1", $url);
}
```

**Verify**: the `--filter SetUrl` runs (table above) pass for both environments, including the
two new tests.

### Step 3: Static analysis clean

Run both PHPStan commands → exit 0.

## Test plan

- New tests: `testSetUrlPreservesValueWithEquals` (value containing `=` survives) and
  `testSetUrlHandlesValuelessSegment` (no warning, flag preserved) — in both env test files.
- Pattern to follow: the existing `testSetUrl*` methods in `CommonFunctionsTest.php`.
- Existing `testSetUrlAddsParams` / `testSetUrlPreservesExistingParams` must still pass
  (no behavior change for the common case).

## Done criteria

ALL must hold:

- [ ] Both `CommonFunctions.php` copies use `explode("=", $tmp_params, 2)` with a `?? ""` default
- [ ] Both `CommonFunctionsTest.php` files contain the two new tests
- [ ] `--filter SetUrl` passes for site and manager in Docker
- [ ] Both `phpstan analyse` runs exit 0
- [ ] `diff site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php` shows no difference (copies stayed in sync)
- [ ] `plans/README.md` status row updated

## STOP conditions

- The two `CommonFunctions.php` copies are **not** identical at the `set_url` region before
  you start (they have already drifted) — stop and report; reconcile is a separate decision.
- An existing `testSetUrl*` test starts failing after your change → the common-case behavior
  shifted; stop and report.

## Maintenance notes

- The `in_array($kp, $params)` filter is still semantically murky (string key vs assoc keys).
  Flag it for a future cleanup but do not change it here.
- Reviewer: confirm both env copies changed identically (the `diff` done-criterion).
