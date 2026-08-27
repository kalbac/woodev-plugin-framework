# gotcha: `phpunit file-a file-b` runs only file-a — and a falsification run then proves less than it looks

**Namespace:** `[testing/*]`
**Discovered:** s99 (2026-08-27), falsifying the #451 tests

## What happened

Three test files were falsified against the unfixed source in one command:

```bash
vendor/bin/phpunit tests/unit/Api/ApiBaseResponseMessageRedactionTest.php \
                   tests/unit/Api/LicensingApiReasonPhraseRedactionTest.php \
                   tests/unit/Shipping/Location/DadataApiClientTest.php
```

It reported `Tests: 6` and three failures. Six is the count of the FIRST file alone. PHPUnit takes
a single path argument; the other two were ignored without a word, so two of the three files had
never been falsified at all. The output looked exactly like a successful three-file run — the same
shape, a plausible number, three failures where three were expected.

Falsification is the one measurement whose whole value is that it ran. A falsification run that
silently covered a third of what it claimed is worse than none, because it is recorded as evidence.

## ✅ One path per run, in a loop

```bash
for f in tests/unit/Api/A.php tests/unit/Api/B.php; do
  echo "=== $f ==="
  rm -f .phpunit.result.cache
  vendor/bin/phpunit --testsuite=Unit "$f"
done
```

Or select across the whole suite by name, which is usually what was actually meant:

```bash
vendor/bin/phpunit --testsuite=Unit --filter "promote|pickup_point_selected"
```

**Always pass `--testsuite=Unit`.** Without it PHPUnit loads every configured suite, including
Integration, and dies before running anything:

```text
Class "WP_UnitTestCase" not found
```

That failure is loud, so it costs minutes rather than trust — unlike the silent one above.

## Read the count, not the colour

The check that would have caught this immediately is comparing the reported `Tests: N` against the
number of tests those files actually contain. A run whose count belongs to one file is not a run
over three.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the same class: a green suite that quietly ran fewer tests than the baseline
- [powershell-drops-the-roots-flag-from-the-jest-command](powershell-drops-the-roots-flag-from-the-jest-command.md) — the jest counterpart, an argument silently dropped
