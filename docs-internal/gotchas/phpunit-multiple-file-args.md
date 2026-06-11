# [testing/unit] PHPUnit silently runs ONLY the first file argument when given several

> Namespace: `testing/*` — added session 9 (2026-06-11)

## The trap

```bash
./vendor/bin/phpunit tests/unit/FileATest.php tests/unit/FileBTest.php
```

PHPUnit 9.x treats only the FIRST path as the test target; the second argument is
silently ignored (it is parsed as an unused positional). The run prints a normal
green summary, so "22/22 both files" can actually mean "file A only — file B never
executed". This bit the s8-p1 worker: its "both files pass" claim covered only
`NormalizeSiteTest`; `LicenseEnvelopeVerifierTest` had never run.

## Correct

- Run each file in its own invocation, OR use a filter/suite:
  `./vendor/bin/phpunit --testsuite Unit`, `--filter 'FileATest|FileBTest'`.
- When verifying a worker's claim, check the reported TEST COUNT against the
  per-file counts — a sum that equals one file's count is the tell.

## Incorrect

- Passing N file paths and trusting the green summary.

## Related

- [[brain-monkey-function-pollution]] — the other "looked green for the wrong reason" trap.
