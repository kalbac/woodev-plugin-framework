# SP-5 — Pickup point selection mechanism (design)

> Status: **approved by the operator, not yet implemented** · Date: 2026-08-06 (s52)
> Supersedes nothing. Extends the shipped SP-5 pickup map (PR #149, merged 2026-08-05).
> Origin: operator review item #6 — "механизм выбранного ПВЗ", the only non-cosmetic item
> left after the map was accepted.

---

## 1. Problem

Today, choosing a pickup point is instantaneous and unconditional. `handleSelection()`
(`woodev/shipping-method/assets/js/frontend/pickup-mount.js:1152`) does four things
synchronously and returns:

1. `applySelection( config, point )` — writes the §8 checkout field
2. `syncTriggerLabel( config )` — re-derives the trigger button's label
3. `fireDocumentEvent( EVENT_POINT_SELECTED, … )` — fires `woodev_pickup_point_selected`
4. `modal.close( 'select' )` → on success, `closeSession()`

There is no point at which the domain can refuse the selection, and no point at which the
customer learns that their choice was refused.

That gap is already documented, in the framework's own code. `Constraint_Checker::check()`
(`woodev/shipping-method/pickup/class-constraint-checker.php:60`) states:

> Unknown constraint data is permissive: a carrier's list response frequently omits
> `accepts_cod`/`max_weight` (**they arrive only with a details call**), so a point whose
> inputs are unknown is emitted as selectable rather than incorrectly greyed out. **The
> server re-check at checkout processing remains the backstop.**

So a customer can today pick a point that will be refused later, and the only thing standing
between them and that refusal is a check that runs at order submission — far from the click,
and long after the customer has stopped thinking about pickup points.

**This design closes that gap by making selection a server round-trip whose verdict the
domain owns.**

### What the operator asked for, verbatim in intent

- the map does not close itself on selection
- an ajax step runs, with a spinner on the button
- on success the button becomes «Продолжить оформление», and *that* click closes
- on reopening the map: camera on the selected point, marker `active`, that point's **detail
  card** open, the point highlighted in the row behind it
  - *originally* "sidebar open, the point highlighted in the list". **Revised by the operator
    on 06.08.2026**, after seeing the shipped behaviour on the rig: he wants the chosen point's
    details and its «Продолжить оформление» button visible immediately on reopen, not a list he
    has to click through to reach them. See §5.3.
- **the plugin decides** whether to close immediately instead

---

## 2. What already exists (verified against the code)

Establishing this precisely is what kept the design small — most of what is needed is
already built and merely never wired to selection.

| Capability | Where | State |
|---|---|---|
| Server-side "may this point be chosen" verdict | `Constraint_Checker::check()` | exists, computed at **fetch** time |
| Domain override of that verdict | filter `woodev_shipping_pickup_point_selectable` | exists |
| Card renders a refusal (warning + dead CTA) | `buildCardFooter()`, `pickup-panels.js:1139` | exists |
| Card re-renders from held state | `renderCard()` | exists |
| CTA label switches to «Продолжить оформление» | `pickup-panels.js:1151`, i18n key `continueCheckout` | exists, but the click behaves identically to «Забрать здесь» |
| Selected id known to the panels | `setSelectedId()`, seeded at session open from the field | exists, affects **only** the CTA label |
| Camera move to a point | `focusGroup( key )` | exists |
| Marker `data-state="active"` | side effect of `focusGroup()` — it *is* what writes the attribute | exists |
| Sidebar deterministic open | `openList()` | exists |
| Cancelable close | `woodev_modal_before_close` (D-14) | exists |
| Point detail fetch | `GET woodev/v1/shipping/pickup/{plugin}/points/{id}` | exists |
| Row highlight for the selected point | — | **does not exist anywhere** (no `is-selected` in JS or CSS) |

Two further facts that shaped decisions below:

- **A non-selectable point is not removed from anything.** `selectable` is read only in
  `buildCardFooter()`. The point stays in the list, stays on the map, its card still opens;
  only the CTA dies and a reason appears above it. (The v1 Yandex.Delivery reference does
  the opposite — `addPlacemarks()` drops such points via `return null`. Ours is the better
  behaviour and is kept.)
- **The trigger button and its label are rendered by the client, not the server.**
  `class-pickup-field.php` emits only an empty anchor (`[data-woodev-pickup-slot="…"]`);
  `mountOne()` (`pickup-mount.js:1660`) creates the button, and `syncTriggerLabel()` derives
  its label from the field's value. On `updated_checkout`, §8 re-places the anchor and the
  mount re-runs (deferred ~60 ms) and re-derives the label. **The framework therefore does
  not need `update_checkout` to keep the pickup UI honest** — which is what makes D-4 below
  possible at all.

### The reference, and where it stops being one

`plugins-reference/woocommerce-yandex-delivery/assets/js/frontend/wc-yandex-delivery-widget-map.js:171`
hands the consumer `onSelectPoint({ target, data })` — the **button element itself** and the
point data — and does nothing else (default `$.noop`). The consumer
(`wc-yandex-delivery-modal-standard-map.js:33`) then owns everything: disables the button,
blocks the map container, `$.post`s to `set_yandex_delivery_pickup_point`, and on success
replaces the address fields, **clicks the modal's own close control**, and triggers
`update_checkout`.

So the reference achieves "the plugin decides whether to close" by simply *not closing* and
leaking a DOM handle outward. Two things follow:

- the reference **confirms** that the close decision belongs to the domain — adopted
- the reference's **mechanism** (hand out the button, let each plugin re-implement spinner,
  error rendering and close) is rejected: it would make framework DOM part of the plugin
  contract and duplicate the same UI code in every carrier plugin
