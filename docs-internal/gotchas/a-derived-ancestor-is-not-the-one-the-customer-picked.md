# A derived ancestor is not the one the customer picked

> `[shipping/location]` — discovered s74 (15.08.2026) while designing the fix for #334/#330.
> Measured against live DaData on the rig, before any code was written.

## The shape

A child record (an ADDRESS) carries its ancestors' identifiers. It is therefore tempting to
answer *"which settlement is this address in?"* by reading one of them — that is exactly what
issue #334 proposed, and the s73 handoff had a measurement backing it: for «г Москва, Цветной
б-р, д 1» the address row's `city_fias_id` equals the settlement row's own `fias_id`, byte for
byte.

**The measurement was real and the conclusion was still wrong.** It generalised from a city
that happens to have no nested settlements.

## The measurement that killed it

DaData fills BOTH `city_fias_id` and `settlement_fias_id` on the same address row whenever the
address sits in a settlement nested inside a city. And a settlement-level row's own `fias_id`
is the DEEPEST filled of the two — verified on five rows, including
«Нижегородская обл, г Бор, деревня Жуковка» (`fias_id` = `settlement_fias_id`).

So an address row has TWO ancestor candidates at the same conceptual level, and picking either
one breaks the other case:

| customer picked | address resolves into | prefer `settlement_fias_id` | prefer `city_fias_id` |
|---|---|---|---|
| «г Пушкино» (city) | нested Черкизово | ✗ | ✓ |
| «деревня Жуковка» (settlement) | Жуковка | ✓ | ✗ |

Not a rare branch: an address search scoped to `city_fias_id` = «г Пушкино» returned **9 of 10
rows with a nested `settlement_fias_id`** (Черкизово, Лесные Поляны, Тарасовка, Любимовка,
Ашукино…), every one of them still `city_fias_id` = Пушкино.

## The rule

**Ancestry is a SET, not a path. Which member of it is "the customer's locality" is not in the
data — it is in what the customer picked.** So:

- ❌ derive the parent key from the child record (any single-field preference, in either
  direction, is wrong for half the real rows);
- ❌ fall back to the child's own key when the parent is unknown — that fallback IS #334: the
  pickup selection is written under one key and read under another, and the customer sees
  «Выберите ПВЗ» over a point they already chose;
- ✅ REMEMBER the pick (store the chain: level → the record the customer actually selected) and
  use the published ancestor set only to VALIDATE it — *"is my remembered settlement still an
  ancestor of this new address?"*, which the set answers exactly and unambiguously in both
  directions of the table above;
- ✅ when there is nothing remembered, answer `''` — the layer's own "refusing to answer"
  sentinel (gotcha `an-empty-domain-key-is-not-a-key`) — never a derived guess.

Corollary for the provider contract: publish ancestry as an opaque flat SET
(`Location_Record::ancestors()` / `is_within()`), never as a `level => key` map. A map forces
the provider to make exactly the choice the data cannot support, and it forces it silently.

## Why this was nearly missed

The first measurement (Moscow) confirmed the hypothesis perfectly. The second measurement
existed only because the question asked was *"where does this rule break?"* rather than
*"does this rule hold?"* — one query against a city with nested settlements, and the whole
design changed. A single confirming example of an identity rule proves nothing about a
hierarchy; probe a node with siblings at two levels before trusting one.

## Related

- [[a-locality-display-name-is-not-an-identifier]] — why the name cannot be matched instead
- [[an-empty-domain-key-is-not-a-key]] — the sentinel this rule degrades to
- [[session-key-vs-order-meta-prefix]] — the `(locality, type)` map this key feeds
- `docs-internal/specs/2026-08-15-location-chain-design.md` — the design it produced (#334, #330)
