# Brainstorm input — V2 location layer, field behaviour and the settings surface

> **Written 18.08.2026 (s78) as INPUT to a brainstorm, not as a design.** It exists so the
> brainstorm starts from what is already built, measured and decided, instead of re-deriving it.
> Nothing here is a proposal except where a section says so explicitly.
>
> **Audience:** whoever runs the brainstorm with the operator. Read §1 before anything else.

---

## 1. How to read this document

Three kinds of statement appear below, and they carry different weight:

| Marker | Meaning |
|---|---|
| **BUILT** | Exists in the code today. A file/symbol is named so you can check rather than trust. |
| **DECIDED** | The operator has ruled. Not open for re-litigation unless he reopens it. |
| **OPEN** | Genuinely undecided. This is what the brainstorm is for. |

**One section is an ANTI-REFERENCE.** §3 describes how the operator's existing CDEK plugin behaves
today. He was explicit (18.08.2026):

> «Только не нужно чтобы Fable брал его как референс, потому что это плохой пример реализации.
> Можно изучить, просто для общего понимания.»

Study it to understand the problem domain and the shop-owner's mental model. **Do not replicate its
shape.** This is a deliberate exception to the standing rule that an operator-supplied reference is
the literal target — he revoked that rule for this particular code.

---

## 2. The layer in one paragraph — BUILT

A WooCommerce checkout "location layer" serves typeahead suggestions for three cascading levels,
`region > settlement > address`. Levels are served by pluggable **providers**. For each
(level, country) a chain walk resolves exactly one provider: the provider selected in settings if
it is configured and declares that level for that country, otherwise the bundled DaData provider if
it does, otherwise nobody — and then that field stays a plain native input.
(`Location_Service::provider_for_level()`, `class-location-service.php`.)

The customer's picks are persisted as a **chain** (`level => Location_Record`). A record's `key` is
`provider_id:native_id`. Two consumers depend on the chain: the next `/suggest` is scoped `within`
the shallower record, and the pickup layer files the chosen point under
`Provider_Selection_Scope::current_locality()`, which reads **strictly** the settlement record and
returns `''` when there is none.

---

## 3. ANTI-REFERENCE — how the operator's CDEK plugin behaves today

> Operator's own description, 18.08.2026. **Reproduced for domain understanding only.** His verdict
> on it: «в целом, всё это работает, но очень криво-косо, поэтому мы сейчас строим механизм который
> должен работать стабильно.»

CDEK is itself a location provider there, but only for **settlement and region**. DaData can be
connected additionally. The behaviour is driven by an option **«Выпадающий список городов»**, on by
default, with three values:

**(a) On.** The settlement field becomes a select2; suggestions cover the whole country (all
cities). DaData's own "suggestions for the settlement field" option becomes **unavailable**, and the
region field degrades to a plain text input with no suggestions — auto-filled from the chosen
settlement, influencing nothing except what is written into the order.

**(b) «Связанный поиск».** The region field becomes a select2 with a preset region list. Until a
region is chosen the settlement field is **blocked**. Once chosen, settlement unblocks and offers
only settlements within that region. With this value, DaData suggestions can be attached to the
**address** field; its search works by concatenating `query = region name + city + address`, with no
real dependency of the address field on region or settlement.

**(c) «Не использовать».** Only here does DaData unlock all three fields — region, settlement,
address. Each is opt-in independently: settlement+address alone, or region+settlement alone, are
both valid configurations.

When DaData attaches to a field it first checks whether the field **exists in the DOM** at all, and
whether it `is_visible`; if not, no binding happens.

**What is worth carrying forward from this (understanding, not shape):**
- The shop owner thinks in terms of *field behaviour* first and *data source* second.
- "Region gates settlement" is a mode the operator's customers already know.
- Attaching to a field that may be absent or hidden is a real, routine case — not an edge case.

---

## 4. DECIDED — settled, do not reopen without the operator

