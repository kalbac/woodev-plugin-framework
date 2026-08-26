# A docblock asserted an API parameter that does not exist — and a capability was declared on it

**Namespace:** `[shipping/location]` · **Discovered:** s96 (2026-08-26), by the operator, from CDEK's
own documentation

## What happened

`Woodev_Test_Cdek_Location_Provider::resolve_region()` called:

```php
$rows = $this->request( '/location/regions', [ 'region_code' => $region_code ] );
$row  = $rows[0];
```

**`/v2/location/regions` has no `region_code` parameter.** Per CDEK's docs it accepts only
`country_codes`, `fias_region_guid` (deprecated), `size` (default **1000**), `page`, `lang`.
`region_code` exists on `/location/cities`, a different endpoint.

So the parameter was silently dropped, the call degenerated to an unfiltered first page of 1000
rows, and `$rows[0]` was whatever came first — Галисия (Spain, `region_code` 482) — for **every**
region key:

```
region_code=81 -> 1000 rows; rows[0] = {"country_code":"ES","region":"Галисия","region_code":482}
   the row actually asked for: ABSENT from the response
region_code=82 -> 1000 rows; rows[0] = same Галисия
   the row actually asked for: present, somewhere in the 1000
```

Two defects at once: the returned row was never checked against what was asked for, and one page is
not the whole dictionary.

## The part that made it durable

The docblock above `resolve_key()` stated it as fact, and **built a capability declaration on it**:

> DECLARES `CAPABILITY_RESOLVE_KEY` — both `/location/regions?region_code=` and
> `/location/cities?code=` are exact single-row lookups by CDEK's own dictionary identity […] so no
> scope/country hint is needed to resolve either half of this provider's own key namespace

The `/location/cities?code=` half is true. The regions half was **inferred by symmetry with its
neighbour** and written down as measured. That sentence then did the work a measurement should have
done: it closed the question for everyone who read the file afterwards, including three rounds of
critics on #488 and a coordinator.

The defect stayed invisible for another reason too: `resolve_key()` was introduced in #488 for
popular settlements, which are **always settlement-level**. No region key was ever passed until
#551 tried — and got Galicia.

## ✅ Correct

- **Verify a vendor parameter against the vendor's documentation before writing it into a
  docblock as fact.** The rule this repo already has — *reference-first, read before you write* —
  applies to the sentence describing the API just as much as to the call itself.
- **A working call in the same file is better evidence than an inference.** `regions()` in this very
  fixture already called it right, and had done all along:

  ```php
  $this->request( '/location/regions', [ 'country_codes' => $country, 'size' => 1000 ] )
  ```

  Fixed by making `resolve_region()` search that same per-country dictionary (transient-cached,
  bounded by the fixture's own country list) instead of inventing a filter.
- **Never take `$rows[0]` from a filtered call.** Match the row against what was requested. If the
  filter is honoured the check is free; if it is not, the check is the only thing standing between
  you and a confidently wrong answer.
- **Mark an inference AS an inference.** The codebase already does this elsewhere — e.g. #538's
  "⚠ INFERRED, not measured, and safe either way". That marking is what lets the next reader know
  where to look.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — why the unit suite could not see this
- [the-cdek-fixture-credentials-are-not-the-option-they-look-like](the-cdek-fixture-credentials-are-not-the-option-they-look-like.md) — the other place a plausible name misled a measurement
- [an-empty-domain-key-is-not-a-key](an-empty-domain-key-is-not-a-key.md) — the same discipline about what a provider's answer means
