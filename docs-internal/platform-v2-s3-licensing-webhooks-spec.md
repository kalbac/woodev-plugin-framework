# Platform v2 — S3.3 Licensing: built-in webhooks (spec — IMPLEMENTED)

> Written 2026-06-11 (s7, Fable orchestrator + operator fork decisions). Implements PLANS
> §3.4.1. **Status: IMPLEMENTED (s9, 2026-06-11) — every §9 item was resolved before its
> code landed; the resolutions are recorded in
> `platform-v2-s3-licensing-webhooks-plan.md` → "§9 protocol resolutions" and the §5
> table below is the FROZEN contract set, pinned by
> `tests/unit/LicenseCommandContractParityTest.php`.** Two recorded deviations from the
> draft text: (1) execution order p1→p0 (the verifier precedes its consumers); (2) the
> client sends the RAW `home_url()` as the `url` request param — the woodev-core server
> normalizes before signing (already implemented s126), so signed `payload.site` is
> normalized regardless; client-side *send* normalization was deliberately not
> implemented to keep the EDD wire byte-for-byte (plan "Additional locked implementation
> decisions" #2).
>
> **Operator decisions on record (2026-06-11):** (D-W1) v1 command power = **deactivate
> only** — file deletion is a separate future decision; (D-W2) **one shared
> framework-owned endpoint** under `woodev/v1`, target plugin selected by signed
> `plugin_id`; (D-W3) **pull-fallback ships in v1** (commands also delivered via the
> weekly license-check / update polling); (D-W4) command envelope is **extensible**, but
> S3.3 implements only license commands — `woodev_support` diagnostics deferred.

## 1. Problem & intent (PLANS §3.4.1)

Two server→client scenarios; v1 implements the first:

1. **Expired license, user ignores renewal notices** → Woodev can remotely **deactivate**
   the plugin on the customer site. Safety requirement: *only Woodev* can trigger it.
2. **Diagnostics for `woodev_support` AI agents** (deferred, D-W4): read-only site/plugin
   diagnostics without wp-admin access. The envelope's `command` vocabulary covers it;
   no v1 implementation.

## 2. Security model — signed command envelope

Reuses the §4 primitive of `platform-v2-s3-licensing-need-license-spec.md` **unchanged**:
Ed25519 (`sodium_crypto_sign_verify_detached`), the embedded production public key, the
canonical-JSON rule (ksort recursive, `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`),
and `woodev_normalize_site()` (§4.2, B-6) for site binding.

```json
{
  "payload": {
    "command": "deactivate_plugin",
    "site": "https://example.com",
    "plugin_id": "216",
    "nonce": "<128-bit random, hex>",
    "issued_at": 1749513600,
    "expires_at": 1749600000,
    "args": {}
  },
  "signature": "base64(ed25519(canonical_json(payload)))"
}
```

Verification — ALL must pass, any failure → reject, никаких side effects:

1. signature verifies against the embedded public key over canonical JSON of `payload`;
2. `woodev_normalize_site( payload.site ) === woodev_normalize_site( home_url() )`;
3. `payload.plugin_id === (string) $plugin->get_download_id()` — a **known installed**
   plugin with that download id must exist (otherwise reject `unknown_plugin`); the
   target's *active state* is NOT a verification concern — an inactive target is handled
   by the command (e.g. `deactivate_plugin` → `{status:"already"}`, nonce consumed);
4. **expiry + one signed lifetime** for both delivery paths: reject when
   `now > payload.expires_at`; `issued_at` and `expires_at` are integers,
   `expires_at > issued_at`, `issued_at` at most **5 minutes** in the future (clock-skew
   allowance), TTL at most the protocol maximum (a client-enforced constant frozen by
   §9.8; recommendation: long enough to cover at least two weekly pull cycles, ~14 days
   — the same envelope must remain deliverable by pull); commands needing urgency rely
   on the inbound fast path, not on a short TTL;
5. **anti-replay:** `payload.nonce` not in the consumed-nonce store; on success the nonce
   is recorded until its `expires_at` passes (store: one option
   `woodev_license_command_nonces`, autoload `false`, pruned on write).

Unknown `command` string → **reject with `unsupported_command` ack, take no action**
(forward tolerance — the B-2 lesson: older framework copies must never hard-fail on
vocabulary added later; but they also must never blindly "ack as done").

## 3. Transport

### 3.1 Inbound (fast path) — one shared REST endpoint (D-W2)

- `POST /wp-json/woodev/v1/license-command` — registered on **core `rest_api_init`**
  (not WC-gated), `permission_callback => '__return_true'` (auth IS the signature —
  there is no WP user in this flow), body = the envelope.
- Registered **once per site** by the version-arbitrated framework copy (same
  highest-version-wins discipline as the rest of `woodev/v1`; follow the S3.2
  registration pattern). The endpoint routes to the target plugin by `plugin_id`.
- Responses: `200 {status:"executed"}` / `200 {status:"already"}` (a **fresh** command —
  new nonce — targeting an already-inactive plugin; a replayed/consumed nonce is always
  `rejected`, per §2.5) / `400/401/404/410 {status:"rejected", reason:...}` (exact
  deterministic mapping frozen by §9.8; rejection reasons are a fixed vocabulary, no
  internal details).

### 3.2 Pull-fallback (durable best-effort delivery, D-W3)

Firewalled/local sites never receive inbound calls. The server therefore also queues
undelivered commands per (site, plugin); the client consumes them from responses it
already fetches on schedule. Delivery is **best-effort, not guaranteed**: it depends on
WP-Cron actually firing (traffic-driven), the site staying reachable to the license API,
and the command's signed lifetime outlasting the polling cadence — a site that never
polls again never receives the command:

- the weekly `check_license` response and the updater's `plugin_latest_version` response
  may carry a top-level `license_commands: [ <envelope>, ... ]` array;
- each envelope passes the **identical** §2 verification (incl. nonce store — a command
  delivered both inbound and via pull executes once);
- after processing, the client acks by sending the consumed nonces on its next scheduled
  call (`consumed_command_nonces` request field) so the server can clear its queue —
  no extra request is introduced.
- ⚠️ Depends on the **B-3 fix** (keyless updater polling) landing with §4 — until then
  pull delivery for keyless plugins has no transport; sequencing: §4 task first.

## 4. v1 command — `deactivate_plugin` (D-W1)

- Executes `deactivate_plugins( $plugin->get_plugin_file(), false, false )` for the
  **single target plugin only**, after `require_once ABSPATH . 'wp-admin/includes/plugin.php'`
  (NOT guaranteed loaded in REST context). Multisite: a **network-activated** target
  cannot be deactivated per-site — v1 **rejects** that case with a distinct terminal ack
  (`network_active_unsupported`) instead of pretending; per-site-activated targets
  deactivate on the claim-bound site only (mirrors the per-site claim rule of §4.2).
- Persists a dismissible admin notice ("плагин отключён: лицензия истекла…", Russian
  i18n) via the existing admin-notice machinery so the site admin learns why.
- Fires `woodev_{plugin_id}_remote_deactivated` action (audit/extensibility) and writes
  one line to the plugin's log source.
- Idempotent: already-inactive target → `{status:"already"}`, nonce still consumed.
- **Never deletes files** (D-W1). `delete_plugin` is NOT in the v1 vocabulary; if the
  server sends it, the client answers `unsupported_command`.

## 5. New installed-site contracts — FROZEN at implementation (s9, 2026-06-11)

Pinned by `tests/unit/LicenseCommandContractParityTest.php` (+ behavioral pins in the
verifier/dispatcher/handler suites). Byte-for-byte; changes require an operator-approved
protocol-version bump.

| Contract | Frozen value |
|---|---|
| REST route | POST `woodev/v1/license-command`, `permission_callback => '__return_true'` (auth IS the signature) |
| Command envelope payload | exact keys `{protocol, command, site, plugin_id, nonce, issued_at, expires_at}` + optional `args`; `protocol = 1` (int); `nonce` = 32 lowercase hex; `issued_at`/`expires_at` int; scalar caps command ≤ 64, site ≤ 255, plugin_id ≤ 20, kid ≤ 64, args ≤ 16 scalar entries ≤ 255 chars |
| Envelope top level | `{payload, signature}` + optional `kid`; signature = strict base64 of 64-byte Ed25519 over canonical JSON (recursive `ksort SORT_STRING`, `JSON_UNESCAPED_SLASHES\|UNICODE`) |
| kid rule (B-5) | absent OR first-16-hex of `sha256(raw 32-byte pubkey)`; any other present value → `bad_signature` |
| Command vocabulary (v1) | exactly `deactivate_plugin`; registry contract: commands MUST be idempotent; `delete_plugin` → `unsupported_command` (D-W1) |
| Rejection vocabulary + HTTP map | `executed` 200 · `already` 200 · `malformed`/`unsupported_protocol`/`unsupported_command`/`invalid_window` 400 · `bad_signature`/`site_mismatch` 401 · `unknown_plugin` 404 · `network_active_unsupported` 409 · `expired`/`replayed` 410 · `rate_limited` 429 · `failed` 500 |
| Wire body shape | 2xx: `{"status":"executed"\|"already"}`; every non-2xx: exactly `{"status":"rejected","reason":"<code>"}`, reason whitelisted against the map (unknown internals masked as `failed`) |
| Nonce store | option prefix `woodev_license_command_nonces_` + first-32-hex sha256(nonce), autoload no; record `{s: processing\|consumed, c, e, r?}`; atomic fresh-claim via `add_option`; takeover after `STUCK_TAKEOVER_AFTER` = 300 s (best-effort); retention `e = min(expires_at, now + MAX_TTL)` |
| Ack store | option `woodev_license_command_acks`, autoload no; entry exactly `{nonce, status, terminal, protocol: 1, ts}`; `terminal = (status !== 'failed')`; FIFO cap 50; retention 30 days |
| Request field | `consumed_command_nonces` = array of ack entries, present ONLY when non-empty; carried by `check_license` and updater `get_version` requests |
| Response fields | `license_commands` (array of envelopes; carried by `check_license` + updater responses, each through the full §2 verification, rate-limit-exempt); `acks_received` (array of nonces; client confirms ONLY the intersection with the set it sent this request); `license_authority` (§4 claim envelope) |
| Action hook | `do_action( "woodev_{plugin_id}_remote_deactivated", array $payload )` — `plugin_id` = `get_id()`, single arg, fired exactly once strictly after successful deactivation |
| Notice option | `woodev_license_remote_deactivation_notices` (site-level, autoload no), keyed by target `get_id()` → `{message, ts}`; rendered by every surviving license instance, once per request, dismissible per-user (message_id `woodev_{target_id}_remote_deactivated`) |
| Constants | `PROTOCOL_VERSION` 1 · `MAX_BODY_BYTES` 8192 · `JSON_DEPTH` 8 · `CLOCK_SKEW` 300 · `MAX_TTL` 1 209 600 (14 d) · `STUCK_TAKEOVER_AFTER` 300 · `MAX_NONCE_ENTRIES` 100 (soft) · `MAX_PENDING_ACKS` 50 · ack retention 2 592 000 (30 d) · rate limit 30 rejections / 60 s window (`woodev_license_cmd_rl`, inbound-only, `{n, t0}`-anchored) |
| §4 claim option | `woodev_{id_underscored}_license_required` (autoload no, raw verified envelope only; `license_required` must be strict bool) |
| Pubkey constant | `WOODEV_LICENSE_AUTHORITY_PUBKEY` (placeholder `''` until the operator captures the production key — `test_production_pubkey_is_not_placeholder` is skipped until then) |
| Shared function | `woodev_normalize_site()` (cross-repo byte-identical contract, B-6) |
| Multisite rules | commands bind per-site (normalized `home_url()`); network-activated target → `network_active_unsupported`; §4 claims consumed in current-blog context only, no `switch_to_blog()` |

These are now part of the §2 installed-site never-break list.

## 6. Server half (woodev-core) — to spec at implementation time

Mirror spec goes to `D:\Projects\woodev_theme\docs\superpowers\specs\` (same pattern as
the license-authority spec): command issuance UI/trigger (expired-license automation),
per-(site,plugin) command queue + ack consumption, nonce generation, signing with the
same `License_Authority` key, TTL policy. Retry/queue robustness lives server-side.

## 7. Testing (Brain Monkey, unit)

- Envelope verifier: tampered signature / wrong site / wrong plugin_id / expired /
  replayed nonce / unknown command → each rejected, zero side effects (silent-failure
  hunter focus).
- `deactivate_plugin`: executes once, idempotent repeat, notice persisted, hook fired.
- Pull path: envelope inside a license-check response consumed identically; double
  delivery (inbound + pull) executes once.
- Forward tolerance: vocabulary-unknown command → `unsupported_command`, no action.

## 8. Atomic decomposition (autodev, when implementation starts — after S3.2)

| id | task | tier |
|---|---|---|
| s8-p1 | generic Ed25519 envelope verifier + `woodev_normalize_site()` (shared: §4 claims AND webhooks consume it; §4's verifier becomes a thin wrapper) | opus (crypto/contract) |
| s8-p2 | nonce store + command dispatcher (vocabulary registry, forward tolerance) | opus |
| s8-p3 | REST `woodev/v1/license-command` endpoint | sonnet |
| s8-p4 | `deactivate_plugin` command handler + notice + hook + log | sonnet |
| s8-p5 | pull-fallback consumption + ack field | sonnet |
| s8-p6 | holistic critic pass + contract freeze (update §5 into INVARIANTS) | — |

## 9. Protocol hardening checklist — ✅ ALL 9 RESOLVED (s9, 2026-06-11)

> Source: GPT-5.5 high adversarial review of this draft (2026-06-11, verdict BLOCK).
> **Every item below was resolved and implemented in s9.** The binding resolutions
> (including the critic-round amendments: lazy at-cap pruning with the recorded
> store_full exception, best-effort takeover + idempotent-vocabulary registry contract,
> soft nonce cap, `{n, t0}`-anchored inbound-only rate limiter, intersection-only ack
> confirmation) live in `platform-v2-s3-licensing-webhooks-plan.md` → "§9 protocol
> resolutions"; the frozen outcomes are §5 above. Original checklist retained for
> traceability:

1. **Atomic nonce claim (→ s8-p2).** The option-based nonce store is read-modify-write —
   two concurrent requests (inbound + pull) can both pass the check. Define an atomic
   claim-before-side-effect (e.g. `add_option`-per-nonce semantics or a
   `processing`/`consumed` state machine) + crash recovery between claim and action.
2. **Nonce store bounds (→ s8-p2).** "Pruned on write" leaves stale entries when no new
   command arrives. Define scheduled pruning, max entries/bytes, and bounded handling of
   attacker-controlled `expires_at` (cap retention at the client-enforced protocol
   maximum TTL, §2.4).
3. **Shared-endpoint election (→ s8-p3).** Specify exactly when the winning framework
   copy registers the route, how duplicate registration is prevented during the B-1
   mixed-fleet window, how all active plugins enter the winner's target registry, and
   reject duplicate `plugin_id` mappings loudly.
4. **Abuse controls on the public endpoint (→ s8-p3).** Strict body-size / field-count /
   scalar-length limits and strict base64 decoding BEFORE canonicalization; signature
   verification BEFORE site/plugin lookup; indistinguishable rejection responses;
   rate-limit garbage (Ed25519 verify on arbitrary POST bodies is cheap but not free).
5. **Ack lifecycle for the deactivated plugin (→ s8-p5) — the hard one.** After
   `deactivate_plugin` executes, the target never makes another scheduled request, so
   its ack may never be sent. Define durable **site-level** pending acks sent by ANY
   surviving framework client on the site + ack-in-the-inbound-response for the fast
   path + bounded retention both sides.
6. **Structured acks (→ s8-p5).** `consumed_command_nonces` can't express
   `executed`/`already`/`unsupported_command`/retryable-rejection. Define an ack schema
   (nonce, terminal|retryable status, protocol version); server clears terminal items,
   retains retryable ones.
7. **Ack authenticity (→ s8-p5).** A third party that obtained a queued envelope must
   not be able to ack it (suppressing delivery). Tie ack acceptance to the same site
   binding the license API already authenticates, and define the keyless-polling case.
8. **Contract freeze completeness (→ s8-p6).** §5 must additionally freeze: full
   envelope schema + types, protocol-version field, command + rejection-reason
   vocabularies, HTTP status mapping (deterministic), pubkey rotation behavior (B-5
   `kid`), nonce-store value schema, TTL/skew constants, ack schema, multisite rules,
   which endpoints carry `license_commands`/acks, and the audit-hook argument signature.
9. **Test matrix additions (→ s8 tasks).** Concurrency double-execution,
   crash-between-claim-and-action, mixed-fleet window, duplicate download id,
   network-active multisite reject, oversized/malformed body, lost ack + redelivery,
   notice deduplication, hook-failure → ack semantics.

## 10. Out of scope

- File deletion (`delete_plugin`) — future operator decision.
- Diagnostics command implementation (waits for `woodev_support`).
- Server-side queue/UI implementation detail (own spec in woodev_theme).
- Any change to `is_license_valid()` / `is_active()` semantics — webhooks act *via
  commands*, never by flipping local license state (anti-pirate invariant intact).

## Related
- [platform-v2-s3-licensing-need-license-spec.md](platform-v2-s3-licensing-need-license-spec.md) — §4 primitive, normalization, claim envelope
- [platform-v2-s3-licensing-ui-spec.md](platform-v2-s3-licensing-ui-spec.md) — S3.2 (must merge first)
- [FUTURE-BACKLOG.md](FUTURE-BACKLOG.md) — deferred feature #3 (originating entry), B-2/B-3 lessons
- PLANS.md §3.4.1
