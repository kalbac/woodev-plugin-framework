# Gotcha: [tooling/orca] — a file for Homelab goes through `orca terminal send`, and `while read` drops the last chunk

> Tags: orca, homelab, remote, file-transfer, base64 | Session: s184

## What happens

The MacBook has no SSH to ACEMAGIC (port 22 refused on its Tailscale address). The reachable path is Orca's paired
environment `Homelab`: `orca terminal create --environment Homelab --worktree path:<dir>` gives a shell there, and
`orca terminal send --environment Homelab --terminal <handle> --text … --enter` types into it. A file goes as base64 in
chunks (`printf '%s' '<chunk>' > dir/0001`), reassembled with `cat dir/* | base64 -d`.

In s184 the checksum did not match three times. Cause: the chunks came from `fold -w 1500`, whose last line has no
trailing newline, and `while IFS= read -r line` skips that final line — the tail of the file never left the Mac.

## ✅ Correct

- Loop with `while IFS= read -r line || [ -n "$line" ]` (or append a newline to the chunk file first).
- One chunk per numbered file, ~1500 chars, ~0.6 s between sends; then compare `md5sum` on Homelab with `md5 -q` on the Mac
  before anyone reads the file.
- Back to the Mac: print `base64 <file>` in that terminal, read it with `orca terminal read --cursor <before> --limit 2000`,
  keep only `^[A-Za-z0-9+/=]+$` lines, decode, compare md5.
- Gitignored landing spot in woodev-ru: `.superpowers/scratch/…`.

## Related

- [orchestration-mail-does-not-reach-a-busy-worker](orchestration-mail-does-not-reach-a-busy-worker.md) — the other half of remote work: talk to the worker, not only its mailbox
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md) — tooling index
