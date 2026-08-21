# Settlement search — replacing the preset settlement list

> Design, 2026-08-21 (s84). Decided with the operator in conversation; nothing implemented yet.
> Supersedes the settlement half of `2026-08-18-shipping-settings-v2-design.md`.

## The problem, stated correctly

`GET /location/list` asks the provider for **everything in scope** and the client renders it. For
Московская область CDEK returns roughly **3000 settlements**. Measured in production (the CDEK
plugin's "Связанный список"): **3–5 seconds to render**, and the resulting `<select>` stays
palpably slow to interact with afterwards.

The current mitigation is `Location_Controller::LIST_HARD_CAP = 500` plus a `truncated` flag.
**Both halves are wrong:**

- 500 is a **blind prefix**. We never know which settlements fall off, so no value of N is
  defensible — the customer whose town is cut sees "my town is not in the database".
- #411 proposed surfacing the `truncated` flag. That makes the failure visible without making it
  stop happening.

The cap is not a number to tune. It is a **symptom of asking the wrong question**: the framework
should never request "everything".

### Why the mode exists at all

Merchants did not choose the preset list for the `<select>`, and not to avoid typing. They chose it
because it gave a **higher chance of finding the settlement** — CDEK's search is weak:

| CDEK method | Behaviour |
|---|---|
| exact-name lookup | `"Санкт"`, `"Питер"`, `"Санкт Петербург"` all return empty; only `"Санкт-Петербург"` matches |
| partial-name suggest | accepts partials, but returns **at most 5 results**, and takes only `name` + `country_code` — it cannot be scoped by region |

So the preset list was a **workaround for a bad search**, not a UI preference. Any design whose
fallback is that same search inherits the same complaint.

### The failure is narrower than it looks

Refined with the operator, from production experience: the 5-result cap is usually harmless,
because typing more characters narrows the candidate set until the target floats into the top 5
(`"Мос"` → Московский, Московье…; `"москва"` → г. Москва).

**Exactly one case genuinely breaks: a fully-typed name with more than five byte-identical
homonyms.** `"Октябрьский"` has 25+ settlements in Russia. There is nothing left to type, because
the discriminator is not in the name — it is the **region**.

## Decisions

### 1. The settlement axis is never a flat preset list

Removed as a mode. `list_localities()` for settlements and `LIST_HARD_CAP` are **deleted, not
tuned**. #411 closes by the truncation ceasing to exist, not by warning about it.

The stored option value for the removed mode **migrates to search**. Installed sites must see their
setting in place after the upgrade, not an empty field.

### 2. The provider contract for settlements becomes a search

Instead of "give me everything in scope", the framework asks **"find matches for this query within
this scope, at most N"**. The provider decides how — a regional dump filtered locally, suggestions,
or anything else. The framework never learns which.

Regions keep the "give me everything" shape: there are ~85 for Russia and they effectively never
change.

### 3. What the framework stores

| Data | Stored? | Why |
|---|---|---|
| Regions | **yes** | ~85 per country, effectively immutable |
| Settlement dictionaries | **never** | 60 000+ in Russia; they rename, change FIAS/ID, disappear, appear |
| The shop's popular settlements | **yes**, ~20–30 | small enough to keep honest |

The load-bearing argument is **not storage cost** — two or three regions would only be 3–9k rows.
It is that **the freshness obligation scales with what you store**. A renamed settlement or a
changed FIAS in a cached row is not a slow list; it is a **broken ID that already travelled into an
order and into the carrier's API**. Thirty rows can be revalidated on a schedule; thousands cannot.

Rule: **store only what has a real revalidation path.** That obligation applies to the popular list
too — it holds the same kind of IDs, and they go stale the same way. It is affordable there.

In practice a shop sells into 20–30 settlements, in its own and neighbouring regions.
Country-wide coverage is rare.

Note that a **transient cache is not storage** in this sense. It is disposable and self-healing,
nothing holds a long-lived reference to it, and it expires on its own. A provider is free to cache
whatever it likes internally.

### 4. The popular list is ranking and empty state, not coverage

Ranked by order count: the more often the shop ships to a settlement, the higher it sits. It gives
the field something useful **before the customer types**, and it orders matches once they do.

It deliberately does **not** guarantee coverage — the first customer from a new town is not in it by
definition. Coverage is the provider's job.

Cold start (a shop with no orders yet) is not a special case: the list is empty and the field
behaves as a plain search, which is what it already does today.

### 5. The framework's cascade has two stages

**Popular list → one provider call.** That is all the framework knows.

Any further laddering lives **inside the provider**. For CDEK the sensible internal shape is:

1. region-scoped corpus (its exact-match endpoint accepts `region_code`, and without `city` it
   returns the whole region — this is what the old preset list was already fetching), filtered
   server-side;
2. country-wide suggest for what the corpus cannot answer.

**The escalation trigger is precise, and it is not "zero results".** It is **"none of the ≤5
suggestions belongs to the chosen region"**. Filtering after the fact cannot recover the case:
CDEK picks its five before we see them, so the right "Октябрьский" may never be among them. Using
"few results" as the trigger would be worse than the original bug — two plausible-looking wrong
matches would suppress escalation and the search would *appear* to have worked.

None of this belongs in the framework.

### 6. Three provider capabilities

Declared at the **contract** level, never as raw API abilities:

| Capability | Enables |
|---|---|
| `list_regions` | the region field can be a preset select |
| `search_settlements_within( region )` | linked search |
| `search_settlements_countrywide()` | unlinked search |

Deliberately **no capability describes a bulk settlement list.** Putting one there would
re-introduce the exact question decision 2 removes, and would make the framework reason about
*how* a provider fetches data.

This corrects an earlier conclusion in the conversation. DaData was assumed to be incapable of
linked search because it offers no bulk endpoints. That was a faulty inference: linked search needs
**search within scope**, not bulk. DaData supports region-constrained suggestions ("гранулярные
подсказки"), so it declares `search_settlements_within` and **linked search works on DaData**. What
it cannot do is `list_regions`, so with DaData the region field is a search rather than a preset
select.

Two axes we had been conflating:

- `list_regions` gates **how the region field is rendered**;
- `search_settlements_within` gates **linkage itself**.

**Trap:** the scope handed to a provider must carry a locality record from **that same provider** —
a foreign region id cannot be used to constrain anything. This project has already been burned by
it: the mixed CDEK+DaData chain, #352 in s78.

### 7. "Связанный поиск" — redefined

The term came from CDEK and used to mean *preset region list + preset settlement list*. It keeps
the name and changes its meaning:

> **Связанный поиск = the settlement field REQUIRES the region.** Without a region it does not
> operate. The control type is irrelevant — `select` or `input`, either qualifies.

"Depends on" must be read as **"requires"**. Otherwise the term cannot distinguish linked mode from
unlinked mode in which a region happens to have been chosen — because there, narrowing the search by
the known region is simply free accuracy.

**Narrowing is therefore not a property of the mode. Do it always, in both modes.** The modes differ
by exactly one bit:

| Mode | Without a region |
|---|---|
| linked | the settlement field does not operate |
| unlinked | country-wide search; narrowed anyway if a region is present |

One search path, one flag.

### 8. Linked mode is derived, not configured

There is **no setting**. Linked mode switches on automatically when the capability is there: a
region field in the chain, and a provider that can narrow settlement search by region for the
selected country.

The reasoning is not "fewer options". It is an **asymmetry of visibility**. A merchant offered a
switch weighs a *visible* small cost (an extra checkout field, a conversion worry) against an
*invisible* large one (a customer who could not find their "Октябрьский" and silently left). People
optimise what they can see. This is the project's standing rule: an obligation the author can
silently forget must be **enforced, plus a filter override** — never an optional parameter.

The condition is stated carefully, because **presence is not precedence**. A region field existing
is about the address contract — the carrier needs a region regardless. Linkage is about input order
and search quality. Deriving linkage from "a region field exists" alone would conflate the two.

An override exists as a **filter**, not a setting. Anyone who genuinely must disable it does so in
code, deliberately. The filter ships even with no consumer.

### 9. The region is a correctness precondition, enforced on the server

Homonyms are unresolvable without a region — this is correctness, not convenience. So it holds
where every other rule in this layer holds: **the value is clamped on read, server-side.** Disabling
the control is presentation only, and `show_if` merely hides. Same pattern as #404 and #406.

## What this closes

- **#411** — the truncation disappears rather than being announced.
- **`LIST_HARD_CAP`** — deleted with the question that created it.
- **The consumer worry.** Nobody has to be told "we no longer support that mode". What merchants
  actually wanted from it — a higher chance of finding the settlement — gets *better*. The mode
  disappears; the capability improves.

## Open questions

- Where the popular list lives and how it is revalidated (schedule, batch size, what happens to an
  ID the provider no longer recognises).
- Whether `search_settlements_within` and `search_settlements_countrywide` are one contract method
  with an optional scope or two, given decision 7 collapses the difference to one bit.
- What the settlement field offers when a provider declares neither settlement-search capability.

## Related

- [2026-08-18-shipping-settings-v2-design.md](2026-08-18-shipping-settings-v2-design.md) — the settings surface this lands on
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the chain the region/settlement axes belong to
- `../adr/005-platform-v2-clean-break-policy.md` — why removing a mode is allowed, and why the stored option value is not
