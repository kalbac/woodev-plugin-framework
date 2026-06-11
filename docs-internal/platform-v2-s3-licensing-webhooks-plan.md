# S3.3 Built-in Webhooks + §4 Ed25519 Signing Implementation Plan

> **For agentic workers:** This plan is executed via the project **autodev-loop** (worker subagent writes the diff → adversarial critic = GPT-5.5 high reviews every contract-adjacent diff AND any in-place fix before commit, no self-certify → holistic critic pass at the end). Atomic task files derived from this plan live in `.autodev/queue/pending/s8-p0..p6`. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Implement server→client signed license commands (v1: `deactivate_plugin` only) over a shared `woodev/v1/license-command` REST endpoint + pull-fallback, AND the §4 Ed25519 signed-claim consumption (`is_license_required()` reads a verified server claim) with the B-3 keyless-updater rework.

**Architecture:** One shared Ed25519 envelope verifier (`Woodev_License_Envelope_Verifier` + `woodev_normalize_site()`) feeds two consumers: (a) the §4 claim store (`Woodev_License_Authority_Claims`) behind `is_license_required()`, and (b) the command dispatcher (`Woodev_License_Command_Dispatcher`) with an atomic per-nonce claim store, reached both via REST (fast path, registered through the existing `Woodev_REST_V1_Registrar`) and via `license_commands[]` arrays in weekly license-check / updater responses (pull-fallback). Acks ride the requests the client already makes.

**Tech Stack:** PHP 7.4+ (`sodium_crypto_sign_verify_detached`, bundled ≥7.2), WordPress REST API, Brain Monkey + Mockery unit tests, wp-env integration test, PHPCS (WPCS), PHPStan level 3.

**Specs:** `platform-v2-s3-licensing-webhooks-spec.md` (D-W1..D-W4 + §9 locked/resolved) + `platform-v2-s3-licensing-need-license-spec.md` §4 (claim primitive, B-3, B-6) + woodev-core server spec (`D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md`, implemented s126 — test vector is authoritative).

---

## §9 protocol resolutions (BLOCKING items — all 9 decided here, 2026-06-11)

### §9.1 Atomic nonce claim → per-nonce `add_option()` + processing/consumed state machine

- One option **per nonce**: name = `woodev_license_command_nonces_` . first-32-hex of `hash( 'sha256', $nonce )`, autoload `'no'`. (The §5 frozen string `woodev_license_command_nonces` survives as the **prefix**; §9.1 explicitly reopened the single-option store, which is read-modify-write-racy.)
- **Claim is atomic**: `add_option( $name, $record, '', 'no' )` — MySQL's UNIQUE index on `option_name` guarantees exactly one winner under concurrency (the loser's INSERT fails → `add_option()` returns `false` → reject `replayed`).
- Record schema (frozen in p6): `array( 's' => 'processing'|'consumed', 'c' => <claimed_at int>, 'e' => <retention expiry int>, 'r' => <terminal status string, when consumed> )`.
- Lifecycle: claim writes `s=processing` → execute → write ack record → `update_option( $name, [ 's'=>'consumed', 'r'=><status>, ... ] )`.
- **Crash recovery (claim-but-no-action):** an existing record with `s === 'processing'` and `time() - c > 300` (`STUCK_TAKEOVER_AFTER`) may be **taken over**: refresh `c`, re-execute (safe — the only v1 command is idempotent). A `processing` record younger than 300 s → reject `replayed` (in-flight).
- A `consumed` record → always reject `replayed` regardless of target state (spec §3.1).

### §9.2 Nonce store bounds

- Retention expiry `e = min( payload.expires_at, time() + MAX_TTL )` with `MAX_TTL = 14 * DAY_IN_SECONDS` — a huge signed `expires_at` cannot pin entries (client-enforced protocol maximum, §2.4). `mark_consumed()` preserves an existing row's `e`; when re-creating a row pruned mid-action it applies the same `min( payload.expires_at, now + MAX_TTL )` rule (the dispatcher passes `expires_at` through).
- Pruning: (a) **lazy at the claim step** — only when the live count is at/over cap: count → if `>= MAX_NONCE_ENTRIES` prune expired → recount → still over → `store_full`. Below cap, a claim attempt performs NO maintenance writes. *(Recorded exception to the zero-side-effect rejection rule: the at-cap `store_full` path may have deleted **expired** rows — it never creates or mutates live state; amended 2026-06-11 after the s8-p2 critic round.)* (b) scheduled — piggybacked on the **existing** `woodev_weekly_scheduled_events` cron (NO new cron hook = no new cron contract), static once-per-request guard. Prune = `$wpdb` SELECT names LIKE prefix (LIMIT 200) → decode → delete rows with `e < time()`.
- Cap: `MAX_NONCE_ENTRIES = 100` live entries — a **soft bound**: concurrent claims may overshoot by the concurrency factor (count-then-insert is not atomic; accepted and recorded). At cap after pruning → reject new commands `rate_limited` (retryable; the server redelivers later). Note: only authentically-signed envelopes ever reach the store (signature verified first), so the cap is a correctness bound, not an attacker-growth bound.
- **Takeover is best-effort, not atomic** (two requests can both pass the stale check and both re-execute): accepted because the v1 vocabulary is idempotent — recorded in code, and the command-registry contract REQUIRES idempotent commands (a future non-idempotent command must first replace the takeover rule). The atomic guarantee covers the fresh-claim path only (`add_option` unique-key).
- **Rate limiter shape:** transient `woodev_license_cmd_rl` stores `{ n, t0 }`; window expired (`now - t0 > 60`) → reset to `{1, now}`; else increment WITHOUT extending the window (no TTL-reset accumulation). Gate: `n > 30` within the window. Increments are lossy under concurrency — best-effort by design, recorded.

### §9.3 Shared-endpoint election

