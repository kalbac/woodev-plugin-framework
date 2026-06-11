---
id: s8-p5-pull-fallback-structured-acks
title: Pull-fallback command delivery + durable structured acks (D-W3, §9.5/§9.6/§9.7)
phase: S3.3 webhooks — durable delivery (webhooks-spec §3.2 + §9.5-§9.7, plan task s8-p5)
type: feature
model: sonnet
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/licensing/class-license-command-acks.php
  - woodev/licensing/class-license-command-dispatcher.php
  - woodev/licensing/class-plugin-license.php
  - woodev/plugin-updater/class-plugin-updater.php
  - woodev/class-plugin.php
  - tests/unit/LicenseCommandAcksTest.php
  - tests/unit/LicenseCommandDispatcherTest.php
depends_on: [ s8-p0-claim-consumption-keyless-updater, s8-p2-nonce-store-command-dispatcher, s8-p4-deactivate-plugin-command ]
contract_zones_touched: [ edd_request_params, new_options, ack_protocol ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "NEW Woodev_License_Command_Acks (site-level store): option woodev_license_command_acks, autoload 'no'; constants MAX_PENDING_ACKS = 50, retention 30 * DAY_IN_SECONDS; record( string $nonce, string $status ): void appends the §9.6 schema entry { nonce, status, terminal, protocol: 1, ts } with terminal = ( 'failed' !== $status ); FIFO drop-oldest beyond 50; expired-by-retention entries dropped on every write AND every get_pending(); get_pending(): array; confirm_received( array $nonces ): void deletes exactly the named entries (others SURVIVE — §9.9 lost ack + redelivery)"
  - "Dispatcher writes an ack record for EVERY terminal outcome on BOTH transports (inbound too — the synchronous HTTP response additionally acks, the duplicate is harmless §9.5) in the frozen order: action → ack record → mark nonce consumed; 'failed' outcome records the retryable ack and leaves the nonce processing"
  - "Pull consumption: dispatcher consume_pull_commands( $response_data, string $transport = 'pull' ): void — extracts top-level license_commands (array of envelopes; object-shape tolerated via json_decode(wp_json_encode())); each envelope goes through the FULL verification pipeline minus HTTP gates 1-2 (schema gate 3 onward applies identically); a malformed/rejected entry is SKIPPED with zero side effects and remaining entries still process; same nonce inbound-then-pull executes ONCE (second → replayed)"
  - "Transport hook-ins (BYTE-FOR-BYTE caution — this is the EDD wire): Woodev_Plugins_License::dispatch(): before make_request, IF get_pending() non-empty add 'consumed_command_nonces' => <structured entries> to $api_params (field ABSENT when no pending acks — existing request shape byte-for-byte); after a successful response, consume license_commands + confirm acks via response 'acks_received' (array of nonces) — all inside the existing try so a transport throw changes nothing (outage grace §3.2); the SAME pair around the updater's make_request in get_version_from_remote()/get_api_params() (pending acks added next to the url param from s8-p0)"
  - "Ack authenticity client side (§9.7 resolution): nothing extra to send — the requests already carry url (raw home_url) + license key when present; the server-side binding rule lives in the mirror spec. Add ONE doc-comment on the dispatch hook-in recording this"
  - "Tests: ack schema entry shape pinned (keys + terminal flag per status incl. failed=false); FIFO cap 50; 30-day retention drop; confirm_received removes only named; lost-ack redelivery (pending survives a response without acks_received, attaches again on next request); pull array with [valid, tampered, valid] → 2 executed 1 skipped; double delivery once; check_license params WITHOUT the field when store empty and WITH structured entries when pending; updater params likewise; response acks_received clears exactly those; dispatch() throw → store untouched"
  - "Wiring: require_once in includes() within the licensing block"
---

# Task

Implement plan task **s8-p5** of `docs-internal/platform-v2-s3-licensing-webhooks-plan.md`
(spec: webhooks §3.2 + §9.5–§9.7 resolutions in the plan — design locked).

The §9.5 "hard one" is solved structurally: acks are SITE-level, so any surviving
woodev plugin drains the deactivated one's ack on its next scheduled call. Keep the
store dumb and the dispatcher the only writer.

The EDD request params are an installed-site contract: the new field appears ONLY
when there is something to send. Diff the assembled `$api_params` in tests against
the exact pre-change shape for the empty case.

TDD: failing tests first, run-fail, implement, run-pass.

## What NOT to change
- Verification pipeline, nonce store, deactivate handler internals (frozen).
- The `url` param value (stays raw `home_url()`, s8-p0 decision).
- `weekly_license_check()` flow (the empty-key early return stays — keyless pull rides
  the updater path, which is exactly why B-3 landed first).

## Gotchas to honor
- `framework/includes-wiring`; `testing/brain-monkey-function-pollution`;
  tabs/Yoda/types/`@since 2.0.0`.

## Verification
- `./vendor/bin/phpunit tests/unit/LicenseCommandAcksTest.php tests/unit/LicenseCommandDispatcherTest.php`
  then full `composer check` green.
- Report: the exact `$api_params` array produced for (a) no pending acks (must equal the
  pre-change shape byte-for-byte) and (b) one pending ack.
