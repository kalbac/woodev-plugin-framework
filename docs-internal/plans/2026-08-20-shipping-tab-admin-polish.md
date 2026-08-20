# Plan — «Доставка» tab admin polish (#375, #380, #377, #376, #373, #378)

> Written s82 (20.08.2026) on branch `feat/shipping-tab-admin-polish`, from three code surveys
> (field_mode consumers, React settings surface, provider contract) — not from memory.
> Operator asked for ALL of it in one go and ONE review call, not card by card.

## Ground facts established before planning

| Fact | Consequence |
|---|---|
| Whole location layer is `@since 2.0.2`; last tag is `v2.0.0`, `VERSION = 2.0.1` unreleased | No TAGGED release carries `woodev_location_field_mode`. ~~So the split needs no migration~~ — **corrected after the Codex critique**: the framework ships vendored inside plugins, so an untagged snapshot may already be installed. Tags do not prove absence. Do the migration |
| `Composite_Settings_Handler::filter_visible_values()` splits the POST by owning child BEFORE evaluating conditions | **Cross-handler `show_if` has NO server-side enforcement.** `Location_Settings` never sees `region_field`, and `effective_condition_values()` scores an unregistered controller as `''` — its own comment names the case. Never rely on a cross-handler condition for correctness; clamp on read instead |
| `Composite_Settings_Handler` routes by setting id, not by section | Moving a field between «Локация» and «Поля» is a `build_sections()` edit only — option names cannot move |
| `Settings_Section` description is plumbed end-to-end (PHP → REST → `SectionView`) | #378 is pure copy, zero mechanism |
| `show_if` (ADR-008) is plumbed end-to-end incl. server-side stripping on save | Every "dynamic, without saving" requirement uses it |
| `Abstract_Location_Provider::get_settings_fields()` already defaults to `[]` | A zero-field provider already works; it must OVERRIDE `is_configured()`, which otherwise returns `true` |
| Admin route `GET woodev/v1/location/default-locality/suggest` already exists, manager-gated | #376 needs a React control, not a new endpoint |

## Two defects that block the rest (Block 0 — in flight)

1. `ControlField`'s toggle/checkbox branch bypasses `withAnatomy` and drops `tooltip` — a tooltip on
   any boolean setting renders nothing. Blocks #373 for «Подсказки для адреса», «Записывать адрес
   пункта», «Закрывать карту после выбора».
2. `Field_Schema::from_handler()` assigns `disabled_reason` INTO `description`, destroying the
   authored text. #375/#377 both rely on "disabled + reason", so a disabled field must keep both.

## Block 1 — #375 + #377: provider keys belong to the provider

**Target picture** (operator, three providers):

