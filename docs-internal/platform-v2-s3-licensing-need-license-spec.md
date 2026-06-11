# Platform v2 — S3.1 Licensing: `is_need_license` + server-signed authority (spec)

> Stage S3 sub-stage 1. Written 2026-06-10 (session 5). Status: **approved design, ready for writing-plans**.
> Scope decision: S3 is decomposed into 3 sub-stages — (1) `is_need_license` flag + license-free authority [this spec], (2) modern license-page UI, (3) built-in webhooks (§3.4.1). This spec covers only sub-stage 1.
> Cross-repo companion: `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md` (the server half — woodev-core).

## 1. Problem & intent (PLANS §3.4)

A plugin may legitimately need **no license** (free product). PLANS asks for an `is_need_license` flag (default `true`, overridable per plugin) so that, when `false`:

- the "Woodev → Licenses" page either hides the key field or shows "license not required";
- license-freshness nags are suppressed;
- **updates keep flowing**.

### 1.1 The trust problem (operator constraint, 2026-06-10)

A plugin must **not** blindly trust a locally-set flag. A pirate would set `is_need_license() = false` in a fork to unlock paid functionality for free. Therefore:

- **The local flag is a presentation hint only.** It is trusted *only* to decide how the license block renders and whether the "enter your license" nags appear.
- **The authority on whether a product truly needs no license is the Woodev server** (`woodev-core`), delivered as a **cryptographically signed claim** the client can *verify* but cannot *forge*.

### 1.2 Honest security boundary

Client-side PHP executes on a machine the pirate controls; **absolute DRM is impossible**. The realistic goal is to raise the bar from "edit one boolean option in the DB" (trivial) to "forge an Ed25519 signature" (infeasible). Updates have an independent server gate (the download URL requires a live license), so a tampered local flag yields, at most, locally-unlocked code that never updates — and only if the signature check is bypassed, which this design prevents for honoring `license_required = false`.

**Asymmetry of this protection (B-4, recorded 2026-06-11).** The Ed25519 claim gates only the **license-free short-circuit**. The **paid-status path is not crypto-gated**: license status lives in a plain WP option (a status string plus the locally-stored key), so a pirate editing local state to look `valid` is still the cheap attack — and client-side code edits can bypass any local feature gating entirely. That is accepted by design: the only things actually enforced are the **server-provided operations** (update downloads require a live license; license API operations are server-checked). Locally-running paid code remains bypassable on a machine the pirate controls. Do not read §4 as DRM for paid products.

## 2. Two-layer model

| Layer | Lives in | Governs | Who can set it |
|------|----------|---------|----------------|
| **L1 — local intent** `is_need_license()` | method on `Woodev_Plugin` (base), default `true` | **presentation only**: license-page block + nags | plugin author (override) — untrusted, and that is fine |
| **L2 — server authority** `license_required` | signed claim in Woodev API responses; verified + cached client-side | **real gating**: `is_active()` / `is_license_valid()` outcome for a license-free product | only the Woodev server (Ed25519 private key) |

**Invariant (anti-pirate):** `is_active()` / `is_license_valid()` must **never** become `true` because of the local L1 flag. They reflect real license state OR a *verified* server claim only.

## 3. This session — safe scaffold (implement now)

Everything here is byte-for-byte back-compatible and testable without the live server.

### 3.1 L1 — `Woodev_Plugin::is_need_license()`

```php
/**
 * Whether this plugin requires a license to operate.
 *
 * Presentation hint only. Plugins that ship without a license override this
 * to return false. NEVER used to gate features or updates — see is_license_required().
 *
 * @since 2.0.0
 * @return bool
 */
public function is_need_license(): bool {
    return true;
}
```

Consumers gated on L1 (presentation only — suppress/alter when `false`):

| Site | Current | When `is_need_license() === false` |
|------|---------|------------------------------------|
| `Woodev_Plugins_License::notices()` | admin nag "invalid/expired" | do not add the notice |
| `Woodev_Plugins_License::plugin_row_license_missing()` | "enter valid license" on plugins list | do not print the license sentence (keep the update-version branch intact) |
| `Woodev_Woocommerce_Plugin::add_class_form_wrap_start()/_end()` | wraps settings in `.woodev-licence-need` when invalid | do not wrap |
| `Woodev_Plugin::plugin_action_links()` (the `$custom_actions['license']` branch, ~line 686) | shows "Указать лицензию" when not valid | do not add the `license` action link |
| `Woodev_Woocommerce_License_Settings::do_license_fields()` | input + Verify + Deactivate | render a short "Лицензия для этого плагина не требуется" block instead of the input/verify/deactivate controls; keep the form + nonce structure so the Settings API round-trips harmlessly |

