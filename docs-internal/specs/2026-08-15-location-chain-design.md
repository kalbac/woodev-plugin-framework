# Location chain — design for #334 + #330

> Written s74 (15.08.2026), before implementation. Both cards share one root; this is the
> single mechanism that closes both. **The mechanism proposed in card #334 was measured and
> rejected** — see "Measurement" below.

## The root (both cards)

`Provider_Selection_Scope::current_locality()` answers the key of the customer's CURRENT
record, whatever level it sits at, and `/location/select` is posted for EVERY level of the
cascade including `address` (`location-cascade.js::onSelectFor`). So picking an address
changes "where I am":

- **#334** — the pickup selection is stored under the settlement key that was current at
  pick time, and recalled under the address key. It is not lost, it is unreachable.
- **#330** — `build_scope()` resolves `within` only against the exact current key, so a
  correct `within=<settlement key>` is silently ignored once the current record is
  address-level, and the address search goes country-wide.

Operator's decision (recorded in #334): **the pickup level must always stay at
settlement/city; refining the address inside a settlement must not affect the chosen point.**

## Measurement (rig, live DaData, 15.08.2026)

Card #334 proposed teaching the record to carry its ANCESTOR identity, and the s73 handoff
recorded `city_fias_id` of an address row = the settlement row's own `fias_id`, byte for byte
(true for Moscow). Measured further, that single-ancestor idea does **not** survive:

1. `fias_id` of a settlement-level row is the DEEPEST filled of
   (`settlement_fias_id`, `city_fias_id`) — five rows, including the both-filled case
   («Нижегородская обл, г Бор, деревня Жуковка» → `fias_id` = `settlement_fias_id`).
2. An address row routinely carries BOTH. Scoping an address search to
   `city_fias_id` = «г Пушкино» returned **9 of 10 rows with a nested
   `settlement_fias_id`** (Черкизово, Лесные Поляны, Тарасовка, …), all still
   `city_fias_id` = Пушкино.

So for an address row there are TWO ancestor candidates and **only the customer's own pick
disambiguates them**:

| customer picked | address row resolves into | `settlement_fias_id`-preferred | `city_fias_id`-preferred |
|---|---|---|---|
| «г Пушкино» (city) | нested Черкизово | ✗ loses the point | ✓ |
| «деревня Жуковка» (settlement) | Жуковка | ✓ | ✗ loses the point |

Neither preference is right. A derived single ancestor key cannot answer this; the layer has
to **remember what the customer picked**.

3. Abroad the ids are OSM-derived and CONSISTENT: «г Ташкент» settlement row `fias_id` =
   `relation:2216724`, and an address in Ташкент carries `city_fias_id` = `relation:2216724`.
   So ancestor identity works outside RU too — the handoff's worry (item 3) does not apply.

## The mechanism

**The layer stores the customer's CHAIN (level → record), not one record.** That is what the
client already keeps in `entry.records` while the page lives; the server persisting only one
record is the whole reason both bugs exist.

### 1. `Location_Record` — provider-published ancestor SET

`from_array()` accepts an optional `ancestors`: a flat list of locality keys, provider-owned,
opaque to the framework. Each is validated exactly like `key` (parses as a `Locality_Key`,
provider prefix equals `provider_id`); the record's own key and duplicates are dropped.
Absent → `[]`.

New API: `ancestors(): string[]` and `is_within( string $key ): bool`
(`$key === key() || in_array( $key, ancestors, true )`). Round-trips through `to_array()`.

A flat SET, not a `level => key` map — deliberately: the measurement above shows the level
mapping is exactly the ambiguity we cannot resolve. The set answers the only question the
framework asks: *"is this stored ancestor still an ancestor of the new record?"*

### 2. DaData publishes it

`record_from_dadata_fields()` fills `ancestors` from the non-empty of `region_fias_id`,
`area_fias_id`, `city_fias_id`, `settlement_fias_id`, `street_fias_id`, composed the same way
`key` is, minus the row's own `fias_id`.

### 3. `Customer_Location_Store` keeps the chain

Stored blob (same `STORAGE_KEY` on both sides — the layer is unreleased, no installed site
depends on the shape, and the OLD shape is still parsed):

```
[ 'records' => [ level => record::to_array() ], 'current' => level, 'implicit' => bool, 'saved_at' => int ]
```

`set( $record, $implicit )` rebuilds the chain:

- drop every level DEEPER than the new record's level;
- for every shallower stored record A: keep iff it is in the same country **and**
  `$record->is_within( A->key() )` — ancestry that cannot be PROVEN is dropped;
- write `records[ level ] = $record`, `current = level`.

`parse_stored()` accepts the legacy single-`record` blob as a one-entry chain, and refuses a
blob carrying TWO records at one level rather than letting serialization order decide which
one wins.

The implicit/explicit precedence rule (D11) is unchanged.

