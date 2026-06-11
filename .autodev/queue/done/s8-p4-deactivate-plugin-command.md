---
id: s8-p4-deactivate-plugin-command
title: deactivate_plugin command handler + persistent admin notice + hook + log (D-W1, multisite reject)
phase: S3.3 webhooks — v1 command (webhooks-spec §4, plan task s8-p4)
type: feature
model: sonnet
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/licensing/commands/interface-license-command.php
  - woodev/licensing/commands/class-license-command-deactivate-plugin.php
  - woodev/licensing/class-license-command-dispatcher.php
  - woodev/licensing/class-plugin-license.php
  - woodev/class-plugin.php
  - tests/unit/LicenseCommandDeactivateTest.php
depends_on: [ s8-p2-nonce-store-command-dispatcher ]
contract_zones_touched: [ public_hook, new_options, command_power_boundary ]
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0, all unit tests pass)
  - "NEW interface Woodev_License_Command (woodev/licensing/commands/interface-license-command.php): get_name(): string; execute( Woodev_Plugins_License $target, array $payload ): string (returns an ack status). Dispatcher's register_command() now type-hints/accepts it (keep the test-callable seam if present); the v1 vocabulary registers EXACTLY ONE command: deactivate_plugin. delete_plugin is DELIBERATELY absent → dispatcher answers unsupported_command (D-W1 — never deletes files; add an explicit test pinning this)"
  - "NEW Woodev_License_Command_Deactivate_Plugin per webhooks-spec §4: require_once ABSPATH . 'wp-admin/includes/plugin.php' before any plugin-API call (NOT guaranteed loaded in REST context); MULTISITE: is_multisite() && is_plugin_active_for_network( file ) → return 'network_active_unsupported', NO deactivation (distinct terminal ack — never pretend); already inactive (! is_plugin_active( file )) → 'already', NO deactivate_plugins call; active → deactivate_plugins( $target->get_plugin()->get_plugin_file(), false, false ) — single target only, $silent=false, $network_wide=false — then notice + hook + log → 'executed'"
  - "Persistent notice (plan decision 10): site-level option woodev_license_remote_deactivation_notices (autoload 'no'), array keyed by target get_id() → { message, ts }; message is Russian, count-neutral, NO _n() (gotcha i18n/russian-source-plural-n), names the plugin and says the license expired and Woodev remotely deactivated it; written on 'executed' only"
  - "Notice rendering: Woodev_Plugins_License::notices() (existing admin_notices callback) additionally reads woodev_license_remote_deactivation_notices and renders EVERY entry through this plugin's Woodev_Admin_Notice_Handler as a dismissible notice with message_id woodev_{target_id}_remote_deactivated; STATIC per-request dedup guard so N surviving license instances render each entry exactly once (§9.9 notice deduplication test: two instances + one stored notice → rendered once); per-user dismissal via the existing dismissible machinery (no new dismiss transport)"
  - "Hook: do_action( \"woodev_{$target_id}_remote_deactivated\", array $payload ) fired EXACTLY ONCE, only on successful deactivation, AFTER deactivate_plugins returns ($target_id = get_id()); signature = 1 arg, the verified payload array — this is a NEW FROZEN PUBLIC CONTRACT (s8-p6 pins it)"
  - "Log: one line via the plugin's existing logger seam (locate the framework's log method on Woodev_Plugin — e.g. log()/get_logger(); verify the real name in source, do not invent) on 'executed'"
  - "Failure semantics (§9.9 hook-failure → ack): deactivate_plugins (or anything before it) throwing → execute() lets the dispatcher catch → ack status 'failed' (retryable), nonce NOT consumed (stays processing → §9.1 takeover retry), hook NOT fired, notice NOT written; dispatcher wires this: Throwable from a handler → mark nothing consumed, result {status:'rejected', reason:'failed', http: 500}? NO — frozen map has no failed HTTP entry: inbound failed → http 500 with {status:'rejected', reason:'failed'} — ADD this to the dispatcher map as the single 5xx (and s8-p6 freezes it); pull path records the §9.6 failed ack"
  - "Idempotency test: same envelope delivered twice (fresh nonce each) against an already-inactive target → 'already' twice, deactivate_plugins never called; nonce consumed both times"
  - "Wiring: interface + class require_once'd in includes() (interface before implementation); dispatcher's default vocabulary registration happens once (static guard) at dispatcher boot/first use"
---

# Task

Implement plan task **s8-p4** of `docs-internal/platform-v2-s3-licensing-webhooks-plan.md`
(spec: webhooks §4, D-W1 deactivate-only — design locked).

The power boundary is the headline: this command deactivates ONE plugin, never deletes,
never touches license state (anti-pirate invariant — `is_license_valid()`/`is_active()`
unaffected; assert no license option is written on any path).

Note the dispatcher gains one addition beyond registration: the handler-Throwable →
`failed` (500, retryable, nonce stays processing) path described above. Keep it minimal.

TDD: failing tests first, run-fail, implement, run-pass.

## What NOT to change
- Verification pipeline order / rejection vocabulary (s8-p2, frozen — `failed` is the
  only addition, and only as an execution outcome, not a verification rejection).
- Nonce store internals.
- No ack-store/pull work (s8-p5).

## Gotchas to honor
- `i18n/russian-source-plural-n`; `framework/includes-wiring`;
  `licensing/two-layer` (no license-state writes anywhere in this task);
  tabs/Yoda/types/`@since 2.0.0`; `@internal` on hook callbacks.

## Verification
- `./vendor/bin/phpunit tests/unit/LicenseCommandDeactivateTest.php tests/unit/LicenseCommandDispatcherTest.php`
  then full `composer check` green.
- Report: every persistent write performed on the 'executed' path (must be exactly:
  nonce consumed-record, notices option, plus s8-p5's ack later) and confirm zero
  writes on every rejection path.
