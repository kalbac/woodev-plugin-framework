# Gotcha: [tooling/serena] — Serena's MCP dies with `CONNECTION_CLOSED` when `uvx` picks a Python that has no `pyyaml` wheel
> Tags: tooling, serena, mcp, windows | Session: s131
> **Platform:** Windows only — the measured missing-wheel fallback requires the Windows compiler toolchain.

## What happens

Serena stops appearing in a Claude Code session. `claude mcp list` reports

```text
plugin:serena:serena: uvx --from git+https://github.com/oraios/serena serena start-mcp-server
  - × Failed to connect — CONNECTION_CLOSED: Connection closed
```

and every `find_symbol` / `get_symbols_overview` call is simply absent from the tool list — including
from `ToolSearch`, which answers "No matching deferred tools found". Nothing in that message names a
build failure, so it reads as a broken MCP server, a network problem, or a Serena regression. It is
none of those.

⚠ **The session-start check in `AGENT-RULES.md` catches this only if you actually run it.** Its whole
point is that a silently missing Serena is what made s45–s59 drift.

## Root cause

`uvx` resolves the interpreter itself and takes the **newest Python installed on the machine**. When
that is newer than the wheels `serena-agent`'s dependencies publish, `uv` falls back to building from
source. Measured 11.09.2026: uv chose **cpython 3.14.3**, `pyyaml` 6.0.2 ships no `cp314` wheel, and
the source build ends with

```text
error: Microsoft Visual C++ 14.0 or greater is required.
hint: `pyyaml` (v6.0.2) was included because `serena-agent` (v1.7.1.dev0) depends on `pyyaml`
```

The MCP client sees only the process exiting, hence `CONNECTION_CLOSED`. **Run the launch command by
hand — that is the only place the real error is printed.**

## Fix

Pin the interpreter to a version the dependencies have wheels for (3.11 verified working):

```jsonc
// ❌ wrong — uvx takes the newest interpreter on the machine
{ "serena": { "command": "uvx",
  "args": ["--from", "git+https://github.com/oraios/serena", "serena", "start-mcp-server"] } }

// ✅ correct
{ "serena": { "command": "uvx",
  "args": ["--python", "3.11", "--from", "git+https://github.com/oraios/serena", "serena", "start-mcp-server"] } }
```

Diagnose and apply it like this:

```bash
uvx --from git+https://github.com/oraios/serena serena --help   # prints the REAL error
uvx --python 3.11 --from git+https://github.com/oraios/serena serena --help   # confirms the fix
```

⚠ **The marketplace copy of `.mcp.json` is NOT the file that is loaded.** Serena is a plugin, and the
live config is the non-orphaned cache copy under
`~/.claude/plugins/cache/claude-plugins-official/serena/<hash>/.mcp.json` — the directory *without* an
`.orphaned_at` marker (there were two of them, and a dozen orphans). Patching only
`~/.claude/plugins/marketplaces/.../external_plugins/serena/.mcp.json` changes nothing, which reads as
"the fix did not work". Verify with `claude mcp list`: it echoes the args it actually used.

⚠ **A plugin update overwrites the cache copy**, so this can come back. The durable alternative is
`UV_PYTHON=3.11` in Claude Code's env — broader, since it binds every `uvx` the harness spawns.

⚠ **MCP tools bind at session start.** Fixing this mid-session does not surface Serena in the session
that fixed it — it needs a new one. Reporting the outage and finishing the non-PHP work is correct;
falling back to `Read` on `.php` is not.

## Related

- [serena-index-vs-git-worktree](serena-index-vs-git-worktree.md) — the other way Serena is present but wrong
- [serena-refuses-the-tests-directory-so-the-never-read-php-rule-cannot-apply-there](serena-refuses-the-tests-directory-so-the-never-read-php-rule-cannot-apply-there.md) — an absence that is NOT an outage, and how to tell them apart
- [serena-activate-path-must-be-the-worker-s-worktree](serena-activate-path-must-be-the-worker-s-worktree.md) — activation aimed at the wrong checkout
- `docs-internal/AGENT-RULES.md` → "Use Serena MCP" — the session-start check this gotcha exists to make actionable
