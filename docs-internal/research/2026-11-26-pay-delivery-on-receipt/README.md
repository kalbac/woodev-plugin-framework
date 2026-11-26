# Pay for goods online, pay delivery on receipt — research, 26.11.2026

Card [#1475](https://github.com/kalbac/woodev-plugin-framework/issues/1475): with a prepayment the buyer pays online only
for the goods; delivery is paid to the carrier on receipt. A carrier plugin declares it can collect only the delivery
fee, our payment plugins can then switch it on, the checkout text is editable. Research only: no framework or plugin
code changed. Decision context: [T-Bank decisions](../2026-10-26-tbank-v2/decisions.md), D6.

## Files

| File | Content |
|---|---|
| [carriers.md](carriers.md) | CDEK, Yandex Delivery, Russian Post, Ozon Delivery: fields, limits, probes, wiring today, refusal |
| [woocommerce.md](woocommerce.md) | How to represent such an order; what WooCommerce core and the framework allow |
| [design.md](design.md) | Framework seams and a card plan |
| [owner-questions.ru.md](owner-questions.ru.md) | Questions for the owner, Russian, each with a recommendation |
| `probe_cdek.py`, `probe_yandex.py`, `probe_pochta.py`, `raw/` | Probes on the owner's test-only carrier accounts and their records |

## Verdict per carrier

| Carrier | Verdict | How | What is proven, what is not |
|---|---|---|---|
| CDEK | **Supported** | native field `delivery_recipient_cost`, items `payment.value` = 0 | documented; 6 orders registered `SUCCESSFUL` on the production API and deleted. Hand-over, real collection, receipt: not tested |
| Russian Post | **Supported, with limits** | ordinary cash on delivery with `payment` = delivery fee, `insr-value` ≥ `payment` | order created in the test cabinet and deleted. No parcel lockers, no international; batch and hand-over not tested |
| Yandex Delivery | **Workaround only, not confirmed** | no prepaid-plus-fee mode; declare the order pay-on-receipt with item `unit_price` = 0 | `offers/create` prices it; creating the request, hand-over and receipt not tested. Only some pickup points, no courier |
| Ozon Delivery | **Not supported** | the API has no payment fields at all | read from the API description of 30.10.2026 |

First release candidate: **CDEK only**.

## WooCommerce and framework, in short

Do not keep delivery in the order total and charge less. Set the delivery **rate cost to 0** while the buyer has a
gateway with the switch on, and carry the real amount in the shipping line's meta. Totals, payment amount, item
reconciliation, refunds and statuses then stay native; what has to be added is the buyer-facing amount, the carrier
export, guards on pickup points and on later payment changes, and our own orders page and analytics. The framework
already has most of the plumbing for a price that depends on the payment method (`Fee_Payments`).

## Top risks

1. **The stated motive (no VAT on delivery) may not be achieved.** All three carriers describe the collected money as
   collected for the sender and transferred to them (CDEK registry with an agent fee; Yandex and Russian Post transfer
   it to the merchant's account). That it therefore stays the merchant's revenue is our inference, not a tax opinion.
   Question 1 of the card is for the accountant and should be answered **before** development.
2. **CDEK silently moves the pickup point.** With a fee to collect, an order to an office without cash on delivery was
   accepted and moved to another office, with only a warning. The CDEK plugin does not read that warning
   ([gotcha](../../gotchas/cdek-moves-the-delivery-point-with-only-a-warning-when-money-is-to-be-collected.md)).
3. **Refusal on receipt.** The goods are paid, the delivery fee is never collected, and the carrier still bills the
   merchant for delivery and return (stated for Yandex; UNVERIFIED for CDEK and Russian Post).
4. **A payment change after the order exists.** The pay-for-order page would keep delivery at 0 with a gateway that
   does not collect it; a checkout retry can leave a stale order meta that makes the export collect delivery twice.
   Guards in the [design](design.md#retries-and-the-order-meta).
5. **Receipts (54-FZ).** The goods receipt stays with the merchant's side; the carrier's receipt covers what it
   collects. Yandex's terms forbid taking money on a *prepaid* shipment, so the workaround rests on declaring it
   pay-on-receipt with zero goods price — its legal and fiscal standing is UNVERIFIED.
6. **Narrower choice for the buyer:** no parcel lockers or points without cash on delivery, no Yandex courier.
7. **The buyer may pay more than the notice says:** Russian Post adds its transfer fee on top when the contract charges
   the recipient.

## Method

- Cards #1475 and #1474 with comments; T-Bank decisions.
- Plugin repositories read at `origin/main`, nothing edited: `woocommerce-edostavka` `dccba61`,
  `woocommerce-yandex-delivery` `d31b8c3`, `woodev-russian-post` `4a0cad0`, `woodev-ozon-delivery` `fdd80bc`.
- Framework at `157c4bd9` (Serena on this worktree, targeted reads).
- WooCommerce **10.5.2** source (the version of `php-stubs/woocommerce-stubs` in the dev dependencies), downloaded from
  wordpress.org. The rig runs 11.3.0; it was not re-read there.
- Official carrier documents, accessed 26.11.2026 (links in [carriers.md](carriers.md)).
- Probes, 26.11.2026, on accounts the owner declared test-only: CDEK — 6 orders created and deleted (deletion
  requested for all; five confirmed by a follow-up 400, the sixth by `v2_entity_not_found`); Russian Post — one order
  created in «Новые», deleted, 404; Yandex — 4 `offers/create` calls, nothing confirmed. No courier call, no hand-over,
  no batch. The scripts only printed the deletion and verification replies to the console: they are **not** saved in
  `raw/`, so the deletions rest on this note.

## Not verified

- Nothing was executed on a shop: no WooCommerce order in this mode, no rig, no test run.
- No parcel was shipped, so collection, the fiscal receipt and the payout registry are unproven for every carrier.
- Block checkout behaviour with a zero-cost rate.
- Contracts: whether a given merchant's contract allows collection from the recipient, and the CDEK collection fee.
- The current online Ozon Delivery reference (the news pages refused the automated fetch).

## Related

- [Carriers](carriers.md)
- [WooCommerce](woocommerce.md)
- [Design](design.md)
- [Yandex cash-on-delivery cost measurement](../2026-10-12-yandex-cod-delivery-cost/README.md)
