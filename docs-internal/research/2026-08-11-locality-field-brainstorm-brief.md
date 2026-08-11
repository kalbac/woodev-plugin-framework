# Brainstorm brief — locality / region / address fields and their data providers

> Written at the end of s66 (11.08.2026) to open the **next** session, which starts as a
> brainstorm: the operator describes an idea of his own about «Населённый пункт» / регион / адрес
> and the data providers behind them, and expects the agent to **confirm it, reject it, or add to
> it** — with evidence.
>
> **This document contains FACTS ONLY, deliberately no proposal.** Its job is to remove the need to
> re-derive the current state, not to pre-empt his idea with a design. Every claim below was
> verified against code or the reference plugins during s66; where a previously recorded claim
> turned out to need a nuance, that is called out.

---

## 1. Terminology to agree on FIRST

The three open cards use overlapping words for different things. Agreeing on these four before
building anything is cheaper than discovering the mismatch later:

| Term | What it means here | Where it lives today |
|---|---|---|
| **locality** | the customer's chosen settlement, as an OPAQUE string | `Selection_Scope::current_locality()` (plugin-supplied) |
| **locality identity** | a carrier's or registry's *identifier* for that settlement (`city_code`, `geo_id`, ФИАС guid, postal index) | nowhere in the framework — each plugin holds its own |
| **address** | the free-form street address the customer types | native WC `billing_address_1` / `shipping_address_1` |
| **normalization** | resolving a free-form address into structured components | `Address_Normalizer::normalize()` — interface exists, never called |

Note the asymmetry that causes most of the confusion: the framework treats locality as an opaque
**string**, while every carrier addresses its own API by an **identity**. Nothing in the framework
currently carries an identity.

---

## 2. What the framework does TODAY (verified in s66)

### Region — WooCommerce owns it natively

`Checkout_Handler::inject_states()` registers a takeover field's source options as WC states via
the **`woocommerce_states`** filter. WooCommerce then renders the `<select>` and persists the value
in its own session, so the region survives `update_checkout` with zero client DOM surgery. Two
constraints follow from that filter being keyed by COUNTRY, not by field: an empty source leaves
WC's own states in place (writing `[]` would HIDE the field), and only one state source per country
can win — a conflict is reported through `_doing_it_wrong()`.

### Locality (city) — a client select2, opaque string end to end

City is not a WooCommerce concept, so it stays a client-side select2 built by §8's suggest
takeover. The picker reads it live: `resolveLocality( config )` returns
`document.getElementById( <target>_city ).value` — a plain string, never cached. That string is
what the bulk points query is addressed by, and `Point_Query` refuses a request naming neither a
locality nor a bbox.

The persistence layer keys a remembered pickup point by `(locality, type)`, where **both halves are
supplied by the plugin** through `Selection_Scope` (`locality_for_point()`, `current_locality()`,
`type_for_method()`, `session_key()`). The framework never compares or normalizes those strings; its
only rule is that `''` means "the seam could not answer" and must be refused on read AND write
(gotcha `an-empty-domain-key-is-not-a-key`).

### Multi-plugin conflict — detected, not resolved

`Checkout_Handler::guard_native_field_conflicts()` records which plugin claimed a native WC field
id and fires `_doing_it_wrong()` on a collision. The behaviour that remains is **"last registration
wins"**, i.e. plugin load order decides who owns «Населённый пункт». This is #273's subject.

### Address normalization — THE SEAM IS ALREADY BUILT, AND HAS NO CALLER

This is the fact most likely to change the shape of the discussion:

- `Woodev\Framework\Shipping\Address\Address_Normalizer` — interface with exactly two methods,
  **`suggest()`** and **`normalize()`** (`woodev/shipping-method/address/interface-address-normalizer.php`).
  Its own docblock says implementations "wrap an address-data provider (such as DaData)".
- `Null_Address_Normalizer` — the no-op default, so the shipping module can always depend on one.
- `Shipping_Plugin::get_address_normalizer()` returns it, overridable per plugin.
- **Zero call sites.** Grepped for CALL sites, not identifiers: `get_address_normalizer(` appears
  only at its own definition, and `->suggest(` / `->normalize(` appear nowhere in `woodev/` or
  `tests/`. §8 does not consult it; the pickup layer does not consult it.
- The framework also **registers** the DaData client assets — `jquery-suggestions` (vendored
  `suggestions-jquery@22.6.0`) and `woodev-dadata-suggestions` — in `class-plugin.php:514-515`.
  Registered, never enqueued by the framework, and no reference plugin enqueues the framework's
  copy (Почта ships and registers its own).

So the *shape* #127 describes as its target ("`suggest(field, query)` / `normalize(address)` plus a
reusable client widget") is already on disk. What is missing is the wiring into §8 and a real
provider implementation. This is a fourth occurrence of the pattern in gotcha
`built-on-both-sides-with-no-caller-in-the-middle`.

---

## 3. What the three reference plugins actually do (verified in `plugins-reference/`)

| | Query key at the carrier boundary | Identities carried locally | Address suggestions |
|---|---|---|---|
| **СДЭК** (`woocommerce-edostavka`) | `city_code` (+ `type`) — its own dictionary; `get_deliverypoints` | `city_code`, `city_id`, `fias_guid`, and a separate `fias_region_guid` | — |
| **Яндекс.Доставка** | `geo_id` — its own dictionary, used exclusively | `geo_id` | — |
| **Почта РФ** (`woodev-russian-post`) | settlement **NAME** + region + district → `/postoffice/1.0/settlement.offices.codes` → postal codes | `fias`, postal `index` | ships `jquery.suggestions.js` (DaData) + `address-suggestions.js`, gated by `wc_russian_post_is_enable_address_suggestions()` |

