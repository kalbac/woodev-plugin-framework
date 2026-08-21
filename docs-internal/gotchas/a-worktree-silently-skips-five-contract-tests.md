# gotcha: a green suite in an agent worktree is not the same suite as in the primary checkout

**Namespace:** `[tooling/parallel-agents]`
**Discovered:** s84 (2026-08-21)

## What happened

Two independent workers reported their unit gate as green and quoted numbers that did not match
the baseline the brief gave them:

```text
primary checkout   2475 tests, 6128 assertions, 66 skipped
agent worktree     2475 tests, 6114 assertions, 71 skipped
```

Same test count, fourteen fewer assertions, five more skips. One critic concluded the brief's
baseline was "stale". It was not. Five tests that RUN in the primary checkout SKIP in every fresh
Orca worktree, and both suites report success either way.

## Root cause: the tests need a gitignored directory that no worktree receives

The four `tests/unit/Contract/Yandex*ContractTest.php` classes (five test methods, fourteen
assertions) guard installed-site data contracts against a donor plugin copy:

```php
$this->markTestSkipped(
    'plugins-reference/woocommerce-yandex-delivery is not present (gitignored); this yandex
     contract guard runs where the reference plugin copy exists.'
);
```

`plugins-reference/` is gitignored (`.gitignore` line 25), so `git worktree add` never creates it,
and it was absent from `.worktreeinclude`, so Orca never copied it. A worker therefore ran five
fewer **release-blocking** data-contract guards than CI — and had no way to notice, because a
skipped test is not a failed test.

## ✅ Fixed — `plugins-reference` is copied into every worktree

```text
# .worktreeinclude
plugins-reference
```

COPIED rather than shared (17 MB): these are read-only references, and a copy keeps a worker from
writing through into the primary checkout. After the fix a worktree measures the same 66 skips as
the primary checkout.

## The transferable lesson

A conditional `markTestSkipped()` turns an absent file into a silently weaker gate. When a
worker's test numbers disagree with the baseline, **the skip count is the first thing to compare**
— not the assertion count, which only tells you something changed. And a brief that quotes a
baseline should quote `tests / assertions / skipped`, all three.

## Related

- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — the other `.worktreeinclude` decision, and why it is a copy too
- [local-npm-run-build-is-not-assets-parity-evidence](local-npm-run-build-is-not-assets-parity-evidence.md) — the other gate that lies inside a worktree
- `../wiki/orchestrating-agents-with-orca.md` — where the worktree contract is documented
