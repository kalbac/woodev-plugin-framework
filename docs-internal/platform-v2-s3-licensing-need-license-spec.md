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

## 4. Deferred to a later cross-repo session — full signed authority (spec only)

Implemented in the framework only once `woodev-core` issues signed claims (cannot be verified end-to-end before then). Fixture-keyed unit tests for the verifier may land earlier if convenient, but the production wiring waits for the server.

### 4.1 Primitive — Ed25519 via libsodium

- `sodium_crypto_sign_verify_detached()` — bundled in PHP core ≥ 7.2; small keys, fast.
- Framework embeds the Woodev **public** key (constant `WOODEV_LICENSE_AUTHORITY_PUBKEY` or a bundled `.pub`); server holds the **private** key.
- This same primitive is reused by the §3.4.1 webhooks ("only we can initiate") — design it once here.

### 4.2 Claim envelope

Stored client-side in a new additive option `woodev_{plugin_id}_license_required` (autoload `false`). The value is the **signed envelope**, never a bare boolean:

```json
{
  "payload": {
    "site": "https://example.com",
    "plugin_id": "woodev_my_plugin",
    "license_required": false,
    "issued_at": 1749513600,
    "expires_at": 1751933600
  },
  "signature": "base64(ed25519(canonical_json(payload)))"
}
```

Verification (all must pass before honoring `license_required = false`):

1. signature verifies against the embedded public key over the canonical JSON of `payload`;
2. `payload.site === home_url()` (anti-replay onto another site);
3. `payload.plugin_id` matches this plugin;
4. `now <= payload.expires_at`.

Any failure → fall back to `license_required = true` (safe / locked).

### 4.3 Transport — how the client learns the claim

Accept the signed envelope from **any** Woodev API response that carries it:

- `check_license` / `activate_license` responses (`Woodev_Licencing_API_Response`);
- the updater's `plugin_latest_version` response (`Woodev_Plugin_Updater::get_repo_api_data()`).

The update check runs on a regular schedule even with an empty license key, so a **keyless free plugin learns its status during normal update polling** — this is what solves the chicken-and-egg for keyless products.

### 4.4 Outage grace (interaction with §3.2)

- A verified claim is honored until `payload.expires_at`. A successful refresh re-issues it with a later expiry.
- Server unreachable → keep the last verified claim until it expires; do not relock within the window.
- Recommended server-issued validity window: 14–30 days (final value set in the woodev-core spec). Long enough that a routine outage never locks a legitimately-free site; short enough that a real change propagates.

### 4.5 When §4 lands, §3.3 changes to

```php
public function is_license_required(): bool {
    $claim = $this->get_verified_license_required_claim(); // null if absent/invalid/expired
    return $claim === null ? true : (bool) $claim->license_required;
}
```

## 5. Release-blocking contracts — preserved byte-for-byte

Unchanged: option keys `woodev_{id}_license` and `woodev_{id}_license_key`; settings group `woodev_license_fields_group`; settings page `woodev_licenses_page`; section `woodev_licenses_section`; admin slug `woodev-licenses` (parent `woodev`); nonce `{id-dasherized}-nonce`; option names `license_key`/`deactivate`/`verify`/`beta_version`; EDD `edd_action` API contract; endpoint `https://woodev.ru/`; hooks `woodev_license_saved` / `woodev_license_deleted` / `woodev_enable_license_logging`; transient `woodev_extensions`; constant `WOODEV_LICENSE_DEBUG`.

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