**Nuance correcting a claim recorded in the s65 handoff.** That handoff records, from the operator,
that of the carriers only СДЭК and DaData work with ФИАС and that «ни Почта, ни Яндекс… не умеют
связать ФИАС с реальным адресом». In substance that holds at the **query** boundary — no carrier
here is addressed by ФИАС. But Почта РФ *does* carry a `fias` value locally: it is the guard on its
single chosen-point slot (`customer-delivery-point-data.php`), which is exactly why it loses the
point when the customer leaves a city and comes back. So ФИАС is present as an **identity** in two
of three plugins and is a **query key** in none. Worth separating those two roles explicitly in the
discussion, because they invite different designs.

Also relevant: the two plugins that keep customer location in their own store —
`WC_Edostavka_Customer_Location_Data` and Почта's `Customer_Delivery_Point_Data` — do so for **two
independent reasons**, both established in s65/s66:

1. WooCommerce has nowhere to put a carrier identity (`city_id`, `fias_guid`, …).
2. WooCommerce persists **no** address at all until every required TEXT address field in the block
   is filled, and that gate lives in the CLIENT (`checkout.js` → `maybe_update_checkout()`), not on
   the server — and sites running these plugins routinely disable the address fields a carrier does
   not need. Gotcha `wc-does-not-save-the-address-until-every-required-text-field-is-filled`.

Reason 2 means any design that persists location data on `updated_checkout` rests on an event that,
on those sites, may never arrive.

---

## 4. The three open cards, and how they overlap

- **#273 — «Стратегия и контракты слоя полей чекаута: как карьеры делят Населённый пункт»**
  (operator's card). Two installed plugins fight over the field; his current production answer is
  "СДЭК wins, the others adapt", which he himself calls a крутыль. Requirement: it must work for
  plugins not yet written — the nearest being OZON Логистика. Framework today only *detects* the
  conflict (§2 above).
- **#127 — «SP-4: DaData-сервис — адресные подсказки + нормализация»**. Decision from s41 was to
  defer until §8 existed, and to co-design the contract with §8. §8 now exists and is merged. Target
  described in the card: full address service — checkout autocomplete (region→city→address cascade,
  postal-code autofill, geo-IP city) plus server-side normalization/ФИАС, with DaData-specific
  endpoints and tokens living in the **plugin**, not the framework.
- **#159 — «Контракт запроса точек: карьер задаёт параметры, а не название населённого пункта»**.
  The points request is addressed by locality NAME today, which is ambiguous («Октябрьский» exists
  in several regions) and suits only Почта. Scope named in the card: `Point_Query`, the REST route,
  `Point_Source`, the trigger field, `pickup-mount.js`, `pickup-datasource.js` and their tests.
  Decision was: its own subproject, own brainstorm → spec → plan.

**These three are one problem seen from three sides:** who owns the field (#273), where the data
comes from (#127), and what identity travels to the carrier (#159). Whatever comes out of the
brainstorm should say something about all three, or say explicitly why one is separate.

---

## 5. Constraints any answer has to respect

- **Framework = mechanism + contract + hooks. Domain stays in the plugin.** Operator corrected this
  twice in s32. Tariffs, single-carrier logic and a specific registry are plugin concerns.
- **Installed-site data contracts are release-blocking**: option keys, session keys, meta keys, hook
  names. The session *key* is the plugin's; the *value's* shape inside it is internal and free to
  change (clean-break) — established while closing #143.
- **A shared external resource (an API key, a quota) is a REQUIRED plugin obligation plus a filter
  override, never an optional parameter the author can forget** (recorded operator preference).
- **Leave the extension hooks even with no consumer yet** — "no consumer" is not a YAGNI argument
  against a filter (recorded operator preference). Note the tension with §2's unwired seam: the
  lesson there is not "don't build seams", it is "a seam with no caller is not a working feature,
  and the docblock will claim otherwise".
- **Russia-only registries are a trap for the general contract.** ФИАС is RU-only; the framework
  must not assume it.

---

## 6. Open questions the facts do NOT answer

Left deliberately unanswered — these are the discussion, not the brief:

1. Should the neutral thing be the **shape** of a locality record (optional identities + a
   per-plugin resolver), or a chosen **registry**? The s65 working hypothesis was the former, and it
   was explicitly marked as needing measurement.
2. When two plugins are installed, does one **own** «Населённый пункт» and the others adapt, or do
   they share a neutral record each can resolve? #273 has the operator's own crutch as the current
   answer.
3. Does the address **provider** (DaData) belong behind `Address_Normalizer` as one implementation
   among several, or is it the thing that supplies the neutral identity all carriers resolve from?
4. Is the postal index a first-class identity (Почта effectively addresses by it) or a derived
   field?
5. What is the minimum a not-yet-written plugin (OZON) must implement to participate?

---

## Related

- Cards: #273, #127, #159; adjacent: #270 (rig fixture knows one city), #274 (trigger presentation).
- Gotchas: `an-empty-domain-key-is-not-a-key`,
  `wc-does-not-save-the-address-until-every-required-text-field-is-filled`,
  `guest-session-write-needs-the-cart-cookie`, `session-key-vs-order-meta-prefix`,
  `checkout-field-takeover-woocommerce-states`, `built-on-both-sides-with-no-caller-in-the-middle`,
  `a-programmatic-parent-change-must-not-run-a-destructive-cascade`.
- Program map: `specs/2026-06-25-shipping-module-decisions.md` (SP-3 field layer, SP-4 DaData).
