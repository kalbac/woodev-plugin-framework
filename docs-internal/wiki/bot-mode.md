# Direct mode and bot mode

> Compiled reference. Last compiled: 2026-11-25 (s245). Operator decision, 25.11.2026.

Two ways of working run side by side. **Direct mode** — the owner talks to an agent in a project folder — is
unchanged: nothing of it was removed or rewritten. **Bot mode** — the owner talks to `bot:Framework` or `bot:Plugins`
in Orca — was added beside it. The direct-mode protocol gained exactly one step (the bridge, below).

## What differs

| | Direct mode | Bot mode |
|---|---|---|
| Who the owner talks to | an agent in the project folder | `bot:Framework` / `bot:Plugins` |
| Where open work lives | `next-session-prompt.md` (the handoff) | the bot's items |
| Where «what next» comes from | the handoff and the board | the bot's **Look around** (weekday mornings) |
| Session records | `sessions/sNN.md`, `SESSION-LOG.md`, the handoff's numbers registry and its `lint:docs` gate | none of its own: «сохрани сессию» is forwarded (below) |
| Gotchas | `AGENTS.md` → Gotcha recording rule, with the compilation step at session end | written when found |
| Capture rule | idea → card at once | the same |
| Disk sweep | a step of the save protocol | a weekly automation on Framework (Friday evening; deletes only what is reproducible, reports one line) is **proposed, not created** — #1980 |
| Shared by both | `CURRENT-STATE.md`, the GitHub boards, git | the same |

The direct-mode checklists stay where they were: `AGENTS.md` → Session Start / Session End.

## «Сохрани сессию» sent to a bot

The bot forwards it to the agent or agents working in that repo; if none is alive there, it starts a worker in the
repo. They run **the same direct-mode protocol** (`AGENTS.md` → Session End plus the global rules in
`~/.agents/AGENTS.md`). A bot never runs its own variant: there is one protocol.

## The bridge: direct-mode save → bot items

The one added step. After the save is committed and pushed, the saving agent mails the owning bot the session's open
work, so the bot opens items for it:

```bash
# bot:Framework or bot:Plugins — see Ownership
/Applications/Orca.app/Contents/Resources/bin/orca orchestration send --to bot:Framework --type status \
  --subject "Open work after sNN — open items" --body-file <file>
```

- One line per open task: what it is, where it stands, what it waits on, its card or PR number. End with the handoff
  path and the commit hash. Say in the body that these lines are to become items: Orca delivers `--type status` as
  information, with no job and no reply.
- No open work → say so in the same mail. A repo with no owning bot → skip the step.
- Send failed, or no route to the bot from this computer → say it in the save report; the handoff still holds the work.
  From another computer the address differs: read `orca bot list` and `orca orchestration send --help` there
  (not measured from the Windows desktop or from Homelab).
- An agent shell cannot use the `orca` on `PATH` on this Mac, hence the full path
  ([two machines](two-machine-setup.md), measured s136).

## Shared truth and owner rules

- **`docs-internal/CURRENT-STATE.md` is the shared truth of both modes.** Bots update it on events: a release, a phase
  change, new debt.
- **A new owner rule goes to both memories:** the bot's `~/.orca/bots/<bot>/MEMORY.md` and the coordinator's
  `~/.claude/projects/-Users-maksimmartirosov-Projects-woodev-plugin-framework/memory/` (one file plus one index line).
- A bot's home is `~/.orca/bots/<bot>/`: `SOUL.md` says who it is (changed only when the owner asks), `MEMORY.md` holds
  one line per fact. Orca writes the other files there. None of it is in git. Work state lives on items and boards,
  never in a memory file.

## Ownership

| Bot | Where | Owns |
|---|---|---|
| Framework | Mac | this repo and its releases, `docs-internal/`, board №6, the cross-project process docs (`~/.agents/AGENTS.md`, the `orca-team` skill, `woodev-docs-system`). Only it changes framework code. Default responder of the group room «Woodev», routing plugin topics to Plugins (on 25.11.2026 the room was still to be created in the Orca UI) |
| Plugins | Mac | every plugin — code, releases, EDD uploads (on the owner's word, per release wave), boards, rig checks |
| Woodev | Homelab | the woodev.ru site and marketing |
| Support | Homelab | customer tickets |
| Homelab | Homelab | servers |
| none | — | `woodev-base-theme` is frozen and has no bot |

Three plugin repos live **only on Homelab**, at `/home/maksim/Projects/wordpress/plugins/<repo>`:
`woocommerce-range-shipping`, `woocommerce-order-tracking-number` and `woodev-vkredit` (`range-shipping`,
`order-tracking-number`, `vkredit`). The other plugins are on the Mac.

Models and the worker fleet are the same in both modes: `CLAUDE.md` → Orca.

## Going back

The git tag `docs-before-bots` marks the repo before bot mode; the files outside the repo were backed up to
`~/Documents/woodev-backup-before-bots-20261125/` on the Mac.

## Related

- [AGENTS.md](../../AGENTS.md) — Session Start / Session End, the direct-mode protocol and its bridge step
- [Orchestrating agents with Orca](orchestrating-agents-with-orca.md) — workers, critics and launch recipes used by both modes
- [CURRENT-STATE.md](../CURRENT-STATE.md) — the shared truth
- [DOCS-SCHEMA.md](../DOCS-SCHEMA.md) — handoff and session-record formats
- [Two machines](two-machine-setup.md) — per-machine facts, the `orca` path included
