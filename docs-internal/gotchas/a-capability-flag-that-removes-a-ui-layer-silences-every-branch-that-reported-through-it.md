# A capability flag that removes a UI layer silences every branch that reported through it

**Namespace:** `[shipping/pickup]` · **Discovered:** s66 (2026-08-11), from #265 — second occurrence of the same family (#260 was the first)

## The trap

`ownsChrome` means "the carrier draws its own list and card, so the framework draws none"
(`panels === null`). It is a statement about **drawing**. But every place that told the customer
anything did so *through* the panels, so the flag silently also meant "say nothing", and the code
still reads as complete:

```js
if ( ! result ) {
    if ( panels ) { panels.showSelectionError( … ); }   // transport failure
    return;
}

if ( ! result.allowed ) {
    if ( panels ) { panels.setPointVerdict( … ); }      // the domain refused
    return;
}
```

Each `if` is a no-op under `ownsChrome`, and the `return` right after leaves the dialog open. The
customer presses «Забрать здесь», waits out the round trip, watches the busy overlay clear — and
gets nothing. **It is indistinguishable from "the dialog just did not close"**, which is exactly
how it was read: the operator reported #260 as «после того как AJAX запрос прошел, у меня карта
автоматически не закрылась».

What makes this a family rather than a one-off: #260 was the same shape on the **waiting** branch
and was fixed there, in `acquireSelectionBusy()`. Fixing one occurrence teaches nothing about the
others, because the shape is not a bug in a function — it is a consequence of the flag.

## The fix

Report through the layer the framework owns in **both** modes. The dialog shell is always ours:

```js
} else {
    announceWithoutPanels( text( config, selectionErrorKey( config, reason ) ) );
}
```

`modal.showNotice()`, **never** `showError()`: the latter replaces the dialog BODY, which under
`ownsChrome` is the carrier's own widget/iframe. One refused point is no reason to destroy the
picker — the customer must stay free to choose another, and tearing the frame down also means
re-running the carrier's handshake. Same non-destructive/destructive split `degrade()` already
makes once a point set is drawn.

Mirror the panels' own precedence for the refusal text rather than inventing a second rule —
`pickup-panels.js` renders `selectable.reason || text( config, 'blocked' )`, so the domain's own
words win and the framework's generic string is only a fallback.

**This is not a breach of D-3.** D-3 says the framework does not draw the carrier's list or card.
Saying how *our own* request ended is not drawing a carrier's UI — and the framework already
reports its own outcomes this way on the `degrade()` path.

## Rule of thumb

When a flag removes a whole layer, **enumerate what that layer was load-bearing for, not what it
drew.** Grep the flag's own guard (`if ( panels )`) and ask of each hit: is this drawing, or is
this the only place the customer learns something? The second kind needs a home in every mode.

Corollary for reviews: `if ( x ) { report(); } return;` is worth a second look on its own. A
conditional whose else-branch is *silence* looks like defensiveness and behaves like a dropped
outcome.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — same shape of blindness in the wiring
  direction: everything present, nothing connected.
- [[jquery-trigger-change-fires-no-native-event]] — also from s66: a listener that exists and
  never runs. Both are "the code is there" failures.
- `docs-internal/specs/2026-08-10-embedded-map-provider-adapter-seam.md` — where `ownsChrome` and
  D-3 are defined.
