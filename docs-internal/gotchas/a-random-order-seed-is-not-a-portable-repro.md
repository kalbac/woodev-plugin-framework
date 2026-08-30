# Gotcha: [testing/phpunit] — a `--random-order-seed` is not a portable reproduction, and a defect reported as one reads as unreproducible
> Tags: testing, phpunit, measurement | Session: s107

## What happens

A worker sweeping orderings reports a real find:

```bash
rm -f .phpunit.result.cache
php vendor/bin/phpunit --testsuite=Unit --order-by=random --random-order-seed=2
# 1) LicenseOutageGraceTest::test_real_validate_license_transport_failure_never_touches_claims
#    Error: Call to undefined function get_option()
```

It reproduces twice in a row for them, so it goes on the card as the reproduction recipe. The
coordinator runs that exact command on `main` and gets a **green** suite — in both sodium
configurations. At that point the obvious reading is "not reproducible, the worker was wrong", and
a genuine latent defect gets closed.

## Root cause

The seed does not determine the order on its own. It seeds a shuffle **of the test set that this
tree happens to contain**. Add or remove a single test and the same seed produces an entirely
different permutation.

That is exactly what happened in s107: the worker's worktree was branched before three merges and
held **3297** tests; `main` by then held **3306**. Same seed, different set, different order — so
the ordering that exposed the leak simply did not occur.

The same applies to anything else that changes the set: `plugins-reference/` being absent skips five
contract tests, and `ext-sodium` being off skips dozens more (`--filter` too, obviously). A seed is
only meaningful relative to one exact set.

## Fix — carry the DEFECT, never the seed

❌ Wrong — the card says how you happened to see it:

> Reproduce with `--random-order-seed=2`.

✅ Correct — the card says what is broken, in terms that survive the tree changing:

> `tests/unit/LicenseOutageGraceTest.php` never declares `get_option`, while its production path
> reaches `class-license-command-acks.php:208`, which calls it. 51 other test files declare it, so
> this one is green only when one of them ran earlier in the same process.

The second form is checkable by reading, needs no ordering at all, and stays true after every merge.
Keep the seed in the card as *how it was noticed* if you like — just never as the reproduction.

**The sharpest check for this class of defect is isolation, not ordering:** run the single test file
alone (`--filter <TestClass>`), where no neighbour can have defined the function for it. If it fails
alone and passes in the suite, the dependency is proved without any seed.

## Related

- [brain-monkey-function-pollution](brain-monkey-function-pollution.md) — the leak this seed was chasing
- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — the other reason two runs of one tree disagree
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — one way a worktree's test SET differs
- [the-skipped-count-is-dominated-by-whether-sodium-is-enabled](the-skipped-count-is-dominated-by-whether-sodium-is-enabled.md) — another
- [phpunit-takes-one-path-and-silently-ignores-the-rest](phpunit-takes-one-path-and-silently-ignores-the-rest.md) — why the isolation check needs `--filter`
