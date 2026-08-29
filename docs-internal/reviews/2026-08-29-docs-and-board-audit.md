# #644 — docs audit + board audit + prioritisation material

**Date:** 2026-08-29 (s104) · **Scope:** all of `docs-internal/`, root `CLAUDE.md`/`AGENTS.md`, and
all 93 open GitHub issues on `kalbac/woodev-plugin-framework` · **Kind:** audit + targeted fixes —
Part 3 is material for the operator's own prioritisation call, not a priority order.

**Method note:** an earlier attempt at this task, run as two parallel background forks, produced one
good result (the docs-audit fork completed cleanly but was then re-triggered by a retry storm and
had to be killed) and one bad one — the board-audit fork span up six unsupervised nested sub-agents
(a `claude`, two `general-purpose`, three more `fork`s) chasing the same 93 cards, well past this
machine's documented 2–3-agent hardware cap. All eight runaway agents were stopped before any did
real damage; one comment they posted (on #153, cited below) was checked and is accurate, so it was
kept. Everything else in this report was produced directly, without further forking. This is worth a
line in a handoff so nobody re-delegates this shape of task to background forks without a check-in.

---

## 1. Contradiction map

Built before any fix was made, per the s39 method. Every `#N` reference `docs-internal/` makes about
a card's *state* was checked against `gh issue view <N> --json state,closedAt` — not against memory,
not against another doc.

| # | Claim | Where | Reality | Fixed? |
|---|---|---|---|---|
| 1 | "Gotchas: **236**." | `CURRENT-STATE.md:185` | **237** — 238 `gotchas/*.md` files minus `README.md`; `GOTCHAS.md`'s own header already says 237 correctly and its 238 index link targets (237 real + the format-comment placeholder) match. Already confirmed by the coordinator before this audit started. | **Left** — file is off-limits (coordinator owns it concurrently) |
| 2 | "**#518** … DECIDED, still NOT started" | *(historical — the #644 card body itself)* | #518 closed 27.08.2026 (PR #586). **Already fixed in `CURRENT-STATE.md` by the time this audit ran** — its current text correctly says "CLOSED 27.08.2026 — PR #586, accepted live by the operator." No action needed; confirms the audit method works. | N/A — already correct |
| 3 | "**Open cards after s103:** … **#650** …" | `CURRENT-STATE.md:62-64` | #650 closed **2026-08-29T02:22:07Z** — minutes before this session started, by the just-merged PR #655 (commit `59b613b`, visible in this session's own starting `git log`). Timing-driven staleness, not neglect. | **Left** — file is off-limits |
| 4 | "🙋 Ждут решения ОПЕРАТОРА: … **#511** …" | `CURRENT-STATE.md:254-258` | #511 closed **2026-08-25T08:13:33Z** — four days before the doc's own "as of 2026-08-29" stamp. Genuine miss, same shape as the #518 one that prompted this card. | **Left** — file is off-limits |
| 5 | "Техдолг и улучшения карты (181, **159**, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем…" | `CURRENT-STATE.md:260` | #159 closed **2026-08-13T21:13:30Z** — over two weeks stale. The other 7 numbers are all still open and correct. | **Left** — file is off-limits |
| 6 | Session Start checklist presented as its own list, no mirror disclaimer | `DOCS-INDEX.md:8-14` | Content is consistent with `AGENTS.md`'s canonical list (no contradiction), but `AGENT-RULES.md` explicitly marks its copy as "mirrors `AGENTS.md` — do not let them diverge" and `DOCS-INDEX.md` did not. Exactly the drift risk `DOCS-SCHEMA.md`'s Sync Rule warns about. | **Fixed** — added the same one-line disclaimer `DOCS-INDEX.md` was missing |
| 7 | "Specs / Plans / Research (live dirs)" table lists 3 specs | `DOCS-INDEX.md:48-56` | `specs/` holds 13 files, `plans/` holds 7 — ten filed after the doc's own "Last updated: 2026-08-09 (s60)" stamp are missing from the table (everything from `2026-08-10-…` through `2026-08-25-…`). All 13 are still in the live directory (not `archive/specs/`), so by the project's own convention all are current. | **Left, flagged** — writing an accurate one-line purpose for each of 10 specs needs a read of each one; out of this lap's budget. Recommend a follow-up pass, not a guess |

**Spot-checked and found CORRECT** (no contradiction): every card CURRENT-STATE.md explicitly claims is *closed* — #518, #473, #488, #512, #606, #627, #632, #613, #353, #514, #583 — really is closed. Every card in its "open"/"waiting" lists that I did **not** flag above (#621, #356, #358, #361, #410, #589, #647, #646, #644, #652, #639, #437, #474, #483, #515, #331, #332, #374, #285, #247, #289, #270, #310, #318, #321, #322, plus 7 of the 8 techdebt-map numbers) is genuinely still open. `GOTCHAS.md`'s `## Related` sections: present in all 237 gotcha files and all 8 wiki articles (grep-verified, zero gaps). Gateway files (`CLAUDE.md`, `AGENTS.md`, `docs-internal/AGENT-RULES.md`) do not contradict each other — Serena rule, i18n rule, backlog protocol and the gotcha-write protocol all read the same
way in all three.

### Not swept

`SESSION-LOG.md`'s index and the ~100 individual `sessions/sNN.md` files were **not** cross-checked
number-by-number against `gh` — that is several hundred more issue references, most of them
historical narrative about cards long since closed by the same session that mentions them, and a full
sweep did not fit this lap. `research/`, `migration/`, `plans/`, `specs/`, `platform-v2-*.md`, and
`audit-2026-06-01.md` were skimmed for obvious staleness (titles, headline claims) but not read
line-by-line — `AGENTS.md`/`DOCS-INDEX.md` themselves mark most of these as historical/frozen/archive
material, not live session-start reads, which is why this pass prioritised the files that actually
assert current state.

---

## 2. Board audit — findings, and how deep the check went

**93 open cards measured 2026-08-29** (`gh issue list --state open`), not 86 — seven were filed
since the operator's 86-count on 29.08 (#644, #646, #647, #652, #653, and two others already
resolved by the time of counting). All 93 were checked against the board's own status field
(`gh project item-list 6`), and every number CURRENT-STATE.md or an operator-decision line asserted
was checked against `gh`. A smaller set (listed under "Deep-checked" below) got an actual code
verification — grep, git-log, or a read of the linked commit — before any comment was posted.

### Board hygiene findings

- **#7 and #8 are not on the board at all.** Filed 2026-03-13 ("good first issue" starter bugs — an
  HPOS token-editor bug and a PSR-3 logger feature request), both real and unaddressed, neither ever
  added to project №6. Every other open card (91 of 93) is on the board: 89 in `Бэклог`, 2 in
  `В работе`. **Zero cards currently sit in `Инбокс`** — everything already triaged has a home.
