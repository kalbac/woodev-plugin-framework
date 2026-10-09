# Gotcha index — [shipping/*] Shipping module (S1)

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [shipping/orders] **A NEGATIVE meta clause OR-ed across providers matches EVERY order — «carrier B has no tracking» is true of every carrier A row. Bind it to the provider's own marker. Invisible with one provider registered.** → [a-negative-meta-clause-or-ed-across-providers-matches-every-order](../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) (s127)
- [shipping/orders] **The orders filter stands on two UNENFORCED invariants: a carrier writes only its own status meta, and an order carries at most one marker (YAGNI, operator) — which `edostavka` already breaks.** → [the-orders-filter-stands-on-two-unenforced-carrier-invariants](../gotchas/the-orders-filter-stands-on-two-unenforced-carrier-invariants.md) (s140)
- [shipping/orders] **An order status set FROM the carrier's «cancelled» news re-enters `Order_Automation` and cancels at the carrier again — guard the change; and canonical `cancelled` also comes from the framework's own marker, which is the merchant's act, not news.** → [a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation](../gotchas/a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation.md) (s166)
- [shipping/contracts] **Session key ≠ order-meta prefix — two distinct installed-site contracts.** → [session-key-vs-order-meta-prefix](../gotchas/session-key-vs-order-meta-prefix.md)
- [shipping/contracts] **Installed-site contract strings are NOT mechanically derivable — the plugin must supply them.** → [contract-string-not-derivable](../gotchas/contract-string-not-derivable.md)
- [shipping/rate-calc] **Do NOT sum per-parcel prices in the framework rate seam.** → [shipping-rate-no-parcel-sum](../gotchas/shipping-rate-no-parcel-sum.md) (s3)
- [shipping/warehouse-identity] **Warehouse identity: storage row id ≠ carrier-unique id.** → [warehouse-storage-id-vs-carrier-id](../gotchas/warehouse-storage-id-vs-carrier-id.md)

- [shipping/rate-cache] **Percentage box costs require per-line merchandise values in cache identity; an unchanged package total does not guarantee an unchanged parcel surcharge.** → [percentage-box-cost-cache-needs-line-values](../gotchas/percentage-box-cost-cache-needs-line-values.md) (s158)

- [shipping/packaging] **Carrier presets use fixed cm/kg; converting them as store units silently changes carton dimensions on mm/g stores.** → [carrier-box-presets-need-fixed-units](../gotchas/carrier-box-presets-need-fixed-units.md) (s158)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
