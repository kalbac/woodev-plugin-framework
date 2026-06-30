# SP-3 — Settings field validation (required + email/url/tel/number) — design spec

> Shipping-module sub-project **SP-3** «поля + валидация». Brainstormed + locked s39 (2026-06-30).
> Supersedes nothing; extends the SP-1 settings page (PR #87) + SP-2 auth/secrets (PR #94).
> `@since 2.0.2` — VERSION not bumped. Applies to BOTH surfaces (settings page + setup wizard) via the shared `ControlField`/`FieldRow` kit.

## 1. Goal

Add field validation to the Settings API and both React surfaces:

- **`required` fields** with an always-visible `*` marker.
- **Format validation** for `email` / `url` / `tel` / `number(min/max)`.
- **Two tiers:** client = UX (live inline errors + blocks Save/«Продолжить»); **server = authoritative per-field gate** (the client is bypassable — the s31 enum-bypass lesson).

Locked s38 (decision #1, NOT reopened): live inline validation = **blur-first → live-clear-on-input once a field is errored**; `required` validated on blur (left empty) + on Save, never on focus; `color`/`date` constrained by their pickers (no live format check needed).

## 2. Grounding (actual code, verified s39)

- `Woodev_Setting::validate_value()` validates by **setting `type`** (`validate_{type}_value`): `string/url/email/integer/float/boolean` already exist. `url` requires an `http(s)://` prefix; `email` → `is_email()`. (`woodev/settings-api/class-setting.php:422-506`)
- **`tel` does not exist** — neither a setting type nor a control type. Genuinely new.
- number `min/max/step` live on `Woodev_Control` (`get_min/get_max/get_step`) but are **not enforced** on save — only forwarded to the HTML input. (`class-control.php:231-292`)
- `required` does **not** exist anywhere.
- `Woodev_Abstract_Settings::update_value()` → `Woodev_Setting::update_value()` validates + sets + the handler `save()`s; on invalid it throws `Woodev_Plugin_Exception(code 400)`. (`abstract-class-settings.php:279-298`, `class-setting.php:308-414`)
- REST `save()` loops fields, catches the exception, and **returns on the first failure** (`WP_Error` carrying `field => id`, status 400); fields before it are already persisted (partial save). (`class-rest-api-settings-page.php:144-196`)
- `FieldRow` already accepts a `required` prop and renders a `*` (`woodev-field__req`), but `Field_Schema::from_handler()` **does not emit `required`** → the marker never lights up. No per-field error display exists. (`src/components/field-row.js`, `woodev/settings-page/class-field-schema.php`)
- `src/settings-page/app.js` shows save failure as a single banner (`saveError`) and does **not** map the response `field` back to a control.
- Setup wizard `src/setup-wizard/app.js` `goNext()` → `saveStep()` → advances **only on success**; failure shows one banner. So the server already gates a step indirectly (save throws → step does not change), but with no per-field display and no client pre-check.
- Both surfaces submit **only changed fields** (dirty-tracking): `app.js` `edits[providerId]`, wizard `values[step.id]`.

## 3. Decisions

### D1 — Flag model: source = `controlType`, minimum new flags (brainstorm Q4)

- New boolean flag **`required`** on `Woodev_Setting` (default `false`), with a setter wired through `Woodev_Register_Settings`.
- New **`tel`** control type on `Woodev_Control` (`TYPE_TEL = 'tel'`).
- Format is derived from **`controlType`** (`email` / `url` / `tel` / `number`) — one source for client and server. The legacy `validate_{type}_value` methods stay for back-compat, but the authoritative format path keys off the control type.

### D2 — Single validation method, two callers (brainstorm Q5)

- **PHP:** new `Woodev_Setting::get_validation_error( $value ): ?string` — runs ALL checks in order (required → format → range → existing type/enum) and returns a human message (Russian) or `null`, **without mutating** the setting. `update_value()` calls it and throws `Woodev_Plugin_Exception` when non-null (single source of validation truth). The REST controller calls it in a validate-only pass.
- **JS:** pure `validateField( schema, value ): ?string` in a new `src/components/validate.js` — a faithful mirror of the PHP logic. Used both for live per-field errors and for the Save / «Продолжить» gate.
- Messages are Russian source strings; **no `_n()`** (gotcha `russian-source-i18n-plural-n`).
- The two implementations MUST stay in sync — both files carry a cross-reference comment naming the other; the spec lists the exact rules (§4) as the contract.

### D3 — `required` semantics per control type (brainstorm Q1)

"Filled" means:

| Control type | "Filled" rule |
|---|---|
| text, textarea, email, url, tel, date, color, password, richtext | non-empty after `trim()` |
| number | a value is present (not `''`/`null`) |
| select, radio | a non-empty option key is selected |
| multiselect | at least one option selected |
| **toggle, checkbox, range** | **`required` is a no-op** — they always carry a value; an "обязательный" toggle would mean force-ON (absurd). The `*` marker is not rendered and the gate ignores them. |

`is_requirable( controlType )` is the single predicate (PHP + JS mirror) that decides whether `required` applies and whether the `*` shows.

### D4 — Format validators

| Format | Server | Client (mirror) |
|---|---|---|
| email | `is_email()` | email regex approximating `is_email()` |
| url | `http(s)://` prefix + valid URL (reuse `Woodev_Setting::is_valid_url` semantics) | prefix check + `URL` constructor |
| tel | permissive: digits + `+ - ( ) space`, minimum digit count (Russian numbers etc.) | same permissive regex |
| number | enforce **min/max** on both sides (currently NOT enforced) | enforce min/max |

- **`step` is a UI hint for the spinner only — NOT a validation error.** Enforcing step on floats is a known floating-point trap; deliberately out of scope (this narrows the "number(min/max/step)" brief — recorded as a gotcha).
- Validation order: `required` → format → range. An empty non-required field is valid (skips format/range).

### D5 — REST error contract: atomic, all-field map (brainstorm Q2 — behavior change)

Replaces the current first-fail + partial-save:

1. **Pass 1 — validate:** run `get_validation_error()` for every submitted (allow-list-scoped) field; collect `{ settingId: message }`.
2. **Any errors → persist nothing**, return `WP_Error( 'woodev_settings_invalid', <summary message>, [ 'status' => 400, 'errors' => { settingId: message } ] )`.
3. **Clean → Pass 2 — persist:** `update_value()` every field, then the existing success response.

- Status **400** (consistent with the existing `code 400` exceptions and WP convention). 422 was considered and declined for consistency.
- Client reads `err.data.errors` and lights up each named field; `err.message` is the fallback banner.
- The `array_intersect_key` allow-list scoping (declared section setting ids) is unchanged.
- Constant-backed fields are still skipped on persist (unchanged); they are read-only and never submitted.

### D6 — Client wiring (decision #1: live blur-first → live-clear)

- `ControlField` gains a local `touched` flag. It **shows** an error when `touched && validateField(schema, value) !== null`; once errored, every input re-checks and **clears the error immediately** when the value becomes valid. `required` is checked on blur (left empty) and on Save.
- `FieldRow` gains an `error` prop → renders `.woodev-field__error` (red text under the control) + `aria-invalid` + a red border on the control. The `*` marker changes `<span>` → `<abbr class="woodev-field__req" title="Обязательное поле">*</abbr>`.
- **Save gate (settings page):** the button stays enabled while there are changes; clicking it runs full-tab validation. If any field is invalid it marks **all** fields `touched` (reveals every error), scrolls to the first invalid field, and does **not** call REST. (Not a greyed-out button — avoids the "why is it disabled?" confusion; matches WP form conventions.)
- Toggle rows (rendered outside `withAnatomy`) need no error display — `required`/format never apply to them.

### D7 — Wizard step-gating (brainstorm Q3)

- «Продолжить» is gated by validating the CURRENT step's fields with the same `validateField`: invalid → reveal errors, do not advance. The server `saveStep()` remains the authoritative gate (already: throw → step does not change).
- **«Пропустить» stays an explicit bypass** (advances WITHOUT saving) — this is the wizard's nature (every step is skippable; settings are editable later on the settings page). The `*` still shows for consistency; hard `required` enforcement is the settings page's job.

### D8 — Known property (documented honestly)

Both surfaces submit only **changed** fields. So the server sees a `required`-empty field only when it is actually submitted empty (a bypass). For an **untouched-empty-required** field the **client is the gate** (blocks Save / «Продолжить»; the field can't even be submitted). This is exactly decision #1: client = UX gate; server = authoritative for what it receives.

## 4. Validation rules (the contract both implementations follow)

Given a field `schema` (`{ controlType, required, min, max, options, is_multi, ... }`) and a `value`:

```
1. requirable = is_requirable(controlType)              // false for toggle/checkbox/range
2. if required && requirable && is_empty(controlType, value):
       return "Обязательное поле."                       // required wins, stop
3. if is_empty(controlType, value):
       return null                                       // empty + optional = valid, skip format/range
4. switch (controlType):
     email  -> valid email?           else "Введите корректный email."
     url    -> http(s):// + valid?    else "Введите корректный URL (с http:// или https://)."
     tel    -> permissive phone?      else "Введите корректный номер телефона."
     number -> numeric?               else "Введите число."
               min!=null && v<min ->        "Значение не меньше {min}."
               max!=null && v>max ->        "Значение не больше {max}."
5. return null
```

`is_empty(controlType, value)`: multiselect → empty array; select/radio → `'' === value`; everything else → `'' === trim((string) value)`.

Server `get_validation_error()` additionally runs the existing enum (`options` key-or-value) + `validate_{type}_value` checks after the above, so SP-2's enum/kses behavior is preserved.

## 5. Files touched

**PHP**
- `woodev/settings-api/class-setting.php` — add `$required` + `is_required()`/`set_required()`; add `TYPE`-agnostic `get_validation_error()`; have `update_value()` route through it; add `tel` + `number(min/max)` checks. Keep legacy `validate_*`.
- `woodev/settings-api/class-control.php` — add `const TYPE_TEL = 'tel'`.
- `woodev/settings-api/register-settings/class-register-settings*.php` — wire a `required` setter (mirror `set_is_multi`/`set_default` ordering rules — gotcha: `set_is_multi` before `set_default`).
- `woodev/settings-page/class-field-schema.php` — emit `'required' => $setting->is_required()` (min/max already emitted).
- `woodev/rest-api/controllers/class-rest-api-settings-page.php` — atomic two-pass `save()` returning the `errors` map.

**JS**
- `src/components/validate.js` — NEW: `validateField`, `isRequirable`, `isEmpty` (PHP mirror).
- `src/components/control-field.js` — `touched` state + blur/live-clear wiring; pass `error` to `FieldRow`; `aria-invalid`.
- `src/components/field-row.js` — `error` prop + `.woodev-field__error`; `<abbr>` marker.
- `src/settings-page/app.js` — Save gate (validate-all, reveal errors, scroll to first, block submit); map `err.data.errors` back to fields.
- `src/setup-wizard/app.js` — gate «Продолжить» on the current step; map server errors.
- SCSS — `.woodev-field__error` + invalid-border tokens (settings + wizard).

## 6. Cross-cutting constraints

- New framework classes → none expected (only methods/consts on existing classes); if any new class is added, regenerate `woodev/class-map.php` via `php bin/generate-class-map.php` (no Composer in prod).
- Edit existing source with the built-in `Edit` tool, never Serena `replace_content` (gotcha `serena-replace-content-eol-flip`).
- Build: `npm run build`, commit built assets (assets-parity CI), LF endings, CSS versioned by `filemtime`. min WP 6.6 → JSX in new files OK.
- i18n: Russian source, no `_n()`.
- PHPStan crashes locally on Windows (segfault) — Linux CI is the gate. Locally run `composer phpcs` + `composer test:unit` + the JS build.
- Tests: unit (Brain Monkey) for `get_validation_error()` across every control type + required/format/range; integration for the atomic REST `save()` error map; JS — `validateField` parity table.

## 7. Out of scope

- `step` enforcement as a validation error (D4 — UI hint only).
- Cross-field / conditional validation (e.g. "B required when A set"). YAGNI; revisit at the carrier pilot if a real plugin needs it.
- Async/remote validation (a value checked against a carrier API) — that is the connection-test path (SP-2), not field validation.
- SP-2-DEF secret-wipe affordance (separate; may fold into the pilot).

## 8. Verification (definition of done)

- Unit: `get_validation_error()` returns the right message/null for every (controlType × required × format × range) case; `update_value()` throws on invalid, persists on valid.
- Integration: REST `save()` returns `{ status:400, errors:{...} }` and persists nothing when any field is invalid; persists all when clean; allow-list scoping intact.
- JS: `validateField` matches the PHP rule table (a shared fixture table).
- Operator rig-approval on `:8888`: `*` markers, blur-first errors, live-clear, Save-blocked-with-errors, wizard «Продолжить» gated, «Пропустить» bypass.
- Every CI job pass + state CLEAN before merge; visual rig-approval before `gh pr merge --squash --delete-branch`.
