# gotcha: a rig measurement taken on a timer invents a defect that is not there — poll, and always add a control

**Namespace:** `[tooling/rig]`
**Discovered:** s93 (2026-08-25), during acceptance of #530

## What happened

Verifying #530's region filtering on the rig, I changed the region to Москва, waited **3000 ms**,
read the settlement dropdown, and saw all six popular settlements instead of the three belonging to
that region.

I wrote it up as a defect. Worse, I *explained* it: the `related-list` region `<option>` carries
only a WooCommerce state code (`"МОСКВА"`, no `data-*`), while popular records key off
`ancestors: ["test-cdek:r81"]` — so there is "nothing on the client to match against". Every one of
those facts is true. The conclusion drawn from them was false. I filed issue #534 with the
measurement attached, and put the "limitation" in a PR description.

Minutes later, opening the same dropdown again showed exactly the three Moscow entries.

**The round-trip on this rig takes 6–10 seconds.** I had read the list before the region change
landed.

## Why it is dangerous rather than merely wrong

A premature read does not look like a failed measurement. It looks like a **clean result**, and a
plausible mechanism is always available to explain it — this layer is complicated enough that a
convincing story can be assembled for almost any observation. The false defect then travels: into a
card, into a PR description, into the next session's handoff, and eventually into someone's design
decision.

Note also that the s92 handoff *already carried this warning* («ждать по факту появления строки, а
не по таймеру»). Knowing it was not enough. The method has to make it impossible.

## ✅ The method

**1. Poll for the state you expect, never `sleep(n)` then read.**

```js
let rows = null;
for ( let i = 0; i < 60; i++ ) {
    await new Promise( r => setTimeout( r, 500 ) );
    rows = read();
    if ( changed( rows ) ) { break; }
}
```

**2. Add a CONTROL — this is the half that actually catches it.** One region proves nothing: an
unfiltered list and a not-yet-arrived list are the same bytes. Flip to a *different* region and
require the content to change accordingly.

```text
Москва           → Внуково, Москва, Бутово          (the three test-cdek:r81 records)
Санкт-Петербург  → Санкт-Петербург, Пушкин, Репино  (the three test-cdek:r82 records)
```

Two states that differ *in the expected direction* cannot both be a timing artefact.

**3. If a measurement produces a defect you can explain elegantly, re-run it before writing it
down.** The elegance of the explanation is not evidence. It is the thing that makes a timing
artefact survive review.

## Known timings on this rig

- `/suggest` for an unknown settlement: **6–10 s** (8.5 s measured in s93 for «Мухосранск»).
- A region change propagating through the cascade: longer than 3 s, comfortably under 30 s.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the other way a rig pass is wasted
- [a-select2-language-callback-that-returns-undefined-renders-blank](a-select2-language-callback-that-returns-undefined-renders-blank.md) — the same session's other example of a confident claim that measurement refuted
- `../sessions/s93.md` — the session this cost a card and a PR revision