- The bootstrap loads exactly **one** framework copy per request (highest version wins), so exactly one `Woodev_REST_API_License_Command` class exists; its static `boot()` (same idempotent-guard pattern as S3.2) is called from `Woodev_Plugins_License::add_hooks()` and registers ONE controller instance through `Woodev_REST_V1_Registrar` (which dedupes by class and hooks `rest_api_init` once). No duplicate registration is possible within a request; pre-v2 framework copies do not know `woodev/v1` at all; the cross-request mixed-fleet window is closed by the B-1 tombstone gate (merged PR #27).
- Target registry = the existing `Woodev_Plugins_License::$registered_instances` keyed by `(string) get_download_id()`.
- **Duplicate download id is rejected loudly:** the constructor, on a colliding key from a *different* plugin id, keeps the FIRST registration, records the id in `self::$ambiguous_download_ids`, and logs one `error_log()` line. The command endpoint rejects commands targeting an ambiguous id with `unknown_plugin` (deterministic, no info leak).

### §9.4 Abuse controls on the public endpoint

Validation pipeline — **frozen order** (any failure → reject, zero side effects, indistinguishable body shape `{"status":"rejected","reason":"<code>"}`):

1. rate-limit gate: transient `woodev_license_cmd_rl` counts rejections in a 60 s window; count > 30 → `rate_limited` (429) without further work;
2. raw body length ≤ `MAX_BODY_BYTES = 8192` → else `malformed`;
3. `json_decode( $body, true, 8 )`; strict schema: top-level keys ⊆ `{payload, signature, kid}` (payload+signature required); payload keys exactly `{protocol, command, site, plugin_id, nonce, issued_at, expires_at}` + optional `args`; scalar caps: `command` ≤ 64, `site` ≤ 255, `plugin_id` ≤ 20 (string), `nonce` = exactly 32 lowercase hex, `issued_at`/`expires_at` int, `args` array ≤ 16 scalar entries ≤ 255 chars each, `kid` ≤ 64 → else `malformed`;
4. strict `base64_decode( $signature, true )`, decoded length === `SODIUM_CRYPTO_SIGN_BYTES` (64) → else `bad_signature`;
5. kid rule (B-5): `kid` absent OR `kid === <embedded-key kid>` → proceed with embedded key; any other `kid` → `bad_signature`. Embedded kid = first-16-hex of `hash( 'sha256', <raw 32-byte pubkey> )`;
6. `sodium_crypto_sign_verify_detached()` over canonical JSON — missing sodium extension OR placeholder/empty pubkey → `bad_signature` (safe default); **signature verifies BEFORE any site/plugin lookup**;
7. `payload.protocol !== 1` → `unsupported_protocol` — rejected with NO nonce consumption and NO ack (the claim step hasn't run yet); the mirror server spec must version deliveries (never send a future-protocol envelope to a client that polls as v1), and command expiry bounds the leftover-queue case. No hard fail, no false "done" ack (B-2 forward tolerance);
8. `woodev_normalize_site( payload.site ) === woodev_normalize_site( home_url() )` (either side FAIL → mismatch) → else `site_mismatch`;
9. plugin lookup via registry; absent or ambiguous → `unknown_plugin`;
10. time window: `is_int` both; `expires_at > issued_at`; `issued_at ≤ now + 300` (`CLOCK_SKEW`); `(expires_at - issued_at) ≤ MAX_TTL` → violations `invalid_window`; `now > expires_at` → `expired`;
11. atomic nonce claim (§9.1) → loser `replayed`; then command dispatch.

Increment the rate counter on every rejection at steps 2–6 (pre-authentication failures).

### §9.5 Ack lifecycle for the deactivated plugin

- Site-level pending-ack store: option `woodev_license_command_acks` (autoload `'no'`), array of ack records (schema §9.6), written by the dispatcher at terminal-outcome time in the same request as the action (order: claim → action → ack record → mark nonce consumed; a crash at any point is healed by §9.1 takeover + idempotent re-execution).
- **Both** delivery paths write the pending-ack record (the inbound HTTP response additionally acks synchronously; the duplicate is harmless — the server ignores nonces already cleared).
- ANY surviving framework client on the site drains the store: pending acks are attached as `consumed_command_nonces` to the next scheduled `check_license` request AND the updater `get_version` request. The server response field `acks_received: [ <nonce>, ... ]` confirms receipt → client deletes exactly those records.
- Bounds: `MAX_PENDING_ACKS = 50` (FIFO drop-oldest on overflow), retention ≤ `30 * DAY_IN_SECONDS` (dropped on next write/drain pass).
- Last-woodev-plugin-deactivated case: nobody polls → the ack may never be sent; **accepted** — delivery is best-effort by design (§3.2), the server-side queue expires commands at `expires_at` (mirror spec).

### Holistic-round rulings (s9 final critic pass, 2026-06-11)

- **Sealed command registry:** runtime command registration is REMOVED — the dispatcher's
  vocabulary is built internally (`deactivate_plugin` only) and there is no public
  `register_command()`. Future commands ship as code, never as runtime registration
  (an open registry would let any plugin replace the kill-switch handler or add
  `delete_plugin`, defeating D-W1 and the anti-pirate invariant). Tests inject via the
  reflection seam on the private registry.
- **Carrier scope enforced in code:** acks attach / pull commands consume ONLY on
  `check_license` (license API) and `get_version` (updater) — `activate_license` /
  `deactivate_license` dispatches carry nothing (D-W3 letter; the §5 table is the law).
- **Ack-store RMW race accepted + recorded:** concurrent distinct commands may overwrite
  each other's ack (single-option read-modify-write). Bounded consequence: the lost ack
  degrades to server-side queue-until-expiry redelivery (`replayed` rejections are
  non-terminal); no security or correctness loss. Atomic per-ack rows are deliberately
  NOT introduced in v1.

### §9.6 Structured acks

`consumed_command_nonces` (D-W3 field name kept) carries **structured entries**, not bare nonces:

```json
[ { "nonce": "<32-hex>", "status": "executed|already|unsupported_command|network_active_unsupported|failed", "terminal": true, "protocol": 1, "ts": 1749513600 } ]
```

`terminal: true` for every status except `failed` (execution threw — retryable; nonce stays `processing` → §9.1 takeover allows redelivery to retry). Server clears terminal items, retains retryable ones (mirror spec).

### §9.7 Ack authenticity

- Server-side rule (mirror spec): an ack is accepted for a queued (site, plugin) command only when the carrying request's normalized `url` matches the command's site binding; the license key authenticates additionally when present.
- Keyless case: keyless acks carry exactly the trust of keyless polling — **accepted with rationale**: an attacker who obtained a queued envelope for a site he controls can equally well block delivery at the network layer (firewall woodev.ru), and delivery is best-effort by design (§3.2). Ack forgery adds no capability beyond what site control already gives. Recorded as resolved.
- Client-side consequence: the updater request must carry `url` (it does not today) — added in s8-p0.

### §9.8 Contract freeze completeness → s8-p6

s8-p6 rewrites spec §5 into the full frozen table (envelope schema + types incl. `protocol: 1`; command vocabulary `deactivate_plugin`; rejection vocabulary + deterministic HTTP map below; kid derivation; nonce-store option prefix + record schema; constants `MAX_TTL=1209600`, `CLOCK_SKEW=300`, `STUCK_TAKEOVER_AFTER=300`, `MAX_BODY_BYTES=8192`, `MAX_PENDING_ACKS=50`, `MAX_NONCE_ENTRIES=100`, rate limit 30/60 s; ack schema; multisite rules; carriers of `license_commands`/`consumed_command_nonces`/`acks_received`; hook signature `do_action( "woodev_{plugin_id}_remote_deactivated", array $payload )`) and adds a parity test pinning every literal string.

**HTTP status map (deterministic, frozen):** `executed` 200 · `already` 200 · `malformed` 400 · `unsupported_protocol` 400 · `unsupported_command` 400 · `invalid_window` 400 · `bad_signature` 401 · `site_mismatch` 401 · `unknown_plugin` 404 · `network_active_unsupported` 409 · `expired` 410 · `replayed` 410 · `rate_limited` 429 · `failed` 500 (execution threw — the single 5xx; retryable, nonce stays `processing`).

**Nonce consumption rule:** consumed for every terminal outcome reached AFTER the atomic claim (pipeline step 11): `executed`, `already`, `unsupported_command`, `network_active_unsupported`. Every rejection BEFORE the claim step (`malformed`, `bad_signature`, `unsupported_protocol`, `site_mismatch`, `unknown_plugin`, `invalid_window`, `expired`, `rate_limited`) never touches the store; `replayed` is the claim failing itself; `failed` leaves the nonce in `processing` for the §9.1 takeover retry.

### §9.9 Test-matrix additions → distributed into tasks

Concurrency double-execution + crash-between-claim-and-action (p2); mixed-fleet window + duplicate download id (p2/p3); network-active multisite reject (p4); oversized/malformed body (p3); lost ack + redelivery (p5); notice deduplication (p4); hook-failure → ack semantics (p4/p5).

## Additional locked implementation decisions (§4 signing)

1. **Execution order is p1 → p0 → p2 → p3 → p4 → p5 → p6.** The mission ordered p0 first; p0's claim verification *consumes* the p1 verifier, so the pure-crypto p1 (no WP integration, no contract risk beyond the primitive itself) lands first. The mission's hard constraint — B-3 lands before pull-fallback p5 — is preserved.
2. **`url` request param stays RAW `home_url()`** in `check_license`/EDD dispatches (existing installed-site contract — changing the wire value could break EDD SL site matching for existing activations). The woodev-core server **already normalizes before signing** (server spec "the server signs the normalized value, never the raw input", implemented s126), so signed `payload.site` is normalized regardless. The framework-spec §4.2 sentence "the client normalizes the request url before sending" is **deliberately not implemented** — deviation recorded here + spec annotated in p6. Verify-side comparison normalizes both operands (double normalization is idempotent — tested).
3. **Claim option name** = `$plugin->get_plugin_option_name( 'license_required' )` → `woodev_{id_underscored}_license_required` (consistent with `woodev_{id}_license`/`_license_key`), autoload `'no'`, value = the raw verified envelope array (never a bare boolean).
4. **Pubkey constant** `WOODEV_LICENSE_AUTHORITY_PUBKEY` defined (`if ( ! defined() )`) in the verifier file with placeholder `''`. Empty/undecodable key → every verification fails → safe default (`license_required = true`, all commands rejected). A `test_production_pubkey_is_not_placeholder` test is `markTestSkipped()` until the operator captures the prod key (wp eval snippet in the woodev-core spec) — **ask the operator at session end, do not block**.
5. **Multisite claim owner:** consumption writes via plain `update_option()` = current-blog context only (no `switch_to_blog()`); the updater (network-level) thus refreshes the main site; other sites refresh through their own weekly checks. Per-site binding tested by asserting consumption never calls `switch_to_blog` and uses `update_option` (current site).
6. **`woodev_normalize_site()` is a guarded global function** (`if ( ! function_exists() )`) in new `woodev/functions-license-authority.php` — the spec/test-vector fix the *function* name cross-repo; the multi-version guard follows gotcha `bootstrap/multiversion-early-class-guards`. (Recorded exception to the "OOP only" convention.)
7. **Canonical JSON:** recursive `ksort( $arr, SORT_STRING )` + `wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )`. Locked by the published test vector (canonical bytes, length 120) — fixture seed `0x01 × 32`, pubkey `iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=`, signature `NPbp0Hce…b6DA==`.
8. **`is_license_required()` outage grace:** returns the last verified, unexpired claim's `license_required`; absent/invalid/expired claim → `true`. The 14-day window IS the grace (two weekly cycles); per-request memoization in a private property to avoid repeated sodium calls.
9. **B-3 `load_updater()`:** constructs `Woodev_Plugin_Updater` unconditionally within `is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI )`; the existing `do_action( 'woodev_plugin_updater', $license_key )` fires exactly as before (public hook contract, byte-for-byte). Updater `get_api_params()` gains `'url' => home_url()` (additive request field; raw per decision 2).
10. **Deactivation notice:** site-level option `woodev_license_remote_deactivation_notices` (autoload `'no'`), array keyed by target `get_id()` → `{ message, ts }`. EVERY surviving license instance's existing `notices()` callback renders all entries through its plugin's `Woodev_Admin_Notice_Handler` (message_id `woodev_{target_id}_remote_deactivated`, dismissible — per-user dismissal via the existing machinery) with a static per-request dedup guard (§9.9 notice dedup). Russian, count-neutral, no `_n()`.
11. **Sodium absence** (`! function_exists( 'sodium_crypto_sign_verify_detached' )`): claims unverifiable → `license_required = true`; commands → `bad_signature`. Never fatal.

## File Structure

| File | Responsibility | Change | Task |
|------|----------------|--------|------|
| `woodev/functions-license-authority.php` | `woodev_normalize_site()` | create | p1 |
| `woodev/licensing/class-license-envelope-verifier.php` | canonical JSON, Ed25519 verify, kid rule, pubkey constant | create | p1 |
| `woodev/licensing/class-license-authority-claims.php` | §4 claim store: consume-from-response, get-verified, option IO | create | p0 |
| `woodev/licensing/class-plugin-license.php` | `is_license_required()` reads claims; claim consumption in `validate_license()`/`activate()`; ambiguous-id registry; notice rendering in `notices()` | modify | p0, p2, p4 |
| `woodev/class-plugin.php` | `load_updater()` rework; `includes()` wiring per task | modify | p0…p5 |
| `woodev/plugin-updater/class-plugin-updater.php` | `url` param; claim + `license_commands` consumption; ack attach | modify | p0, p5 |
| `woodev/licensing/class-license-command-nonce-store.php` | atomic claim, state machine, prune, caps | create | p2 |
| `woodev/licensing/class-license-command-dispatcher.php` | §9.4 pipeline, vocabulary registry, execution, ack writes | create | p2 (+p4/p5) |
| `woodev/licensing/commands/interface-license-command.php` | command handler contract | create | p4 |
| `woodev/licensing/commands/class-license-command-deactivate-plugin.php` | D-W1 handler: deactivate + notice + hook + log | create | p4 |
| `woodev/licensing/class-license-command-acks.php` | pending-ack store (bounds, drain, `acks_received`) | create | p5 |
| `woodev/licensing/api/class-rest-api-license-command.php` | REST controller (boot via registrar; abuse gates; HTTP map) | create | p3 |
| `woodev/handlers/class-cron-handler.php` | weekly prune hook-in | modify | p2 |
| `tests/unit/NormalizeSiteTest.php` · `LicenseEnvelopeVerifierTest.php` | B-6 table + test vector | create | p1 |
| `tests/unit/LicenseAuthorityClaimsTest.php` · `UpdaterKeylessPollingTest.php` | §4 consumption, outage grace, B-3 regression | create | p0 |
| `tests/unit/LicenseCommandNonceStoreTest.php` · `LicenseCommandDispatcherTest.php` | §9.1/§9.2/§9.4 + §9.9 | create | p2 |
| `tests/unit/LicenseCommandRestTest.php` + `tests/integration/LicenseCommandEndpointTest.php` | endpoint election, abuse, HTTP map | create | p3 |
| `tests/unit/LicenseCommandDeactivateTest.php` | handler + notice + hook + multisite | create | p4 |
| `tests/unit/LicenseCommandAcksTest.php` + pull-path tests in dispatcher test | §9.5/§9.6/§9.7 client side | create | p5 |
| `tests/unit/LicenseCommandContractParityTest.php` | §9.8 string pinning | create | p6 |

## Contract guard-rails (every task re-checks)

**Preserved byte-for-byte:** option keys `woodev_{id}_license`/`woodev_{id}_license_key`/`woodev_{id}_beta_version`; EDD `edd_action` contract + params (`license`, `item_id`, `url` raw `home_url()`, `version`) + endpoint `https://woodev.ru/`; hooks `woodev_license_saved`/`woodev_license_deleted`/`woodev_enable_license_logging`/`woodev_plugin_updater` (arg = license key); cron `woodev_weekly_scheduled_events` (recurrence + payload untouched — pruning is an added listener, the hook itself unchanged); transient `woodev_extensions`; constant `WOODEV_LICENSE_DEBUG`; REST `woodev/v1` existing routes; updater cache option `woodev_{md5}`.

**New, frozen at implementation (p6):** route `woodev/v1/license-command` (POST); option prefix `woodev_license_command_nonces_`; options `woodev_license_command_acks`, `woodev_license_remote_deactivation_notices`, `woodev_{id}_license_required`; constant `WOODEV_LICENSE_AUTHORITY_PUBKEY`; hook `woodev_{plugin_id}_remote_deactivated` (1 arg: payload array); request fields `consumed_command_nonces`, response fields `license_commands`/`acks_received`/`license_authority`; status + reason vocabularies + HTTP map; all §9.8 constants; function `woodev_normalize_site()`.

**Anti-pirate invariant (gotcha `licensing/two-layer`):** commands act via `deactivate_plugins()` ONLY — never write license state; `is_license_valid()`/`is_active()` consult only `is_license_required()` (server-verified claim), never `is_need_license()`. Any verification failure → reject, zero side effects.

---

## Task s8-p1 (model: opus): Ed25519 envelope verifier + `woodev_normalize_site()`

**Files:** create `woodev/functions-license-authority.php`, `woodev/licensing/class-license-envelope-verifier.php`, `tests/unit/NormalizeSiteTest.php`, `tests/unit/LicenseEnvelopeVerifierTest.php`; modify `woodev/class-plugin.php` (`includes()` — require both BEFORE the licensing requires).

- [ ] **Step 1: failing tests — normalization table** (`NormalizeSiteTest`, pure function, no WP stubs beyond `untrailingslashit`):

```php
public function normalization_provider(): array {
    return [
        'scheme+host lowercased'   => [ 'HTTPS://Example.COM/', 'https://example.com' ],
        'default port dropped 443' => [ 'https://example.com:443/shop', 'https://example.com/shop' ],
        'default port dropped 80'  => [ 'http://example.com:80', 'http://example.com' ],
        'non-default port kept'    => [ 'https://example.com:8443/', 'https://example.com:8443' ],
        'path case preserved'      => [ 'https://example.com/Sub/Dir/', 'https://example.com/Sub/Dir' ],
        'query+fragment dropped'   => [ 'https://example.com/?a=1#f', 'https://example.com' ],
        'ipv6 brackets kept'       => [ 'https://[2001:DB8::1]/x', 'https://[2001:db8::1]/x' ],
        'idempotent'               => [ 'https://example.com', 'https://example.com' ],
    ];
}
// FAIL cases (return null): 'ftp://x.com', '//no-scheme.com', 'https://', 'https://user:p@x.com',
// 'not a url', "https://пример.рф/" (host bytes > 0x7F)
```

- [ ] **Step 2: failing tests — verifier** (`LicenseEnvelopeVerifierTest`): reproduce the **published test vector** — `sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) )` → assert pubkey b64 `iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=`; assert `canonical_json()` of the vector payload === the exact 120-byte canonical string; assert signature `NPbp0Hce…b6DA==` verifies; tampered payload (flip one byte) → null; wrong key → null; strict-b64 reject (`'!!!notb64'`); non-64-byte sig → null; kid mismatch → null; kid matching derived kid → passes; empty pubkey constant → null; sodium-absent path (`@runInSeparateProcess`, gotcha `testing/brain-monkey-function-pollution`) → null, no Error.
- [ ] **Step 3: run — expect FAIL** (`./vendor/bin/phpunit tests/unit/NormalizeSiteTest.php tests/unit/LicenseEnvelopeVerifierTest.php`).
- [ ] **Step 4: implement** `woodev_normalize_site( string $url ): ?string` exactly per spec §4.2 algorithm (steps 0–6; `parse_url` once; FAIL ⇒ `null`):

```php
if ( ! function_exists( 'woodev_normalize_site' ) ) {
    function woodev_normalize_site( string $url ): ?string {
        $parts = wp_parse_url( $url );
        if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) { return null; }
        $scheme = strtolower( $parts['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return null; }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) { return null; }
        $host = strtolower( $parts['host'] );
        if ( preg_match( '/[^\x00-\x7F]/', $host ) ) { return null; }
        $port = '';
        if ( isset( $parts['port'] ) && ! ( ( 'http' === $scheme && 80 === $parts['port'] ) || ( 'https' === $scheme && 443 === $parts['port'] ) ) ) {
            $port = ':' . $parts['port'];
        }
        $path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
        return $scheme . '://' . $host . $port . $path;
    }
}
```

(IPv6: `wp_parse_url` returns the host without brackets — re-add brackets when the host contains `:`.)

- [ ] **Step 5: implement `Woodev_License_Envelope_Verifier`** (class_exists-guarded): `const PUBKEY_CONSTANT = 'WOODEV_LICENSE_AUTHORITY_PUBKEY'` + `define`-if-missing placeholder `''`; `public static function canonical_json( array $payload ): string` (recursive `ksort SORT_STRING` + `wp_json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`); `public static function verify( array $envelope, ?string $public_key_b64 = null ): ?array` — schema sanity (payload array + signature string), strict b64 (sig 64 bytes, key 32 bytes), kid rule (first-16-hex sha256 of raw key), `function_exists( 'sodium_crypto_sign_verify_detached' )` guard, verify over canonical JSON → payload array or `null`. NO site/plugin/time semantics here (callers own those). `$public_key_b64` param defaults to the constant (tests inject the fixture key).
- [ ] **Step 6: wiring** — `require_once` both files in `includes()` immediately BEFORE the licensing block (`class-plugin.php` ~line 500); gotcha `framework/includes-wiring`.
- [ ] **Step 7: run tests → PASS; `composer check` green.**
- [ ] **Step 8: adversarial critic (contract zone: crypto primitive + test vector) → fix → re-critic fixes → commit** `feat(s3): Ed25519 envelope verifier + woodev_normalize_site (shared §4/webhooks primitive)`.

## Task s8-p0 (model: opus): §4 claim consumption + B-3 keyless updater

**Files:** create `woodev/licensing/class-license-authority-claims.php`, `tests/unit/LicenseAuthorityClaimsTest.php`, `tests/unit/UpdaterKeylessPollingTest.php`; modify `woodev/licensing/class-plugin-license.php` (`is_license_required()`, consumption in `validate_license()`/`activate()`), `woodev/class-plugin.php` (`load_updater()` + `includes()`), `woodev/plugin-updater/class-plugin-updater.php` (`get_api_params()` url + consumption hook-in), `tests/unit/LicenseRequiredSeamTest.php` (seam now reads claims).

- [ ] **Step 1: failing tests — claims** (`LicenseAuthorityClaimsTest`, fixture keypair from p1): consume-from-response stores the envelope under `woodev_test_plugin_license_required` ONLY after full §4.2 verification (sig + site + plugin_id `'216'` + unexpired); tampered/wrong-site/wrong-plugin/expired envelope → option untouched; `get_verified()` → payload when valid, `null` when absent/expired/tampered-at-rest; `is_license_required()` false ⇔ verified claim says `license_required === false`; expired stored claim → `true` (outage grace boundary: `now ≤ expires_at` honored, after → locked); response without `license_authority` key → no-op, stored claim KEPT (last-known-good within window); memoization (second call: zero sodium calls — assert via injected verifier spy or option-read count); double-normalization idempotence (stored normalized site vs raw `home_url('//Example.com/')`).
- [ ] **Step 2: failing tests — B-3** (`UpdaterKeylessPollingTest`): NO key + `is_admin()` true → `Woodev_Plugin_Updater` constructed; NO key + cron context (`wp_doing_cron` true, `is_admin` false) → constructed; ordinary frontend (neither) → NOT constructed; `do_action( 'woodev_plugin_updater', '' )` still fired with the key arg; `get_api_params()` includes `'url' => home_url()` and keeps every existing key byte-for-byte (`edd_action`, `license`, `item_id`, `version`, `slug`, `beta`, `php_version`, `wp_version`); updater response containing `license_authority` → claim consumed (verify store write).
- [ ] **Step 3: run — expect FAIL.**
- [ ] **Step 4: implement `Woodev_License_Authority_Claims`** — instance per license engine (`__construct( Woodev_Plugins_License $license, Woodev_Plugin $plugin )`):

```php
public function consume_from_response( $response_data ): void {
    $envelope = is_object( $response_data ) ? ( $response_data->license_authority ?? null ) : ( $response_data['license_authority'] ?? null );
    $envelope = is_object( $envelope ) ? json_decode( wp_json_encode( $envelope ), true ) : $envelope;
    if ( ! is_array( $envelope ) ) { return; }                       // absent → keep last-known-good
    if ( null === $this->verify_claim( $envelope ) ) { return; }     // invalid → keep last-known-good (safe)
    update_option( $this->plugin->get_plugin_option_name( 'license_required' ), $envelope, false );
    $this->memoized = null;
}
private function verify_claim( array $envelope ): ?array {
    $payload = Woodev_License_Envelope_Verifier::verify( $envelope );
    if ( null === $payload ) { return null; }
    $site = woodev_normalize_site( (string) ( $payload['site'] ?? '' ) );
    if ( null === $site || $site !== woodev_normalize_site( home_url() ) ) { return null; }
    if ( (string) ( $payload['plugin_id'] ?? '' ) !== (string) $this->plugin->get_download_id() ) { return null; }
    if ( ! isset( $payload['expires_at'] ) || ! is_int( $payload['expires_at'] ) || time() > $payload['expires_at'] ) { return null; }
    return $payload;
}
public function get_verified(): ?array { /* read option (array guard) → verify_claim() → memoize */ }
```

- [ ] **Step 5: `is_license_required()`** per spec §4.5: `$claim = $this->get_authority_claims()->get_verified(); return null === $claim ? true : (bool) ( $claim['license_required'] ?? true );` — keep the docblock's anti-pirate paragraph; `is_need_license()` still NEVER consulted.
- [ ] **Step 6: consumption hook-ins** — `validate_license()` + `activate()`: after a successful `dispatch()`, `$this->get_authority_claims()->consume_from_response( $license_data->get_response_data() )` (claims piggyback on responses already fetched; a `dispatch()` throw still bypasses consumption — outage grace §3.2 untouched). Updater `get_version_from_remote()`: after parsing `$response`, `$this->plugin->get_license_instance()->get_authority_claims()->consume_from_response( $response )`. No `switch_to_blog()` anywhere (decision 5).
- [ ] **Step 7: B-3 `load_updater()`:**

```php
public function load_updater() {
    if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return;
    }
    $license_key = $this->get_license_instance()->get_license();
    new Woodev_Plugin_Updater( $this ); // keyless polling is the §4 claim transport (B-3)
    do_action( 'woodev_plugin_updater', $license_key );
}
```

`includes()` already loads the updater file for `DOING_CRON` — verify, else align the gate there too.

- [ ] **Step 8: run tests (new + `LicenseRequiredSeamTest` + `LicenseOutageGraceTest`) → PASS; `composer check` green.**
- [ ] **Step 9: adversarial critic (contract zone: enforcement seam + updater identity) → fix → re-critic → commit** `feat(s3): is_license_required() consumes verified Ed25519 server claims; keyless updater polling (B-3)`.

## Task s8-p2 (model: opus): nonce store + command dispatcher

**Files:** create `woodev/licensing/class-license-command-nonce-store.php`, `woodev/licensing/class-license-command-dispatcher.php`, `tests/unit/LicenseCommandNonceStoreTest.php`, `tests/unit/LicenseCommandDispatcherTest.php`; modify `woodev/handlers/class-cron-handler.php` (weekly prune), `woodev/licensing/class-plugin-license.php` (ambiguous-id registry), `woodev/class-plugin.php` (`includes()`).

- [ ] **Step 1: failing tests — nonce store**: `claim()` true on fresh nonce (asserts `add_option` with prefix + autoload `'no'` + record `['s'=>'processing','c'=>time,'e'=>capped]`); `add_option` returns false (concurrent loser) → claim false (§9.9 concurrency double-execution); retention cap `e = min( expires_at, now + MAX_TTL )`; `mark_consumed()` updates `s`/`r`; consumed → `claim()` false always; processing younger than 300 s → false; processing older than 300 s → takeover true (refreshed `c`) (§9.9 crash-between-claim-and-action); prune deletes only `e < now` rows (wpdb spy); cap: 100 live entries → claim false with distinct `store_full` return (dispatcher maps → `rate_limited`).
- [ ] **Step 2: failing tests — dispatcher pipeline** (fixture-signed envelopes): each §9.4 step rejects with the exact reason + zero side effects (`add_option`/`deactivate_plugins`/`do_action` never called — silent-failure focus): oversized body, depth/schema/scalar-cap violations, protocol≠1, loose b64, kid mismatch, bad signature, site mismatch, unknown + **ambiguous** plugin id, skewed `issued_at`, TTL > max, expired, replay; happy path claims nonce then dispatches to a registered command stub exactly once; unknown command → `unsupported_command`, nonce consumed, **no action** (forward tolerance, B-2); pull-vs-inbound double delivery executes once (same nonce store).
- [ ] **Step 3: run — expect FAIL.**
- [ ] **Step 4: implement** `Woodev_License_Command_Nonce_Store` (constants `MAX_TTL = 14 * DAY_IN_SECONDS`, `STUCK_TAKEOVER_AFTER = 300`, `MAX_NONCE_ENTRIES = 100`, `OPTION_PREFIX = 'woodev_license_command_nonces_'`; methods `claim( string $nonce, int $expires_at ): string` returning `'claimed'|'replayed'|'store_full'`, `mark_consumed( string $nonce, string $status ): void`, `prune(): void`) and `Woodev_License_Command_Dispatcher` (static command registry `register_command( string $name, Woodev_License_Command $handler )` — interface lands p4, registry accepts callables until then; `handle_envelope( array $envelope, string $transport ): array` returning `[ 'status' => ..., 'reason' => ..., 'http' => int ]` per the frozen map; `handle_raw_body( string $body ): array` adding gates 1–3). Rate-limit transient `woodev_license_cmd_rl` (30/60 s). Vocabulary + reasons as class constants (single source for the p6 parity test).
- [ ] **Step 5: duplicate-download-id guard** in `Woodev_Plugins_License::__construct` per §9.3 (first wins, `$ambiguous_download_ids[]`, one `error_log`); static accessor `is_download_id_ambiguous( string $id ): bool`; dispatcher rejects ambiguous → `unknown_plugin`.
- [ ] **Step 6: weekly prune** — in `Woodev_Cron_Handler::add_hooks()` add `add_action( 'woodev_weekly_scheduled_events', array( __CLASS__or$this, 'prune_license_command_nonces' ) )` with a static once-guard (N plugins → one prune); callback delegates to the store. Existing cron hook name/recurrence untouched.
- [ ] **Step 7: wiring** (`includes()`, unconditional, after the licensing block) **+ run all tests → PASS; `composer check` green.**
- [ ] **Step 8: adversarial critic (contract zone: new options + pipeline) → fix → re-critic → commit** `feat(s3): atomic license-command nonce store + signed-command dispatcher (§9.1/§9.2/§9.4)`.

## Task s8-p3 (model: sonnet): REST `woodev/v1/license-command`

**Files:** create `woodev/licensing/api/class-rest-api-license-command.php`, `tests/unit/LicenseCommandRestTest.php`, `tests/integration/LicenseCommandEndpointTest.php`; modify `woodev/class-plugin.php` (`includes()`), `woodev/licensing/class-plugin-license.php` (`add_hooks()` boot call).

- [ ] **Step 1: failing unit tests**: `boot()` idempotent, registers ONE controller through `Woodev_REST_V1_Registrar` (election §9.3); route registered as POST `license-command` with `permission_callback => '__return_true'` (locked decision — auth IS the signature) and NO args schema (the dispatcher owns validation — core's args layer must not leak distinguishable errors); handler passes raw body to `Woodev_License_Command_Dispatcher::handle_raw_body()` and maps `[status, reason, http]` → `WP_REST_Response` exactly per the frozen HTTP table; rejection body is exactly `{status:"rejected", reason}` (no extra keys, no exception text); oversized body (8193 bytes) → 400 malformed (§9.9); garbage JSON → 400 malformed; response for tampered-sig vs unknown-plugin distinguishable ONLY by the fixed reason code (no internal details).
- [ ] **Step 2: failing integration test** (wp-env; endpoint is PUBLIC — `rest_cookie_check_errors` semantics do NOT apply here, see gotcha `testing/integration rest-cookie`; no nonce, no user): unauthenticated POST with a fixture-signed valid envelope (fixture pubkey injected via filter/constant override — define the production-constant fallback override order in the controller: filter `woodev_license_authority_pubkey` for tests) → 200 executed against the test plugin; replay → 410; tampered → 401.
- [ ] **Step 3: run — expect FAIL** (unit locally; integration via `npx wp-env start` + `composer test:integration`).
- [ ] **Step 4: implement controller** (pattern = `Woodev_REST_API_License`): static `boot()` guard → `Woodev_REST_V1_Registrar::register_controller( new self() )`; `register_routes()` → `register_rest_route( Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, '/license-command', [ 'methods' => 'POST', 'callback' => ..., 'permission_callback' => '__return_true' ] )`; callback = thin transport, all logic in the dispatcher.
- [ ] **Step 5: wiring** — `require_once` in `includes()` next to the license REST controller; `boot()` from `Woodev_Plugins_License::add_hooks()` beside `Woodev_REST_API_License::boot()`.
- [ ] **Step 6: run all tests + `composer check` green.**
- [ ] **Step 7: adversarial critic (contract zone: public route) → fix → re-critic → commit** `feat(s3): public woodev/v1/license-command endpoint (signature-authenticated, D-W2)`.

## Task s8-p4 (model: sonnet): `deactivate_plugin` command handler

**Files:** create `woodev/licensing/commands/interface-license-command.php`, `woodev/licensing/commands/class-license-command-deactivate-plugin.php`, `tests/unit/LicenseCommandDeactivateTest.php`; modify `woodev/licensing/class-license-command-dispatcher.php` (register the v1 vocabulary), `woodev/licensing/class-plugin-license.php` (`notices()` renders remote-deactivation notices), `woodev/class-plugin.php` (`includes()`).

- [ ] **Step 1: failing tests**: executes `deactivate_plugins( $plugin->get_plugin_file(), false, false )` once after `require_once ABSPATH . 'wp-admin/includes/plugin.php'` (stub `is_plugin_active` true) → `executed`; already-inactive → `already`, NO `deactivate_plugins` call, nonce still consumed; **multisite network-active** (`is_multisite` true + `is_plugin_active_for_network` true) → `network_active_unsupported`, NO deactivation (§9.9); notice option `woodev_license_remote_deactivation_notices` written (Russian count-neutral message, NO `_n()` — gotcha `i18n/russian-source-plural-n`); `do_action( "woodev_{$id}_remote_deactivated", $payload )` fired once with the payload array; one log line via the plugin's logger; `deactivate_plugins` throwing → status `failed` (retryable ack), nonce NOT consumed (stays processing → takeover retry §9.1), hook NOT fired (§9.9 hook-failure semantics: the hook fires only after successful deactivation); notice rendering: two license instances + one stored notice → rendered exactly once per request (static dedup, §9.9 notice dedup); dismissed per existing per-user machinery.
- [ ] **Step 2: run — expect FAIL.**
- [ ] **Step 3: implement** `Woodev_License_Command` interface (`get_name(): string; execute( Woodev_Plugins_License $target, array $payload ): string` returning an ack status) + `Woodev_License_Command_Deactivate_Plugin` per spec §4; dispatcher registers it as the ONLY v1 vocabulary entry (`delete_plugin` deliberately absent → `unsupported_command`, D-W1); `notices()` hook-in reading the site-level option.
- [ ] **Step 4: wiring + run all tests + `composer check` green.**
- [ ] **Step 5: adversarial critic (contract zone: hook + notice option + D-W1 power boundary) → fix → re-critic → commit** `feat(s3): remote deactivate_plugin command handler + persistent admin notice (D-W1)`.

## Task s8-p5 (model: sonnet): pull-fallback + structured acks

**Files:** create `woodev/licensing/class-license-command-acks.php`, `tests/unit/LicenseCommandAcksTest.php`; modify `woodev/licensing/class-license-command-dispatcher.php` (ack writes + `consume_pull_commands()`), `woodev/licensing/class-plugin-license.php` (`dispatch()` attaches acks + consumes `license_commands`), `woodev/plugin-updater/class-plugin-updater.php` (same for `get_version`), `woodev/class-plugin.php` (`includes()`), `tests/unit/LicenseCommandDispatcherTest.php` (pull-path additions).

- [ ] **Step 1: failing tests — ack store** (`LicenseCommandAcksTest`): `record()` appends the §9.6 schema entry (nonce/status/terminal/protocol/ts); terminal flag false ⇔ `failed`; FIFO cap 50; 30-day retention drop; `get_pending()` returns entries for attaching; `confirm_received( array $nonces )` deletes exactly those (lost-ack: unconfirmed entries SURVIVE for the next request — §9.9 lost ack + redelivery).
- [ ] **Step 2: failing tests — pull path + transport**: a `license_commands: [envelope, envelope]` array in a `check_license` response → each envelope through the FULL §9.4 pipeline (minus HTTP gates 1–2; schema gate still applies), executes once; same envelope inbound-then-pull → second is `replayed` (single execution); malformed entry in the array → skipped (rejected, no side effects), remaining entries still processed; request params: pending acks present → `consumed_command_nonces` (structured) added to the `check_license` params AND updater `get_api_params()`; no pending acks → field ABSENT (byte-for-byte existing request); response `acks_received: [nonces]` → confirmed; response without it → store untouched; dispatcher writes ack records for BOTH transports (inbound too, §9.5).
- [ ] **Step 3: run — expect FAIL.**
- [ ] **Step 4: implement** `Woodev_License_Command_Acks` (option `woodev_license_command_acks`, autoload `'no'`, constants `MAX_PENDING_ACKS = 50`, retention `30 * DAY_IN_SECONDS`) + dispatcher ack writes at terminal-outcome time (order: action → ack record → mark consumed) + `consume_pull_commands( $response_data, string $transport = 'pull' ): void` + transport hook-ins: in `Woodev_Plugins_License::dispatch()` add acks to `$api_params` pre-request and consume `license_commands` + `acks_received` post-response (inside the existing try — a transport throw changes nothing, §3.2); same pair in the updater around `make_request()`.
- [ ] **Step 5: run all tests + `composer check` green.**
- [ ] **Step 6: adversarial critic (contract zone: request/response fields on the EDD wire) → fix → re-critic → commit** `feat(s3): pull-fallback command delivery + durable structured acks (D-W3, §9.5–§9.7)`.

## Task s8-p6 (orchestrator): holistic critic + contract freeze

- [ ] **Step 1: contract parity test** (`tests/unit/LicenseCommandContractParityTest.php`) pinning every literal from "New, frozen at implementation": route string, option names/prefix, hook sprintf pattern, request/response field names, full reason vocabulary + HTTP map, all §9.8 constants' values, kid derivation, ack schema keys, `woodev_normalize_site` existence. Mutation-check one pin (change a constant locally → test fails → revert).
- [ ] **Step 2: spec §5 rewrite** — full frozen-contract table (§9.8 list) + annotate §2/§3 with the resolved details + record the two deliberate deviations: (a) execution order p1→p0; (b) raw `url` on the wire / server-side normalization (decision 2). Mark §9 items 1–9 RESOLVED with one-line pointers. Annotate need-license spec §4.2 sentence about client-side send normalization.
- [ ] **Step 3: production pubkey TODO test** — `test_production_pubkey_is_not_placeholder` (`markTestSkipped( 'Awaiting production WOODEV_LICENSE_AUTHORITY_PUBKEY capture — woodev-core spec wp-eval snippet' )`).
- [ ] **Step 4: mirror server spec** → `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-11-woodev-core-license-command-queue-spec.md` (§6: per-(site,plugin) queue, issuance trigger, signing with `License_Authority` key incl. `protocol`/`nonce`/TTL policy, `license_commands` attachment to the two response types, `consumed_command_nonces` ingestion + `acks_received`, ack-site-binding rule §9.7, terminal-vs-retryable clearing §9.6) + local commit in woodev_theme (no remote — same as s7).
- [ ] **Step 5: holistic GPT-5.5 critic pass** over the whole s8-p0…p5 diff (focus: anti-pirate invariant, zero-side-effect rejections, contract table vs code, §9.9 matrix coverage) → fix → **re-critic all fixes** (no self-certify) → commit(s) `feat(s3): freeze license-command contracts (§5) + holistic hardening`.
- [ ] **Step 6: tracker + docs** — `platform-v2-program-tracker.md` S3.3 status; new gotchas if discovered; delete `docs-internal/next-session-prompt.md`; PR to `main`; merge ONLY after green GH Actions AND operator decision.

---

## Self-review (against both specs)

- Webhooks §2 (envelope, 5 verification rules, forward tolerance) → p1 verifier + p2 pipeline steps 7–11 + unknown-command consume/ack. ✓
- §3.1 (route, core rest_api_init, `__return_true`, registrar, response shapes) → p3; §3.2 (pull, identical verification, once-only, acks on scheduled calls, B-3 dependency) → p5 after p0. ✓
- §4 command semantics (single target, plugin.php require, multisite reject, notice, hook, log, idempotent, never deletes) → p4. ✓
- §5 + §9.8 freeze → p6 parity test + spec rewrite. §6 server mirror → p6 step 4. §7 + §9.9 test matrix → distributed (mapped in §9.9 resolution). ✓
- §9.1–§9.7 → resolutions section, each implemented in its mapped task. ✓
- need-license §4.1 primitive once for both consumers → p1. §4.2 envelope/normalization/multisite/test-vector(+normalization case in p0 claim tests) → p1/p0. §4.3 transport + B-3 (1)(2)(3) → p0. §4.4 outage grace → p0 tests. §4.5 seam swap → p0 step 5. §5 contracts → guard-rails. ✓
- Anti-pirate hard rule (commands never flip license state; `is_license_valid`/`is_active` independent of `is_need_license`) → guard-rails + p2/p4 zero-side-effect tests + existing `LicenseRequiredSeamTest`. ✓
- Gotchas honored: includes-wiring (every task wires), two-layer (guard-rails), unit ×2 (`@runInSeparateProcess` p1, reflection guards in registry tests), rest-cookie (p3 integration note: N/A — public route), russian-plural (p4), pr-conflict-skips-ci (final PR check `gh pr view --json mergeable,mergeStateStatus`). ✓
- Type consistency: `claim()` returns `'claimed'|'replayed'|'store_full'` (p2 def, p2/p4/p5 use); `handle_envelope(): array{status,reason,http}` (p2 def, p3/p5 use); `Woodev_License_Command::execute(): string` ack status (p4). ✓

## Related
- Specs: [platform-v2-s3-licensing-webhooks-spec.md](platform-v2-s3-licensing-webhooks-spec.md), [platform-v2-s3-licensing-need-license-spec.md](platform-v2-s3-licensing-need-license-spec.md)
- Tracker: [platform-v2-program-tracker.md](platform-v2-program-tracker.md) · Protocol: [platform-v2-execution-protocol.md](platform-v2-execution-protocol.md)
- Autodev tasks: `.autodev/queue/pending/s8-p0…p6`