| # | Decision | Reason he gave |
|---|---|---|
| D1 | **The settlement level is the minimum a provider must serve**, per country. A provider that only offers regions is not registered. | «Если провайдер даёт только регионы, то нам эти регионы ничего не дают, мы вообще можем без регионов работать.» The region level is optional for the layer; the settlement level is not — the pickup point's filing key hangs off it. Tracked as **#353**; the check does not exist in code yet. |
| D2 | **The settlement field always exists.** It is the base minimum of the checkout form. | Same root as D1. |
| D3 | **"Основной" (DaData) is about ORIGIN, not arbitration.** DaData is bundled into the framework and therefore always reachable as a fallback. The selected provider still wins any level it serves. | Explicit correction, 18.08.2026. The naming differs from the code's ("active" / "bundled fallback"); the behaviour is identical to what is built. |
| D4 | **A foreign-provider address record never enters the chain** — it lives only as field TEXT. | Issue #352, variant A. Shipped, `main` `ad07896`. |
| D5 | **A settlement typed without picking a suggestion** adopts the first suggestion when the list returned ≥1 result; when it returned none, the typed text stays and the address field is NOT locked. | Issue #350. Shipped, `main` `841e249`. |
| D6 | **The customer's field TEXT is never cleared**, only identities. Region is never cleared by an unknown settlement — it was chosen earlier and separately. | #350. «Московская область» + a village absent from the registry is a valid combination. |
| D7 | **A stale customer record is treated as ABSENT**, not re-resolved. Plus the silent scope fallback must become a visible signal. | #346/#333, decided 17.08.2026. In flight at the time of writing. |
| D8 | **These field options belong to the FRAMEWORK, not to carrier plugins.** | «Чтобы все карьеры вели себя одинаково предсказуемо, а не как сейчас, каждый плагин приносит какое-то своё правило и карьеры начинают конкурировать за опцию.» |
| D9 | **Sections adapt to what the plugin declares.** A plugin with no pickup-type methods needs no map, so it gets no map section. | Existing seam: `supported_features` in the loader definition, read by the bootstrap. |
| D10 | **Fixed locality is a SEED for a customer who has not entered a settlement yet** — not a shop-wide policy and not per-carrier. | A shop that hides the settlement field and still ships to other cities is contradicting itself; that is the shop owner's problem, not the framework's. |
| D11 | **A blocked control is EXPLAINED by default.** An admin option that cannot reach the current checkout renders `disabled` **with a description** saying why. Detection: `Blocks_Handler::is_checkout_block_in_use()`. | Operator, 18.08.2026, correcting a misreading of his own earlier words: *«это не строгое правило проекта. Частный случай для конкретного кейса… Я бы вообще назвал это исключением. Там где подсказки нужны, мы их показываем.»* **«Заблокирована и всё» is an EXCEPTION, not a project rule** — it applies only where the user just performed the action that disabled the control and can see the causality (the pickup-type filter checkboxes, #243). Do not cite it as a general principle; that misreading has already been rejected on the rig once. This resolves what was OPEN question 5. |

---

## 5. OPERATOR'S WANTED SHAPE — a proposal, not a decision

His words, 18.08.2026, lightly structured. Treat as the starting point to challenge, not as a spec.

### 5.1 Provider selection
- When **DaData** is selected it should behave as it does now, but the script must tolerate the
  **region field being absent**. In that case the scope for settlement and address, while no
  settlement is chosen, is the country.
- If the **settlement field** is absent — «мы вам ничего и не можем предложить магазину». It must
  always be there (D2).
- The shop may choose whether address suggestions are used at all.
- When a provider that serves no addresses (e.g. CDEK) is selected, the **"address suggestions"
  option becomes available only if DaData credentials are present.**
- **"Fixed locality"** pulls its cities from whichever provider is selected. Country scope follows
  «WooCommerce store setting → `RU`».

### 5.2 Settings surface — «Настройки доставки» with sections
| Section | Contents he named |
|---|---|
| Локация | choose and configure providers |
| Настройка полей | hide the address field for pickup-type methods; settlement/region field TYPE — text-with-suggestions or select2; disable the country field (CSS-hide when the shop ships to one country); disable the region field (remove from DOM); disable the postcode field (remove entirely, or `hidden` for pickup delivery); **field-order preset**, on by default |
| Настройка карты | everything map-related — where the button goes, whether to write the point's address into the address field, whether to close the map on selection |

### 5.3 The field-order preset
WooCommerce's default order is `Страна > Адрес > Доп.адрес > Город > Регион > Индекс`. The RU/CIS
convention is `Страна > Регион > Город > Адрес > Индекс`. **On by default.**

Operator's chosen mechanism: the `woocommerce_get_country_locale` filter — per country rather than
one global rule. He flagged it as debatable.

---

## 6. Constraints and traps — measured or verified, record these before designing

**T1 — "Remove" and "hide" are two different mechanisms and must not be conflated.**

```
remove from DOM   region, postcode ("отключить совсем")
                  -> value never reaches the order, deliberately

CSS-hide          country (single-country shop), settlement (fixed locality)
                  -> field stays in the DOM and filled; the value DOES reach the order
```

The operator's own solution for the "distance-based" plugin rests on the second: the settlement
field stays mandatory, the shop fixes the city, the field is hidden, and the address is thereby
always scoped to that one city. Removing it from the DOM instead would send orders with no city.

**T2 — Removal is done in PHP, not JS**, via `woocommerce_checkout_fields` /
`woocommerce_default_address_fields`. `unset()` on a field makes it non-required automatically —
WooCommerce simply forgets it. A field that is *hidden but present* needs `required = false` set
explicitly through the same filters. (Operator, 18.08.2026.)

Note the two filters differ: `woocommerce_default_address_fields` is the base template applied to
both billing and shipping; `woocommerce_checkout_fields` is the assembled array, where a field must
be unset in **both** sections. The framework currently hooks only the latter
(`class-checkout-handler.php:191`), so new field options extend an existing seam.

**T3 — The settlement field is always `required` at framework level.** A carrier may override that
for its own case (the "distance-based" plugin); the framework is not responsible for the override.
Residual risk to state plainly: a hidden, required settlement field whose fixed-locality value fails
to write leaves the customer facing a validation error on a field they cannot see.

**T4 — Read the FINAL result of a WooCommerce filter, never your own contribution to it.** The
country locale can put `required` back after you removed it. The layer already learned this on the
region level: the issue-#294 arbitration reads the final `woocommerce_states` after every filter has
run, not its own opinion (`class-checkout-config.php`, `build_location_block()`).

**T5 — The field-order preset may reach the CLASSIC checkout only. VERIFY, do not assume.**

What is certain: this layer does not live on the block checkout at all today — the rig's
`/checkout/` is the block checkout, `form.checkout` does not exist there, and the block adapter
(SP-11) is unbuilt (gotcha `rig-checkout-url-is-the-block-checkout`).

What is NOT established: whether the block checkout genuinely refuses field-order customisation.
The operator asked for this to be checked rather than asserted — WooCommerce Blocks has gained
field APIs over time. **Establish it before designing around it.**

Either way the framework already owns the right instrument. `Blocks_Handler::is_checkout_block_in_use()`
(`woodev/handlers/blocks-handler.php:77`, backed by
`Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default()`) answers
whether the SITE currently serves the block checkout. Operator's own rule for options that cannot
reach it: render them **`disabled` with a description saying the option is unavailable on the block
checkout** — see D11.

**T6 — Attaching to an absent or invisible field is routine, not an edge case.** The current CDEK
plugin already checks DOM presence and visibility before binding (§3). Whatever V2 does must handle
it as a normal path.

---

## 7. Measured behaviour from s78 — use instead of assumptions

All measured on the rig with a control in the same run.

**M1 — `within` is resolved through the customer's stored chain, and a key that is not in it is
silently dropped.** Country-wide search, no signal:

| `within` | results |
|---|---|
| `nosuchprovider:zzzz` | 10 |
| an invented key not in the chain | 10 |
| absent (CONTROL) | 10 |

**M2 — A foreign-provider key DOES scope, by accident of the handover shape.** With DaData active
and a CDEK settlement in the chain, `within=test-cdek:44` gave 8 results against 10 for the control,
with shortened labels. `Dadata_Provider::build_locations_constraint()` sees the foreign
`provider_id`, refuses to use its `raw` payload, and **silently degrades to a text constraint** on
the region/city names.

**M3 — `within_applied` cannot detect any of this.** It is computed from `$scope->has_parent()`,
i.e. from what the scope BUILDER decided, not from what the provider honoured. Gotcha
`within-applied-reports-the-scope-builder-not-the-provider`.

**M4 — There is no server-side forget path at all.** Not a method, not a route. The #346 card's
claim that `Location_Provider_Registry::reset_for_tests()` is "the only reset" is wrong — it removes
hooks and nulls a singleton and never touches the store.

**M5 — Measure a lock only where the lock can fire.** The #346 claim "a stale record unlocks the
address field" reads as confirmed on `AM`, where the layer serves no address level at all and the
early return wins regardless. `BY` is a country that can actually test it. Gotcha
`measure-a-gate-where-the-gate-can-actually-fire`.

---

## 8. OPEN — what the brainstorm is actually for

1. **The settings information architecture.** Operator's §5.2 split is a proposal. Where does
   "address suggestions on/off" live — under Локация (it is a provider capability) or under
   Настройка полей (it is field behaviour)? The same question repeats for the settlement/region
   field TYPE.
2. **Field type as a mode — LIKELY SETTLED, confirm and pick the vocabulary.** The layer already
   has a `mode` (`typeahead` / `related-list` / `ajax-select2`). §5.2's "field type" and §3's
   «Выпадающий список городов» are almost certainly the same axis under three names. The operator,
   18.08.2026: «не могу утверждать на 100%, но по-моему ты всё верно определил. Речь о `mode` со
   значениями typeahead / related-list / ajax-select2, а не о новом механизме.» So the remaining
   work is choosing ONE vocabulary and mapping his three CDEK values onto it — not inventing a
   mechanism. Getting this wrong ships two settings that steer the same thing.
3. **"Region gates settlement"** (§3b) is a real mode shop owners already use. Is it in scope for
   V2, and if so is it a *mode* or a consequence of the region field's type?
4. **How a plugin declares what it needs**, so sections can adapt (D9). `supported_features` exists;
   is per-section granularity the right resolution, or too coarse?
5. ~~Option availability vs option visibility.~~ **ANSWERED — see D11.** A blocked control is
   explained by default; the "say nothing" case is a narrow exception. What remains is only whether
   the same treatment applies to the "address suggestions" option when the selected provider serves
   no addresses and DaData has no credentials — presumably yes, by the same logic, but the operator
   has not said so in those words.

   **Cleanup this creates:** `refreshAddressLock()` in `location-cascade.js` (#337) currently
   justifies its silence by calling this "the same standing operator rule". That framing is wrong
   by D11 and should be corrected to cite the narrow exception instead — the LOCK itself stays
   unexplained, but for the case-specific reason, not a project-wide one.
6. **The field-order preset's reach** (T5) — classic-only, or a reason to pull SP-11 forward.
7. **Whether the postcode option's two values are one option.** "Remove entirely" and "hidden for
   pickup delivery" are different mechanisms (T1) sharing one control.

---

## Related

- `docs-internal/specs/2026-06-25-shipping-module-decisions.md` — the active program map
- `docs-internal/GOTCHAS.md` — `[shipping/location]`, `[shipping/checkout]`, `[shipping/pickup]`
- Issues: #353 (settlement minimum), #352, #350, #346, #333, #337, #334
