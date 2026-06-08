# `composer audit --no-dev` errors when there are no runtime dependencies

> [build/ci] — discovered 2026-06-08 fixing PR #20 Lint job.

## The trap

The framework's `composer.json` declares **only** `"php"` under `require` — zero runtime
(non-dev) package dependencies. The CI Lint job ran:

```yaml
- name: Security audit
  run: composer audit --no-dev
```

`--no-dev` restricts the audit to non-dev installed packages; with none, Composer aborts:

```
No installed packages found. Please run "composer install" before running "audit"
or pass "--locked" to audit the lock file.
```

Exit code 1 → the whole Lint job fails before PHPCS/PHPStan even run.

## Fix

```yaml
run: composer audit --locked
```

`--locked` audits every entry in `composer.lock` (here, the dev toolchain — phpunit, phpcs,
phpstan, brain-monkey, mockery) for known advisories. `composer install` generates the lock
on CI even though `composer.lock` is gitignored, so `--locked` works after install.

## Why it matters

`--no-dev` is the intuitive "only audit what ships" flag, but for a library with no runtime
deps it audits *nothing* and Composer treats "nothing to audit" as an error, not a pass.

## How to apply

- For a package with an empty (or `php`-only) `require`, audit the lock file (`--locked`) or
  all installed deps (`composer audit`), not `--no-dev`.
- This step is identical on `main`; it had been failing there too (see [[ci-failing-gate-skips-dependent-jobs]]).

## Related

- [[ci-failing-gate-skips-dependent-jobs]] — this failure gated/skipped the Unit Tests matrix
