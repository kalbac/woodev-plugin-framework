# Card #644 part 3 — prioritisation material

> Reviewed: 2026-08-31 (s110). Live board facts measured this pass:
> `gh issue list --repo kalbac/woodev-plugin-framework --state open --limit 300` → **72 open
> cards**; `gh project item-list 6 --owner kalbac --limit 400` → **329 total items on board №6**
> (the first `--limit 300` pull silently truncated 10 of the newest cards as "not on board" —
> re-measured at `--limit 400`; see the `## Related` note). This report does not set priorities —
> the operator asked for that explicitly (§#644 body) — it answers the one question he said he
> cannot answer today: *is a given layer finished or not*.

**⚠ Live, uncommitted work in this exact checkout at the time of writing.** `git status` shows
`phpcs.xml` and ~75 `woodev/*.php` files modified but not committed — this is **#116/#139** being
worked RIGHT NOW (see their card comments, timestamped `s110`, commit `df7a64b` already landed for
part of #116(в)). This report does not touch those files and does not treat #116/#139 as idle
backlog — see Part 1.

## 1. Layer map — every open card, exactly once

| Layer | Cards | Count |
|---|---|---|
| Pickup map (ПВЗ) | 144, 148, 150, 151, 152, 163, 165, 170, 171, 173, 174, 181, 182, 215, 270, 379 | 16 |
| Checkout field layer (§8) | 331, 332, 364, 371, 474, 652 | 6 |
| Location provider layer | 310, 567, 573, 589 | 4 |
| Settings API + admin React | 103, 105, 109, 110, 112, 121, 126, 129, 692, 701 | 10 |
| Payment-gateway | 117, 118, 621, 639 | 4 |
| Licensing + updater | 106, 124 | 2 |
| Shipping SP-track (post-SP-5 / Ozon / SP-10) | 417, 418, 419, 694, 695 | 5 |
| Infra, tooling & tech debt | 104, 107, 111, 115, 116, 138, 139, 140, 141, 247, 285, 381, 382, 644, 689, 704 | 16 |
| Ideas after v2 | 108, 113, 114, 119, 120, 122, 123, 125, 130 | 9 |
| **Total** | | **72** |

Sums to the live open count (16+6+4+10+4+2+5+16+9 = 72; script-verified, no card duplicated or
missing). Straddling cases, called out so the count doesn't look like it's hiding ambiguity: **#364**
(country-locale size) sits on checkout-field policy code but its symptom is location-driven — kept
with location-adjacent framing under Pickup map in part-2's own report, moved here to Checkout field
layer since `Checkout_Field_Policy::filter_country_locale()` is where the code actually lives. **#652**
touches pickup-required-field interaction directly but its own title and all four scenarios are
framed as checkout-field-layer live-review, so it stays there rather than under Pickup map. **#417–419**
and **#694/#695** sit together as "the SP-7…SP-10 the SP-track table calls 🚧 IN PROGRESS but has not
actually started" rather than under "ideas after v2" — they are pre-work for a *specific*, currently
active integration effort (`woodev-ozon-delivery`, per each card's own "Source" line), not deferred
speculation.

## 2. Per-layer closure status

| Layer | Closed? | What remains |
|---|---|---|
| Pickup map | **закрыт, остались хвосты** | SP-5 is ✅ DONE (`CURRENT-STATE.md:198`). 16 open cards are all bugs/polish/tech-debt on an already-shipped feature — see full paragraph below. |
| Checkout field layer (§8) | **закрыт, остались хвосты** | PR #132 shipped the core (`CURRENT-STATE.md:197`). 6 open cards: one unenforced invariant needing a decision (#474, disputed — see Part 3), one live-review list (#652), two deliberately-parked future features (#331/#332), one design fork already effectively answered by precedent (#371), one research record (#364). |
| Location provider layer | **закрыт, остались хвосты** | 16/16 tasks done (`CURRENT-STATE.md:199`); #437 (the one genuinely large remaining item) **CLOSED in s109** (`CURRENT-STATE.md:118`). 4 cards open: one narrowed to a single UI button (#310), one convention question already answered in its own comment thread (#567 — only a release-gated remainder is open), one needs a rig reproduction, not a decision (#573), one is YAGNI-parked pending a second real consumer (#589). |
| Settings API + admin React | **в работе** | No single "done" milestone — this is the accumulated admin-surface polish/idea backlog. Two already fixed by unrelated work (#106 in the licensing sub-bucket, #113 — both Codex-verified `Already fixed`), one measured-and-mostly-resolved-pending-his-look (#692), one moot (#701, driver gone). The rest (#103, #109, #110, #112, #121, #126, #129) are independent, non-blocking polish/research items. |
| Payment-gateway | **закрыт, остались хвосты** | Core shipped (`CURRENT-STATE.md:207`, Phase Status table: ✅/✅). #621's diagnosis and fix design are fully measured (three comment rounds, s101–s109) and deliberately **held behind #639** — an explicit, already-recorded entry condition (2–3 payment plugins of different types), not an open question. #117/#118 are pre-existing debt on the same file, ordered to come *after* #639 by #639's own text. |
| Licensing + updater | **закрыт** | S3 shipped (need-license → React UI → webhooks, `CURRENT-STATE.md:195`). Both open cards are cosmetic/optional (#105 a crooked React block, #124 an auto-cleanup idea) — Codex report: neither blocking. |
| Shipping SP-track (SP-7…SP-10) | **не начат** | Program table itself says SP-6…SP-11 are pending (`CURRENT-STATE.md:198`) — this is accurate, not stale. #417/#418/#419 are Ozon-driven design input for SP-7/SP-8, explicitly gated on "decide before SP-7 starts" (verified in part 2 of this #644 pass — SP-7 has not started). #694 is a live decision fork sitting in `Инбокс` right now. #695's own reconciliation pass is now DONE (see `2026-08-31-695-shipping-plans-reconciliation.md`) and surfaced that #694's rationale rested on a mis-citation (§16 misread §15) — so #694 is not just unstarted work, it is unstarted work whose only recorded justification for the "default" option was already found wrong. |
| Infra, tooling & tech debt | **в работе** | No single closure signal — this is the framework-wide cleanup backlog. **#116 and #139 are being actively worked on in this exact session right now** (uncommitted `phpcs.xml` + ~75 `woodev/*.php` files in the working tree, comments timestamped today) — treat as in-progress, not idle. #285's underlying question is already answered on the card itself (operator: don't raise `VERSION` until v2 is genuinely ready) but the fact that raising it on `main` auto-publishes a GitHub Release (CI `release` job) is recorded only in that one comment — see Part 3. #247 similarly already answered (before-release). #689/#704/#141/#111/#104/#107/#115/#138/#140/#381/#382 are independent, non-blocking. |
| Ideas after v2 | **не начат (осознанно)** | S4/S5/S6 (EDD/React admin/ecosystem) explicitly `⚪ deferred, post-v2.0` (`CURRENT-STATE.md:200`); #123 explicitly says "do NOT start automatically." None of this should compete with anything above — that itself is a decision already made, just not attached to a board field. |

### Pickup map (ПВЗ) — the fuller answer, because this is one of the two he asked about

**Yes, functionally closed.** SP-5 (settings, auth+secrets, validation, `show_if`, map/ПВЗ incl.
pickup selection + viewport accumulation) is ✅ DONE per the program table
(`CURRENT-STATE.md:198`), and part 2 of this #644 pass verified that claim against the actual
source for all 16 of the open cards in this layer, not from memory. What's left breaks into four
groups, evidenced in `2026-08-31-644-tail-pickup-shipping.md`:

1. **A 7-card set the operator has ALREADY decided to hold** — "Техдолг и улучшения карты (181,
   152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции"
   (`CURRENT-STATE.md:257-259`, see Part 3 below). This is not neglect; it is a standing decision.
   **But the code-verification pass found the set is not uniformly still-live**: #148 is now
   **already fixed** by unrelated work (#232/#234/#238/#248 — a GitHub comment was posted with the
   evidence), and #182 was re-verified today and its scope **grew** (5→7 unguarded fields). The
   "hold" decision itself may be worth a fresh look now that one member no longer describes a live
   defect.
2. **Two items whose blocking condition has newly fired.** #150's own gate — "after the map
   rework is finished" — has fired (SP-5 is done); the defenses (`checkZoomRange: true`,
   `maxZoom`) are still in the code, but the card's own requested live-rig zoom check was never
   done. #165 is very likely already fixed by the general `_cameraFit` gate added in PR #177
   (s52) — a GitHub comment cites the exact mechanism — but the original symptom was
   rare/unreproduced, so this is a "confirm on the rig" item, not a clean auto-close.
3. **Independent, un-held bugs/polish** — #151 (no server pagination), #163 (distance-anchor
   default disagrees with its own docblock — contains a small UX fork), #171 (card loses
   focus/scroll on every rerender), #173 (a narrow ~400ms marker-parking race on normal open),
   #181 (no type badge in search results), #270 (rig fixture serves exactly one city) — all
   verified still accurate as filed, none blocked on a decision.
4. **Deferred by explicit priority, not urgency** — #215 (dark theme + more map providers),
   gated on "not before v2 ships" (`VERSION` is still `2.0.1 (unreleased)`, `CURRENT-STATE.md:325`)
   — gate has not fired.

### Checkout field layer (§8) — the fuller answer

**Yes, functionally closed**, core shipped in PR #132 (`CURRENT-STATE.md:197`). The 6 open cards
split cleanly:

- **One real, disputed decision**: #474 — an unenforced invariant on a public field-builder
  contract. The card's own text says explicitly "Решение за оператором... потому что это
  публичный контракт билдера." But `CURRENT-STATE.md`'s Next Actions section (the more recently
  written one) says the opposite: "#474 отнесена к архитектурным — решать замером, не спрашивать"
  (`CURRENT-STATE.md:253-254`, superseding the earlier `:123` line in the same file that still says
  "Operator decision needed"). **This is a genuine contradiction inside `CURRENT-STATE.md` itself**,
  not something this report is resolving — flagged, not silently picked.
- **A design fork the code has already effectively answered**: #371 (multi-package pickup — JS
  reads only the first radio, PHP treats any-package-pickup as pickup). Re-verified today
  (comment on the card): the PHP method's own docblock already records "loosen is the safe side"
  as a decision, not an inference — which settles the fork toward option 1 without needing to ask
  again. What's missing is a jest/unit test, not a decision.