- the two-step «Продолжить оформление» is **not** in the reference; it is the operator's own
  requirement, and the framework already carries the i18n key for it

---

## 3. Decisions

**D-1 — The framework owns the transport; the domain owns the verdict.**
A new REST route performs the round trip; a PHP seam lets the plugin decide. Spinner, error
rendering, button states, close and refresh are implemented once, in the framework. This is
the same shape `Constraint_Checker` + `woodev_shipping_pickup_point_selectable` already use:
the framework computes, the domain overrides.

**D-2 — Two observational JS events, no client-side veto.**
`woodev_pickup_point_select_requested` on click and
`woodev_pickup_point_select_resolved` on the answer, both on `document`, matching the
existing `woodev_pickup_point_selected`. They are notifications: the framework neither waits
for them nor lets them veto. A veto path already exists and is unchanged
(`woodev_modal_before_close`).

**D-3 — `close` and `refresh_checkout`: plugin config default, per-response override.**
The plugin declares a default in PHP (travelling to JS by the same path as `pointIcons`,
`defaultLocation`, `searchNearestCount`); the domain's response may override it for a
specific selection. Rationale: a per-carrier default is what an author actually thinks in,
but the correct answer can be case-dependent — Yandex changes the tariff when the point type
changes and does not otherwise; CDEK never changes it within one city.

**D-4 — `refresh_checkout` is a decision, never a constant.**
Firing `update_checkout` unconditionally is wrong (CDEK: pointless work on every selection);
never firing it is wrong (Yandex: the customer is shown a stale price). The framework must
not guess, and this must not be a merchant setting — it is knowledge only the domain has, at
the moment of the selection. Made possible by the fact established in §2: our trigger UI does
not depend on a server re-render.

**D-5 — Absent means "the domain said nothing"; explicit `false` wins.**
Read with `??`, **never `||`**. `response.close ?? config.close` gives the correct result for
`undefined`, `null` and `false` alike; `||` silently converts an explicit `false` into the
default. This is the identical trap fixed in s40 (`||` → `??`, fail-closed parity). A
regression test pinning "explicit `false` does not fall through to a `true` default" is
mandatory.

**D-6 — Domain refusal and technical failure are different outcomes.**
They are not two designs; they are the two branches HTTP already forces us to write. A
refusal is a successful response carrying `allowed: false` and lands in the resolve branch; a
network error, timeout or 5xx lands in the reject branch. Not distinguishing them would mean
deliberately writing the same message twice to get a worse result — and would grey out a
perfectly good point because one request was dropped.

