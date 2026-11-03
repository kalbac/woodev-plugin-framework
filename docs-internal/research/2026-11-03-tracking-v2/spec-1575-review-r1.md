CRIT1575

# Review — buyer tracking display design (r1)

## Must fix

1. **What this is:** §4 keeps `{tracking_number}` and `{tracking_url}` as single-valued email placeholders for multiple Ozon shipments. **Problem:** With two postings, the numbers are joined but only the first URL is supplied; the existing `Delivery_Status_Email::format_html()` creates one anchor for the placeholder, so the second number cannot be followed from the email, and Q2 leaves the link behavior undecided despite the spec saying each number is also linked. **Fix:** Settle the multi-shipment HTML contract and render each number with its own shipment URL (or another way to track each number) before W1/W2 acceptance. **If we skip it:** Ozon email lists multiple shipments but gives the buyer a working link to only the first.

2. **What this is:** §2 maps Ozon's per-posting `status_changed_at` into `Tracking_Shipment::$status_time`. **Problem:** `_woodev_ozon_postings` stores that value as an ISO-8601 string (for example, `2026-11-04T12:00:00Z`), but `Tracking_Shipment::create()` requires `?int`; passing the row value directly throws a type error, and omitting it loses the status date. **Fix:** Specify conversion to Unix time with the existing Ozon time parser, `Ozon_Wire::parse_time()`, before creating the shipment. **If we skip it:** Ozon's required per-shipment status line cannot be built safely from the stored row as specified.

## Should fix

None.

## Nice to have

None.

Verdict: Fix findings 1 and 2 before implementation; the remaining verified seams align with the issue decisions and cited consumer spec. The proposed framework additions are additive public API, and ADR-005 permits internal API changes while preserving installed-site data contracts.

Not checked: No implementation, unit tests, rig pages, or email render were run; this review is based on the named specs and source reads only. Ozon's public tracking URL remains the spec's explicit Q1 link-check item.

## Round 2

Findings 1 and 2 are resolved: §4 now specifies one linked line per shipment, and §2 converts Ozon's stored ISO-8601 timestamp through `Ozon_Wire::parse_time()` before passing the integer timestamp. The parser exists with the stated signature and safely returns `null` for absent or unreadable values; the current `Delivery_Status_Email::format_html()` supports only a single `{tracking_url}`, so the described W1 change to retain shipments and create per-shipment links is necessary and implementable.

## Must fix

3. **What this is:** The rollout statement in §7 says W1 can ship alone and Yandex will show nothing until W3. **Problem:** With the currently registered Yandex provider in `class-yandex-order-actions.php`, the default path in §2 instead reads `_yandex_delivery_request_id` as the shipment number and resolves `_yandex_delivery_sharing_url` as its URL, so a framework-only W1 deployment exposes the internal request ID as buyer-facing tracking text. **Fix:** Make the Yandex resolver and framework release atomic, or make the framework's fallback suppress this provider's internal ID until its resolver is available. **If we skip it:** A Yandex buyer can see an internal request ID in the new block, contrary to §2's statement that it is never a buyer number and §7's rollout claim.

## Should fix

None.

## Nice to have

None.

Verdict: Findings 1 and 2 are resolved; fix finding 3 before treating W1 as independently releasable.

## Coordinator resolution of finding 3 (04.11.2026, s214)

Applied in spec §7: W1 is not releasable alone; framework 2.0.25 and the Yandex W3 resolver ship in one wave (operator decision 11). The W1 implementation brief must also carry a unit test: Yandex-shaped provider without a resolver → the request id never appears as a buyer number (guard for the window inside the wave).