| Active provider | Location section shows | Notice |
|---|---|---|
| DaData | `token`, `clean_secret` | when `is_configured()` false |
| СДЭК (`test-cdek`) | no key fields (keys live in the carrier's own settings) + DaData keys, because СДЭК does not serve `address` | when `is_configured()` false |
| Тестовый список (`test-list`) | nothing | none (`is_configured()` is honestly `true`) |

**Mechanism — register EVERY provider's fields, gate them with `show_if`.** Today
`Location_Provider_Registry::register_settings()` merges only the ACTIVE provider's fields, resolved
from the STORED option — so the field set changes only after a save. The operator asked for it to be
dynamic without saving, and `show_if` is exactly that mechanism, with server-side enforcement.

- Each provider's fields are registered with
  `show_if => [ 'setting' => 'active_provider', 'value' => '<that provider id>' ]`.
- DaData's two fields get the wider condition
  `[ 'setting' => 'active_provider', 'operator' => 'in', 'value' => [ 'dadata', ...ids that do NOT serve `address` ] ]`
  — the id list is computed server-side at schema-build time, so the client only ever compares values.
  This is operator variant 2 for #377 ("show when DaData is active OR the active provider brings no
  addresses"), not variant 1.
- **The predicate is evaluated for the STORE's country only** (correction after the Codex critique).
  "Serves address" is country-dependent — DaData drops `address` for specific countries and
  `get_suggest_levels( null )` returns the UN-narrowed set — and also depends on whether the chosen
  provider and the fallback are configured. A country-blind list answers a different question. The
  settings page is a store-level surface with one store country, so reuse
  `Location_Service::resolve_default_country()` ("store setting → RU") and state the narrowing in a
  comment instead of implying the condition is universal.
- Consequence to keep, not to fix: `test-list` declares only region+settlement, so DaData's keys ARE
  shown while it is active. #375's "nothing for test-list" is about that provider's OWN fields and
  its notice; the fallback keys are governed by #377's rule, whose converse the operator stated
  himself.
- Field-id collision between two providers must fail loudly (`_doing_it_wrong()`, first wins) and be
  documented in the `Location_Provider` contract — the shared option namespace `woodev_location_*`
  makes ids global.
- Hidden-field stripping on save drops the ids from the SUBMITTED map only; stored options of an
  inactive provider survive. Assert this with a test — it is the whole safety of the approach.

**Fixture rework (do this first — it is the reference implementation plugin authors will copy).**
`test-cdek` currently declares `cdek_client_id`/`cdek_client_secret` as its own required fields and
reads them from `woodev_location_*`. It must instead declare zero fields and answer `is_configured()`
from the CARRIER's own settings. The rig runs with `test-cdek` active, so the credential seeder
(`Test_Credential_Seeder`, rig constants `WOODEV_TEST_CDEK_CLIENT_ID/SECRET`) has to seed the new
home or the rig loses its provider — that is part of this task, not a follow-up.

**Notice.** Precedent is `Shipping_Plugin::add_not_configured_notices()` (`class-shipping-plugin.php:602-633`)
→ `get_admin_notice_handler()->add_admin_notice( $msg, "{$id}-not-configured", [ 'notice_class' => 'notice-warning' ] )`.
Add the location-provider equivalent: fires when the ACTIVE provider answers `is_configured() === false`.
`is_configured() === false` must NOT remove the provider from the select — that is already true and
must stay true (assert it).

**Docs.** The fork "declare your own fields" vs "read the carrier's settings" goes into the
`Location_Provider::get_settings_fields()` / `is_configured()` docblocks and the wiki, or every plugin
author will guess differently.

## Block 2 — #380: split the field type into two axes (closes #369)

One setting `field_mode` (`typeahead` / `related-list` / `ajax-select2`) becomes two, and **each axis
carries the SAME three values** (operator decision 20.08.2026, after the Codex critique showed that
the two-values-per-axis sketch in the card silently DROPS two working states):

| Value | Meaning | Gate |
|---|---|---|
| текст с подсказками | plain input + typeahead over `/suggest` | none |
| предустановленный список | everything preloaded from `/location/list`, search is LOCAL | `CAPABILITY_LIST` |
| список с поиском | select2 querying `/suggest` per keystroke | none — `suggest()` is mandatory |

Today's three modes decompose without loss:

| Today | Регион | НП |
|---|---|---|
| `typeahead` | текст с подсказками | текст с подсказками |
| `related-list` | предустановленный список | предустановленный список + overlay |
| `ajax-select2` | список с поиском | список с поиском |

**Why the card's own sketch was wrong (Codex, verified):** the card gave Регион
{текст, предустановленный список} and НП {текст, список с поиском}. That makes
**Регион = select2-с-запросом** unexpressible (today's whole `ajax-select2` mode for the region), and
it silently converts today's НП inside `related-list` — a PRELOADED, locally-searched select2 fed by
`/location/list` — into an ajax-per-keystroke control. Two working states lost, contradicting the
card's own claim that the split introduces no new states and loses none.

**«Связанный поиск» is an overlay, not a value.** When Регион = предустановленный список, НП keeps
its own type and additionally becomes blocked until a region is chosen and then scoped by it. Today
`related-list` welds the two together; the decomposition is what makes combinations like
**Регион = список + НП = текст с подсказками** expressible.

The overlay must be carried by its OWN signal, not by a literal mode string. Three contracts read
`related-list` literally today and all three have to move to the overlay flag together:
`inject_related_list_states()` gate (`class-location-provider-registry.php:1478-1486`), the #294
arbitration that zeroes `levels.region` from the final `woocommerce_states`
(`class-checkout-config.php:732-749`), and the client-side region exception inside `isNodeActive()`
(`location-cascade.js:485-524`).

Deliberately supersedes decision S2 of the #362 spec (one axis) — mark it in the spec header.

**Migration is REQUIRED, contrary to this plan's first draft.** Git tags prove only that no TAGGED
release carries `woodev_location_field_mode`; the framework ships vendored inside plugins, so an
untagged snapshot may already sit on a site. Read the old option once on upgrade and map
`typeahead → (текст, текст)`, `related-list → (список, список)`, `ajax-select2 → (поиском, поиском)`.
Cheap insurance beats a bet.

**Consumer map that must move together** (from the survey; a missed consumer is the whole risk):

- PHP: `Location_Provider_Registry` (`SETTING_FIELD_MODE`, `MODE_*`, `FIELD_MODES`,
  `offered_field_modes_for()`, `field_mode_labels()`, `offered_field_mode_options()`,
  `get_field_mode()`, `inject_related_list_states()` gate at `:1477`), `Location_Service`
  pass-throughs (`:1761`, `:1776`), `Checkout_Config::build_location_block()` `:851` — the only
  place the value reaches the client.
- JS: `location-cascade.js` (`isRelatedListRegionNode()` `:521`, the region exception inside
  `isNodeActive()` `:487`, `resolveModeRenderer()` `:542`), `location-select-modes.js` registry keys
  `related-list:region`, `related-list:settlement`, bare `ajax-select2`.
- The client currently receives ONE `location.mode`. It must receive the two axes plus the derived
  overlay flag, and renderer resolution becomes per-level rather than per-mode-then-level.

**#369 closure:** with `region_field = remove`, «Тип поля Регион» is hidden via `show_if` and clamped
to typeahead on read, so `region_field=remove` + list-region (today's silent breakage) is
unconstructible. Verify and close #369 with this work. NOTE: the condition crosses handlers
(`region_field` belongs to `Checkout_Field_Settings`, the mode to `Location_Settings`) — React builds
condition values across the whole tab, but assert the cross-handler case on both sides.

**Section move:** «Тип поля Регион», «Тип поля НП» and «Подсказки для адреса» move to «Поля»,
interleaved with the fields they describe:
`field_order_preset, country_field, region_field, field_mode_region, field_mode_settlement, address_field, address_suggestions, postcode_field`.
«Локация» keeps provider, provider keys, «Локация по умолчанию». Registration stays with
`Location_Settings` — the option namespace does not move.

## Block 3 — #376: fixed-location picker (closes #370)

- New control type (e.g. `location-picker`) — must be added to
  `Woodev_Abstract_Settings::get_control_types()`, or `register_control()` throws.
- React control: fork `src/components/select-field.js`, replace static options with debounced fetch
  against `woodev/v1/location/default-locality/suggest` using `window.woodevSettings.nonce`; store
  `JSON.stringify( entry.record )`. Do NOT reuse `location-select-modes.js` — it is a jQuery/select2
  IIFE welded to the checkout DOM.
- `default_locality_record` gets `show_if => [ 'setting' => 'default_locality_policy', 'value' => 'fixed' ]`.
- `default_locality_needs_repick` leaves `get_owned_setting_ids()` entirely (#370 variant 2, the
  narrowest): still registered and writable by code, never on screen. Its state is already surfaced
  through `apply_default_locality_status_note()`.
- Country for the query: store country → `RU` (steps 2–3 of `Location_Service::resolve_default_country()`,
  reuse it rather than writing a second cascade).

## Block 4 — #373 + #378: copy

Only after the field set is final, or the copy is written twice.

- **#373** — `desc_tip` by default (`register_control( …, [ 'tooltip' => … ] )`), `description` when the
  text carries a link. For select fields the tooltip must explain the DIFFERENCE between values —
  e.g. «Скрывать для ПВЗ» still sends the value to the order while «Убрать» removes the field.
  Scope: every field of all three sections, plus provider-declared fields (their copy belongs in the
  provider's own `get_settings_fields()`).
- **#378** — one or two sentences per section, passed as the 4th argument of `Settings_Section::create()`.
  «Поля» already passes `get_section_note()`; keep that and prepend the static description.

## Not in scope

- **#374** (option names / value vocabulary) — operator said explicitly: do not start without him.
- **#379** (map button colour/text) — low priority; the `магазин → карьер → фреймворк` chain already
  works in `resolve_accent_color()`, only the existing `pickup_accent_color` field is not surfaced.

## Verification gates

Per block: `composer test:unit` (baseline 2405/5930), `npm run test:js -- --roots "<rootDir>/tests/js"`
from bash (baseline 1209), `composer phpcs && composer phpstan`, `npm run lint:docs`, and
`php bin/generate-class-map.php && npm run build` whenever `src/` changes (CI has an assets-parity job).
Integration (110) via the container before the review call. Then a full rig pass on `:8973` — the rig
serves the WORKING TREE, so the branch must be named out loud when the operator is asked to look.

Codex is the critic for each block, driven through an Orca terminal (`orca terminal create --command codex`),
where its shell genuinely works — see the rewritten `codex-shell-sandbox-broken-windows` gotcha.
