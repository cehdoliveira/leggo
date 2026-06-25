# Plan 007: Content-Security-Policy is tightened without breaking Alpine.js

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done. This plan changes nginx config that is loaded at
> container start — verification requires reloading nginx and loading the app in a browser.
>
> **Drift check (run first)**: `git diff --stat ccc4095..HEAD -- docker/interface/default.conf`
> On any change, re-read the excerpt below against live config before editing.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `ccc4095`, 2026-06-22

## Why this matters

Both nginx vhosts send a CSP whose `script-src` includes both `'unsafe-inline'` **and**
`'unsafe-eval'`. `'unsafe-inline'` on scripts means an injected `<script>` or inline handler
executes — it neutralizes much of CSP's value as an XSS backstop. The policy also omits
`object-src` and `base-uri`, two cheap directives that block plugin-based and `<base>`-tag
injection vectors.

**Constraint that bounds this plan**: the UI uses Alpine.js with inline expressions
(`@click="..."`, `x-data`, `@submit.prevent`), which require `'unsafe-eval'` to function.
Removing `'unsafe-eval'` would break the dashboard. So this plan does **not** remove
`'unsafe-eval'`. It targets the achievable hardening: add `object-src 'none'` and `base-uri
'self'`, and document the inline-handler dependency. Fully removing `'unsafe-inline'` requires
migrating inline event handlers to a nonce/CSP-friendly Alpine setup — that is a larger,
separate effort, called out in Maintenance.

## Current state

`docker/interface/default.conf` — **two** identical `server {}` blocks (manager.leggo.local
and leggo.local). Each contains:

```
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:" always;
```

Note: `site/public_html/index.php` and `manager/public_html/index.php` also set some security
headers via PHP `header()` (`X-Frame-Options`, etc.) but **not** CSP — CSP is nginx-only, so
this plan only edits nginx. The app's inline `@click`/`x-data` usage is visible in
`manager/public_html/ui/page/dashboard.php` (e.g. `@click="openEdit(...)"`).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| nginx config syntax check | `docker exec leggo nginx -t` | "syntax is ok" / "test is successful" |
| Reload nginx | `docker exec leggo nginx -s reload` | exit 0 |
| Inspect sent header | `curl -sI http://leggo.local/login \| grep -i content-security-policy` | shows the new directives |
| Count edited blocks | `grep -c "object-src 'none'" docker/interface/default.conf` | `2` |

## Scope

**In scope**:
- `docker/interface/default.conf` — the `Content-Security-Policy` line in **both** server blocks.

**Out of scope**:
- Removing `'unsafe-eval'` (breaks Alpine) or `'unsafe-inline'` (breaks inline handlers) — see Maintenance.
- Any PHP `header()` change in `index.php`.
- The other security headers (`X-Frame-Options` etc.) — already present and fine.

## Git workflow

- Branch: `advisor/007-tighten-csp`
- Commit: `fix: endurece CSP com object-src 'none' e base-uri 'self'`
- Both server blocks edited identically in the same commit.

## Steps

### Step 1: Add `object-src` and `base-uri` to both blocks

In each `server {}` block, change the CSP `add_header` to append the two directives. Target
value (keep everything else, including `'unsafe-eval'`, exactly as-is):

```
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; object-src 'none'; base-uri 'self'" always;
```

Apply to **both** blocks (manager and site).

**Verify**: `grep -c "object-src 'none'" docker/interface/default.conf` → `2`;
`grep -c "base-uri 'self'" docker/interface/default.conf` → `2`.

### Step 2: nginx accepts the config

**Verify**: `docker exec leggo nginx -t` reports the config test is successful, then
`docker exec leggo nginx -s reload` exits 0.

If the container is not running, start the stack
(`docker compose -f docker/docker-compose.yml up -d`) before testing.

### Step 3: App still works (no CSP regression)

Load both `http://leggo.local/login` and `http://manager.leggo.local/` (logged in) in a
browser and confirm:
- The page renders, fonts/styles load, jsdelivr assets load.
- Alpine interactions still work: open the user edit modal on the manager dashboard
  (`@click="openEdit(...)"`), toggle a user — no CSP violation in the browser console.

**Verify**: browser devtools Console shows **no** `Content Security Policy` violation messages
during normal use. Record a screenshot/console capture in the PR.

## Test plan

- This is infrastructure config; there is no PHPUnit coverage. Verification is manual:
  `nginx -t` passes, the header is present (`curl -sI`), and the app + Alpine work with a clean
  console. Document the console check in the PR.
- No application code changes, so PHPStan/PHPUnit are unaffected (do not need to run, but they
  must remain green if you do).

## Done criteria

ALL must hold:

- [ ] Both server blocks' CSP include `object-src 'none'` and `base-uri 'self'`
- [ ] `'unsafe-eval'` is **still present** in `script-src` (removing it is explicitly out of scope)
- [ ] `docker exec leggo nginx -t` passes and reload succeeds
- [ ] `curl -sI http://leggo.local/login` shows the updated CSP header
- [ ] Manual browser check: dashboard Alpine interactions work, no CSP console violations
- [ ] Only `docker/interface/default.conf` modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

- After reload, the app shows CSP console violations during normal use → revert the change and
  report which directive caused it (the existing inline/eval usage may need a directive you
  removed by accident — re-check you only *added* `object-src`/`base-uri`).
- `nginx -t` fails → you introduced a syntax error; fix the quoting and re-test before reload.

## Maintenance notes

- The real prize — dropping `'unsafe-inline'`/`'unsafe-eval'` — requires moving Alpine.js to a
  CSP-compatible pattern (the `@alpinejs/csp` build, no inline expression strings) and adding a
  per-request nonce to `<script>` tags. That is a multi-file frontend migration; spec it as its
  own plan if/when the team wants strict CSP.
- Reviewer: confirm both server blocks were edited (a one-block edit leaves the other vhost on
  the weaker policy — the same duplication hazard as the shared lib).
