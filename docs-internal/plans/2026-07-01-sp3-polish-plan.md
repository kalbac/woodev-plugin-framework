# SP-3 Polish — Implementation Plan

> **For agentic workers:** subagent-driven (fresh agent per task + spec + code-quality review). Operator-requested polish after SP-3 shipped (s39). `@since 2.0.2`, VERSION unchanged. Built-in Edit (never Serena). Short arrays, Yoda, type decls. Do NOT run PHPStan (Windows segfault) — unit + phpcs + build are local gates.

**Goal:** three operator-requested polish items on top of SP-3: (1) `placeholder` support on fields; (2) a per-field server-side `validate` callback that overrides the default format check; (3) scroll-to-first-error + an error snackbar when Save/«Продолжить» is blocked by validation (so an off-screen invalid field is discoverable).

**Decisions (locked with operator s40/2026-07-01):**
- tel default stays as-is (allowed chars + ≥5 digits). Strictness is opt-in via the new `validate` callback, supplied by the plugin.
- `validate` is a PHP callable `fn($value): bool` → server-authoritative; it OVERRIDES the default format/type/enum check for that field (required still applies). The client cannot run a PHP callback, so a field with a custom validate skips the client-side format check (only required runs client-side) and relies on the server error contract.
- scroll + snackbar: do BOTH on both surfaces.

---

## Task PT-1: `placeholder` support

**Files:** `woodev/settings-api/class-control.php`, `woodev/settings-api/abstract-class-settings.php` (`register_control`), `woodev/settings-page/class-field-schema.php`, `src/components/control-field.js`, tests `tests/unit/SettingsApi/SettingsControlTypesTest.php` + `tests/unit/FieldSchemaTest.php`.