- **Two deliberately parked future features**: #331 (cart address hints), #332 (account-page
  hints) — both explicitly "Постановка оператора 15.08.2026. Сейчас не реализуем — карточка на
  будущее," i.e. already answered ("not now"); what's genuinely open is *when*, which is exactly
  what this whole #644 exercise is for.
- **One live-review list, not a bug report**: #652 — four rig scenarios the operator dictated
  himself and wants checked with his own eyes (`CURRENT-STATE.md`: "#652 (его глаза на риге)").
- **One research record, not a defect**: #364 (locale payload growth) — explicitly "not a bug,"
  card exists to record a measurement.

## 3. Decisions buried in prose (invisible on the board)

| Decision | Where recorded | Cards it governs |
|---|---|---|
| **"Техдолг и улучшения карты (181, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции"** — hold pickup polish/tech-debt until a real carrier pilot shows which items are real. | `CURRENT-STATE.md:257-259`, prose only, no board label/milestone. | #181, #152, #148, #182, #174, #173, #151. **Nuance found this pass:** #148 is now already-fixed and #182's scope grew — see the pickup-map paragraph above. |
| **Raising `Woodev_Plugin::VERSION` on `main` is not a docs-consistency edit — it auto-publishes a GitHub Release** (tag + CHANGELOG + signed ZIP via the CI `release` job, gated only on the constant changing between `HEAD` and `HEAD~1`). | **Coordinator correction (s110):** the CONSEQUENCE is recorded in two places — `CURRENT-STATE.md:325` ("Raising VERSION on `main` publishes a release — do it deliberately (#285)") and `AGENT-RULES.md:194-196`. What is recorded ONLY in the #285 comment (13.08.2026) is the MECHANISM: tag + CHANGELOG + signed ZIP via the CI `release` job, triggered by the constant differing between `HEAD` and `HEAD~1`. The original wording of this row claimed the fact was in neither doc; that was checked and is wrong. The operator's actual answer ("не поднимаем до тех пор пока у нас реально не будет готово V2") IS on the card, so #285 itself isn't "waiting on him" — but the release-trigger MECHANISM is a fact anyone editing `class-plugin.php:20` needs and currently can only find by reading #285's third comment. | #285 directly; also the release-readiness gate for #247 (minify/enqueue) and the "when" for #116(a)'s `@since` normalization, both of which cite "before release" without saying what "raising VERSION" actually does. |
| **`SHIPPING-PLANS.md` §16 misquotes §15** — §15 decided "one page, tab-per-provider"; §16 claims to mirror it while deciding the opposite ("one page PER provider"), and that wrong citation propagated into `specs/2026-06-25-shipping-module-decisions.md`'s SP-10 line ("per-carrier React page"). The actual shipped code (`Settings_Page_Registry`, one singleton menu item) followed §15, not §16. | `2026-08-31-695-shipping-plans-reconciliation.md` (§16 row and "Cross-reference errors" table) + a comment on #694, both dated 31.08.2026 — nowhere on the board itself. | #694 (the decision fork this error created) — the "mirrors §15" argument for option (1) is not just stale, it never held. |
| **#567's language-convention question is answered — only a release-gated remainder is open.** The card's own top-level body (fetched without `--comments`) still reads as an open question; it is a stale snapshot. Its comment thread shows: rule 1 (frontend → English msgid) shipped in PR #645 (`e2c6612`), rules 2–4 were given by the operator 29.08.2026 and are now codified in `AGENTS.md` → Conventions (`c5ccae8`). Only "150 English msgids without a translation — leave them, regenerate `.pot`/`.mo` before release" remains, which is exactly what `CURRENT-STATE.md:61-62` already says. | `CURRENT-STATE.md:61-62` (correct and current) vs. #567's own top-level body (stale) — a card whose *body* undersells its own resolved state. | #567 only; worth a body edit or closure note so a future title-only triage doesn't reopen a solved question. |
| **#692's remaining copy edits are sequenced behind PR #699**, not blocked on a new decision — "if I add `description` text to the same settings tab #699 already touched, your one review pass of #699 stops answering 'what did I approve.'" | A comment on #692 (31.08.2026), nowhere else. | #692. |
| *(Resolved, not a live finding)* The s104 audit (`2026-08-29-docs-and-board-audit.md` §3) flagged #289/#270/#310/#318/#321/#322 as "old, no stated reason." Re-checked against the live 72-card list: #289, #318, #321, #322 are **already closed**. Only #270 and #310 remain open, and both now carry clear, current reasons (#270: rig-fixture single-locality, tied to #176's persistence scenario; #310: narrowed to one remaining UI button, per a comment posted today). This finding has evaporated on its own — noted so nobody re-raises it as still live. | — | — |

