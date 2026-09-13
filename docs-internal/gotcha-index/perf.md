# Gotcha index — [perf/*] Payload size and wire cost

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [perf/payload] **A raw JSON byte count is not a wire cost: a repetitive locale table grew +47 KB raw and +503 bytes after gzip (96:1). Measure compressed, and confirm `Content-Encoding` on the real response.** → [a-raw-payload-size-is-not-a-wire-cost-measure-it-after-gzip](../gotchas/a-raw-payload-size-is-not-a-wire-cost-measure-it-after-gzip.md) (s118)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
