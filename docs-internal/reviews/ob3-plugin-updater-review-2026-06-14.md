# OB-3 — `Woodev_Plugin_Updater` code review + design decision (2026-06-14, s14)

> **Source:** GPT-5.5 read-only critic, inline-bundle transport (gotcha
> `codex-shell-sandbox-broken-windows`). Bundle = the full updater file +
> `class-plugin.php` `load_updater()`/`construct_updater()`/`includes()` slice +
> `class-licensing-api.php`.
>
> **Operator decision posture (s14): RECORD, do not auto-fix.** This is a *review*
> task and several findings change **installed-site data contracts**
> (release-blocking under ADR-005) or hook argument shapes; the updater is also a
> critical path (plugin auto-update + signed license-command transport) that needs
> **browser/integration verification** before any change. Per the Codex plugin
> contract, review findings are never auto-applied — the operator triages. Two
> findings were hand-verified against source this session (see ✅ below); the rest
> are Codex-reported, **re-verify before acting**.

## Lifecycle clarification (answers the operator's "singleton" question)

`Woodev_Plugin_Updater` is **not a singleton**. It is constructed fire-and-forget as
`new Woodev_Plugin_Updater( $this )` in `Woodev_Plugin::construct_updater()` (called
from `load_updater()` on `init`) and self-registers WP hooks in its constructor. The
instance is retained only by those hook callbacks, not owned by the plugin.

**Recommendation:** it should *stay* per-plugin (identity/version/license/slug/update
metadata are plugin-specific — a global singleton would be wrong), but the plugin
should **own exactly one** instance and construction should be **idempotent**
(Finding 4). See the design section.

## Findings (Codex verbatim, condensed; severity as reported)

### BLOCK (correctness / robustness — none are contract-safe to apply blindly)

1. **`sections` normalization corrupts the response shape** (`~625`, `convert_object_to_array`/`get_version_from_remote`).
   The loop promotes each section to a **top-level** `$response->{$key}` and casts HTML
   strings to arrays, leaving `$response->sections` itself unnormalized. ⚠️ Interacts
   with `show_update_notification()`, which reads `…->sections->changelog` as an
   **object** — so a cast-to-array here would break that access. **Plausible-real;
   verify against the store's actual `sections` payload before changing.**
2. **Empty `catch ( Exception $e ) {}` swallows API failures** (`~634`). No diagnostics;
   `Throwable` (TypeError, malformed response) is not caught and can break WP update
   checks; and because nothing writes the failure-timestamp option, the backoff in
   Finding 6 never engages. **Fix dir:** catch `Throwable`, log via the framework logger,
   write the existing `woodev_failed_http_*` option. Preserve the cache key exactly.
3. **Shared cache key conflates `get_repo_api_data()` and `plugins_api_filter()`** (`~125`, `~367`).
   Only `get_repo_api_data()` injects `plugin`/`id`/`tested`; if `plugins_api_filter()`
   populates the cache first, `check_update()` can inject an under-normalized object.
   **Fix dir:** normalize on every read for `check_update()`; **do not change the key.**
4. **Updater construction is not idempotent** (`class-plugin.php ~347`). `load_updater()`
   is public; each call constructs another updater that registers identical hooks →
   duplicate store requests / rendered rows / command+ack processing. **Fix dir:**
   plugin owns one instance; preserve the `load_updater()` gate expression and the
   public `woodev_plugin_updater` action exactly.

### MINOR

5. **`api_request()` is a false multi-action abstraction** (`~449`) — `$_action` unused;
   only `slug` is honored; `is_ssl`/`fields`/`beta` discarded. Every call is `get_version`.
   **Fix dir:** collapse to one explicit `get_version` op, *after* verifying the wire shape.
6. **Failed-request backoff is dead code** (`~471`) — `request_recently_failed()` reads +
   deletes an option nothing writes. ⚠️ The key is **endpoint-wide** (shared by all plugins
   on the same URL) — confirm intent before enabling. Pairs with Finding 2.
7. **Missing command/ack classes silently disable signed-command transport** (`~574`, `~728`) —
   required unconditionally in `includes()` yet guarded by `class_exists()` and skipped
   silently. **Fix dir:** keep update-flow containment but log wiring failures.
8. ✅ **Wrong 2nd arg to `in_plugin_update_message-{$file}`** (`~324`). **Verified real:**
   source is `do_action( "in_plugin_update_message-{$file}", $plugin, $plugin )` — passes
   the plugin-data array twice; WP convention is `($plugin_data, $response_object)`.
   ⚠️ **Hook-argument shape = installed-site contract** — audit consumers first.
