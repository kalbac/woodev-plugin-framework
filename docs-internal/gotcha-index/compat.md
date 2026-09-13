# Gotcha index — [compat/*] Backward compatibility, HPOS

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [compat/hpos] **A row rebuilt from the same `WC_Order` after an action is stale ONLY on the legacy CPT store: `update_order_meta()` writes through the object under HPOS and AROUND it on CPT. A mocked test and an HPOS rig are both blind.** → [a-row-rebuilt-after-an-action-is-stale-only-on-the-legacy-cpt-store](../gotchas/a-row-rebuilt-after-an-action-is-stale-only-on-the-legacy-cpt-store.md) (s134)
- [compat/hpos-order-meta-safety] **Never use get_post_meta() on orders.** → [hpos-order-meta-safety](../gotchas/hpos-order-meta-safety.md) (s2)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
