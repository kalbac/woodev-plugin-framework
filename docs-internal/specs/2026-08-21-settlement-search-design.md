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

> **Amended 24.08.2026 (operator): no migration will be written.** Two reasons, and the second is
> the load-bearing one.
>
> 1. There are no live consumers of the framework yet — the operator is its only user.
> 2. **The failure this clause guards against cannot happen anyway.**
>    `Location_Provider_Registry::get_field_mode_settlement()` already clamps on READ:
>    `in_array( $stored, $offered, true ) ? $stored : self::MODE_TYPEAHEAD`. A stored value that is
>    no longer offered falls back to a valid mode — it never renders as an empty field. The
>    "migration" would only be choosing WHICH valid mode it lands on.
>
> So what remains is a one-line taste question inside that clamp, to be settled when #437 is
> actually written: a stored `related-list` on the settlement axis currently lands on
> `typeahead` (plain text with suggestions), and the argument for landing it on `ajax-select2`
> instead is that a preset list was a dropdown and search is its successor, so the shopper's UI
> changes less. **No upgrade routine, no data touched, no migration code.**

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

### 8. Linked mode is a checkbox, defaulting to on

**Decision revised** later the same day. The first version had no setting at all — linked mode
derived purely from capability, overridable only by filter. The operator overruled it, and the
reason is one this design had not priced: **support load.** A filter-only override turns every
merchant who wants "let them type the settlement without a region" into a ticket whose answer is
"write code" — which for most of them means hiring someone.

The shape:

- a single checkbox, **"включить гранулярность"**, global rather than per-country;
- **default ON** whenever the capability is there: a region field in the chain, and a provider that
  can narrow settlement search by region for the selected country;
- **off and inactive** when the region field cannot be relied on — the `Регион` option set to
  `Удалять`, or the field removed by other code.

The original objection still stands and is worth stating rather than burying: a switch lets a
merchant trade an *invisible* large cost (a customer who could not find their settlement and left)
for a *visible* small one (a field in the checkout). **Default-ON absorbs most of it** — the safe
state is free, and turning it off becomes a deliberate act rather than an unset checkbox.

Two things the checkbox needs to be safe.

**The inactive state must explain itself.** A greyed-out checkbox with no reason is exactly the
complaint in #412 — "выглядит как оно не работает". The merchant sees the prohibition and not
what lifts it, so the reason has to be on screen.

**Detection is for the UI. Self-release is what carries correctness.** The operator pushed back on
the first version of this, which said detection was unreliable in general — and he was partly
right, so here is what was actually checked.

Reading the merged field config IS possible, and on the classic checkout it is honest: our own
filter runs at `LATE`, "after everyone who has an opinion", so WooCommerce's defaults and any
third-party field manager have already had their say. The API is
`WC()->checkout()->get_checkout_fields( 'billing' )` — used in four of our own production plugins.
(Not `get_field( 'billing', 'state' )`, which does not exist; and `class` is an array, not a
string.)

What it does not cover is the reason detection cannot be load-bearing. **There are two
instruments, and that API sees only one:**

| Instrument | Read by |
|---|---|
| `woocommerce_checkout_fields` | the **classic** checkout only, by construction |
| `woocommerce_get_country_locale` | the **block** checkout — `CheckoutFields::get_core_fields()` hard-codes the core address fields (gotcha `block-checkout-reads-country-locale-not-checkout-fields`) |

So on the block checkout a third party can remove the region through the locale instrument and
`get_checkout_fields()` will not show it. A second risk worth measuring: the settings screen renders
in wp-admin, while some third-party hooks register on the front end only — an admin-time read could
then differ from what the shopper gets.

Keep one more distinction in mind: `hidden` in the locale sense means *not rendered and not
required* — **hidden is not removed**. In this layer hiding is never done by unsetting (T1/T2).

`Регион = Удалять` is our own option and reads deterministically either way.

So the dependency is inverted: **granularity self-releases on read when the region is not actually
in the chain.** Undetected third-party removal then degrades to country-wide search rather than to
a dead field the shopper cannot escape — the same self-releasing pattern already agreed in #406.
Detection is then only needed to grey the checkbox nicely in the admin, and its imperfection stops
being dangerous.

This is also why the checkbox must be labelled as an **intention, not a state**. "Включить
гранулярность" is never false. "Гранулярность активна" would be a lie in exactly the case detection
misses — the box looks on while the runtime has released it.

### 8a. What the control says in each state

Three states, not two, because the cause of unavailability changes the remedy. Structure:
**state, cause, what to do.** Wording is the operator's to set; this is the shape.

| State | Draft copy |
|---|---|
| Unavailable — our own setting removed the region | «Недоступно: поле «Регион» удалено из чекаута настройкой выше. Верните его, чтобы включить.» |
| Unavailable — other code removed the region | «Недоступно: поле «Регион» отсутствует в чекауте — его убирает тема или другой плагин.» |
| Available, switched off | «Ограничивает поиск населённого пункта выбранным регионом. Без этого покупатель может не найти свой пункт среди тёзок — «Октябрьский» встречается в России более 25 раз.» |

