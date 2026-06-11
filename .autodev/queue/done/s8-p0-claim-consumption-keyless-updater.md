---
id: s8-p0-claim-consumption-keyless-updater
title: §4 signing consumption — is_license_required() reads verified server claim + B-3 keyless updater polling
phase: S3.3 webhooks + §4 signing — claim transport & enforcement seam (need-license-spec §4.2-§4.5, plan task s8-p0)
type: feature
model: opus
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/licensing/class-license-authority-claims.php
  - woodev/licensing/class-plugin-license.php
  - woodev/class-plugin.php
  - woodev/plugin-updater/class-plugin-updater.php
  - tests/unit/LicenseAuthorityClaimsTest.php
  - tests/unit/UpdaterKeylessPollingTest.php
  - tests/unit/LicenseRequiredSeamTest.php
depends_on: [ s8-p1-envelope-verifier-normalize-site ]
contract_zones_touched: [ enforcement_seam, updater_identity, edd_request_params ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "NEW class Woodev_License_Authority_Claims (woodev/licensing/class-license-authority-claims.php, class_exists-guarded): __construct( Woodev_Plugin $plugin ); consume_from_response( $response_data ): void — extracts top-level 'license_authority' (object or array; objects converted via json_decode(wp_json_encode(...), true)); ABSENT key → no-op (stored claim KEPT, last-known-good); present but failing verification → no-op (NEVER store unverified data, NEVER delete the previous valid claim); verified → update_option( $plugin->get_plugin_option_name('license_required'), $envelope, false ) i.e. option woodev_{id_underscored}_license_required, autoload false, value = the raw envelope array, never a bare boolean"
  - "verify_claim (private): Woodev_License_Envelope_Verifier::verify() THEN woodev_normalize_site(payload.site) !== null AND === woodev_normalize_site(home_url()) THEN (string) payload.plugin_id === (string) $plugin->get_download_id() THEN is_int(expires_at) AND time() <= expires_at. Any failure → null. get_verified(): ?array reads the option (array guard), re-verifies at rest (tamper-at-rest → null), memoizes per request (private property; second call performs zero sodium/option reads — asserted via injected key/spy)"
  - "Woodev_Plugins_License::is_license_required() becomes: $claim = claims->get_verified(); return null === $claim ? true : (bool)($claim['license_required'] ?? true). Anti-pirate docblock paragraph kept; is_need_license() still NEVER consulted (existing LicenseRequiredSeamTest + LicenseNeedLicenseFlagTest keep passing, update LicenseRequiredSeamTest only to cover the new claim-reading branch)"
  - "Consumption hook-ins: validate_license() and activate() call consume_from_response( $license_data->get_response_data() ) ONLY after a successful dispatch (a dispatch() throw bypasses consumption — outage grace §3.2 byte-for-byte: LicenseOutageGraceTest untouched and green); Woodev_Plugin_Updater::get_version_from_remote() consumes from the parsed $response after the sections handling, wrapped so a consumption Throwable never breaks the update flow"
  - "B-3 load_updater() rework (woodev/class-plugin.php): gate becomes is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI); constructs new Woodev_Plugin_Updater( $this ) UNCONDITIONALLY (no license-key check); do_action( 'woodev_plugin_updater', $license_key ) fires exactly as before with the key arg (PUBLIC HOOK CONTRACT byte-for-byte). Verify includes() loads plugin-updater file for the cron context — align if gated"
  - "Updater get_api_params() gains 'url' => home_url() (ADDITIVE; value stays RAW home_url() — plan decision 2: client-side send normalization deliberately NOT implemented, server normalizes before signing; do NOT change the url value in dispatch() either); every existing param key/value byte-for-byte (edd_action get_version, license, item_id, version, slug, beta, php_version, wp_version)"
  - "Multisite owner rule (plan decision 5): consumption uses plain update_option (current blog), NO switch_to_blog anywhere — asserted"
  - "Tests (fixture keypair from s8-p1, seed chr(1)x32, key injected via verify() param or a test-only filter — document which): claim stored only after FULL verification; tampered/wrong-site/wrong-plugin/expired → option untouched; get_verified null for absent/expired/tampered-at-rest; is_license_required() false ⇔ verified claim license_required === false; expired stored claim → true (grace boundary: <= expires_at honored); response without license_authority → stored claim kept; double-normalization idempotence (stored normalized site verifies against raw home_url 'https://Example.com/'); UpdaterKeylessPollingTest: no key + is_admin → updater constructed; no key + cron (is_admin false, wp_doing_cron true) → constructed; plain frontend → NOT constructed; hook woodev_plugin_updater fired with '' key; updater response with license_authority → claim consumed (B-3 regression test trio from need-license-spec §4.3)"
  - "Wiring: class-license-authority-claims.php require_once'd in includes() within the licensing block BEFORE class-plugin-license.php"
  - "Release-blocking contracts untouched: woodev_{id}_license / woodev_{id}_license_key options, EDD edd_action params, endpoint, hooks woodev_license_saved/deleted, transient woodev_extensions, updater cache option name, woodev_plugin_updater hook"
---

# Task

Implement plan task **s8-p0** of `docs-internal/platform-v2-s3-licensing-webhooks-plan.md`
(spec: need-license §4.2–§4.5 incl. the B-3 corrected premise — design locked).

This makes `is_license_required()` real: it reads a server-signed Ed25519 claim
(verified via the s8-p1 primitive) instead of the literal `true`, with 14-day outage
grace, and gives keyless plugins the polling transport that delivers claims (B-3).
The safe default direction never flips: ANY doubt → `true` (license required).

TDD: failing tests first (`LicenseAuthorityClaimsTest`, `UpdaterKeylessPollingTest`),
run-fail, implement, run-pass. Scaffolding patterns: `LicenseRequiredSeamTest` /
`LicenseOutageGraceTest` for license-engine stubs; reflection access guarded with
`PHP_VERSION_ID < 80100` (gotcha testing/reflection-setaccessible-version-guard).

## What NOT to change
- `is_license_valid()` / `is_active()` bodies (they already consult `is_license_required()`).
- `dispatch()` request params (url stays RAW `home_url()`).
- `weekly_license_check()` (outage-grace wrapper stays as-is).
- The S3.2 pure ops / REST controller / registrar.
- No webhook/command code (s8-p2+).

## Gotchas to honor
- `licensing/two-layer` (the local flag NEVER gates enforcement), `framework/includes-wiring`,
  `testing/reflection-setaccessible-version-guard`, `testing/brain-monkey-function-pollution`.
- Conventions: WPCS tabs, Yoda, type decls, `@since 2.0.0` docblocks.

## Verification
- `./vendor/bin/phpunit tests/unit/LicenseAuthorityClaimsTest.php tests/unit/UpdaterKeylessPollingTest.php tests/unit/LicenseRequiredSeamTest.php tests/unit/LicenseOutageGraceTest.php tests/unit/LicenseNeedLicenseFlagTest.php`
  then full `composer check` green.
- Report: which paths can make `is_license_required()` return `false`, and confirm each
  requires a cryptographically verified, site-bound, unexpired claim.
