# Session key ≠ order-meta prefix — two distinct installed-site contracts

**Namespace:** `[shipping/contracts]`
**Discovered:** 2026-06-06 (autodev loop, escalation `critic-s1-p1-pickup-selection`)

## The trap

When persisting "the customer's chosen X" (pickup point, time interval, etc.) it is tempting to
compose ONE key from the plugin's order-meta prefix and write it to **both** the WC checkout
**session** and the **order meta**. This silently breaks installed-site data on plugins where
the two stores use different key namespaces.

For the Yandex reference plugin they are genuinely different:

| Store | Installed key | Source |
|-------|--------------|--------|
| WC session (checkout) | `chosen_yandex_pickup_point` / `chosen_yandex_pickup_point_test` | `includes/functions.php:316-323` |
| Order meta | prefix `_yandex_delivery_` (decomposed `_destination_station_id`, `_destination_station_address`, …) | `includes/class-order.php:45-100` |

A single composed key (`$prefix . $key`) can satisfy **neither** — `chosen_yandex_pickup_point`
is not under `_yandex_delivery_`, and the order-meta side is decomposed fields, not one blob.

## Correct pattern

- **Session persistence** (during checkout) is keyed by a plugin-supplied **session key**.
  Keep it in a session-only primitive (`Pickup_Selection`: `set`/`get`/`clear`).
- **Order-meta persistence** is a SEPARATE responsibility owned by the order handler
  (`class-shipping-order-handler.php`), keyed by the plugin's **order-meta prefix**.
- Never funnel both through one composed key. They are two independent entries on the
  "never break" list (`.autodev/INVARIANTS.md` `order_session_meta.exact_strings` lists BOTH
  `_yandex_delivery_` AND `chosen_yandex_pickup_point*` precisely because they are distinct).

## Why it matters

Both keys are release-blocking installed-site data contracts (CLAUDE.md "never break" list).
Conflating them orphans live checkout session selections and/or live order meta on every
installed site at plugin-migration time.

## Related
- Spec corrected: `docs-internal/platform-v2-s1-shipping-spec.md` §4.1.v (session-only)
- Contract source of truth: `docs-internal/migration/yandex-data-preservation-checklist.md`
  ("Chosen-point session key" row)
- `.autodev/INVARIANTS.md` — `order_session_meta` zone
