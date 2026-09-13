# Gotcha index — [shipping/*] Shipping module (S1)

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [shipping/orders] **A NEGATIVE meta clause OR-ed across providers matches EVERY order — «carrier B has no tracking» is true of every carrier A row. Bind it to the provider's own marker. Invisible with one provider registered.** → [a-negative-meta-clause-or-ed-across-providers-matches-every-order](../gotchas/a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) (s127)
- [shipping/contracts] **Session key ≠ order-meta prefix — two distinct installed-site contracts.** → [session-key-vs-order-meta-prefix](../gotchas/session-key-vs-order-meta-prefix.md)
- [shipping/contracts] **Installed-site contract strings are NOT mechanically derivable — the plugin must supply them.** → [contract-string-not-derivable](../gotchas/contract-string-not-derivable.md)
- [shipping/rate-calc] **Do NOT sum per-parcel prices in the framework rate seam.** → [shipping-rate-no-parcel-sum](../gotchas/shipping-rate-no-parcel-sum.md) (s3)
- [shipping/warehouse-identity] **Warehouse identity: storage row id ≠ carrier-unique id.** → [warehouse-storage-id-vs-carrier-id](../gotchas/warehouse-storage-id-vs-carrier-id.md)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
