# gotcha: the checkout "required" rule is implemented TWICE — fixing the server half leaves the browser half blocking

**Namespace:** `[shipping/checkout]`
**Discovered:** s111 (2026-09-01), cards #708 and #721

## What happened

#708 fixed `Checkout_Handler::validate()` so the server stops enforcing `required` on a takeover
field that `inject()` never put on the form. Merged, gated, green. The operator then went to the
rig and found checkout still impossible:

> Кнопка «Place order» по прежнему не активна, даже если заполнены все поля выбран ПВЗ, и даже
> если метод доставки выбран дефолтный «Free shipping». Включил кнопку принудительно через JS,
> нажал, ошибок нет, заказ успешно создался.

The same rule lives in the browser as well, in `checkout-field-classic.js` → `refreshGate()`, and
it had not been touched.

## The observation that localises this class of defect in one step

**"I forced the control and the action succeeded cleanly."** That sentence says the SERVER accepted
the request, so whatever refused it was client-side — no further server debugging is warranted. It
is the fastest disambiguation available for any disabled-control report, and it is worth asking for
explicitly when someone reports a dead button.

## Root cause: two independent implementations of one rule

| half | where | what it did |
|---|---|---|
| server | `Checkout_Handler::validate()` | enforced `required` on every declared descriptor |
| client | `checkout-field-classic.js` → `refreshGate()` | disables `#place_order` when any field is `required` and empty |

`refreshGate()` asked `evaluateRequired()`, which reads `field.required` and nothing else. A
takeover field is owned by the CLIENT, so `inject()` deliberately never adds it, and WooCommerce's
own visibility settings can drop its native field as well. Measured live on the rig:

| field | `required` | takeover declared | `takeover.RU` | in DOM |
|---|---|---|---|---|
| `billing_company` | `true` | yes | **false** | **no** |
| `billing_address_2` | `true` | yes | **true** | **no** |

Both permanently empty and unfillable, so `blocked` latched `true` on the first recalculation and
nothing could clear it — not filling fields, not choosing a pickup point, not switching methods.

⚠ Note `billing_address_2`: takeover is **true** for RU and the field is STILL absent. So testing
ownership alone does not fix this — the same trap #708's own card recommendation fell into.

## ✅ The rule

**A required-field guard must ask whether the customer can actually act on the field, and it must
be asked on BOTH sides.** The client can answer presence directly:

```js
// checkout-field-classic.js → refreshGate()
if( entry.store.hasTakeover( fieldId ) && ! $( '#' + fieldId ).length ) {
    return
}
```

Scoped to takeover fields on purpose: every other field is injected by us and is present by
construction, so an absent one is OUR bug and should stay loud rather than silently un-block
checkout. `hasTakeover()` is deliberately not `takeoverFor()` — the key means "the client owns this
field's rendering", the per-country value means "…and it owns it here".

## Why it survived

**The gate had no test coverage at all.** Not one assertion anywhere on `#place_order`'s disabled
state, in a file with 1600+ passing JS tests. A green suite says nothing about a rule nobody
asserted. Four tests now pin it, one per corner: absent-and-required, present-and-empty,
rendered-takeover-and-empty, absent-but-optional.

## Related

- [whose-checkout-error-is-it-the-wording-tells-you](whose-checkout-error-is-it-the-wording-tells-you.md) — the server half's own diagnostic, and the #708 defect
- [a-registered-setting-without-a-control-never-renders](a-registered-setting-without-a-control-never-renders.md) — the same shape one layer up: declared is not rendered
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other "a green suite proved less than it looked like" lesson
