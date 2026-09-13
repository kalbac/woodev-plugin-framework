# Gotcha index — [framework/contracts] What the framework guarantees to its consumers

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [framework/contracts] **Sweeping for a boundary by one spelling of its call finds only that spelling — four sweeps in a row each declared #594 finished; redact at the SINK, not at the call sites.** → [grep-the-sink-not-one-spelling-of-it](../gotchas/grep-the-sink-not-one-spelling-of-it.md) (s101)
- [framework/contracts] **A field that is a VIEW of another must be derived at the boundary, never at the display sites.** → [derive-a-view-field-at-the-boundary-not-at-display-sites](../gotchas/derive-a-view-field-at-the-boundary-not-at-display-sites.md) (s64)
- [framework/contracts] **A cross-provider `within` is handed over as COMPONENTS, never as a key — no key translation layer is needed or wanted.** → [a-cross-provider-within-is-handed-over-as-components](../gotchas/a-cross-provider-within-is-handed-over-as-components.md) (s76)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