### 3.2 Outage-grace hardening (server may be down)

The Woodev server can be unreachable (has happened). A failed/empty validation response must:

- **never** raise a PHP warning/notice/fatal;
- **never** relock a previously-valid license (last-known-good is retained);
- **never** auto-unlock.

Changes:

- `Woodev_Cron_Handler::weekly_license_check()` — **keeps running** regardless of `is_need_license()` (the local flag is not authority; a keyless free plugin is already a natural no-op via the existing `empty( $license_key )` guard). Wrap the `validate_license()` call so a transport failure is swallowed without touching stored state.
- `Woodev_Plugins_License::validate_license()` / `verify_license()` — confirm that a `dispatch()` failure leaves the stored `Woodev_License` untouched (it already does: the exception is caught before any `update()`/`save()`). Add an explicit guard/test so this is not regressed.

> **Correction recorded:** an earlier design draft said "weekly_license_check() — do not run when is_need_license is false". That is **wrong** and must not be implemented — the cron must keep running and tolerate outages.

### 3.3 Enforcement seam — conservative default

```php
// Woodev_Plugins_License
/**
 * Authoritative answer to "does this product require a valid license?".
 *
 * Returns true unless a VERIFIED server claim says license_required = false.
 * Until signed claims are issued (see §4), always returns true → behavior
 * is byte-for-byte unchanged. The local is_need_license() flag does NOT
 * influence this method.
 *
 * @since 2.0.0
 * @return bool
 */
public function is_license_required(): bool {
    return true; // safe default; §4 makes this read a verified claim
}
```

`is_active()` and `is_license_valid()` consult it:

```php
public function is_license_valid() {
    if ( ! $this->is_license_required() ) {
        return true; // server-confirmed license-free
    }
    return ! empty( $this->license_key ) && $this->has_status( 'valid' );
}
```

With the §3.3 default (`is_license_required()` always `true`), this collapses to the current implementation — **no behavioral change, no new option read** (so no tamper vector is introduced before signing exists).

## 4. Full signed authority — ✅ IMPLEMENTED (s9, 2026-06-11)

> **Status: implemented** alongside S3.3 webhooks (the shared Ed25519 primitive): the
> verifier + `woodev_normalize_site()` (s8-p1), claim consumption + `is_license_required()`
> + the B-3 keyless-updater rework (s8-p0). One operator step remains: capturing the
> PRODUCTION `WOODEV_LICENSE_AUTHORITY_PUBKEY` after deploy (wp-eval snippet in the
> woodev-core spec) — until then the placeholder `''` keeps every claim rejected =
> safe default (license required), and `test_production_pubkey_is_not_placeholder`
> stays skipped. Section retained as the design record:

### 4.1 Primitive — Ed25519 via libsodium

- `sodium_crypto_sign_verify_detached()` — bundled in PHP core ≥ 7.2; small keys, fast.
- Framework embeds the Woodev **public** key (constant `WOODEV_LICENSE_AUTHORITY_PUBKEY` or a bundled `.pub`); server holds the **private** key.
- This same primitive is reused by the §3.4.1 webhooks ("only we can initiate") — design it once here.

### 4.2 Claim envelope

> **Cross-repo contract RESOLVED (woodev-core s126, 2026-06-10)** — the server half is implemented; the values below are now fixed, not proposals. See `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md` ("Resolved decisions" + "Test vector").

Delivered in API responses under the top-level key **`license_authority`**, and stored client-side in a new additive option `woodev_{plugin_id}_license_required` (autoload `false`). The value is the **signed envelope**, never a bare boolean:

```json
{
  "payload": {
    "site": "https://example.com",
    "plugin_id": "216",
    "license_required": false,
    "issued_at": 1749513600,
    "expires_at": 1750723200
  },
  "signature": "base64(ed25519(canonical_json(payload)))"
}
```

- **`plugin_id` is the EDD download id as a DECIMAL STRING** (e.g. `"216"`), NOT `woodev_{slug}`. The framework already sends `item_id` in every API request, so the server binds the claim to it.
- **`expires_at` = `issued_at` + 14 days.** The weekly (7-day) license check refreshes the claim → two cycles of grace, so a single missed check never relocks a legitimately-free site.

