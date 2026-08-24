# Popular settlements — design

> Settled with the operator in a brainstorm on 24.08.2026 (s88). Closes the "where the popular
> list lives and how it is revalidated" open question of
> [2026-08-21-settlement-search-design.md](2026-08-21-settlement-search-design.md) (#437), which
> owns the surrounding settlement-search redesign. Read that spec first — this one only details
> the popular-list subsystem it names.

## What this is

The shop's ~20–30 most-shipped-to settlements, stored, ranked by order count. It gives the
settlement field something useful **before the customer types**, and orders matches once they do.

It is **ranking and empty state, not coverage** (#437 decision 4). The first customer from a new
town is not in it by definition; coverage is the provider's job. Cold start — a shop with no
orders — leaves the list empty and the field behaves as a plain search, which is what it does
today.

## D1. An entry is a whole `Location_Record`, not a key

**Decision:** store every field the provider returned, not just the locality key.

**Why:** the operator's criterion is behavioural — *picking from the popular list must be
indistinguishable from picking through search*. `Location_Record` is already the canonical object
that "travels whole everywhere" (#437 spec §4.2) and round-trips through
`from_array( to_array() )`. Storing it whole means the equivalence is not something this feature
implements — it is the same object flowing the same path, including the same `/select` call.

Required by `Location_Record::from_array()`: `key`, `provider_id`, `level`, `country`. Carried
with it: `region`, `district`, `settlement`, `street`, `house`, `block`, `flat`, `postcode`,
`lat`, `lon`, `label`, `meta`.

**Rejected:** storing name + region and re-resolving on every render. Nothing would go stale, but
every render costs a provider call and the pick stops being the same code path.

## D2. Two clocks, never one

**Decision:** the row carries `last_ordered_at` **and** `last_verified_at` as separate columns.

**Why:** they are driven by different events and behave in opposite directions.

| | `last_ordered_at` | `last_verified_at` |
|---|---|---|
| Bumped by | an order shipped to this settlement | a successful `resolve_key()` |
| Expiry means | the shop stopped shipping here | nobody has confirmed this record recently |
| Removes | dead weight | the risk of a dead key |

Collapsed into one column they fight: orders would indefinitely extend the life of a record nobody
had confirmed, so the dead-key protection would fail precisely on the **most-ordered** settlements
— where the cost of being wrong is highest.

**Eviction is not data loss.** The list is ranking, not coverage: an evicted settlement simply
stops appearing before the customer types, and search still finds it. Erring toward evicting early
is nearly free; erring toward keeping a dead key is not.

## D3. The table

Own surrogate `id` as the primary key — **not** the provider key.

| Column | Purpose |
|---|---|
| `id` | surrogate primary key (see D6: the provider key can change) |
| `provider_id` | which provider owns this entry |
| `country` | ISO-3166 alpha-2 |
| `record` | the whole `Location_Record`, serialized |
| `order_count` | ranking |
| `last_ordered_at` | usage clock (D2) |
| `last_verified_at` | freshness clock (D2) |
| `created_at` | audit |

`provider_id` is part of the row, not assumed: switching the active provider must not make another
provider's entries surface. Entries whose `provider_id` is not the active one are never offered.

## D4. A fifth provider capability: `resolve_key`

**Decision:** add `CAPABILITY_RESOLVE_KEY` and a contract method that takes a locality key and
returns the provider's current record for it, or `null` when the provider no longer recognises it.

**Why it has to be new:** the whole contract today is

```
suggest( query, scope )            mandatory
list_localities( scope )           CAPABILITY_LIST
locate( ip )                       CAPABILITY_LOCATE
normalize( free_form, scope )      CAPABILITY_NORMALIZE
```

**Not one of them accepts a key.** Without a by-key method the `last_verified_at` clock has nothing
to call. The workarounds are all worse: `suggest( name )` and hunting for our key in the results is
the exact failure #437 exists to fix (the right one may not be among the five); `list_localities`
is capability-gated and capped; `normalize` is optional, **throws** when absent, costs a paid call,
and — measured in #339 — can silently answer with a different address.

**A capability, not a mandatory method** (operator, 24.08.2026). A provider that cannot resolve by
key gets **no popular list at all**: nothing is stored for it and nothing is offered. This is the
same discipline `related-list` already follows behind `CAPABILITY_LIST` — absent capability means
the feature is simply absent, never a degraded imitation of it.

## D5. Verification is lazy, at the point of use — there is no validating cron

**Decision:**

| Trigger | What runs |
|---|---|
| Customer picks a popular entry whose `last_verified_at` is stale | `resolve_key()` for **that one entry**, inside the existing `/select` request |
| Merchant presses "Проверить актуальность" | a deliberate sweep of the whole table |
| Cron | **cleanup only — never `resolve_key()`** |

**Why lazy beats scheduled:** the operator's own observation is that a popular settlement is a big
city, and a big city's FIAS/ID effectively never changes; the settlements that DO change identity
are the ones ordered once every six months, and those age out through `last_ordered_at` on their
own. A periodic sweep therefore spends provider calls — paid ones, for DaData (measured in #339) —
mostly on rows nobody will pick.

Verifying at pick puts the single call exactly where a dead key would otherwise leak into an order
and into the carrier's API, and nowhere else.

**No extra round trip.** A popular-list pick already goes through the same `/select` endpoint as a
search pick (D1). The verification happens server-side inside that request.

## D6. What verification does with each answer

| Provider says | Action |
|---|---|
| alive, unchanged | bump `last_verified_at` |
| alive, **changed** | overwrite the stored record in place; **do not touch** `order_count` / `last_ordered_at` |
| gone (`null`) | delete the row, and handle the customer's pick per D7 |

A rename or a new postcode must land, because search would have returned the new record — the same
equivalence criterion as D1. Ranking must survive it: a settlement does not become less popular
because it was renamed.

**When the key itself changes**, the row keeps its identity and the key is updated. The provider
answered *our query for the old key*, so it is asserting continuity; discarding the order history
over a FIAS change would be wrong. This is why the primary key is a surrogate `id` (D3).

**Deliberately unbuilt:** no migration machinery, no merge logic, no history table for a key
change. Per the operator's practical read, a key change on a popular entry is close to
hypothetical — this is cheap insurance, and it should cost one `UPDATE`, not a subsystem.

## D7. What the customer sees when the entry is gone

**Decision:** on `null` from `resolve_key()` at pick time:

1. Run the ordinary search for the stored name, scoped to the stored region.
2. **Exact, unambiguous match on name AND region** → adopt it silently. The customer notices
   nothing; it is the same settlement with a new identity.
3. **Anything else** → cancel the pick and clear the field, showing **«Данные не актуальны,
   выберите заново»**.

Never substitute a different settlement on a near match: a locality display name is not an
identifier (gotcha `a-locality-display-name-is-not-an-identifier`, #339).

**The message is not optional.** The project default is to explain a blocked or changed control;
silence is reserved for cases where the customer sees the causality with their own eyes, and this
is not one — they click, the field empties, and nothing on screen says why. The address field then
re-locks on top of it, so a silent clear reads as two breakages in a row. The existing precedent is
the empty-suggestions case, which got «Поиск не дал результатов…» for exactly this reason.

## D8. Two merchant actions in the admin

- **«Проверить актуальность популярных городов»** — sweep the table through `resolve_key()`,
  applying D6 to each row.
- **«Очистить список популярных городов»** — drop every row for the active provider.

Neither needs a new control type. The settings framework already has an action-plus-status seam —
the connection test (`Woodev_Settings_Connection_Test`, route
`/woodev/v1/settings/{provider}/connection/{id}/test`). These follow it. *(How cleanly that seam
generalises is an implementation-time check, not an assumption.)*

## Numbers, deliberately not invented here

`last_ordered_at` TTL, `last_verified_at` TTL and the list cap (~20–30) are calibration, not
design. Set them generously and revisit against real data rather than guessing now. The operator
floated ~2 months for the usage clock as a starting point.

## What this design does NOT do

- It does not guarantee coverage (#437 decision 4).
- It does not store a settlement dictionary. The prohibition in #437 decision 3 stands: this table
  is ~20–30 rows with a real revalidation path, which is the entire reason it is allowed to exist.
- It does not run a validating cron (D5).
- It does not exist at all for a provider without `CAPABILITY_RESOLVE_KEY` (D4).

## Related

- [2026-08-21-settlement-search-design.md](2026-08-21-settlement-search-design.md) — #437, the
  surrounding redesign; this spec closes its first open question
- [../AGENT-RULES.md](../AGENT-RULES.md) — Rule 7a/7b/7c, the checkout address-field ownership rules
- Gotchas: `a-locality-display-name-is-not-an-identifier`,
  `a-level-served-can-come-from-the-fallback-not-the-active-provider`
