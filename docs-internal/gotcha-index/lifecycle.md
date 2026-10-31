# Gotcha index — [lifecycle/*] Install/upgrade routines

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [lifecycle/install-upgrade-detection] **Lifecycle detects install vs upgrade by version comparison.** → [lifecycle-install-upgrade-detection](../gotchas/lifecycle-install-upgrade-detection.md) (s2)
- [lifecycle/schema-upgrade] **`DROP COLUMN` on MariaDB / MySQL 8.0.29+ is INSTANT and frees no space (247 MB stayed 247 MB; 34.58 MB after `OPTIMIZE TABLE`) — an upgrade that slims a table must rebuild it.** → [a-mariadb-or-mysql-8-0-29-drop-column-is-instant-and-frees-no-space](../gotchas/a-mariadb-or-mysql-8-0-29-drop-column-is-instant-and-frees-no-space.md) (s207)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
