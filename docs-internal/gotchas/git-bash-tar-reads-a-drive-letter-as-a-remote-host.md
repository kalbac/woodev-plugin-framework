# Gotcha: [tooling/windows] — GNU tar in Git Bash reads `D:/…` as a remote host
> Tags: windows, git-bash, tar, scripts | Session: s135
> **Platform:** Windows (Git Bash / MSYS) only — BSD tar on macOS has no remote-archive syntax.

## What happens

A script that works on macOS fails in Git Bash the moment an ABSOLUTE path reaches tar:

```text
$ tar -C "$ROOT" -cf "$OUT/plugins-reference.tar" plugins-reference
tar: Cannot connect to D: resolve failed
```

`$ROOT` came from `git rev-parse --show-toplevel`, which in Git Bash prints `D:/Projects/...`.

## Root cause

GNU tar treats an archive name of the form `host:path` as a REMOTE archive reached over rsh — and a
drive letter followed by a colon is exactly that shape. `-C` is affected too. `--force-local` switches
it off, but BSD tar (macOS) does not know that flag, so it is not a portable fix.

## Fix

Keep every path out of tar's argv — `cd` into the directory and let the shell open the archive:

```bash
# ❌ breaks in Git Bash on any D:/ path
tar -C "$ROOT" -cf "$OUT/archive.tar" plugins-reference

# ✅ same on Git Bash and macOS
( cd "$ROOT" && tar -cf - plugins-reference ) > "$OUT/archive.tar"
( cd "$ROOT" && tar -xf - ) < "$OUT/archive.tar"
```

Found writing `scripts/machine/rig-state-export.sh`, whose first run on the desktop died on it.

## Related

- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — the other way Git Bash rewrites a path before the tool sees it
- [../wiki/two-machine-setup.md](../wiki/two-machine-setup.md) — the scripts this was found in, and the per-OS differences