The third one names the failure it prevents rather than the mechanism it performs. A merchant
reading "narrows the search to the region" learns what it does; they do not learn why they would
want it.

**No admin notice when granularity self-releases at runtime** (operator decision). The precondition
is observable in the product itself — the merchant opens the checkout and sees whether a region
field is there. A notice would be noise for a case a human can check by looking, and nothing is
broken when it happens: the search degrades to country-wide, it does not lock anyone out.

That also settles global-versus-per-country: **global**, because in a country without the
capability it self-releases anyway.

The checkbox is a **new stored option**, so existing installs need a defined default. Default-ON
means they gain the behaviour on upgrade, which is intended — but it is a deliberate choice, not a
side effect.

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

## ⚠ Status, 26.08.2026 — this spec needs a scope conversation before any of it is built

The operator kept #437 open but flagged it for **detailed discussion**, and a read of the spec
against the code says why: the thing it is TITLED for — replacing the preset settlement list —
already happened by another route (#486 clamped `related-list:settlement` after the `LIST_HARD_CAP`
= 500 measurement; the dead code under it is #529; the settlement axis is `ajax-select2` today and
already narrows by region). Decision 6's capability model and decision 8's «связанный поиск»
checkbox **do not exist in code at all**.

What still looks live: decisions 7/8 (must the settlement field REQUIRE a region, and does that need
a checkbox) and decision 9 (region as a server-side correctness precondition).

The operator's own worry — "don't load the whole settlement list into the DOM, resolve server-side,
return only the match" — was raised and then answered by him in the same message: it is the
PROVIDER's responsibility, and it is already the shipped contract. Nothing was missed. Detail on the
card, #437.

## Open questions

- ~~Where the popular list lives and how it is revalidated (schedule, batch size, what happens to
  an ID the provider no longer recognises).~~ **CLOSED 24.08.2026** — settled with the operator and
  written up separately: [2026-08-24-popular-settlements-design.md](2026-08-24-popular-settlements-design.md).
  Short version: a stored table of whole `Location_Record`s with TWO clocks
  (`last_ordered_at` / `last_verified_at`), a new `CAPABILITY_RESOLVE_KEY`, verification LAZY at
  the moment of pick rather than on a schedule, and cron reduced to deleting expired rows without
  ever calling a provider.
- Whether `search_settlements_within` and `search_settlements_countrywide` are one contract method
  with an optional scope or two, given decision 7 collapses the difference to one bit.
  **Not an operator decision** (26.08.2026) — and the shipped code already answers it: ONE method,
  `suggest( string $query, Location_Scope $scope )`, with the scope carrying `within`. Measured on
  the rig: `/location/suggest?q=Пушк&level=settlement&country=RU&within=test-cdek:r82`.
- ~~What the settlement field offers when a provider declares neither settlement-search
  capability.~~ **CLOSED 26.08.2026 (operator): the case cannot arise.** Settlement search is a
  PRECONDITION of registering a provider at all, not a capability — confirmed in code, `suggest()`
  is declared on `interface-location-provider.php:335` itself, so a provider that cannot search
  cannot implement the contract. DaData always offers it. If the case somehow appeared, the floor
  is a plain text field with free input. ⚠ Note the capability model this question belonged to
  (decision 6) **does not exist in code** — today's four are `list`, `locate`, `normalize`,
  `resolve_key`.
- ~~**Is `WC()->checkout` reachable in wp-admin at all?**~~ **MEASURED 24.08.2026: it is.** Under
  `WP_ADMIN` with `set_current_screen()`, `WC()->session` and `WC()->cart` both instantiate,
  `WC()->checkout()` returns a `WC_Checkout`, and `get_checkout_fields( 'billing' )` returns the
  full 9-field set including this layer's own. Caveat: measured through wp-cli with `WP_ADMIN`
  defined, not a real admin HTTP request. So "does the read work" is answered; the SECOND question
  below — whether the merged field config differs there — is not, and still needs its own
  measurement. `WC()->checkout()` instantiates lazily and the checkout object leans on
  session and cart state that an admin request may not have. Measure that first; only if it IS
  reachable does the second question arise — whether the merged field config differs there, given
  that some third-party hooks register on the front end only. Neither affects correctness
  (self-release does), only whether the checkbox can be greyed out with a truthful reason.

## Related

- [2026-08-18-shipping-settings-v2-design.md](2026-08-18-shipping-settings-v2-design.md) — the settings surface this lands on
- [2026-08-15-location-chain-design.md](2026-08-15-location-chain-design.md) — the chain the region/settlement axes belong to
- `../adr/005-platform-v2-clean-break-policy.md` — why removing a mode is allowed, and why the stored option value is not