**D-7 — A refusal is remembered; a failure is not.**
A domain refusal is written into the point object the panels already hold, so it survives
card re-render — `buildCardFooter()` then draws the warning and the dead CTA on its own,
reusing rendering that already exists. A technical failure is transient and clears on the
next re-render or card switch.

**D-8 — The refusal does not reach the list.**
No blocked-row styling is added. The list today has no notion of a blocked point (verified:
`selectable` is not read there and no CSS rule exists), and introducing one would be genuine
new UI surface with new states. Refusal lives in the card only.

**D-9 — The card is locked while the request is in flight, AND a staleness guard is kept.**
These solve different problems and both are required:
- **Locking** prevents a *server-side* ordering inversion: without it a customer can send A
  then B, B can reach the server first, and the server ends holding A while the client shows
  B. A locked card makes a second request impossible by construction.
- **The staleness guard** covers the paths that are not clicks inside the card and which
  locking cannot intercept: `updated_checkout` re-placing the §8 anchor *while a session is
  open* (`pickup-mount.js:15-27`), Esc, the backdrop, the close button. The request records
  which point it was sent for; on arrival, an answer for a point the card no longer shows is
  discarded silently.

**D-10 — No `AbortController`.**
The request has server-side side effects — the domain may have already written the point into
the WC session. Aborting stops us listening; it does not undo the server's work, and would
leave the server holding point A while the client shows B. Let it complete and ignore the
answer.

**D-11 — «Продолжить оформление» never re-sends the request.**
That state means the point is already accepted; the click only closes. The same applies on
reopening the modal, where the previously chosen point's card reads that way from the start.
Accepted consequence: if the cart changed between sessions, that click passes without
re-asking the domain. This is not a hole — `selectable` is recomputed server-side on **every**
points fetch with the current cart, so anything the framework can see already kills the
button, and anything only the carrier's backend knows is caught by the checkout-processing
backstop that `Constraint_Checker` already names as the backstop.

**D-12 — No third marker state.**
"Selected" is not given its own marker appearance. The icon contract the framework promises
plugins is exactly two images per type — `pointIcons: { typeCode: { default, active } }` —
and a third state would oblige every plugin to draw a third variant for every point type:
a breaking change to an outward-facing contract, for a nuance the list row carries
permanently anyway. On the map, `active` means **focused**; "selected" lives in the list row
and in the trigger button's label.

**D-13 — The existing `is-busy` machinery is not reused.**
`.woodev-pickup-stage.is-busy` (`pickup.css:298-309`) is designed for "no data exists yet": it
whitens the whole stage, kills map pointer events and **hides the search and filter controls**.
Applying it to confirmation of an already-made choice would make the search bar vanish under
the customer mid-selection. The busy state for this step is the button's own spinner plus the
card lock.

**D-14 — Address-field replacement stays in the plugin, on the client, gated by the
merchant's own setting.**
Whether a chosen point's street/house/postcode is written into the checkout address fields is
a merchant decision exposed in the plugin's settings — established in earlier sessions and
unchanged here. The framework does not do it and does not offer a switch for it. The plugin
performs it from JS, listening to `woodev_pickup_point_select_resolved` (D-2) or the existing
`woodev_pickup_point_selected`, both of which carry the point — including the domain-refined
`point` payload when the response supplied one (§4.3). This is the one thing the reference
also does on its own side (`wc-yandex-delivery-modal-standard-map.js:55-70`), and it is the
reason D-2's events exist rather than being merely nice to have: without them the plugin has
no moment at which to act. No server-side seam is possible here — the checkout DOM is the
client's.