Verification (all must pass before honoring `license_required = false`):

1. signature verifies against the embedded public key over the canonical JSON of `payload`;
2. `woodev_normalize_site( payload.site ) === woodev_normalize_site( home_url() )` (anti-replay onto another site; see the normalization rule below);
3. `payload.plugin_id === (string) $this->get_download_id()` (compare against the **download id**, not a slug);
4. `now <= payload.expires_at`.

Any failure → fall back to `license_required = true` (safe / locked).

**`site` normalization (B-6, defined 2026-06-11).** Without a single normalization rule, a trailing slash or scheme/host case difference between what the client sent at request time (what the server signed) and `home_url()` at verify time silently locks a legitimately-free site. One pure function, applied **byte-identically at every point** — the `url` is normalized **server-side** before signing (see deviation note below; the wire value stays raw `home_url()`), the normalized value becomes `payload.site`, and the client normalizes `home_url()` before comparing at verify time:

> [Implementation deviation, s9 2026-06-11: the client keeps sending the **raw**
> `home_url()` on the wire — the `url` request param is a pre-existing EDD installed-site
> contract and changing its value could break EDD SL site matching for existing
> activations. The server normalizes before signing (implemented woodev-core s126), so
> `payload.site` is normalized regardless; the client normalizes **both** operands at
> verify time (double normalization is idempotent — tested). Recorded in
> `platform-v2-s3-licensing-webhooks-plan.md` → "Additional locked implementation
> decisions" #2.]

```
woodev_normalize_site( $url ): string|FAIL
    0. input MUST be an absolute http/https URL with a non-empty host
       (parse_url() failure, missing/other scheme, empty host → FAIL);
    1. scheme → strtolower
    2. host   → strtolower; non-ASCII (IDN) hosts MUST already be in their
       IDNA-ASCII (punycode) form — a host containing bytes > 0x7F → FAIL
       (WP stores home/siteurl as entered; punycode is the deterministic form);
       IPv6 literal hosts keep their brackets, hex lowercased
    3. drop default ports (:80 for http, :443 for https); keep non-default ports
    4. path  → untrailingslashit(), preserved case (subdirectory installs are
       case-sensitive); absent path → '' (empty string)
    5. drop query + fragment (user/pass present → FAIL)
    6. return exact concatenation: scheme . '://' . host [. ':' . port] . path
```

`FAIL` semantics: client-side, a `FAIL` on either input makes the claim **invalid → locked** (safe default); server-side, a `FAIL` on the received `url` means **do not issue a claim**.

- **Multisite (minimal rule + explicit §4 TODO):** claims are stored, refreshed, and verified strictly **per-site** — the binding is the normalized per-site `home_url()` of the site whose options hold the license; there is no network-wide claim. ⚠️ Open at §4 implementation time: WP's update flow runs network-level (one `update_plugins` cycle for all sites), so claim *consumption from updater responses* needs a defined owner (e.g. only the request's current-blog context consumes; other sites refresh via their own weekly license checks). Decide + test blog-switching and cache isolation in the §4 task — do not hand-wave it.
- **Test vector:** when §4 is implemented, the published woodev-core test vector must gain at least one normalization case — e.g. raw client URL `https://Example.com/` → normalized **and signed** `site` = `https://example.com`, verified on a site whose raw `home_url()` is `https://Example.com/` — so cross-implementation fixtures lock the rule, not just the signature math.

> The public key is generated lazily per environment (prod ≠ dev), so the embedded `WOODEV_LICENSE_AUTHORITY_PUBKEY` must be the **production** key, captured after deploy via the woodev-core spec's `wp eval` snippet. A documented test vector (payload + canonical bytes + signature + public key) lives in that spec for a cross-implementation fixture test.

### 4.3 Transport — how the client learns the claim

Accept the signed envelope from **any** Woodev API response that carries it:

- `check_license` / `activate_license` responses (`Woodev_Licencing_API_Response`);
- the updater's `plugin_latest_version` response (`Woodev_Plugin_Updater::get_repo_api_data()`).

The intended model: the update check runs on a regular schedule even with an empty license key, so a **keyless free plugin learns its status during normal update polling** — this is what solves the chicken-and-egg for keyless products.

