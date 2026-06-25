# Plan 010: Neutralize CSV formula injection in `array_to_csv()`

> **Executor instructions**: Follow step by step, verify each step, honor STOP conditions,
> update `plans/README.md` when done.
>
> **Drift check (run first)**: `git diff --stat 86e28f1..HEAD -- site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php`
> On any change, re-read the excerpts below against live code before editing.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security (CSV/formula injection, CWE-1236)
- **Planned at**: commit `86e28f1`, 2026-06-25

## Why this matters

`array_to_csv()` writes database values straight to a CSV via `fputcsv()` with no
neutralization of formula-leading characters. The manager user export
(`manager/app/inc/controller/site_controller.php`, the `export-csv` action) feeds
user-controlled fields — `name`, `mail`, `login` — into this function. A user who
registers with a name such as `=HYPERLINK("http://evil","click")` or `@SUM(1+1)*cmd|...`
gets that string written verbatim into the CSV. When an admin opens the export in
Excel/LibreOffice/Google Sheets, the spreadsheet **interprets the cell as a formula**
and can exfiltrate data or trigger command execution (via DDE) — a classic stored
formula-injection that turns the admin's own export into the attack vector.

This is exploitable today through normal registration input. The fix is small and
behavior-preserving for all legitimate (non-formula) values.

## Current state

Both `CommonFunctions.php` copies are byte-identical. Relevant excerpt from
`site/app/inc/lib/CommonFunctions.php` (the `array_to_csv` body, around lines 844-866):

```php
$output = fopen('php://output', 'w');

if (empty($data)) {
  fclose($output);
  exit();
}

if ($headers === null) {
  $headers = array_keys(reset($data));
}

fputcsv($output, $headers, ';', '"', '\\');

foreach ($data as $row) {
  $csvRow = [];
  foreach ($headers as $key) {
    $csvRow[] = $row[$key] ?? '';
  }
  fputcsv($output, $csvRow, ';', '"', '\\');
}

fclose($output);
exit();
```

There is no sanitization between reading `$row[$key]` and `fputcsv`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Confirm callers | `grep -rn "array_to_csv" --include="*.php" site manager \| grep -v vendor` | definition + the manager export-csv call |
| PHPStan site | `cd site && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| PHPStan manager | `cd manager && php app/inc/lib/vendor/bin/phpstan analyse` | exit 0 |
| Unit suite (Docker) | `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit` | all pass |

## Scope

**In scope**:
- `site/app/inc/lib/CommonFunctions.php` — add sanitization inside `array_to_csv`.
- `manager/app/inc/lib/CommonFunctions.php` — identical edit (the two copies must stay byte-identical).
- A new unit test in `site/tests/CommonFunctionsTest.php` and the identical `manager/tests/CommonFunctionsTest.php`.

**Out of scope**:
- The calling controller (`site_controller.php`) — no change needed; the fix belongs in the shared helper so every future CSV export is covered.
- Any other function in `CommonFunctions.php`.
- The CSV delimiter/quote/escape arguments — keep `';', '"', '\\'` exactly as-is.

## Git workflow

- Branch: `advisor/010-csv-formula-injection`
- Commit: `fix: neutraliza injecao de formula em exportacao CSV (array_to_csv)`
- Edit both copies + both test files in the same commit.

## Steps

### Step 1: Add a private sanitizer helper inside `array_to_csv`'s file scope

Add a small pure function near `array_to_csv` (top of the same file region is fine, or
inline a closure). It must prefix any value whose first character is one of
`= + - @`, TAB (`\t`), or CR (`\r`) with a single apostrophe `'`, which spreadsheets
treat as "force text". Leave all other values untouched. Cast non-strings with `(string)`
first so numeric/`null` cells are handled.

