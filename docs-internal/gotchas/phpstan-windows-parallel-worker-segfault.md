# [tooling/phpstan-windows-segfault] PHPStan crashes with exit `-1073741819` on Windows — environmental, not a code error

> Namespace: `[tooling/*]` · Added s28 (2026-06-21)

## Symptom

`composer phpstan` / `composer check` fails with:

```
[ERROR] Found 1 error
  Child process error (exit code -1073741819):
⚠️  Result is incomplete because of severe errors. ⚠️
```

`-1073741819` is `0xC0000005` — a **native access violation / stack overflow** of a PHPStan
worker process, not an analysis finding. The stack trace points into PHPStan's recursive
PHPDoc type resolver (`TypeNodeResolver->resolveUnionTypeNode` → `resolveReturnTag` →
`MutatingScope->resolveType` for a `wp_safe_remote_request`-style call). On this codebase it
surfaced inside `woodev/account/class-account-connection.php`'s `request()` return-type
resolution.

## Why it's a trap

- It is **non-deterministic**: the same code passed `composer check` cleanly minutes earlier,
  then crashed on the next run. Adding/removing a file shifts worker file-ordering and changes
  whether a worker hits the deep recursion that overflows the native stack.
- It crashes even when analysing a **single untouched file** (`phpstan analyse
  woodev/account/class-account-connection.php`) and even with `--debug` (single-process),
  proving it is unrelated to whatever you just changed.
- Clearing the result cache (`phpstan clear-result-cache -c phpstan.neon`) does **not** fix it.

## How to confirm it's environmental (not your code)

Analyse a file you did **not** touch in isolation:
`./vendor/bin/phpstan analyse --memory-limit=2G --no-progress woodev/account/class-account-connection.php`
If that crashes too, your change is not the cause.

## Resolution

- **Linux CI is the authoritative PHPStan gate.** The `Lint` job (`ci.yml`, "Run PHPStan",
  PHP 8.1) runs `./vendor/bin/phpstan analyse --memory-limit=2G` on Linux, which has a larger
  native stack and does **not** segfault. In s28 the exact code that crashed locally passed
  "Run PHPStan: success" on CI.
- Do **not** chase it as a code bug, and do **not** add ignores/baseline to "fix" it.
- If you must get a local pass: retry (it is flaky), close other heavy processes (background
  `composer`/agents raise memory pressure), or rely on CI.
- Still run `composer phpcs` + `composer test:unit` locally — those are stable on Windows;
  only PHPStan has this native-crash flakiness here.

## s106 (2026-08-30): the same command now fails a SECOND way — the memory limit, not the stack

`--memory-limit=2G`, the value this file recommends above and the one CI still uses, is **no longer
enough for a local run on this tree**. The failure is a different one wearing the same coat:

```
Child process error: PHPStan process crashed because it reached configured PHP memory limit: 2G
 while running parallel worker
[ERROR] Found 1 error
⚠️  Result is incomplete because of severe errors. ⚠️
```

It is **not** the `-1073741819` access violation above, and it is **not** flaky — it reproduces every
run. The trap is the last two lines: `Found 1 error` plus "result is incomplete" is exactly what a
real analysis failure prints, so a session reads it as "PHPStan found something" and starts hunting
a defect in the diff it just wrote. There is nothing to find.

**Locally, use `--memory-limit=4G`.** With it the same tree reports `[OK] No errors`. CI stays on
2G and stays green, because a Linux worker's footprint on the same file set is smaller — so a local
2G crash proves nothing about CI, in either direction.

Note the asymmetry with the segfault above: that one is environmental and CI is the authority; this
one is a local resource setting and the fix is a flag, not a shrug.

## Related

- [[framework-classmap-autoload-vendored-boot]] — the s27/s28 autoloader work that PHPStan analyses
- [[codex-shell-sandbox-broken-windows]] — another Windows-only tooling crash on this box
- [[serena-replace-content-eol-flip]] — Windows-only tooling gotcha
