---
id: s8-p3-rest-license-command-endpoint
title: REST POST woodev/v1/license-command via Woodev_REST_V1_Registrar (election §9.3, abuse §9.4)
phase: S3.3 webhooks — inbound fast path (webhooks-spec §3.1 + §9.3/§9.4, plan task s8-p3)
type: feature
model: sonnet
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/licensing/api/class-rest-api-license-command.php
  - woodev/licensing/class-plugin-license.php
  - woodev/class-plugin.php
  - tests/unit/LicenseCommandRestTest.php
  - tests/integration/LicenseCommandEndpointTest.php
depends_on: [ s8-p2-nonce-store-command-dispatcher ]
contract_zones_touched: [ rest_namespaces, public_route ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "NEW controller Woodev_REST_API_License_Command (woodev/licensing/api/class-rest-api-license-command.php, class_exists-guarded): static idempotent boot() registering ONE instance through Woodev_REST_V1_Registrar (election §9.3 — the registrar dedupes by class and owns rest_api_init); register_routes() → register_rest_route( Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, '/license-command', [ methods POST, callback, permission_callback => '__return_true' ] ) — LOCKED operator decision: auth IS the Ed25519 signature, there is no WP user in this flow; NO args schema (the dispatcher owns ALL validation so rejections stay indistinguishable — core's args layer must not leak field-level errors)"
  - "Handler is a thin transport: $result = Woodev_License_Command_Dispatcher::handle_raw_body( $request->get_body() ) → new WP_REST_Response( body, $result['http'] ) where body = {status:'executed'|'already'} or {status:'rejected', reason} EXACTLY per the plan's frozen HTTP map; no try/catch swallowing (a dispatcher Throwable is a bug — let it surface in tests), no extra response keys, no exception text in responses"
  - "Unit tests (LicenseCommandRestTest, Brain Monkey, register_rest_route capture pattern from LicenseRestControllerTest): boot() idempotent (second call registers nothing new); route + namespace + methods + permission_callback === '__return_true' pinned; handler maps each dispatcher result {status,reason,http} to the exact WP_REST_Response status+body (table-driven across all 13 statuses/reasons); oversized 8193-byte body → 400 malformed; garbage JSON → 400 malformed; rejected responses for different causes differ ONLY in the reason code"
  - "Integration test (tests/integration/LicenseCommandEndpointTest.php, wp-env): the endpoint is PUBLIC — no cookie/nonce setup at all (gotcha testing/integration rest-cookie semantics do NOT apply; do not copy LicenseRestAuthTest's nonce scaffolding); fixture-signed valid envelope for the test plugin's download id → 200 {status:'executed'} (or 'already' per fixture activation state — assert deterministically); immediate replay of the same envelope → 410 {status:'rejected', reason:'replayed'}; tampered signature → 401 bad_signature; fixture pubkey injected via the same seam s8-p0/p2 established (document it in the test header)"
  - "Wiring: require_once in includes() next to class-rest-api-license.php (unconditional); Woodev_REST_API_License_Command::boot() called from Woodev_Plugins_License::add_hooks() beside Woodev_REST_API_License::boot()"
  - "Existing woodev/v1 license routes and the registrar untouched; route string 'license-command' is a NEW frozen contract (s8-p6 pins it)"
---

# Task

Implement plan task **s8-p3** of `docs-internal/platform-v2-s3-licensing-webhooks-plan.md`
(spec: webhooks §3.1, D-W2 one shared endpoint — design locked).

The controller is deliberately dumb: every security decision lives in the s8-p2
dispatcher. Resist adding validation, sanitization, or capability checks here —
the signature is the authentication, and indistinguishable rejections are a §9.4
requirement.

TDD: unit tests first → fail → implement → pass; then the wp-env integration test
(`npx wp-env start` + `composer test:integration`).

## What NOT to change
- Dispatcher/nonce-store internals (s8-p2, frozen).
- `Woodev_REST_API_License` and the registrar.
- No pull-fallback (s8-p5), no deactivate handler (s8-p4) — integration test may use a
  registered stub command if p4 hasn't landed; coordinate with queue order (p4 lands
  after p3, so use the dispatcher's test-registry seam with a stub command named
  'deactivate_plugin' OR assert the unsupported_command path — pick the one that keeps
  the test meaningful and deterministic, and say which you chose).

## Gotchas to honor
- `testing/integration` wpenv-resolver-fixture-mapping (fixtures already mapped — reuse
  the existing wp-env setup); `framework/includes-wiring`; tabs/Yoda/types/`@since 2.0.0`.

## Verification
- `./vendor/bin/phpunit tests/unit/LicenseCommandRestTest.php`, integration suite, then
  full `composer check` green.
- Report: the exact HTTP status + body produced for each of the 13 outcome codes.