**Reversed after the adversarial review — the "no ancestors published" bypass is gone.** The
first draft kept a shallower record when the new one published no ancestors at all, reasoning
that "no information is not negative information". The critic produced the counter-example that
settles it: a Moscow settlement survives a Saint-Petersburg address in the same country, and
what survives is exactly what `current_locality()` answers — so the pickup point is filed under
a city the customer has left, silently. That is the failure this whole change is about, and the
layer's own discipline decides it: refusing beats a plausible wrong answer.

The cost is deliberate and one-sided. A provider that publishes no ancestors gets a one-entry
chain, so after a reload its customers lose parent SCOPING (country-wide search — visible,
self-correcting) and lose pickup PERSISTENCE (`current_locality()` answers `''` — degraded,
never wrong). Publishing `ancestors` is therefore a provider obligation, not an optional extra.

**The country check stays in front of it** — it is the one compatibility fact the framework owns
itself, and `Location_Scope::within()` takes the scope's country FROM THE PARENT, so a
cross-country survivor would silently move the customer's next search to another country.
`build_scope()` carries the mirror guard: a `within` naming a chain record from another country
is refused exactly like an unknown key.

### 4. `Location_Service`

`get_customer_record()` keeps its exact current contract (current record + `implicit` +
`saved_at`), including the lazy default-locality trigger. Added:
`get_customer_chain()` and `get_customer_record_at( string $level ): ?Location_Record`, both
routed through `get_customer_record()` first so the default trigger stays in ONE place.

### 5. #334 — `Provider_Selection_Scope::current_locality()`

Answers `get_customer_record_at( LEVEL_SETTLEMENT )`'s key, `''` otherwise. Stays `final`.

- settlement-level current record → its own key (unchanged behaviour);
- address-level → the settlement the customer actually picked (bug fixed);
- region-only, or an address typed with no settlement ever picked → `''`, the layer's own
  documented "refusing to answer" sentinel, which `Pickup_Selection` already refuses on both
  the write and the read side. **Never the current record's key** — that fallback IS the bug.

### 6. #330 server — `build_scope()`

Resolve `within` against ANY record in the chain, not the current key alone. The matched
record is a REAL record with its own `raw`, so `Location_Scope::within()` and DaData's
`build_locations_constraint()` are untouched and still get exact native ids.

### 7. #330 client — `prefill()`

The checkout config block gains `chain: { level: { key, level } }` alongside `current`
(`current` keeps its exact shape — byte-for-byte identical to `/select`'s own `current`).
`prefill()` seeds `entry.records[ level ]` for EVERY level in the chain; the
`woodev_location_applied` event still fires for `current` only. `/location/select` returns
the rebuilt `chain` too, and the client adopts it.

**Adoption is AUTHORITATIVE when the chain names at least one usable level** (adversarial
review): a level the server does NOT name is a level it dropped, so the client clears it.
Adding levels could never REMOVE one, and a stale client record is precisely what keeps
sending a `within` the server refuses — the seam this change exists to close. An EMPTY chain
is the exception and must stay one: a server with nothing to report (a guest whose session
never initialized, `persisted: false`) has repaired nothing, and wiping the client's own
in-session memory there would break the flow #324 was about.

### 8. #330's third point — the silence

`/location/suggest` answers `within_applied: bool`. No UI: the point is that a `within` that
did not resolve becomes visible to an HTTP probe instead of being indistinguishable from a
correct country-wide search.

## Deliberately OUT of scope (filed separately)

The pickup MAP is addressed by the current record too — `Pickup_Handler::current_location_record()`
and `location_config_block()` both read `get_customer_record()`, and `pickup-mount.js` adopts
whatever key `woodev_location_applied` carries. After an address pick that is the address
record, so the adapter resolves the carrier city from the DEEPEST settlement component
(«деревня Жуковка») instead of the one the customer picked («г Пушкино»). #330 already
flagged this as "стоит проверить отдельно".

It is NOT fixed here: neither card asks for it, and it is not a regression — it is today's
behaviour. The correct rule there is different from the storage key's, which is why it needs
its own card: the map must PREFER the settlement record but FALL BACK to the current record
(a customer who typed an address with no settlement pick must still see points), whereas the
storage key must refuse (`''`) rather than fall back, because a fallback key is what silently
mis-files a selection.

## Known, accepted degradation

A customer who types an address with NO settlement ever picked gets a one-entry chain, so
`current_locality()` answers `''` and the pickup selection is not remembered across reloads
(the map itself still works — it reads the record's components, not the key). Deriving a
settlement key here is exactly the ambiguity measured above. Filed separately.

## Related

- #334, #330, #324
- Gotcha `a-locality-display-name-is-not-an-identifier` — why names are not matched
- Gotcha `an-empty-domain-key-is-not-a-key` — why `''` is refused on both sides