## 4. Waiting on HIM

Discriminator per `AGENTS.md`: does this card need HIS answer, not who filed it. Verified against
each card's full comment thread, not just its top-level body (#567 and #692 both showed why a
body-only read would have miscategorised them).

| Card | The question |
|---|---|
| **#644** | Priorities themselves — the reason this whole report exists. |
| **#652** | Which of 4 checkout-field/pickup scenarios actually blocks "Place order" — his own dictated live-review list, and per his established rig-testing habit this is his eyes, not a code check. |
| **#694** | SP-10 orders-page layout: one-page-per-carrier / one shared page / a third option the code measurement itself proposes — sitting in `Инбокс` right now, and its only recorded justification for option (1) was just found to rest on a mis-citation (see Part 3). |
| **#692** | Which of 4 measured settings' explanatory text should move from tooltip to visible `description` (author's own lean: first three yes, `region_field` no) — deliberately deferred until after he's looked at PR #699 on the same tab. |
| **#331 / #332** | Not "what" (already answered: cart/account address hints, deferred) but "when" — his own "не сейчас" from 15.08.2026, revisited by exactly this prioritisation pass. |
| **#695** | Now that its own reconciliation pass is DONE (`2026-08-31-695-shipping-plans-reconciliation.md`), the disposition of `SHIPPING-PLANS.md`/`PLANS.md`: archive the spent parts and move the live remainder into `specs/`+cards (author's lean), keep in root with a status banner, or move under `docs-internal/` — deliberately left for after the pass, which has now happened. |
| **#141** | Confirm the s32 YAGNI decision (delete the still-present warehouse scaffold — table name `wc_yandex_delivery_warehouses` is a preserved contract either way) or reverse it and record why the code was kept. A fresh comment (s109) adds a third fact to weigh: the "dead" class already served as a design *precedent* for the popular-settlements layer (#488), so deleting it also orphans that precedent unless it's written up elsewhere first. |

