# Gotcha index — [woocommerce/states] The `woocommerce_states` table

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [woocommerce/states] **`(array) WC()->countries->get_states( $country )` is NEVER empty for a country without states.** → [array-cast-of-get-states-false-is-not-empty](../gotchas/array-cast-of-get-states-false-is-not-empty.md) (s71)
- [woocommerce/states] **WooCommerce uppercases the posted state and rewrites it through a flipped map — a human label used as a state KEY is mangled and the select then loses….** → [wc-uppercases-the-posted-state-and-flips-the-map](../gotchas/wc-uppercases-the-posted-state-and-flips-the-map.md) (s71)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
