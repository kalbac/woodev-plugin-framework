# Gotcha: [shipping/pickup] — a restore tied to a server confirmation looks like a render artefact
> Tags: pickup, checkout, diagnosis, timing | Session: s77

## What happens

The customer changes locality and the applied pickup selection disappears, then reappears a few
seconds later. Watched with the eye, it lands *around* the moment WooCommerce finishes its
`update_checkout` round trip — so the obvious reading is "the server re-rendered the review
fragment and restored it".

That reading is wrong, and acting on it costs real work: it says the client-side restore is
redundant (delete it) and that the original defect had some other cause (chase it).

## Root cause

The restore is bound to `woodev_location_applied` — the cascade's own "this locality is persisted
and its identity is agreed" signal. That event can only fire AFTER the `/select` round trip, which
on a live provider takes 2.5–9 s (measured, DaData). WooCommerce's own `update_checkout` is
triggered by the very write the restore performs, so the two land close together and in an order
the eye cannot separate.

It cannot be made faster, either: before `/select` answers, the browser does not know WHICH
locality the customer landed in, and restoring against the old key would re-apply the point
belonging to the locality just left.

## Fix

Do not settle a "who did this" question by watching. Timestamp the events, and then prove it with
a control in the same pass.

❌ Wrong — inferring the actor from the order things appear on screen.

✅ Correct — stamp the ledger:

```js
document.body.addEventListener( 'woodev_location_applied', () => log( 'applied', field.value ) );
jQuery( document.body ).on( 'update_checkout', () => log( 'update_checkout', field.value ) );
```

```
t=189715  location_applied  key=dadata:0c5b2444…   field=""
t=189717  update_checkout                          field=019373a0…   <- +2 ms, before any request
```

✅ And then disable the suspected code and repeat the scenario. With the restore call removed the
field stayed empty for 15 s — which is what actually settles it, because a ledger only shows
order, while the control shows causation.

## Related

- [built-on-both-sides-with-no-caller-in-the-middle](built-on-both-sides-with-no-caller-in-the-middle.md) — the defect this diagnosis belongs to: server memory, client clearing, nothing in between
- [a-mutation-you-did-not-confirm-applied-proves-nothing](a-mutation-you-did-not-confirm-applied-proves-nothing.md) — the same discipline from the other end
- [a-probe-that-uses-the-production-accessor-creates-the-state-it-measures](a-probe-that-uses-the-production-accessor-creates-the-state-it-measures.md) — another way a measurement answers about itself