**Disputed, not placed on this list outright:** **#474** — the card's own text says this is his
call ("публичный контракт билдера"); `CURRENT-STATE.md`'s most recent line says the opposite
("решать замером, не спрашивать"). Reported as a contradiction in Part 3 rather than resolved
here in either direction.

**Considered and explicitly excluded, with why:** #639 (a stated entry condition — 2-3 payment
plugins of different types — not an open question); #621 (held behind #639 by its own author's
recommendation, not itself awaiting an answer); #285 (already answered, on the card); #567 (already
answered, on the card — see Part 3); #701 (its own driver is gone; a closure candidate, not a
question); #247/#689/#589 (already decided: release-gated / deliberately not fixed / YAGNI-parked);
#371 (direction already implicit in existing code precedent, downgraded to Part 5).

## 5. Ready to pick up

Unblocked, verified live this pass (part 2 of #644, the Codex core/gateway/infra pass, or a direct
s109/s110 comment on the card itself), needing no decision. Not ranked.

### Candidates for closure (verification only, no new code)

- **#106** — Codex-verified `Already fixed` (F1/F3 landed in commit `c66a955`).
- **#113** — Codex-verified `Already fixed` (marketplace tab + redirect present).
- **#148** — this pass's own finding: already fixed by #232/#234/#238/#248's cart-change refresh.
- **#701** — its own author's comment: driver is gone, no consumer exists; recommends closing if
  none appears rather than building for a hypothesis.
