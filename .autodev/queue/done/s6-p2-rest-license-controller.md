---
id: s6-p2-rest-license-controller
title: Woodev_REST_API_License controller + reusable woodev/v1 registrar on core rest_api_init (not WC-gated)
phase: S3.2 license UI — REST transport layer (spec §4.3/§4.4, plan task s6-p2)
type: feature
model: opus
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/rest-api/class-rest-v1-registrar.php
  - woodev/licensing/api/class-rest-api-license.php
  - woodev/class-plugin.php
  - woodev/licensing/class-plugin-license.php
  - tests/unit/LicenseRestControllerTest.php
depends_on: [ s6-p1-license-pure-operations ]
contract_zones_touched: [ rest_namespaces ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "NEW reusable registrar Woodev_REST_V1_Registrar (woodev/rest-api/class-rest-v1-registrar.php): const ROUTE_NAMESPACE = 'woodev/v1'; static register_controller( object $controller ): void dedupes by controller class and hooks rest_api_init exactly ONCE; @internal static handler calls each registered controller's register_routes(). This is THE single registration point — the S3.3 webhook controller (s8, DRAFT spec platform-v2-s3-licensing-webhooks-spec.md, do NOT implement it) will register through it"
  - "NEW controller Woodev_REST_API_License (woodev/licensing/api/class-rest-api-license.php) with static idempotent boot() that registers one controller instance through the registrar; routes under woodev/v1: GET /licenses/(?P<plugin_id>[\\w-]+) -> get_state(); POST .../verify {license_key} -> activate(); POST .../deactivate -> deactivate(); POST .../beta {enabled:bool} -> set_beta_enabled() then state"
  - "plugin_id resolution via Woodev_Plugins_License::get_registered_instance( (string) $plugin_id ); unknown id -> WP_Error woodev_license_unknown_plugin status 404"
  - "Permission callback on EVERY route: current_user_can('manage_options') else WP_Error with status rest_authorization_required_code() (wp_rest nonce itself is verified by core via X-WP-Nonce)"
  - "Typed schema args: license_key (string, required, sanitize_text_field, validate_callback rejecting empty/non-string); enabled (boolean, required). Malformed bodies are rejected by the args layer"
  - "NO SILENT FAILURE: every Throwable/Exception from a pure op maps to WP_Error('woodev_license_request_failed' or more specific code, NON-EMPTY user-facing message, ['status'=>4xx/502]); no catch block swallows; happy path returns the get_state() array as rest_ensure_response"
  - "Wiring: registrar + controller files require_once'd UNCONDITIONALLY in Woodev_Plugin::includes() next to the licensing requires (~lines 528-534) — gotcha framework/includes-wiring (REST requests are neither admin nor WC contexts); Woodev_Plugins_License::add_hooks() calls Woodev_REST_API_License::boot(). NOT registered through the WC-gated Woodev_REST_API (spec §4.4)"
  - "B-7 test: with ZERO WooCommerce functions defined (Brain Monkey defines none), boot + route registration + a verify request handling complete without Error — the licensing REST layer is WC-agnostic"
  - "Tests: registrar hooks rest_api_init once for N controllers and dedupes repeated register_controller calls; permission rejection; unknown plugin 404; happy-path verify/deactivate/beta invoke the matching pure op exactly once (Mockery) and return its state; op throwing -> WP_Error with message; boot() idempotent (second call registers nothing new)"
  - "Existing REST namespace contracts untouched (plugin get_id_dasherized() namespaces, yandex-delivery etc.); woodev/v1 is ADDITIVE"
---

# Task

Implement plan task **s6-p2** of `docs-internal/platform-v2-s3-licensing-ui-plan.md`
(spec `docs-internal/platform-v2-s3-licensing-ui-spec.md` §4.3/§4.4 — design locked).

The controller is a thin transport over the s6-p1 pure ops: resolve the right
`Woodev_Plugins_License` via the static registry, call the op, map exceptions to
explicit `WP_Error`s. The registrar exists so `woodev/v1` has ONE owner when s8 adds
the `license-command` endpoint — keep it tiny (constant + register_controller +
single-hook guard), do not build a framework.

TDD: write `tests/unit/LicenseRestControllerTest.php` FIRST. For WP REST scaffolding
in Brain Monkey tests, see how the S1 REST controller tests stubbed
`register_rest_route`/`WP_Error`/`rest_ensure_response` (e.g.
`tests/unit/` REST tests from s1-p4; `Functions\when('register_rest_route')` capture
pattern). `WP_REST_Request` can be a lightweight Mockery stub exposing
`get_param()`/`['param']` access as your handlers need.

## What NOT to change
- `Woodev_REST_API` (woodev/rest-api/class-plugin-rest-api.php) — stays WC-gated, untouched.
- The pure ops' signatures/behavior from s6-p1.
- No webhooks / license-command endpoint (S3.3 is DRAFT — s8 only).

## Gotchas to honor
- `framework/includes-wiring` (unwired class files fatal in production),
  `testing/reflection-setaccessible-version-guard` (registry reset in tests),
  `i18n/russian-source-plural-n` (error messages: count-neutral Russian).
- Conventions: WPCS tabs, Yoda, short arrays, type decls + `@since 2.0.0`, docblocks
  with @internal on hook callbacks.

## Verification
- `./vendor/bin/phpunit tests/unit/LicenseRestControllerTest.php` then `composer check` green.
- Report which silent-failure paths you probed (e.g. op throws generic Exception, op
  throws with empty message — both must surface a non-empty WP_Error message).
