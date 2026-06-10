# Platform v2 — S3.3 Licensing: built-in webhooks (spec — DRAFT)

> Written 2026-06-11 (s7, Fable orchestrator + operator fork decisions). Implements PLANS
> §3.4.1. **Status: DRAFT — direction + operator decisions are fixed; the protocol
> details listed in §9 (GPT-5.5 adversarial review, 2026-06-11) MUST be resolved in the
> implementation session before any code lands.** Implementation deferred until S3.2
> (license UI) merges — both touch `woodev/licensing/` + the `woodev/v1` REST namespace;
> S3.2 establishes the namespace registration pattern this spec reuses.
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

## 5. New installed-site contracts created here (freeze at implementation)

| Contract | Value |
|---|---|
| REST route | `woodev/v1/license-command` (POST) |
| Option (nonce store) | `woodev_license_command_nonces` |
| Action hook | `woodev_{plugin_id}_remote_deactivated` |
| Response/request keys | `license_commands`, `consumed_command_nonces`, ack `status` values |

Once shipped these join the §2 never-break list (byte-for-byte).

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

## 9. Protocol hardening checklist — BLOCKING, resolve in the s8 implementation session

> Source: GPT-5.5 high adversarial review of this draft (2026-06-11, verdict BLOCK).
> Items #3/#4/#7/#8 of that review are already folded into §2/§4 above. The rest are
> protocol-engineering decisions that need code in hand — each becomes part of its
> matching s8 task's acceptance criteria. **No s8 task ships while its items are open.**

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
