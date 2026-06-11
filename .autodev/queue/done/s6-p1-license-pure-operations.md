---
id: s6-p1-license-pure-operations
title: Woodev_Plugins_License — transport-agnostic pure ops (activate/deactivate/set_beta_enabled/get_state) + delete legacy Settings-form transport
phase: S3.2 license UI — PHP operations layer (spec §4.1/§4.2, plan task s6-p1)
type: feature
model: opus
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/licensing/class-plugin-license.php
  - woodev/licensing/class-woocommerce-license-settings.php
  - woodev/class-plugin.php
  - tests/unit/LicensePureOperationsTest.php
  - tests/unit/LicenseNeedLicenseFlagTest.php
depends_on: []
contract_zones_touched: [ license_option_keys, edd_action_contract, license_hooks ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "activate( string $key ): array — sanitize_text_field's the key, writes option get_plugin_option_name('license_key') via update_option (parity: the legacy Settings API wrote this option, not the handler), early-returns get_state() when already is_license_valid() (parity with the legacy handler), dispatches edd_action=activate_license, delete_transient('woodev_extensions') when response license==='valid', Woodev_License::save($response->get_response_data()); throws (Woodev_Plugin_Exception or Exception from dispatch) on transport/validation failure WITHOUT touching stored license data"
  - "deactivate(): array — dispatches edd_action=deactivate_license with the stored key, on success Woodev_License::delete(); NEVER deletes or writes the *_license_key option (parity: the legacy handler left it); throws on transport failure"
  - "set_beta_enabled( bool ): void — true => update_option(get_plugin_option_name('beta_version'),'yes'); false => delete_option(...) — exactly the legacy register_setting sanitize semantics"
  - "get_state(): array — exact spec §4.1 shape: plugin_id (string download id), plugin_name, license_key (full, '' when none), status (raw token or ''), status_label ('' when status ''), message (Woodev_License_Messages::get_message()), message_variant, expires (raw), is_valid, is_active, is_need_license, beta_enabled (Woodev_Plugin::is_beta_allowed())"
  - "message_variant buckets (private get_message_variant()): error = expired|disabled|revoked|missing|missing_url|invalid|invalid_item_id|item_name_mismatch|key_mismatch|site_inactive|no_activations_left|license_not_activable; warning = valid + non-lifetime expiry within MONTH_IN_SECONDS (timestamp computed like Woodev_License_Messages::__construct(), NOT the broken `is_numeric(...) ?: strtotime(...)` line from the deleted renderer); success = valid otherwise; info = everything else ('', deactivated, unknown)"
  - "License-free no-ops: when plugin->is_need_license()===false, activate()/deactivate() return get_state() without dispatching or writing anything"
  - "Anti-pirate: is_valid/is_active in get_state() come from is_license_valid()/is_active() (which consult ONLY is_license_required()); test proves is_need_license()=false + status 'expired' still yields is_valid=false/is_active=false"
  - "Static instance registry: constructor records self::$registered_instances[(string) $plugin->get_download_id()] = $this; public static get_registered_instances(): array and get_registered_instance( string $plugin_id ) (null when unknown); tests reset the static via reflection with the PHP_VERSION_ID < 80100 setAccessible guard"
  - "Legacy transport DELETED: activate_license()/deactivate_license() methods + their two admin_init add_action lines removed; file woodev/licensing/class-woocommerce-license-settings.php deleted; Woodev_Plugin::load_license_settings_fields() + its constructor call removed. notices(), plugin_row_license_missing(), verify_license(), validate_license(), plugin_screen_scripts() and all read accessors UNTOUCHED"
  - "B-12: is_active() docblock documents the three meanings of true (genuinely-active license / not-known-bad: no failing status recorded / license-free product per server authority); behavior unchanged"
  - "LicenseNeedLicenseFlagTest: test_deactivate_license_no_wp_die_when_license_not_needed replaced with a license-free deactivate() no-op test (the handler it covered is gone); other tests untouched and green"
  - "No installed-site contract changed: option keys woodev_{id}_license / _license_key / _beta_version, EDD edd_action contract + params shape (license/item_id/url/version), hooks woodev_license_saved/_deleted, transient woodev_extensions — byte-for-byte (parity tests prove the writes)"
---

# Task

Implement plan task **s6-p1** of `docs-internal/platform-v2-s3-licensing-ui-plan.md`
(spec `docs-internal/platform-v2-s3-licensing-ui-spec.md` §4.1/§4.2 — design locked, do not re-open).

Extract the four transport-agnostic license operations onto `Woodev_Plugins_License`
(single writer under the future REST controller) and delete the legacy Settings-form
transport (clean-break ADR-005). The stored-data contract is RELEASE-BLOCKING: the new
ops must perform byte-for-byte the same option writes the legacy
`activate_license()`/`deactivate_license()` handlers **plus** the `register_setting`
sanitize callback did (the sanitize callback — in the file you are deleting — was what
wrote `woodev_{id}_license_key` and the `beta_version` option; read it first).

TDD: write `tests/unit/LicensePureOperationsTest.php` FIRST (scaffolding pattern =
`LicenseNeedLicenseFlagTest::make_license_for_plugin()` reflection builder; Brain Monkey
`Functions\expect` for `update_option`/`delete_option`/`delete_transient`; Mockery mock
for the `api_handler` property to stub `dispatch()`'s response). Code sketches for
`activate()` and the variant buckets are in the plan — follow them.

## What NOT to change
- `is_license_required()` / `is_license_valid()` / `is_active()` logic (docblock on
  `is_active()` only — B-12). The L2 enforcement seam is S3.1 territory.
- `verify_license()` / `validate_license()` / `notices()` / `plugin_row_license_missing()`.
- Any option key, hook name, EDD action, endpoint, slug (see acceptance).
- Do not add the REST controller or registry *consumers* here (that is s6-p2).

## Gotchas to honor
- `licensing/two-layer` (anti-pirate invariant), `testing/reflection-setaccessible-version-guard`,
  `testing/brain-monkey-function-pollution`, `i18n/russian-source-plural-n` (no `_n()`,
  count-neutral Russian user-facing strings).
- Conventions: WPCS tabs, Yoda, short arrays `[]`, type decls + `@since 2.0.0`, English
  comments, Russian user-facing strings, `??` over `isset`.

## Verification
- `./vendor/bin/phpunit tests/unit/LicensePureOperationsTest.php tests/unit/LicenseNeedLicenseFlagTest.php` then full `composer check` green.
- State in the report how you proved the parity tests would catch a regression (e.g. temporarily changing an option name makes them RED).
