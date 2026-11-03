TRACKCRIT

# Design spec review — tracking v2

## Must fix

### 1. A distinct manual number on a carrier-owned order is silently omitted from automatic buyer emails

**What this is:** In §4.3 and the carrier + manual rows of §7, the auto-sender rejects every order for which `resolve_provider_for_order()` returns a provider, even when a newly entered manual number differs from the carrier number. **Problem:** For example, a CDEK order has carrier tracking `CDEK-1` and the merchant adds a separate return/second-parcel number `MANUAL-2`: the framework email carries only the carrier shipment's number, while §4.2 suppresses this plugin's table in framework emails, so `MANUAL-2` is not delivered automatically; this contradicts decision 3's automatic mail for each new manual number and the manual-number-for-any-carrier scope in decision 1. **Fix:** Exclude the matching pulled number, not the entire carrier-owned order, from auto-send; send the distinct manual item in the plugin's own email, or explicitly revise the binding decision if all manual numbers on carrier-owned orders are meant to require the order action. **If we skip it:** A merchant can add a distinct manual tracking number and the buyer receives no notice unless the merchant discovers and presses the resend action.

## Should fix

None.

## Nice to have

None.

## Verification notes

- Confirmed against Agenda «Решения» items 1–10, `a-v1-inventory.md` §§2–3, `c2-source-check.md`, `d-v1-buyer-tracking.md`, `e-carrier-links.md`, and the v1 plugin source.
- Framework API checks passed: `Orders_Registry::instance()` and `resolve_provider_for_order( \WC_Order ): ?Orders_Provider` exist; `Orders_Provider::get_tracking_meta_key(): ?string`, `resolve_tracking_url( \WC_Order ): ?string`, `get_label(): string`, and `get_id(): string` exist; `Woodev_Plugin::get_settings_providers(): array` exists; `Delivery_Status_Email` exists and extends `WC_Email`.
- The design's framework ownership check is appropriate for carrier ownership and handles Ozon's absent tracking-meta key. I found no separate mismatch in the installed-site contracts, licence-off visibility, or the described AJAX capability/nonce/escaping controls.

Verdict: Fix finding 1 before implementation; the rest of the reviewed design is consistent with the supplied decisions and source facts.

Not checked: WooCommerce core runtime behavior, real theme rendering, rig behavior, and the proposed browser link checks; those require a running site/browser and are outside this source review.

## Round 2

No findings. The revision resolves finding 1: auto-send and resend now exclude only the manual item matching the pulled carrier number, while a distinct newly added manual number on a carrier-owned order is sent in the plugin's own email. The revised §7 rows consistently show the framework's carrier number and the distinct manual number in separate emails/surfaces, with same-number deduplication; this matches the binding decisions and introduces no buyer-sees-twice or buyer-sees-nothing row.

Verdict: Finding 1 is resolved; no new contradiction or §7 coexistence gap found in the requested scope.
