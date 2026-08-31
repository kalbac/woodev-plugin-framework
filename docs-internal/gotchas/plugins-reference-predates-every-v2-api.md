# gotcha: `plugins-reference/` cannot answer "how many consumers does this v2 API have" — it predates all of them

**Namespace:** `[tooling/references]`
**Discovered:** s111 (2026-09-01), cards #708 and #709

## What happened

Two cards independently ended with the same instruction before their fork could be chosen:

> ⚠ Measure: how many takeover fields in `plugins-reference/` are declared required. (#708)
> ⚠ Before choosing, measure how this is done in the three plugins in `plugins-reference/` — pickup
> is live there, and if they already duplicate, the migration volume will be visible. (#709)

Both measurements returned **zero**, and not because the search was wrong.

## Root cause: those plugins bundle framework 1.3.3 / 1.4.0

```text
plugins-reference/woocommerce-edostavka/woodev/class-plugin.php:20    const VERSION = '1.3.3';
plugins-reference/woocommerce-yandex-delivery/woodev/class-plugin.php const VERSION = '1.4.0';
plugins-reference/woodev-russian-post/…                               const VERSION = '1.3.3';
plugins-reference/woodev-vkredit/…                                    const VERSION = '1.3.3';
```

None of them ships `woodev/shipping-method/checkout/` at all. Every v2 checkout, pickup and
location API — `takeover_condition`, `Pickup_Field`, `set_requires_pickup_methods`,
`is_pickup_shipping`, `pickup_method_ids`, `Selection_Scope` — was written **after** these copies
were taken. Grepping them for a v2 symbol returns zero by construction.

## The trap

Zero hits reads like "measured: no consumers, so breaking it is free". That conclusion is right,
but not for the reason the grep suggests — and the same zero would appear for an API with a
thousand real consumers, because these copies simply cannot contain any v2 symbol.

## ✅ How to answer the question that was actually being asked

- **"What does breaking this cost?"** → zero out-of-tree, always, for any v2-only API. Say so with
  the version numbers above as the evidence, not with a grep hit count.
- **"How is this done in a real carrier plugin?"** → `plugins-reference/` still answers this, but
  only for **v1** mechanisms. It is a donor for prior art and for installed-site DATA contracts
  (which is why the Yandex contract tests read it), never a census of v2 API usage.
- **"Who calls this today?"** → the framework itself plus `tests/_fixtures/`. Use Serena
  `find_referencing_symbols`, not a grep over `plugins-reference/`.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other way this directory misleads a measurement
- `../adr/005-platform-v2-clean-break-policy.md` — why zero out-of-tree consumers makes a break free