- [ ] **PHP:** `Woodev_Control` — add `protected $placeholder = '';` + `get_placeholder(): string` / `set_placeholder( string ): void` (`@since 2.0.2`). In `register_control()` add `if ( isset( $args['placeholder'] ) ) { $control->set_placeholder( (string) $args['placeholder'] ); }` (next to the min/max/tooltip forwards).
- [ ] **Schema:** in `Field_Schema::from_handler()` `$entry`, add `'placeholder' => $control ? $control->get_placeholder() : ''`.
- [ ] **JS:** `control-field.js` — pass `placeholder: schema.placeholder || ''` to the `TextControl` in the text/email/url/tel/number/date default case and to the `TextareaControl`. Do NOT add to toggle/select/radio/color/range. (Leave the sensitive PasswordControl's own "saved" placeholder logic untouched — only apply schema.placeholder to a non-sensitive `password` control branch.)
- [ ] **Tests:** `SettingsControlTypesTest` — `register_control` forwards `placeholder` to the control (`get_placeholder()` returns it). `FieldSchemaTest` — `from_handler` emits `placeholder`.
- [ ] Run `./vendor/bin/phpunit --testsuite=Unit` (0 fail), `npm run build:settings && npm run build:setup`, commit `feat(settings): placeholder support on fields (SP-3 polish)`.

---

## Task PT-2: per-field `validate` callback (server override)

**Files:** `woodev/settings-api/class-setting.php`, `woodev/settings-api/abstract-class-settings.php` (`register_setting`), `woodev/settings-page/class-field-schema.php`, `src/components/validate.js`, tests `tests/unit/SettingsApi/SettingValidationTest.php`.

- [ ] **PHP `Woodev_Setting`:** add `protected $validate = null;` (callable|null) + `protected $validate_message = '';` with getters/setters (`get_validate(): ?callable` via a stored callable, `set_validate( ?callable )`, `get_validate_message()/set_validate_message( string )`, `@since 2.0.2`). NOTE: a callable can't be type-hinted as a property cleanly in 7.4 — store as `private $validate = null` (mixed) and guard with `is_callable()` at use.
- [ ] **`register_setting()` args:** add defaults `'validate' => null, 'validate_message' => ''`; after `set_required`, `if ( is_callable( $args['validate'] ) ) { $setting->set_validate( $args['validate'] ); } $setting->set_validate_message( (string) $args['validate_message'] );`.
- [ ] **`get_validation_error()`:** after the empty-optional short-circuit and BEFORE the `switch ( $control_type )` format block, insert:
  ```php
  			// A plugin-supplied validate callback overrides the default format/type/enum
  			// check for this field (required still applied above). Server-authoritative.
  			if ( is_callable( $this->validate ) ) {
  				return call_user_func( $this->validate, $value )
  					? null
  					: ( '' !== $this->validate_message ? $this->validate_message : __( 'Неверное значение.', 'woodev-plugin-framework' ) );
  			}
  ```
  (When a callback is present it returns null/message and the method returns — the format switch, legacy type, and enum are skipped for that field.)
- [ ] **Schema flag for the client:** in `Field_Schema::from_handler()`, after building `$entry`, add `if ( null !== $setting->get_validate() ) { $entry['server_validated'] = true; }` (so the client knows to skip its default format live-check).
- [ ] **JS `validate.js` `validateField`:** after the required check + empty short-circuit, add `if ( schema.server_validated ) { return null; }` BEFORE the format `switch` (client does required only; server enforces the callback). Add a doc line.
- [ ] **Tests (`SettingValidationTest`):** a setting with a `validate` callback returning false → `get_validation_error('anything')` returns the custom message (and the default custom message when none given); callback returning true → null; required still fires for empty before the callback; a tel field with a `validate` callback enforcing 11 digits rejects `'+79009'` and accepts `'+79009009090'`.
- [ ] Run unit (0 fail), commit `feat(settings): per-field validate callback override (SP-3 polish)`.

---

## Task PT-3: scroll-to-first-error + error snackbar (settings page)

**Files:** `src/settings-page/app.js`.

- [ ] Add a `useEffect` keyed on `showErrors` that, when any provider's `showErrors` is true, scrolls the first visible `.woodev-field--error` into view and focuses its control:
  ```js
  useEffect( () => {
  	if ( ! Object.values( showErrors ).some( Boolean ) ) {
  		return;
  	}
  	const el = document.querySelector( '.woodev-settings .woodev-field--error' );
  	if ( el ) {
  		el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
  		const control = el.querySelector( 'input, textarea, button' );
  		if ( control ) {
  			control.focus( { preventScroll: true } );
  		}
  	}
  }, [ showErrors ] );
  ```
- [ ] In `onSave`'s client-error early-return branch, also dispatch an error snackbar (consistent with the existing success/fail snackbars):
  ```js
  		dispatch( noticesStore ).createErrorNotice(
  			__( 'Проверьте правильность заполнения полей.', 'woodev-plugin-framework' ),
  			{ type: 'snackbar', id: 'woodev-settings-validate' }
  		);
  ```
  (Keep the server-rejection path's existing snackbar.)
- [ ] `npm run build:settings`, confirm tree clean, commit `feat(settings): scroll to first error + validation snackbar (SP-3 polish)`.

---

## Task PT-4: scroll-to-first-error + banner (setup wizard)

**Files:** `src/setup-wizard/app.js`.

- [ ] Add a `useEffect` keyed on `showErrors` (the wizard's bool) that scrolls the first `.woodev-field--error` inside the wizard card into view + focuses it (same querySelector pattern, scoped to `.woodev-setup`).
- [ ] In `goNext`'s client-error block, set the existing `error` banner message to a summary so the user sees the click registered:
  ```js
  			setShowErrors( true );
  			setFieldErrors( {} );
  			setError( __( 'Проверьте правильность заполнения полей на этом шаге.', 'woodev-plugin-framework' ) );
  			return;
  ```
  (The wizard is a standalone full-screen surface without the SnackbarList; reuse its `error` banner rather than the notices store. Classic createElement style preserved.)
- [ ] `npm run build:setup`, tree clean, commit `feat(setup-wizard): scroll to first error + summary on blocked step (SP-3 polish)`.

---

## Task PT-5: fixture demo + full build + tests

**Files:** `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`.

- [ ] Add a `placeholder` to a couple of «Карьер» demo fields: `manager_email` control → `[ 'placeholder' => 'name@company.ru' ]`; `tracking_url` control → `[ 'placeholder' => 'https://track.example.com/{track}' ]`; `support_phone` control → `[ 'placeholder' => '+7 (___) ___-__-__' ]`.
- [ ] Demo the `validate` callback: give `support_phone` a `validate` arg (RU 11-digit) so the override is rig-visible, e.g. in `register_setting( 'support_phone', …, [ …, 'validate' => static fn( $v ) => 11 === strlen( (string) preg_replace( '/\D/', '', (string) $v ) ), 'validate_message' => 'Введите номер из 11 цифр.' ] )`. (Keep `required` on it.)
- [ ] `npm run build` (5/5), `./vendor/bin/phpunit --testsuite=Unit` (0 fail), `composer phpcs` (clean), integration foreground (`MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration`) — green/0 risky, or DONE_WITH_CONCERNS if wp-env down.
- [ ] Commit `test(fixture): placeholder + validate-callback demo on Карьер (SP-3 polish)`.

---

## Final (controller): Codex critic on PT-2 (validate callback + the JS skip) + a re-critic of fixes; my browser e2e self-verify on :8888 (placeholder visible, scroll-to-error on blocked Save, snackbar, the support_phone 11-digit callback rejecting +79009 on save); PR; every CI job CLEAN; squash-merge.