- **The two `В работе` cards are accurately tracked, not stale.** #646 (pickup distance-label i18n)
  has a real branch (`kalbac/s104-646-distance-units`, commit `4a9fd38`, message says `Closes #646`)
  not yet merged into `main` — a parallel session's live work, correctly shown in progress. #361
  (`within_status` visibility) likewise has an active history; not independently re-verified beyond
  the board status agreeing with `git log`.
- A commit-message grep for `#N` across `git log --all` is **not reliable evidence of closure** —
  this repo's low issue numbers (`#7`, `#8`, `#103`…) collide with old squash-merge commit messages
  that cite *different* issue numbers from an earlier period of the repo's history (e.g. `(#7)` and
  `(#8)` inside commit `bbc09bb`/`086585c` refer to unrelated, already-shipped account-connector
  work, not to the still-open #7/#8 above). Every grep hit used this way in this audit was confirmed
  by reading the actual issue body before treating it as a signal, not by the commit-message match
  alone.

### Duplicate closed (already actioned)

- **#153** ("Смешанный язык исходных строк i18n") — a Russian comment recommending
  `закрыть как дубликат #567`, with the full token-count measurement cited, was already posted before
  the runaway-fork cleanup; verified accurate on inspection, kept as-is. No further action from this
  pass.

### Obsolete — comment posted this pass