**D-15 — A missing selected point restores silently.**
If the previously chosen point is no longer in the current results (city changed, cart
changed, carrier withdrew it), the map opens in its ordinary default view and the checkout
field is **not** cleared. No fourth empty-state message is introduced — the three existing
ones (`emptyLocality` / `emptyInView` / `noResults`) are deliberately distinct and a fourth
would blur them (this is the standing risk tracked as #166).

---

## 4. The contract

### 4.1 Route

```
POST  woodev/v1/shipping/pickup/{plugin_segment}/select
```

`{plugin_segment}` is baked in as a literal exactly as the existing `points` routes do
(`class-pickup-controller.php:219-230`) — each shipping plugin registers a distinct route.

**Body:** `field_id`, `point_id`, `method_id`.

**Permission (D-16).** Unlike the two `GET` routes this one is not a public read: it writes to
the WC session, and the domain seam behind it may call the carrier's API — an unguarded
POST route is also a free way to burn the merchant's carrier quota using a visitor's browser.
A capability check is impossible and unwanted: guests place orders.

The route therefore keeps the **same `wp_rest` nonce the rest of the feature already uses**
(`Pickup_Handler:764` → `X-WP-Nonce`, `pickup-datasource.js:215`), and deliberately does NOT
invent its own action carried in the body. Two reasons, and the second is the load-bearing
one:

1. A second nonce scheme in the same feature is a second thing to keep fresh, for no gain.
2. **A custom body nonce would only work if we stopped sending `X-WP-Nonce` entirely** — and
   that demotes the request to anonymous at WordPress's cookie-auth layer, which puts a
   logged-in customer's WC session identity at risk (their session is keyed on user id).
   Writing a pickup point into the wrong session is a worse defect than the one we would be
   solving.

**The consequence that dictates the rest of this section:** an *invalid* nonce is rejected by
`rest_cookie_check_errors()` **before any `permission_callback` runs** — see the existing
gotcha `rest-cookie-nonce-auth-semantics` (a *missing* nonce merely demotes to anonymous;
only an *invalid* one errors outright). That is why issue #157 reports a bare
`403 rest_cookie_invalid_nonce` from a route declared `permission_callback => '__return_true'`:
WordPress refused it before the framework was consulted. **We therefore cannot emit our own
error code for a stale nonce on this route**, and any design that assumes we can — including
an earlier draft of this section — is wrong.

### 4.1.1 #157 is folded into this work (operator decision, 2026-08-06)

Issue #157 was originally out of scope here. It is not separable: the select route inherits
the identical failure, and the cost of that failure is higher. On `GET points` a stale nonce
produces an empty map — irritating. On `POST select` it produces a **silently refused pickup
choice at the end of checkout**.

Both of #157's own proposals are in scope:

1. **Read the nonce at request time from the live config**, not captured at construction
   (`pickup-datasource.js` captures once today). `updated_checkout` re-renders the fragment,
   so a freshly rendered nonce becomes available on its own; reading late is what picks it up.
   This removes the cause for the ordinary long-open-page case.
2. **Distinguish `rest_cookie_invalid_nonce` from an empty result** and say so: a new i18n key
   alongside the existing `upstreamError` / `rateLimited` / `notFound` map in
   `pickup-mount.js`, wording to the effect of "страница устарела — обновите её", with retry.

Residual cases the freshness fix cannot cover (a login or logout mid-session invalidates the
nonce immediately, because the session token changes) land on proposal 2's message. On the
select route specifically, that outcome is routed to the **technical-failure** branch of §5.1
— retryable, button alive, verdict not remembered — which is exactly correct: nothing about
the point was refused.

Still out of scope: automatic nonce refresh-and-retry. It needs an endpoint of its own and
would be the third nonce mechanism in one feature.

### 4.2 Server-side seam

The framework seeds the result with its own verdict (the same `Constraint_Checker::check()`
used at fetch time, with the current cart and payment method), then hands it to the domain:

```php
$result = apply_filters(
    'woodev_shipping_pickup_point_selection',
    [
        'allowed'          => (bool) $verdict['allowed'],
        'reason'           => $verdict['reason'],   // ?string
        'close'            => null,                 // null = domain said nothing
        'refresh_checkout' => null,                 // null = domain said nothing
        'point'            => null,                 // ?array — refreshed point payload
    ],
    $point,      // Pickup_Point
    $context     // array: field_id, method_id, payment_method, cart_weight (GRAMS)
);
```

Sanitization follows `Constraint_Checker::sanitize_verdict()`'s existing discipline —
**fail-closed, silently**: a filter returning a non-array, a wrongly typed `allowed`, or a
`reason` that is neither string nor null is discarded in favour of the computed verdict.
`close` and `refresh_checkout` accept `null` or `bool` only; anything else is normalised to
`null` (i.e. treated as "the domain said nothing").

`null` is serialised as JSON `null` rather than omitted — `??` treats `null` and `undefined`
identically, so both shapes are safe and the server does not need to prune keys.

### 4.3 Response, as read by the client

| Field | Source | Rule |
|---|---|---|
| `allowed` | domain, seeded from the framework verdict | `false` → refusal; the field is not written |
| `reason` | domain | shown in the card's existing warning slot |
| `close` | plugin config default, domain may override | `response.close ?? config.close` |
| `refresh_checkout` | plugin config default, domain may override | `response.refresh_checkout ?? config.refreshCheckout` |
| `point` | domain, optional | when present, replaces the held point data (address/postcode the domain refined against the carrier) |

---

## 5. Client behaviour

### 5.1 Button state machine

```
                    ┌─────────────────────────────────────────┐
                    │  «Забрать здесь»          (point not     │
                    │                            selected)     │
                    └──────────────┬──────────────────────────┘
                                   │ click
                                   ▼
                    ┌─────────────────────────────────────────┐
                    │  spinner in the button, button dead,     │
                    │  card locked by its own overlay          │
                    └──┬───────────┬──────────────┬───────────┘
           allowed:false│          │ transport    │ allowed:true
                        ▼          ▼  failure     ▼
        ┌──────────────────┐ ┌──────────────┐ ┌────────────────────────┐
        │ reason shown     │ │ "try again"  │ │ close:true  → close    │
        │ button dead      │ │ button alive │ │ close:false →          │
        │ REMEMBERED       │ │ NOT          │ │  «Продолжить           │
        │ (survives        │ │ remembered   │ │    оформление»         │
        │  re-render)      │ │              │ │                        │
        └──────────────────┘ └──────────────┘ └────────────────────────┘
```

Both messages use the **existing** `.woodev-pickup-card__warning` slot. No new slots.

### 5.2 Order of operations on success

1. write the field (`applySelection`)
2. re-derive the trigger label (`syncTriggerLabel`)
3. fire the existing `woodev_pickup_point_selected` — **unchanged**, it is already a public
   contract
4. if `close` → `modal.close( 'select' )`; tear the session down only if the close actually
   took (a `before_close` listener may veto)
5. if `refresh_checkout` → `update_checkout`, awaited to `jQuery.active === 0`

Close precedes refresh: the customer gets immediate feedback and the recalculation happens
behind a closed modal. When `close: false` **and** `refresh_checkout: true`, the button stays
in its busy state until the refresh settles — otherwise the customer can press «Продолжить
оформление» in the middle of a totals update.

That combination is the one place needing rig attention: `update_checkout` re-places the §8
anchor **underneath an open modal**. The case is known and handled (`pickup-mount.js:15-27`,
deferred re-mount), but it is precisely the class of thing green tests have never been honest
about on this feature.

### 5.3 Restoring a selection on reopen

| Requirement | Mechanism | New? |
|---|---|---|
| camera on the selected point | `setPoints( groups, { focus: key } )` — camera BEFORE the draw | exists |
| marker `active` | side effect of that same focus pass | exists |
| **that point's detail card open** | `openCard( group, selectedId, 'restore' )` | **revised 06.08.2026** |
| row highlighted in the list *behind* the card | `is-selected` on the row | **new** |
| CTA reads «Продолжить оформление» | `setSelectedId()` (before the card) + existing label logic | exists |

The only new work is the row highlight. If the point is absent from the current results, see
D-15: open normally, silently, field untouched.

**Revision, 06.08.2026 — the card, not the list (operator decision).** This row previously read
`sidebar open | openList() | exists`. The operator reviewed the shipped behaviour on the rig and
decided the reopened picker must show the chosen point's **detail card**: he wants its details and
its «Продолжить оформление» button in front of him immediately, rather than a sidebar list whose
highlighted row he still has to click. `restoreSelection()` calls `openCard()` accordingly.

Two consequences, both accepted:

- **The list behind the card holds exactly one row.** `setPoints( …, { focus } )` opens the map at
  `MAX_ZOOM` (18) and the sidebar list is viewport-filtered (the provider emits `visibleChange`,
  the mount answers with `panels.setVisible( groups )`), so at that zoom only the restored marker
  is in view. Closing the card reveals a one-row list. This is the zoom's doing, not a defect —
  the operator kept zoom 18 knowingly and this is not to be "improved".
- **The card-open must not move the camera a second time.** `openCard()` emits `cardOpened`, and
  the mount answers that event with `provider.focusGroup()` for every other route to a card. The
  restore passes `origin: 'restore'`, the one origin that listener returns early on: this pass's
  camera move already went out ahead of the draw, and that ordering is load-bearing (moving the
  camera across the ObjectManager's first layout parks the marker's overlay off screen — s52; see
  `docs-internal/gotchas/ymaps-draw-then-move-parks-the-overlay.md`). The sidebar half is
  unchanged: `openCard()` still opens a closed stage through `setStageOpen()`, so `listToggle` →
  `provider.setMargin()` still fires exactly as `openList()` made it.

The row highlight stays required — it is simply behind the card until the customer dismisses it.

---

## 6. Out of scope

- Automatic nonce refresh-and-retry (§4.1.1). **#157 itself is IN scope** — see §4.1.1.
- Blocked-row styling in the sidebar (D-8).
- A third marker state (D-12).
- Any framework-side address-field writing (D-14) — the plugin owns it, from JS.
- A "вы выбрали пункт X" summary line in the checkout. It does not exist today; if it is ever
  added it must be **client-rendered** from the field value, like the trigger label, or we
  reintroduce the server-render coupling that D-4 depends on being absent.
- The filter behaviour the operator flagged mid-brainstorm ("баг или фича, потом обсудим") —
  not investigated, not designed here.

---

## 7. Test obligations

Beyond ordinary coverage of the new route, seam and states:

1. **`??` not `||`** — an explicit `close: false` / `refresh_checkout: false` from the domain
   must not fall through to a `true` config default (D-5).
2. **Filter fail-closed** — a filter returning junk for `close`/`refresh_checkout`/`allowed`
   yields the framework's computed result, silently (§4.2).
3. **Staleness guard** — an answer arriving for a point the card no longer shows changes
   nothing (D-9).
4. **Refusal is remembered, failure is not** — re-rendering the card after each (D-7).
5. **«Продолжить оформление» sends no request** (D-11).
6. **`is-busy` is not applied** during selection — the search and filter controls stay
   visible (D-13).
7. **Nonce freshness (#157)** — the datasource reads the nonce at request time, so a config
   whose nonce changed after construction is used with the NEW value, not the captured one;
   and a `403` carrying `rest_cookie_invalid_nonce` yields the stale-page message rather than
   an empty result or a generic error. Note the ordinary "green suite" trap here: the bug is
   only reachable when the nonce changes *after* construction, so any test that builds the
   config once and never mutates it will pass against the broken code.
8. Rig verification, not tests, for: spinner appearance, the `close:false` +
   `refresh_checkout:true` anchor re-placement under an open modal, and the restore-on-reopen
   sequence. Four sessions running, the rig has found what no test saw; treat a green suite
   as necessary and not sufficient.

---

## Related

- `docs-internal/archive/specs/2026-08-01-sp5-pickup-map-rework-design.md` — the map rework this
  extends (note: its §6 contains a wrong `margin.addArea()` shape; see the gotcha below)
- `docs-internal/archive/specs/2026-08-03-sp5-pickup-map-visual-rework-design.md` — the visual pass
  that produced the panels, card and CTA this design drives
- `docs-internal/gotchas/ymaps-margin-area-needs-explicit-width.md`
- `docs-internal/gotchas/css-hidden-attribute-needs-explicit-override.md` — relevant to the
  card lock overlay's own `hidden` gating
- `docs-internal/adr/010-yandex-maps-js-api-2-1-not-3-0.md`
- Issues: **#157** (stale nonce), **#166** (empty filtered sidebar), **#168** (sidebar as a
  floating card — separate operator decision, 2026-08-06)
