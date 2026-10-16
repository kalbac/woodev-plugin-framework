# Gotcha index — [shipping/*] Shipping module (S1)

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [shipping/yandex] **The operator's production Yandex account reads fine but refuses every `offers/confirm` / `request/create` («корпоративный счёт больше не активен»): book and cancel requests on the TEST contour (Moscow) or a stub.** → [the-yandex-test-account-cannot-create-requests](../gotchas/the-yandex-test-account-cannot-create-requests.md) (s174)
- [shipping/orders] **A NEGATIVE meta clause OR-ed across providers matches EVERY order — «carrier B has no tracking» is true of every carrier A row. Bind it to the provider's own marker. Invisible with one provider registered.** → [a-negative-meta-clause-or-ed-across-providers-matches-every-order](../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) (s127)
- [shipping/orders] **The orders filter stands on two UNENFORCED invariants: a carrier writes only its own status meta, and an order carries at most one marker (YAGNI, operator) — which `edostavka` already breaks.** → [the-orders-filter-stands-on-two-unenforced-carrier-invariants](../gotchas/the-orders-filter-stands-on-two-unenforced-carrier-invariants.md) (s140)
- [shipping/orders] **A status set FROM the carrier's «cancelled» news re-enters `Order_Automation` and cancels again; canonical `cancelled` also comes from the framework's own marker (the merchant's act).** → [a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation](../gotchas/a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation.md) (s166)
- [shipping/contracts] **Session key ≠ order-meta prefix — two distinct installed-site contracts.** → [session-key-vs-order-meta-prefix](../gotchas/session-key-vs-order-meta-prefix.md)
- [shipping/contracts] **Installed-site contract strings are NOT mechanically derivable — the plugin must supply them.** → [contract-string-not-derivable](../gotchas/contract-string-not-derivable.md)
- [shipping/rate-calc] **Do NOT sum per-parcel prices in the framework rate seam.** → [shipping-rate-no-parcel-sum](../gotchas/shipping-rate-no-parcel-sum.md) (s3)
- [shipping/warehouse-identity] **Warehouse identity: storage row id ≠ carrier-unique id.** → [warehouse-storage-id-vs-carrier-id](../gotchas/warehouse-storage-id-vs-carrier-id.md)

- [shipping/rate-cache] **Percentage box costs require per-line merchandise values in cache identity; an unchanged package total does not guarantee an unchanged parcel surcharge.** → [percentage-box-cost-cache-needs-line-values](../gotchas/percentage-box-cost-cache-needs-line-values.md) (s158)

- [shipping/packaging] **Carrier presets use fixed cm/kg; converting them as store units silently changes carton dimensions on mm/g stores.** → [carrier-box-presets-need-fixed-units](../gotchas/carrier-box-presets-need-fixed-units.md) (s158)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
- [shipping/cod] **COD collection must include the STORED line tax + shipping tax (from the order's own numbers, not the live product's tax status), in either price-entry mode.** → [cod-collection-must-include-stored-line-tax-and-shipping-tax](../gotchas/cod-collection-must-include-stored-line-tax-and-shipping-tax.md) (s184)
- [shipping/cancel] **A carrier «cancel» reply can mean accepted-but-pending (Yandex `CREATED`); only a completed cancel may clear the request id and run the destructive success path.** → [a-carrier-cancel-reply-can-mean-accepted-but-pending](../gotchas/a-carrier-cancel-reply-can-mean-accepted-but-pending.md) (s184)