- **#165** — very likely already fixed (general `_cameraFit` gate, PR #177), but the original
  symptom was rare/unreproduced — closing needs a rig confirmation pass, not just a code read.

### Ready to implement (no decision blocking, effort signal where measured)

**Pickup map** (none of these are in the 7-card pilot-hold set):
- **#150** — verification-sized: existing `checkZoomRange`/`maxZoom` defenses just need the live
  single-point-city zoom check the card always asked for.
- **#163** — small UX fork (default anchor = map centre vs. search-only) then a one-line code
  change either way.
- **#171** — one function (`renderCard()` in `pickup-panels.js`), focus/scroll save-restore.
- **#173** — narrow (~400ms) ymaps overlay-layout timing fix, same family as the already-fixed
  restore-path case.
- **#270** — mechanical: teach the rig's bulk fixture a second locality (77/78 already mapped in
  `class-test-selection-scope.php`).
- **#371** — direction settled by existing precedent (see Part 2); remaining work is a
  jest/unit test on `selectedShippingMethod()` with two radio sets, since the rig can't reproduce
  multi-package orders.

**Location provider layer:**
- **#310** — narrowed to ONE remaining item: a "locate by IP" button in the already-shipped React
  picker (`location-picker-field.js`); the route it would call already exists and works.
- **#573** — needs a rig reproduction (rapid double-region-click inside the ~10.5s cold-region
  window), not a decision; fix shape already sketched (a generation token, same pattern as
  `activeAbort`).

**Infra / tooling** (independent, non-blocking):
- **#111** (dead-file sweep), **#129** (block-level secret disable), **#138** (`includes()` vs
  `class-map` decision — small), **#140** (REST test-stub type-hint restoration), **#124**
  (account-state auto-cleanup on revoke).

**Excluded from this list on purpose:** **#116 and #139** — already being worked on in this exact
session (see the note at the top of this report); handing either to someone else right now would
collide with in-flight, uncommitted changes.

## Related

- [Card #644](https://github.com/kalbac/woodev-plugin-framework/issues/644) — the audit umbrella
  this report answers part 3 of.
- [Pickup/shipping tail pass](2026-08-31-644-tail-pickup-shipping.md) — part 2 of this same #644
  effort; source for most Pickup-map evidence above.
- [Core/gateway/infra tail pass](2026-08-31-644-tail-core-gateway-infra.md) — the Codex worker's
  parallel part 2; source for the Settings/Licensing/Payment-gateway `Already fixed`/`Scope
  changed` verdicts above.
- [Shipping plans reconciliation](2026-08-31-695-shipping-plans-reconciliation.md) — source for
  the §16/§15 mis-citation finding governing #694.
- [Docs and board audit, s104](2026-08-29-docs-and-board-audit.md) — the prior prioritisation-
  material pass (93 open cards then); several of its findings (the buried techdolg-map decision,
  the "old cards" list) are re-verified above against the now-72-card board, one confirmed live,
  one found resolved.
- [`CURRENT-STATE.md`](../CURRENT-STATE.md) — program table and "Open cards after s109" section,
  cited throughout; not edited by this pass.
- **Board-count note:** `gh project item-list 6 --owner kalbac --limit 300` silently truncates —
  the project holds 329 items total and the newest ~10-29 cards fall outside a 300-row pull with
  no error. Re-run with `--limit 400` (or paginate) before trusting a "not on board" read; this
  is exactly what caused this pass to briefly misread #694 as absent from the board before
  re-measuring found it correctly sitting in `Инбокс`.