Reference implementation (match the file's existing 2-space indentation and brace style):

```php
function csv_sanitize_cell(mixed $value): string
{
  $value = (string) ($value ?? '');
  if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
    return "'" . $value;
  }
  return $value;
}
```

**Verify**: `grep -n "function csv_sanitize_cell" site/app/inc/lib/CommonFunctions.php` → one match.

### Step 2: Route every data cell through the sanitizer

In the `foreach ($data as $row)` loop, change:

```php
$csvRow[] = $row[$key] ?? '';
```

to:

```php
$csvRow[] = csv_sanitize_cell($row[$key] ?? '');
```

Do **not** sanitize the `$headers` row — headers are developer-defined, not user input,
and sanitizing them could corrupt legitimate column names. (If you prefer defense-in-depth,
sanitizing headers too is acceptable and harmless given current callers — but it is optional.)

**Verify**: `diff site/app/inc/lib/CommonFunctions.php manager/app/inc/lib/CommonFunctions.php` → no difference.

### Step 3: Add a regression test

In `site/tests/CommonFunctionsTest.php`, add a test that captures `array_to_csv` output.
`array_to_csv` ends with `exit()` and writes to `php://output`, so test the **sanitizer
function directly** (`csv_sanitize_cell`) rather than the `exit()`-terminating wrapper:

```php
public function test_csv_sanitize_cell_prefixes_formula_leading_chars(): void
{
    $this->assertSame("'=HYPERLINK(\"x\")", csv_sanitize_cell('=HYPERLINK("x")'));
    $this->assertSame("'+1+1", csv_sanitize_cell('+1+1'));
    $this->assertSame("'-2", csv_sanitize_cell('-2'));
    $this->assertSame("'@SUM(1)", csv_sanitize_cell('@SUM(1)'));
    // Benign values pass through untouched
    $this->assertSame('Carlos', csv_sanitize_cell('Carlos'));
    $this->assertSame('a@b.com', csv_sanitize_cell('a@b.com')); // '@' only matched at position 0
    $this->assertSame('', csv_sanitize_cell(null));
}
```

Apply the identical test to `manager/tests/CommonFunctionsTest.php`.

**Verify**: `docker exec leggo php /var/www/leggo/site/app/inc/lib/vendor/bin/phpunit --filter test_csv_sanitize_cell_prefixes_formula_leading_chars` → passes.

### Step 4: Static analysis + full suite

Run both PHPStan commands (exit 0) and the full PHPUnit suite in Docker for both envs (all pass).

## Test plan

- New test `test_csv_sanitize_cell_prefixes_formula_leading_chars` in **both** test files
  (they are kept identical), following the existing `CommonFunctionsTest` style (it already
  covers `generate_slug`, accents, `set_url`).
- No test for the `exit()`-terminating `array_to_csv` wrapper itself — testing the extracted
  pure helper is the correct seam.

## Done criteria

ALL must hold:

- [ ] `csv_sanitize_cell` exists and is called for every data cell in `array_to_csv`
- [ ] `grep -n "csv_sanitize_cell(\$row" site/app/inc/lib/CommonFunctions.php` → matches
- [ ] `diff` of the two `CommonFunctions.php` copies shows no difference
- [ ] `diff` of the two `CommonFunctionsTest.php` copies shows no difference
- [ ] Both `phpstan analyse` runs exit 0
- [ ] Full PHPUnit suite passes in Docker for both envs
- [ ] `plans/README.md` status row updated

## STOP conditions

- The two `CommonFunctions.php` copies differ before you start → stop (pre-existing drift; report it).
- `array_to_csv` no longer matches the excerpt (it was refactored) → stop and re-plan against live code.

## Maintenance notes

- Any future CSV/TSV export must reuse `array_to_csv` (or at least `csv_sanitize_cell`) — do
  not hand-roll `fputcsv` on user data elsewhere.
- The single-quote prefix is the OWASP-recommended mitigation; it is intentionally visible in
  the cell. If a stakeholder objects to the leading apostrophe on legitimate values, the only
  safe alternative is wrapping the whole field and instructing recipients to import as text —
  do **not** drop the sanitization.
