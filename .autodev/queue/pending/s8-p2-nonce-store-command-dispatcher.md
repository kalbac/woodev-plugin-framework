---
id: s8-p2-nonce-store-command-dispatcher
title: Atomic nonce claim store + signed-command dispatcher (§9.1/§9.2/§9.4 pipeline, forward tolerance)
phase: S3.3 webhooks — command core (webhooks-spec §2 + §9.1/§9.2/§9.4, plan task s8-p2)
type: feature
model: opus
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/licensing/class-license-command-nonce-store.php
  - woodev/licensing/class-license-command-dispatcher.php
  - woodev/licensing/class-plugin-license.php
  - woodev/handlers/class-cron-handler.php
  - woodev/class-plugin.php
  - tests/unit/LicenseCommandNonceStoreTest.php
  - tests/unit/LicenseCommandDispatcherTest.php
depends_on: [ s8-p1-envelope-verifier-normalize-site, s8-p0-claim-consumption-keyless-updater ]
contract_zones_touched: [ new_options, command_protocol ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "NEW Woodev_License_Command_Nonce_Store: constants MAX_TTL = 14 * DAY_IN_SECONDS, STUCK_TAKEOVER_AFTER = 300, MAX_NONCE_ENTRIES = 100, OPTION_PREFIX = 'woodev_license_command_nonces_'; option name = PREFIX . first-32-hex of hash('sha256', $nonce), autoload 'no'; claim( string $nonce, int $expires_at ): string returns 'claimed'|'replayed'|'store_full'; CLAIM IS ATOMIC via add_option() (UNIQUE option_name index — concurrent loser's add_option returns false → 'replayed'); record = ['s'=>'processing','c'=>time(),'e'=>min($expires_at, time()+MAX_TTL)]; existing record: s=consumed → 'replayed' always; s=processing and time()-c <= 300 → 'replayed' (in-flight); s=processing and time()-c > 300 → TAKEOVER: refresh c via update_option, return 'claimed' (crash recovery §9.1 — v1 command is idempotent); mark_consumed( string $nonce, string $status ): void → ['s'=>'consumed','r'=>$status,...]; prune(): wpdb prepared SELECT option_name/option_value LIKE prefix LIMIT 200 → delete rows whose decoded e < time(); cap: >= MAX_NONCE_ENTRIES live entries after prune → 'store_full'"
  - "NEW Woodev_License_Command_Dispatcher implementing the §9.4 pipeline in the plan's FROZEN order (1 rate-limit transient woodev_license_cmd_rl 30-rejections/60s → rate_limited; 2 body <= MAX_BODY_BYTES 8192 → malformed; 3 json_decode assoc depth 8 + strict schema: top keys ⊆ {payload,signature,kid}, payload keys exactly {protocol,command,site,plugin_id,nonce,issued_at,expires_at} + optional args, scalar caps command<=64 site<=255 plugin_id<=20 nonce=32-lowercase-hex issued/expires int args<=16 scalar entries<=255 kid<=64 → malformed; 4 strict b64 sig 64 bytes → bad_signature; 5 kid rule → bad_signature; 6 Ed25519 verify via Woodev_License_Envelope_Verifier (sodium/pubkey missing → bad_signature) BEFORE any site/plugin lookup; 7 protocol !== 1 → unsupported_protocol (NO consumption, NO ack); 8 normalize-site match → site_mismatch; 9 registry lookup, absent OR ambiguous → unknown_plugin; 10 expires_at>issued_at, issued_at<=now+300, TTL<=MAX_TTL → invalid_window, now>expires_at → expired; 11 atomic claim → replayed|rate_limited(store_full)). Rate counter incremented on rejections at steps 2-6 only"
  - "handle_envelope( array $envelope, string $transport ): array{status,reason?,http} per the frozen HTTP map (executed 200, already 200, malformed/unsupported_protocol/unsupported_command/invalid_window 400, bad_signature/site_mismatch 401, unknown_plugin 404, network_active_unsupported 409, expired/replayed 410, rate_limited 429); handle_raw_body( string $body ): array adds gates 1-3; rejection result carries ONLY {status:'rejected', reason} — no internals, no exception text"
  - "Command registry: register_command( string $name, $handler ) (callable for now; s8-p4 lands the interface) + reset for tests; vocabulary lookup AFTER successful claim; unknown command → mark_consumed(nonce,'unsupported_command'), NO action executed, result unsupported_command (forward tolerance B-2: never hard-fail, never ack-as-done)"
  - "ZERO side effects on every rejection path: no add_option/update_option (except rate transient), no command handler call, no do_action — every rejection test asserts this explicitly (silent-failure focus)"
  - "Duplicate-download-id guard (§9.3): Woodev_Plugins_License::__construct keeps FIRST registration on collision from a different plugin id, records into static $ambiguous_download_ids, one error_log line; static is_download_id_ambiguous( string $id ): bool; dispatcher rejects ambiguous ids as unknown_plugin (test: two stub plugins, same download id → second register flagged, command → unknown_plugin)"
  - "Weekly prune: Woodev_Cron_Handler::add_hooks() adds a listener on the EXISTING woodev_weekly_scheduled_events hook (name/recurrence/payload byte-for-byte untouched) with a static once-per-request guard so N plugin instances prune once"
  - "§9.9 tests included: concurrency double-execution (add_option false → replayed, handler called exactly once across two deliveries); crash-between-claim-and-action (processing+stale → takeover claims and re-executes); pull-vs-inbound double delivery executes once; oversized body; every schema violation class; skewed issued_at; TTL over max; expired; replay"
  - "Wiring: both new files require_once'd in includes() after the licensing block; class_exists guards"
---

# Task

Implement plan task **s8-p2** of `docs-internal/platform-v2-s3-licensing-webhooks-plan.md`
(spec: webhooks §2 + §9.1/§9.2/§9.4 — all protocol decisions RESOLVED in the plan's
"§9 protocol resolutions" section; implement exactly those, do not re-decide).

The dispatcher is transport-neutral: s8-p3 (REST) and s8-p5 (pull) both feed it.
Envelopes are signed with the s8-p1 fixture keypair in tests; inject the fixture
pubkey the same way s8-p0 did (param/filter — follow its pattern).

Time handling: pass `time()` through one overridable seam (e.g. protected `now()` or
injected clock) so window/skew/stuck tests don't sleep.

TDD: failing tests first, run-fail, implement, run-pass.

## What NOT to change
- `Woodev_License_Envelope_Verifier` / `woodev_normalize_site()` (s8-p1, frozen).
- The claims store / `is_license_required()` (s8-p0).
- No REST endpoint (s8-p3), no deactivate handler (s8-p4), no acks/pull transport (s8-p5).
- `woodev_weekly_scheduled_events` hook identity.

## Gotchas to honor
- `framework/includes-wiring`; `testing/reflection-setaccessible-version-guard` (static
  registry resets); `testing/brain-monkey-function-pollution`; Yoda, tabs, type decls,
  `@since 2.0.0`.

## Verification
- `./vendor/bin/phpunit tests/unit/LicenseCommandNonceStoreTest.php tests/unit/LicenseCommandDispatcherTest.php`
  then full `composer check` green.
- Report: the list of rejection reasons that can touch ANY persistent state (must be
  empty except the rate transient and the claim/consume writes listed above).
