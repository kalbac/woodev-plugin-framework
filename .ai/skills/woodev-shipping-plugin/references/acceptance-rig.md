# Acceptance on the rig

A green unit suite does not prove a carrier plugin works: it stubs the transport, never builds the real class hierarchy and
never boots a second carrier. The framework's local rig (wp-env, `docs-internal/wiki/local-rig.md`) plus the carrier's own test
contour is where a carrier plugin is accepted. The CDEK plugin's harness is the worked example: `tests/acceptance/README.md`,
`tests/acceptance/e2e/` and `scripts/rig/` in `kalbac/woocommerce-edostavka` (framework card #1169, s161).

## Design the gate so it cannot lie

- **One serial run that ends in a PASS / FAIL / UNVERIFIABLE table**, re-run after every large block: setup from zero → checkouts
  (classic `/classic-checkout/` and block `/checkout/` × courier and pickup × prepaid and cash on delivery) → export → print →
  status sync (poller AND webhook) → order metabox → courier call → cancel → cleanup. CDEK's latest: 105 PASS / 12 UNVERIFIABLE /
  0 FAIL, 41/41 tests (s168).
- **A row passes only on CARRIER data.** Judge each outcome against a fresh read of the carrier's record (and the buyer's
  choice, and the order's own totals), never against what the harness wrote. Keep every judgement as a pure function and ship a
  self-test that feeds it doctored data and requires FAIL. The first CDEK run was 33 green; the critic then proved eight
  false-PASS paths with doctored data, and round 2 judged everything against fresh reads (s161).
- **`UNVERIFIABLE` is never `PASS`, and only when positively established** — what the contour cannot do (COD orders stay
  `ACCEPTED` with no number, so no print and no cancel; the print task sometimes answers `INVALID` with an EMPTY reason list; days
  for a non-Moscow sender; a 100-order cap) is listed as such with its reason.
- **The harness drives the same REST routes and hooks the admin page does** (`POST woodev/v1/shipping/orders/{id}/actions/…`,
  `GET …/documents/{type}`, the refresh tool's hook); the React page is not clicked. State that in the README, and add a load
  smoke for the page.
- **Cleanup proves itself**: delete exactly what the run created, cancel every carrier object at the carrier, verify in a FRESH
  carrier listing that webhook subscriptions and intakes are gone (an unreadable listing is a FAIL), restore options byte for byte,
  then fingerprint the rig against its start by identity AND value — options by md5, zones with methods, products, orders with
  plugin meta, queued actions by id. Ignore what the platform changes by itself: WooCommerce background options (edostavka#33) and
  the licence updater's `woodev_<md5>` version cache, which refreshes when its TTL expires mid-run (edostavka#55, gotcha
  [licence updater cache options](../../../../docs-internal/gotchas/the-licence-updater-cache-options-change-by-themselves-during-a-rig-run.md)).
- **A rig measurement on a timer invents defects** — the round trip is 6–10 s, so a premature read looks clean and a plausible
  mechanism is always available. Poll, and add a control: gotcha
  [a rig measurement on a timer](../../../../docs-internal/gotchas/a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md).
  The same applies to carriers: the sandbox accepts what the documentation forbids (40 days ahead, a one-hour window, a 300-char
  comment) and a `202` means nothing — read the object back (CDEK: an invalid intake turns `INVALID` 1.5–3 s later).
- **A carrier sandbox is not a merchant contract**: quotes are sandbox list prices, not contract discounts or surcharges.
- **Measure what you changed against the real thing.** The packing grid "fix" looked right until its volume was measured; the
  `boxes` bug was found only by comparing against physical placement ([packaging](packaging.md)).

## Rig facts that cost sessions

| Fact | Source |
|---|---|
| `/checkout/` on the rig is the BLOCK checkout; the pickup picker lives on `/classic-checkout/` | gotcha [rig checkout URL is the block checkout](../../../../docs-internal/gotchas/rig-checkout-url-is-the-block-checkout.md) |
| The rig runs the plugin's OWN gitignored `woodev/` copy, not the framework checkout: run the plugin's `scripts/sync-framework.sh` before any measurement and prove it on the page, not with `docker inspect` (one acceptance "against" fixed code ran stale code and its report claimed the opposite) | gotcha [the rig runs the plugin's own woodev copy](../../../../docs-internal/gotchas/the-rig-runs-the-cdek-plugins-own-woodev-copy-not-the-framework-checkout.md), s162 |
| The rig serves the WORKING TREE: a branch switch silently un-fixes things, and a concurrent agent's half-written edit fatals every request | gotcha [rig serves the working tree](../../../../docs-internal/gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md) |
| A fresh plugin worktree has no `vendor/` and no `woodev/`; a worker cannot see a pinned-source test break there | gotcha [a fresh plugin worktree](../../../../docs-internal/gotchas/a-fresh-cdek-plugin-worktree-has-no-vendor-and-no-woodev.md) |
| The rig runs `en_US`: no translation ever renders there, so it can show an i18n defect and never its fix | gotcha [the rig runs en_US](../../../../docs-internal/gotchas/the-rig-runs-en-us-so-no-translation-ever-renders-there.md) |
| A rig option left switched after an acceptance reads as "stale e2e tests"; diff the rig against its baseline first | gotcha [a rig option left switched](../../../../docs-internal/gotchas/a-rig-option-left-switched-after-an-acceptance-reads-as-stale-e2e-tests.md) |
| A fixture plugin below the winning framework's `backwards_compatible` floor is silently dropped | gotcha [loader floor](../../../../docs-internal/gotchas/a-fixture-plugin-below-the-winners-backwards-compatible-floor-is-silently-dropped.md) |
| `wp wc shipping_zone_method delete <zone> <instance>` deletes instance `<zone>` (it took Free shipping twice): delete over REST after a GET that proves the row | gotcha [shipping_zone_method delete](../../../../docs-internal/gotchas/wp-wc-shipping-zone-method-delete-takes-the-first-number-as-the-instance.md) |
| A fixture's no-op `init_*()` becomes a fatal the day it goes live; the geoip default-locality policy cannot resolve on `127.0.0.1` | gotchas [fixture no-op becomes a fatal](../../../../docs-internal/gotchas/a-fixtures-no-op-becomes-a-fatal-the-day-the-fixture-goes-live.md), [geoip on a local rig](../../../../docs-internal/gotchas/the-geoip-default-locality-cannot-resolve-on-a-local-rig.md) |
| `wp eval-file` prints nothing for a probe with one very long line; `docker cp` into the container fails on a bind mount — pipe the probe in | gotchas [eval-file long line](../../../../docs-internal/gotchas/wp-eval-file-prints-nothing-for-a-probe-with-one-very-long-line.md), [docker cp](../../../../docs-internal/gotchas/docker-cp-into-the-wp-env-container-fails-pipe-the-probe-instead.md) |
| A logged-in customer's WC session outranks the profile and holds the location record too: a probe that edits only user meta reads stale data | gotcha [logged-in customer's session](../../../../docs-internal/gotchas/a-logged-in-customer-s-wc-session-outranks-the-profile.md) |
| The rig is HPOS, the integration environment is the posts store: measure every order query on both | [orders and tracking](orders-tracking.md#finding-orders--wc_get_orders-drops-meta_query-on-the-posts-store) |
| A migration does not re-run where the target version is already stored; on the rig run the migration by hand before judging it (do not bump the version for the rig) | s157 (finding F2) |

## Real deliveries to a local rig

A carrier cannot reach `localhost`, so a webhook is judged from EMULATED requests (the documented schema, the site's real
receiver URLs, the subscription token) and reported `UNVERIFIABLE` for real delivery. If real delivery must be proven, expose
exactly one path through a tunnel and nothing else: the CDEK harness uses Tailscale Funnel restricted to the callback path
(`--set-path /wc-api/…`), checks that `/wp-admin/` through the tunnel answers 404 from the tunnel and never the WordPress login,
turns the tunnel off again after EVERY run, and judges "the carrier's own request" only by provenance (the TCP peer, a public
client address, the tunnel header, the site's token) — edostavka#26, `tests/acceptance/README.md` "Real CDEK deliveries". Do not
touch anything else of the tunnel's network configuration. An agent cannot start a public ingress by itself (gotcha
[auto-mode blocks a public ingress tunnel](../../../../docs-internal/gotchas/auto-mode-blocks-a-public-ingress-tunnel-the-operator-runs-it.md)).
A test SITE is not a test CONTOUR: a webhook subscribed from a rig with production keys takes a production slot.

## Migration stand

For a v1 → v2 plugin, accept the migration on a separate site with the REAL previous release installed (CDEK:
`tests/migration-stand/`, edostavka#41): seed data on v1, snapshot, swap to v2, restore from the snapshot to retry. It exists
because the migration can only be judged on migrated data and it found three defects no unit test saw ([settings and
migration](settings-migration.md#mechanics-that-held-up)).

## Two carriers on the rig

Release needs two compatible carrier plugins on one site (framework #1179). Boot both and run each one's gate: the traps are in
[shipping traps](traps.md#a-second-carrier-plugin-on-the-same-site). Give each carrier its own checkout field id, marker meta key
and webhook route, and scope every locator to one carrier's slot — `button.woodev-pickup-trigger` is a strict-mode violation as
soon as two carriers render one.