- **#188** ("Локальный jest считает воркдеревья субагентов внутри репозитория") — filed 07.08.2026,
  before Orca was adopted (s83, 20.08.2026); describes `.claude/worktrees/` and proposes a bespoke
  `jest.config.js`. The problem is already solved a different, simpler way: `npm run test:js --
  --roots "<rootDir>/tests/js"` is mandatory project-wide (`AGENTS.md`, `AGENT-RULES.md`, gotcha
  `jest-scans-agent-worktrees-inside-the-repo`), `--roots` narrows scanning before any worktree is
  reached, and `.orca/` is gitignored too. Current measured jest count on `main`: 1548 tests / 21
  suites. Commented `закрыть как выполненное` with the citation; not closed.

### Deep-checked and confirmed still valid (no action)

`#116` (three-part @since/array()/phpcs consistency review) — spot-measured directly: 138 files still
use `array(`, 527 `@since 1.x` tags remain, `phpcs.xml` has no `DisallowLongArraySyntax` rule. Not
touched by the separate #555 `@since` normalisation. Genuinely open.
`#141` (warehouse dead code) — the five files the card names are still present. `#510` (Codex cannot
read an Orca worktree over WSL) — this is a standing, documented environment limitation with an
accepted workaround (Opus as critic instead), not a bug with a pending fix; leaving it open is
correct, no closure recommended. `#573` (region-select promise race) — recent (27.08.2026), body
says "read, not measured" — no evidence it was fixed since.

### Not deep-checked (title/label triage only)

The remaining ~85 cards were bucketed by title and label and cross-referenced against
`CURRENT-STATE.md`'s own narrative (which already tracks the cards that matter most right now) rather
than each getting an independent code read. This is the honest limit of what one lap covered — a
genuinely exhaustive per-card code verification of all 93 is a multi-session undertaking on its own,
and guessing completeness would be worse than saying so. None of the title-only cards showed an
obvious red flag (a title describing already-shipped behaviour) the way #188 did.

---

## 3. Material for prioritisation (NOT a priority order — that call is the operator's)

### By layer

