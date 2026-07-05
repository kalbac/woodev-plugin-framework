# Conditional / dependent fields (conditional visibility) — design spec

> Follow-on to **SP-3** (field validation, PR #96/#97). Brainstormed + locked s40 (2026-07-05).
> Operator's explicit ask after rig-testing SP-3: show/hide a field based on another field's value.
> `@since 2.0.2` — VERSION not bumped. Applies to BOTH surfaces (settings page + setup wizard) via the shared `Field_Schema` + `ControlField`.

## 1. Goal

Let a plugin declare that a field is visible only when other fields hold certain values — e.g. show «API-ключ» only when `mode = live`, show «ставка» only when `calc_type = fixed`. The realistic drivers are the shipping/carrier plugins; the framework fixture «Карьер» already carries exactly these shapes (`mode` test/live, `calc_type` fixed/dynamic).

**The non-trivial requirement:** a *hidden* required field must NOT block Save / «Продолжить». Visibility must be resolved identically in three places — client render, client save-gate, and the authoritative server gate — because the server persists nothing when validation fails (SP-3 atomic REST).

First version is deliberately **minimal**: individual fields only, a flat condition grammar, four operators. Everything richer is additive and deferred (§8).

## 2. Grounding (actual code, verified s40)

- **`Woodev_Setting`** (`woodev/settings-api/class-setting.php`) is the per-field DTO. It already carries analogous per-field attributes wired through `register_setting()` args: `required`, `validate`/`validate_message` (SP-3), `sensitive`, `constant_name` (SP-2). A new `show_if` attribute belongs here, same pattern.
- **`Field_Schema::from_handler()`** (`woodev/settings-page/class-field-schema.php`) is the **single source of truth for the per-field schema consumed by BOTH surfaces**. The settings page (`Settings_Page_Registry::build_sections()`, `class-settings-page-registry.php:178`) and the setup wizard (`class-setup-wizard.php:619`) both call it. A field attribute emitted here reaches both surfaces for free. It already conditionally emits keys (`server_validated`, `sensitive`, `min/max/step`).
- **`Woodev_Abstract_Settings::validate_values( array $values ): array`** (`abstract-class-settings.php:311-368`) is the authoritative server gate: read-only, returns `{setting_id: message}`, skips unknown ids and defined-constant fields via `continue`. It iterates the **posted** values map.
- **`Woodev_Setting::get_validation_error( $value ): ?string`** (`class-setting.php:486-577`) is the single per-field validator (mirrored by `src/components/validate.js`).
- **REST save** is atomic two-pass (SP-3): `validate_values()` first; if the error map is non-empty → `{status:400, errors:{...}}`, persist nothing.
- **Client render:**
  - Settings: `src/settings-page/section-view.js` maps `Object.keys(section.fields)` → one `ControlField` each. Only **one section renders at a time** (`app.js` `renderSection` picks one sub-tab).
  - Wizard: `src/setup-wizard/step-view.js` `renderFields()` maps `step.fields` → `ControlField` each (with ad-hoc consecutive-toggle grouping).
- **Client save-gate:**
  - Settings: `src/settings-page/app.js` `onSave()` gathers `allFields` across non-connection sections, builds `merged = providerEdits[id] ?? allFields[id].value`, runs `validateFields(allFields, merged)`; if any error → block REST, reveal errors, snackbar.
  - Wizard: `app.js` `goNext()`/`saveStep()` advances only on success; a parallel client pre-check exists (SP-3).
- **Effective value** the client already computes as `edit ?? schema.value` (edited value, else the schema's stored/default value).
- Both surfaces submit **only changed fields** (dirty-tracking) — `edits[providerId]` / `values[step.id]`.

## 3. Decisions

### D1 — Declaration: per-field `show_if`, accepting an array OR a callback

New per-field attribute **`show_if`** on `Woodev_Setting` (default `[]` = always visible), wired through `register_setting()` args next to `required`/`validate`. It accepts **either**:

```php
// (a) a conditions array directly
'show_if' => [ 'relation' => 'AND', [ 'setting' => 'mode', 'value' => 'live' ] ],

// (b) a callback returning the same array (DRY — one method serves many fields)
'show_if' => [ $this, 'field_rules' ],
// where, plugin-side:
private function field_rules( string $field_id ): array {
    if ( in_array( $field_id, [ 'live_api_key', 'live_secret' ], true ) ) {
        return [ 'relation' => 'AND', [ 'setting' => 'mode', 'value' => 'live' ] ];
    }
    return []; // always visible
}
```

The callback **returns the same declarative array** a direct value would — it is NOT a visibility predicate. This keeps everything **mirror-safe**: the framework resolves the callback once at schema-build time into a plain array, which is what gets serialized to the client and re-used by the server. (Contrast: SP-3's `validate` callback is a server-side per-value predicate and is explicitly un-mirrorable; conditional visibility must mirror, so only data-returning callbacks are allowed.)

**Framework owns the mechanism** (the grammar, the dual evaluator, the resolution seam); **the plugin owns the domain** (the actual rules inside the callback). This follows the project's `framework = mechanism, domain = plugin` rule.

Storage + resolution:
- `Woodev_Setting::$show_if` holds `array|callable`.
- `Woodev_Setting::get_show_if_conditions(): array` normalizes: if callable → `call_user_func( $cb, $this->id )`, cast to array; else return the array as-is. Both `Field_Schema` and `validate_values` call this — one resolution path.
- The callback runs **at schema-build time only** and must return static-per-id data (it does NOT see live form values — reactivity comes from the JS evaluator applied to live values). This constraint is documented on the setter and in the public docs.

### D2 — Condition grammar (WP_Query-style, flat, no nesting)

Adopt the proven `WP_Meta_Query`/`WP_Tax_Query` shape (a `relation` plus a flat list of condition arrays) — a canonical WP convention, so we are not designing the grammar blind.

```php
[
    'relation' => 'AND' | 'OR',                                    // optional, default 'AND'
    [ 'setting' => '<controlling_id>', 'operator' => '=', 'value' => 'live' ],
    [ 'setting' => '<controlling_id>', 'operator' => 'in', 'value' => [ 'a', 'b' ] ],
    // ...
]
```

- **A single condition** may be passed as the bare inner array (sugar): `[ 'setting' => 'mode', 'value' => 'live' ]` is treated as a one-condition AND group.
- `operator` is optional, **default `'='`**.
- **Operators (v1): `=`, `!=`, `in`, `not_in`.** `in`/`not_in` take an array `value`; `=`/`!=` take a scalar.
- **Flat only — no nested groups** in v1.

### D3 — Comparison + empty semantics (mirror-critical)

- **String comparison.** Both sides are cast to string before comparing (`(string) $cv` vs `(string) $val`; `in` = string-membership). This guarantees PHP and JS agree (JS has no loose PHP `==`), and matches how enum values already round-trip as **option keys**. For enum controlling fields (`select`/`radio`) the condition `value` is the **option key** (what is stored), not the label.
- **The evaluator is a total pure function on the literal current value — no special-casing of empty/unset.** An empty/unset controlling value is simply the empty string:
  - `=` against a non-empty target → no match (field hidden).
  - `!=` / `not_in` against a non-empty target → **match** (field shown).

  Consequence, documented for authors: to show a field when `mode = live`, write `= 'live'`, not `!= 'test'` (the latter also shows while `mode` is still unset). This is the author's choice; the framework stays predictable.
- **Relation:** `AND` = every condition matches; `OR` = at least one matches; an empty conditions list = always visible.

### D4 — Shared evaluator (KEEP IN SYNC, like SP-3's validator)

- **PHP:** `Woodev_Setting::evaluate_conditions( array $conditions, array $values ): bool` — pure static; plus an instance helper `is_visible( array $values ): bool` = `evaluate_conditions( $this->get_show_if_conditions(), $values )`.
- **JS:** `evaluateConditions( conditions, values )` + `isFieldVisible( schema, values )` in `src/components/validate.js` (co-located with the existing validator mirror; it already owns the "KEEP IN SYNC" discipline).
- Both files carry a cross-reference comment naming the other; §5 is the contract.

### D5 — Server resolution: posted-else-stored

`validate_values()` resolves each field's visibility against the **effective** controlling value: the posted value if the controlling id is in the submitted map, otherwise the current stored value (`$this->get_value( $controlling_id )`). This mirrors the client's `edit ?? schema.value` so both gates agree even though the client submits only changed fields.

Implementation: at the top of the per-field loop (after the existing unknown-id and constant guards), resolve `$conditions = $setting->get_show_if_conditions()`; if non-empty and `! evaluate_conditions( $conditions, $effective_values )` → `continue` (skip — no error). `$effective_values` is built once per call as `posted + stored-fallback` for the ids referenced by any condition.

### D6 — Hidden fields are skipped, not stripped

A hidden field is **excluded from validation** (both gates) but its posted value, if any, is still **persisted as-is**. Rationale: preserve user input when the controlling field toggles back (set `mode=live`, type the key, switch to `test` → the key survives, hidden); the plugin does not read a hidden field's value, so persisting an unvalidated hidden value is harmless. This keeps the change surgical — no new "strip on hide" path.

### D7 — Scope: individual fields only

- `show_if` lives only on `Woodev_Setting` (the field). No section/sub-tab/step-level visibility in v1.
- **Hiding a group of fields** = the same conditions on each field (or one callback branch returning them for several ids). No new group primitive; it already works because each field evaluates independently.

## 4. Client behavior (both surfaces)

- **Render:** before rendering a field, compute `isFieldVisible( schema, effectiveValues )` against the surface's current effective values; a hidden field renders nothing.
  - Settings: `section-view.js` filters `section.fields` by visibility, evaluating against `values` merged over `section.fields[id].value`. Because values are React state, changing a controlling field re-renders the section → live show/hide **within the visible section**.
  - Wizard: `step-view.js` filters `step.fields` the same way (filter before the consecutive-toggle grouping so borders wrap only visible fields).
- **Save-gate:** exclude hidden fields from `validateFields`.
  - Settings `onSave`: build `merged` as today, then validate only `{id: schema}` where `isFieldVisible(schema, merged)`.
  - Wizard client pre-check: same filter against the step's effective values.
- **Cross-section note (settings):** if a controlling field lives in a *different* sub-tab than the dependent field, the dependent field's visibility is still computed correctly on render (the controlling value is available from edits/schema), but it will not update live while the user is on another sub-tab. The realistic case («Карьер») keeps controller + dependent in the same section. Out of scope to make cross-section reactive.

## 5. The mirror contract (PHP ↔ JS)

`evaluate_conditions( conditions, values )`:

1. If `conditions` is empty → `true` (visible).
2. **Normalize the sugar:** if `conditions` has a `setting` key, it is a single bare condition → wrap as `[ conditions ]` (a one-condition group with no `relation`).
3. `relation` = `conditions['relation'] ?? 'AND'`, uppercased; the condition list = the members that are themselves arrays (i.e. drop the scalar `relation` entry — in PHP the numeric-key members, in JS the `Object.values` that are arrays). An empty condition list → `true` (visible).
4. For each condition `{ setting, operator = '=', value }`:
   - `cv = (string)( values[setting] ?? '' )`.
   - `'='`      → `cv === (string) value`
   - `'!='`     → `cv !== (string) value`
   - `'in'`     → `in_array( cv, array_map( 'strval', (array) value ), true )`
   - `'not_in'` → `! in_array( cv, array_map( 'strval', (array) value ), true )`
   - unknown operator → treated as **not matching** (fail-closed).
5. `AND` → all conditions true; `OR` → any true.

JS `evaluateConditions` reproduces this exactly (`String(values[setting] ?? '')`, array members via `Object.keys` filtering the `relation` key, same operator table, unknown-operator → false).

`isFieldVisible( schema, values )` = `schema.show_if` absent/empty → `true`, else `evaluateConditions( schema.show_if, values )`.

## 6. Server behavior

- **Emission:** `Field_Schema::from_handler()` adds `'show_if' => $setting->get_show_if_conditions()` to the entry **only when non-empty**. (Resolves the callback once, server-side, at build time.)
- **Validation skip:** `validate_values()` per D5 — resolve effective values for referenced controlling ids, `continue` on hidden fields.
- **No REST route changes.** The atomic two-pass and error-map contract are unchanged; hidden required-empty fields simply never enter the error map.

## 7. Fixture demo («Карьер»)

Extend the existing fixture to prove both surfaces and both operators/relations:
- `mode` (select: test/live) controls a `live_api_key` (`show_if = mode = live`).
- `calc_type` (select/radio: fixed/dynamic) controls a `rate` field (`show_if = calc_type = fixed`) and a `formula` field (`show_if = calc_type != fixed`, demonstrating `!=` + the empty-controlling rule).
- One field driven by a **callback** `show_if` returning conditions for several ids (demo DRY).
- Include at least one `in` / `not_in` and one `OR` case so the evaluator paths are exercised end-to-end.

## 8. Scope / deferred

**In v1:** individual fields; grammar `relation` + flat conditions; operators `=` `!=` `in` `not_in`; `show_if` array-or-callback; posted-else-stored server resolution; both surfaces.

**Deferred → `FUTURE-BACKLOG.md` (low priority), one entry "conditional-fields v2":**
- Comparison operators `>` `<` `>=` `<=` (numeric coercion — new semantic shape).
- Unary `empty` / `set` (no `value` operand; partially reachable via `!= ''` for scalars today).
- `contains` for a multiselect/array controlling field (v1 constrains controlling fields to **scalar** controls — `select`/`radio`/`toggle`/`text`; document it).
- **Nested** condition groups (groups within groups).
- Section / sub-tab / step-level visibility.
- A DRY registration helper (`apply_show_if( ids, conditions )`) if per-field/callback proves verbose on the pilot.

**ADR** (`docs-internal/adr/`): record the operator set (`=`/`!=`/`in`/`not_in`), the empty-controlling-value rule (total pure function, string comparison), and *why* the above are deferred — so a future operator addition follows the written contract instead of re-deciding.

## 9. Testing

- **PHP unit:**
  - `evaluate_conditions` — every operator, empty controlling value, `AND` + `OR`, single-condition sugar, unknown operator (fail-closed), string-vs-int key coercion.
  - `get_show_if_conditions` — array pass-through and callback resolution (callback receives the field id).
  - `validate_values` — a hidden required field produces NO error; a visible required-empty field still does; posted-else-stored resolution (controlling value only in DB).
  - `Field_Schema::from_handler` — emits `show_if` only when non-empty; resolves the callback.
- **JS:** `evaluateConditions` / `isFieldVisible` mirror parity (same cases as the PHP evaluator).
- **Integration:** settings + wizard schema round-trip carries `show_if`; atomic save with a hidden required field succeeds.
- **Fixture** «Карьер» drives the browser e2e (§7).
- `@since 2.0.2`, VERSION unchanged. `composer check` (unit + phpcs + PHPStan on Linux CI) green; assets rebuilt (class-map regen not needed — no new class files, only methods; confirm at impl).

## 10. Process (per next-session-prompt + operator rules)

- `writing-plans` → **subagent-driven** (fresh agent per task, two-stage spec + code-quality review) → Codex GPT-5.5 critic (inline bundle ≤~12KB) → **my own browser e2e on `:8888`** (Playwright, admin/password) before merge.
- All work on a feature branch (never commit to `main` directly — gotcha `git-squash-onto-stale-origin-main-diverge`).
- Implementers use built-in `Edit` (not Serena `replace_content` — EOL flip) for existing source; run unit + phpcs + build each task.
