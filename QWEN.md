# QWEN.md — entry point for Qwen

> **Read [AGENTS.md](AGENTS.md).** It is the single source of project rules for every agent: the
> session start/end protocol, coding principles, conventions, the gotcha rule and the backlog rule.
> This file deliberately restates none of it.

Until s135 this file carried its own copy of the project overview, conventions and architecture. The
docs audit found that copy wrong in a dozen places the canonical files had long since corrected — the
loader-definition field name, the `@since` rule, the fixture count, the rig ports, the SESSION-LOG
format — because a second copy is only ever updated by whoever remembers it exists. So there is no
second copy.

Where to look things up:

| Question | File |
|---|---|
| What did the last session leave? | `docs-internal/next-session-prompt.md` |
| What state is the project in? | `docs-internal/CURRENT-STATE.md` |
| Has this trap been hit before? | `docs-internal/GOTCHAS.md` (topic map) → `gotcha-index/{topic}.md` |
| Where does a responsibility live? | `docs-internal/wiki/architecture.md` |
| What may I break? | `docs-internal/adr/005-platform-v2-clean-break-policy.md` |
| Workflow and architecture rules | `docs-internal/AGENT-RULES.md` |
| How to write a doc here | `docs-internal/DOCS-SCHEMA.md` |

Tooling that only Claude Code has (Serena, Context7, the Orca recipes) is described in `CLAUDE.md`;
an agent without those tools follows `AGENTS.md` and says once that it lacks them.
