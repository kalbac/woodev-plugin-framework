# Gotcha: [rig/state] — A rig option left switched after an acceptance reads as stale e2e tests

> Tags: rig, e2e, playwright, woocommerce-settings, verification | Session: s155

## What happens

`npm run test:e2e` failed 3 of 7 classic-checkout tests on a clean `main` (s155, 06.10.2026):

```
✘ checkout-pickup.spec.js:130 › location fields fan out to BOTH address columns (Rule 7b, #458)
✘ checkout-pickup.spec.js:157 › a pickup method hides address and postcode on BOTH columns …
✘ checkout-pickup.spec.js:213 › the settlement field is a LIVE source …
```

The failure looked like the tests had drifted behind a recent contract change (#1107 "one locality
field, never two" landed the session before), and a worker asked to fix them rewrote all three
against the DOM it saw — `billing_city`, no shipping column — and reported 7/7 green with a
plausible contract citation for each.

The tests were right. The rig was wrong: `woocommerce_ship_to_destination` was `billing_only`,
switched during the #1107 acceptance (that card is about `billing_only` mode) and never restored.
The rig's documented baseline is `shipping` (`wiki/local-rig.md`, "Which field the live cascade is
actually on"). Restoring it gave 7/7 on the untouched specs, on `main` and on the wave branch.

## Why it is non-obvious

- e2e is not in CI, so the first run after a session that changed a rig option is the first signal,
  and it arrives detached from its cause.
- The failure sits exactly where the last merged work was, so "the tests are stale" is the most
  available explanation — for the coordinator AND for the worker, who saw only the current DOM.
- A worker that edits tests to match the live DOM always succeeds: the rig is the oracle it is
  calibrating against.

## ❌ Wrong

```text
e2e red after a contract change → brief a worker "update the stale expectations" → it matches the rig
```

## ✅ Correct

Before treating an e2e failure as stale tests, diff the rig against its documented baseline:

```bash
npx @wordpress/env run cli wp option get woocommerce_ship_to_destination   # baseline: shipping
```

…and any other option the previous session's cards were about. A session that switches a rig option
for an acceptance restores it before it ends, and says so in its session notes.

## Related

- [../wiki/local-rig.md](../wiki/local-rig.md) — the rig's documented baseline
- [the-rig-runs-en-us-so-no-translation-ever-renders-there](the-rig-runs-en-us-so-no-translation-ever-renders-there.md) — another rig fact that inverts a verification