> **⚠️ Corrected premise (B-3, verified 2026-06-11).** Today's framework does NOT provide that transport: `Woodev_Plugin::load_updater()` constructs `Woodev_Plugin_Updater` only when `get_license_instance()->get_license()` returns a non-empty key, and only in `is_admin() || WP_CLI` contexts (`woodev/class-plugin.php:376-388`) — a keyless plugin never polls, never receives `license_authority`, and `is_license_required()` would stay `true` forever. The §4 implementation plan therefore **must include a framework-side task**:
>
> 1. rework `load_updater()` to construct the updater **regardless of license key**, within the eligible contexts `is_admin() || DOING_CRON || WP_CLI` (literal unconditional construction would reference a class not loaded on ordinary frontend requests) — empty-key polling is harmless and the woodev-core server tolerates it (no woodev-core change needed);
> 2. align the `is_admin() || WP_CLI` gate with the cron path so polling cadence does not depend on admin visits (`includes()` already loads the updater file for `DOING_CRON`);
> 3. regression tests: *no key → updater constructed (admin AND cron contexts) → `license_authority` claim consumed*; *ordinary frontend request → updater NOT constructed* (unchanged).

### 4.4 Outage grace (interaction with §3.2)

- A verified claim is honored until `payload.expires_at`. A successful refresh re-issues it with a later expiry.
- Server unreachable → keep the last verified claim until it expires; do not relock within the window.
- Server-issued validity window: **resolved — 14 days** (woodev-core s126 resolved decision #4; `issued_at + 14·DAY_IN_SECONDS`). Long enough that a routine outage never locks a legitimately-free site (two weekly check cycles); short enough that a real change propagates.

### 4.5 When §4 lands, §3.3 changes to

```php
public function is_license_required(): bool {
    $claim = $this->get_verified_license_required_claim(); // null if absent/invalid/expired
    return $claim === null ? true : (bool) $claim->license_required;
}
```

## 5. Release-blocking contracts — preserved byte-for-byte

Unchanged: option keys `woodev_{id}_license` and `woodev_{id}_license_key`; settings group `woodev_license_fields_group`; settings page `woodev_licenses_page`; section `woodev_licenses_section`; admin slug `woodev-licenses` (parent `woodev`); nonce `{id-dasherized}-nonce`; option names `license_key`/`deactivate`/`verify`/`beta_version`; EDD `edd_action` API contract; endpoint `https://woodev.ru/`; hooks `woodev_license_saved` / `woodev_license_deleted` / `woodev_enable_license_logging`; transient `woodev_extensions`; constant `WOODEV_LICENSE_DEBUG`.

> [Superseded in S3.2: the Settings-API form was removed (ADR-005); the registration-time names `woodev_license_fields_group` / `woodev_licenses_page` / `woodev_licenses_section` and the form nonce `{id-dasherized}-nonce` no longer exist — see `platform-v2-s3-licensing-ui-spec.md` §2.1. The data-contract option keys (`woodev_{id}_license`, `woodev_{id}_license_key`), admin slug, and EDD/API contracts above remain unchanged.]

**Additive only:** method `Woodev_Plugin::is_need_license()`; method `Woodev_Plugins_License::is_license_required()`; (deferred) option `woodev_{id}_license_required` holding the signed envelope; (deferred) public-key constant.

## 6. Testing (Brain Monkey, unit)

- `is_need_license()` default `true`; override → `false`.
- Each L1 consumer suppresses/alters output when `false` (notices, plugin-row, form-wrap, action-link, license-fields render).
- **Anti-pirate:** `is_license_valid()` / `is_active()` are unaffected by `is_need_license()` (a `false` flag with no verified claim still yields the paid-license outcome).
- `is_license_required()` defaults `true`; with the §3.3 default, `is_license_valid()` is byte-for-byte the current behavior.
- Outage: a `dispatch()` failure in `validate_license()`/`weekly_license_check()` raises no warning/fatal and leaves stored `Woodev_License` state unchanged.
- (Deferred §4, fixture-keyed) signature verify happy path; tampered payload → rejected; wrong-site → rejected; expired → rejected; absent → `license_required = true`.

## 7. Out of scope (other S3 sub-stages)

- Modern interactive license-page UI (React/AJAX redesign) — sub-stage 2.
- Built-in webhooks §3.4.1 (remote kill-switch + diagnostics) — sub-stage 3 (reuses the §4 Ed25519 primitive).

## Related

- [PLANS.md](../PLANS.md) §3.4 / §3.4.1
- [platform-v2-program-tracker.md](platform-v2-program-tracker.md) — S3 stage
- woodev-core server spec: `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md`
- CLAUDE.md → Backward Compatibility (clean-break vs installed-site data contracts)