9. **Weak changelog-endpoint validation** (`~508`) — capability check but no nonce, no
   unslash/sanitize, only checks `plugin` non-empty (not `=== $this->name`). ⚠️ Adding a
   nonce changes the changelog **URL shape** (contract) — treat as a migration if so.
10. **Version cache key ignores the licensing endpoint** (`~760`) — key has slug/license/beta
    but not the filtered `woodev_license_base_url`; changing the endpoint can serve up to
    3h of stale cross-store data. ⚠️ **Do not change the key**; invalidate explicitly in
    tooling/tests, or stamp+validate source metadata inside the existing option value.
11. **Malformed `tested` can warn** (`~183`) — `$tested_parts[0]/[1]` read without shape
    validation. **Fix dir:** validate before indexing; return original `tested` if malformed.
    (Safe, no contract impact.)
12. **Types/visibility below v2 standard** (`~13–770`) — untyped properties, missing
    param/return types, `init()`/cache getters+setters are `public`. Clean-break allows
    hardening freely; reduce visibility after confirming no internal callers.
13. ✅ **Unescaped HTML attributes** (`~246`). **Verified real:** `printf('<tr … id="%1$s-update"
    data-slug="%1$s" data-plugin="%2$s">', $this->slug, $file, …)` outputs `$this->slug` and
    `$file` without `esc_attr()`. Low XSS risk (plugin-controlled values) but WPCS-correct
    to fix. (Safe, no contract impact.)

### NOTE
- 14. No HPOS risk — the class never touches orders/order meta.
- 15. `api_url` + `failed_request_cache_key` are dead state unless the backoff (6) is repaired.
- 16. Multisite custom update-row + changelog UI are inherited EDD/GoDaddy-era code — verify
  against WP ≥ 6.3 before retaining.

## Design recommendation — MOVE under `woodev/licensing/`, keep it a distinct component

The updater is no longer a generic WP updater: it constructs `Woodev_Licensing_API`,
reads the licensing endpoint, sends license identity, transports **signed authority
claims**, pulls **license commands**, and sends+confirms **acks**. That places it on the
licensing transport boundary.

- **MOVE** `woodev/plugin-updater/` → `woodev/licensing/updater/` (namespace/dir) for
  cohesion + clearer include ordering. Clean-break (ADR-005) permits the internal
  rename/move freely — **no shim**.
- **DO NOT** merge into `Woodev_Licensing_API`. Responsibilities stay distinct: the API
  owns HTTP protocol + response handling; the updater owns WP update hooks, caching,
  update metadata, and scheduled-pull transport. The `updater → Licensing_API`
  dependency direction is fine. The coupling worth untangling is the updater reaching
  claims/dispatcher/ack stores via concrete global classes — inject a small licensing
  transport collaborator so response consumption isn't embedded in WP update-metadata parsing.

**The move (and every fix above) must preserve byte-for-byte:** the `woodev_plugin_updater`
hook name+args, WP update filters/actions, the cache + failed-request option keys, the store
request identity + wire fields, unconditional admin/cron/WP-CLI construction, and the
expression-identical `includes()` ↔ `load_updater()` gate (B-3 parity).

## Suggested execution order (when the operator schedules it)

1. **Safe, no-contract pre-clean (low risk, could even be solo+critic):** Findings 11
   (`tested` guard) + 13 (`esc_attr`) + 12 (type/visibility hardening). Browser-verify the
   update row still renders.
2. **Robustness (needs integration test on real WP + store):** Findings 2 + 6 + 7 (error
   handling + backoff + wiring-failure logging) — decide the endpoint-wide-key question first.
3. **Normalization correctness (verify store payload first):** Findings 1 + 3 + 5.
4. **Contract-touching (operator sign-off + consumer audit + migration note):** Findings 8,
   9, 10.
5. **Structural MOVE** to `woodev/licensing/updater/` with the collaborator extraction — its
   own autodev session with a data-preservation checklist for the 6 frozen contracts above.

## Open questions (from the critic)

1. Does the store return identical payloads for `plugin_latest_version` vs `plugin_information`?
2. Any external consumers relying on the (incorrect) 2nd arg of `in_plugin_update_message-{$file}`?
3. Is endpoint-wide failed-request backoff intentional across all plugins sharing `woodev.ru`?
4. Are the multisite update row + changelog endpoint covered by browser/integration tests on WP ≥ 6.3?

## Related

- `docs-internal/FUTURE-BACKLOG.md` → OB-3 (this review is its detail file).
- `docs-internal/reviews/fable5-architecture-review-2026-06-10.md` → B-3 (keyless updater
  construction), the parity this review must not break.
- `woodev/plugin-updater/class-plugin-updater.php` (subject).
- `woodev/licensing/api/class-licensing-api.php` — `get_url()` / `woodev_license_base_url`
  (s13 consolidation; relevant to Finding 10).
