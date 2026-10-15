# Gotcha index — [autodev/*] Adversarial dev loop tooling

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [autodev/serena-worktree] **Serena MCP index is bound to the main working tree — agents editing in a git worktree must NOT navigate via Serena.** → [serena-index-vs-git-worktree](../gotchas/serena-index-vs-git-worktree.md) (s7)
- [autodev/circuit-breaker] **Refund the circuit-breaker attempt on EVERY external pause, not just the worker's.** → [autodev-attempt-refund-symmetry](../gotchas/autodev-attempt-refund-symmetry.md)
- [autodev/critic] **Autodev critic over-flags two non-breaks on every incremental task.** → [autodev-critic-overflag](../gotchas/autodev-critic-overflag.md)
- [autodev/critic] **invoke-critic mis-reads benign repo text as a rate-limit (the loop's own docs poison its 429 detector).** → [autodev-critic-ratelimit-false-positive](../gotchas/autodev-critic-ratelimit-false-positive.md)
- [autodev/gate-fence] **autodev-loop gate/fence design pitfalls (per-value guards, fingerprint fence).** → [autodev-loop-gate-fence-pitfalls](../gotchas/autodev-loop-gate-fence-pitfalls.md) (s33)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