| Layer | Cards | Functionally finished? | What remains |
|---|---|---|---|
| **Pickup map (ПВЗ)** | 144, 148, 150–155, 163, 165, 170, 171, 173, 174, 181, 182, 188(→close), 379, 499, 646 | **Yes** — SP-5 shipped (program table ✅), #646 has a live PR in flight right now. | 15 bugs/polish/tech-debt items on an already-working feature. 6 of them (152, 148, 182, 174, 173, 151) are the operator-decided "leave until pilot migration" set (§ below) — not neglect, a deliberate hold. |
| **Checkout fields (§8)** | 331, 332, 371, 474, 483, 503, 652 | **Yes**, core layer shipped (PR #132). | One operator-gated invariant (#474), one open question possibly-correct-as-is (#483), one multi-package edge case (#371), two genuinely NEW features extending the pattern beyond pickup (#331 cart hints, #332 account hints — not bugs), a phone-mask enhancement answered-on-card-not-started (#503), and a fresh 4-scenario live-review card from s103 (#652). |
| **Location provider layer** | 289, 310, 318, 321, 322, 356, 358, 361(in progress), 364, 410, 437, 567, 573, 589 | **Yes**, 16/16 tasks done — **except #437**, explicitly "the next big one, not started" (removing the 500-item search cap; spec exists). | Three items CURRENT-STATE.md itself says need a contract/UX decision first (#356/#358/#410), one in-flight (#361), older standing tech-debt items answered-on-card-not-started (#289, #310, #318, #321, #322), one research note (#364), one idea (#589), and the framework-wide i18n-language decision (#567) which touches this layer's original registry but is no longer scoped to it. |
| **Payment-gateway** | 7, 117, 118, 621, 639 | **Yes**, core layer shipped. | #639 (filed 28.08.2026) asks a scope question — is 13.9k lines the right size for one hosted, non-tokenising gateway at all — and **#621's real fix is deliberately held behind that answer.** #117/#118 are pre-existing trait-extraction/dead-code debt on the same file. #7 is a real, dated (13.03.2026), unaddressed HPOS bug, never folded into any of this narrative and not on the board. |
| **Licensing** | 105, 112, 124 | **Yes**, S3 shipped (need-license → React UI → webhooks). | All three are cosmetic/optional: a crooked React block on the license page, an auto-cleanup-on-revoke idea, and a marketplace-page modernisation idea. None blocking. |
| **Infrastructure / tech-debt** | 8, 103, 104, 106, 107, 109–111, 115, 116, 126, 129, 138–141, 147, 188(→close), 247, 270, 285, 374, 381, 382, 429, 482, 510, 515, 567, 568, 644, 647, 653 | No single answer — this is the accumulated framework-wide cleanup/polish/research backlog. | Two operator decisions live here but are buried in prose, not visible as cards (§ below). Everything else is optional cleanup with no code-shipping consequence if left alone. |
| **Post-v2 ideas** | 108, 113, 114, 119–123, 125, 130, 215, 417–419 | N/A — explicitly deferred | S4/S5/S6 (EDD/React admin/ecosystem) are `⚪ deferred, post-v2.0` in the program table; #123 says explicitly "do NOT start automatically." #417-419 are pre-work for a *specific* future carrier (Ozon), not the current pilot. None of this should compete with anything above. |

Note: bucket assignment is my best-effort read of 93 titles in one pass, not a rigid taxonomy — a few cards straddle two layers, e.g. #567 spans i18n-infra and the location registry it originated from, #499 spans pickup and location. Flagged inline where it matters.

### Buried decisions — surfaced as their own list (currently invisible on the board)

1. **"Техдолг и улучшения карты (181, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до
   пилотной миграции"** — `CURRENT-STATE.md:260`, in prose only. (Its own listed #159 is stale —
   already flagged in §1 — the other 7 numbers are the real, current set.) No board label or
   milestone marks these 7 as held; a board visitor would read them as ordinary open tech-debt.
2. **#567's remainder is deferred to release, not to be worked now** — "150 English msgids with no
   translation — regenerate the `.pot` and rebuild the `.mo` before release" — operator decision
   29.08.2026, `CURRENT-STATE.md:66-68`, also prose-only.
3. **#613's `payment-tokens-handler.php:700` remainder** is waiting on the operator specifically —
   the rest of #613 (47/51 sites) is closed; this one site is a named carve-out buried in the same
   paragraph as the closure note.
4. **The "Старое" (old) list — #289, #270, #310, #318, #321, #322 — carries no stated reason for
   being old and untouched**, unlike every other item in this report. Age-without-explanation is
   itself a signal worth the operator's eye; surfacing it as its own line, not folding it silently
   into "waiting on his word" below, is this report's addition (not something a source doc already
   said explicitly).

### Waiting on the operator's word (confirmed still open, 2026-08-29)

**#567**, **#437** (needs a scope conversation, do not take autonomously), **#613** (only the
`payment-tokens-handler.php:700` part), **#474**, **#483**, **#515**, **#331**, **#332**, **#374**.
Deferred to release: **#285**, **#247**. Old, unexplained: **#289**, **#270**, **#310**, **#318**,
**#321**, **#322**. (#511 was on this list in `CURRENT-STATE.md` but is now closed — see §1 finding
4; removed here.)

---

## Related

- [`CURRENT-STATE.md`](../CURRENT-STATE.md) — the file most of §1's contradictions point at; not
  edited by this pass (coordinator-owned)
- [`GOTCHAS.md`](../GOTCHAS.md) — gotcha `jest-scans-agent-worktrees-inside-the-repo`, cited against #188
- [`DOCS-INDEX.md`](../DOCS-INDEX.md) — the mirror-disclaimer and stale specs-table findings, both here
- [`AGENT-RULES.md`](../AGENT-RULES.md) — Serena/Orca rules this audit followed; not edited (owned by a parallel PR)
- Issue [#644](https://github.com/kalbac/woodev-plugin-framework/issues/644) — the card this report answers
