# Fable 5 — Architecture & Direction Review (2026-06-10)

> Fresh-eyes architecture/direction review of the platform-v2 foundation, run per
> `docs-internal/fable5-architecture-review-prompt.md` (single Fable 5 agent, effort `high`,
> read-only, docs-first → targeted Serena code reads). Scope: foundation soundness, S0–S6
> direction, S3 licensing security (two-layer `is_need_license` + Ed25519), installed-site
> contract integrity, blind spots. Out of scope per prompt: legacy slated for deletion
> (ADR-005), items already on PLANS/FUTURE-BACKLOG/GOTCHAS, style nits.
>
> **Triage rule (prompt §after-the-run):** findings below are NOT auto-applied; real ones
> become autodev atomic tasks (worker + adversarial critic) after operator triage.

## A. Executive read

**The direction is sound.** The clean-break-with-data-preservation policy (D-2/ADR-005) is the right call and is executed with unusual discipline: real boundary tests, ADR-recorded scope decisions, pragmatic cancellations of gold-plating. The platform split (S0) genuinely achieved a WC-name-free base, and the S1–S3 sequencing is sensible. The two-layer licensing model is correctly conceived.

**Top 3 risks, all in the deployment/transport layer rather than the class design:**

1. **Mixed v1/v2 plugin fleets fatal the whole site.** The bootstrap rendezvous class kept its name but lost `register_plugin()`; end users update ~12 plugins independently, so the first staggered update WSODs a customer site. Nothing in the docs addresses this.
2. **The bootstrap/resolver/loader-definition layer is "first-loaded-wins", not version-arbitrated, and its validation hard-rejects unknown vocabulary** — a forward-compat trap that will fire exactly at S4 (EDD).
3. **The keyless-free-product flow is built on a false premise:** both S3 specs assume the updater polls without a license key; `load_updater()` constructs it only when a key exists. As designed, free products get neither claims nor updates.

All three are cheap to fix now and expensive after the first plugin rewrite ships.

## B. Findings (by impact)

### B-1. Mixed v1/v2 fleet → site-wide fatal at the bootstrap rendezvous — **Critical** · bootstrap/deployment

**Evidence:** `woodev/bootstrap.php:10` — `if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) )`, class exposes only `register_loader_definition()`. Tag `platform-v2-pre-refactor:woodev/bootstrap.php` — same class name, same guard, exposes `register_plugin()` (line 62). Fixture entry files (`tests/_fixtures/woodev-test-*.php:72-73`) call `register_loader_definition()` with no `method_exists()` probe — i.e. the canonical v2 entry template assumes a v2 bootstrap.

**Why it matters:** WP loads plugins in directory-alphabetical order, so whichever copy defines the class is arbitrary with respect to framework version. On any site mixing one v2-rewritten plugin with one v1 plugin (the *normal* state during a staggered fleet rollout — users update plugins at different times over months), one side calls a method that doesn't exist → uncaught `Error` → WSOD on every request, front and admin. ADR-005's "a released v2 ships only once the consuming plugins migrate" covers the repos, not customers' update timing. Not on PLANS/FUTURE-BACKLOG/GOTCHAS.

**Recommendation:** two ~30-line guards, neither of which violates the clean-break policy (this is site-availability armor, not internal-API nostalgia): (a) the v2 entry-file template probes `method_exists( $bootstrap, 'register_loader_definition' )` and fails soft (admin notice "update plugin X", plugin stays dormant); (b) the v2 bootstrap carries a `register_plugin()` *tombstone* that never initializes the legacy plugin but records it into the incompatible list + admin notice. Add a fixture test for both directions. Do this **before** the first production rewrite ships.

### B-2. Loader-protocol layer is first-loaded-wins + hard-rejecting validation = forward-compat trap (fires at S4/EDD) — **High** · resolver/EDD future

**Evidence:** `bootstrap.php:7-8` — each vendored copy `require_once`'s *its own* resolver/definition files; `class_exists(..., false)` guards in `class-framework-resolver.php:12` and `class-framework-plugin-loader-definition.php:12` mean the **alphabetically-first** plugin's copies define the protocol classes for everyone (version sorting in `load_plugins()` selects only `class-plugin.php`, never the resolver itself). `Framework_Plugin_Loader_Definition::validate_raw_definition()` (lines 286-356) **rejects** unknown platforms/capabilities and explicitly errors `PLATFORM_EDD` ("reserved and unsupported in Platform v2.0").

**Why it matters:** when S4 ships an EDD plugin (or v2.1 adds any capability constant), every site also running an older-v2 plugin that loads first will validate the new plugin's definition with the *old* class → silently dumped into `invalid_loader_definitions`. The `backwards_compatible` window in `load_plugins()` protects framework-class selection but nothing protects the arbitration layer itself. The loader-definition schema and the bootstrap's public surface are a **wire protocol between vendored copies of different versions** — a new category of installed-site contract that the §2 contract list doesn't name.

**Recommendation:** (1) document the bootstrap public surface + definition schema as a frozen, additive-only contract alongside the §2 data-contract list; (2) change capability validation to warn-and-ignore unknown strings instead of reject (forward tolerance; keep hard rejection for structurally malformed definitions); (3) drop or rethink the EDD hard-reject *before* S4 — under first-loaded-wins it guarantees old copies veto EDD plugins; (4) optionally add a protocol-version constant so future copies can detect an outdated rendezvous and notice.

### B-3. Keyless/free-product updates can't work: `load_updater()` requires a license key — **High** · licensing/updater

**Evidence:** `woodev/class-plugin.php:370-388` — `Woodev_Plugin_Updater` is constructed only `if ( $license_key )`. Framework spec §4.3: *"The update check runs on a regular schedule even with an empty license key… this is what solves the chicken-and-egg for keyless products."* woodev-core spec §3 repeats the same assumption. PLANS §3.4 requires «возможность получать обновления сохраняется» for license-free plugins.

**Why it matters:** both halves of the cross-repo design rest on a transport that doesn't exist. When §4 lands as specced: free plugin, no key → updater never constructed → never polls → never receives `license_authority` → `is_license_required()` stays `true` forever **and** the plugin never updates. The server half is already implemented (woodev-core s126), so the wrong assumption is now baked into two repos.

**Recommendation:** add an explicit task to the §4 implementation plan: rework `load_updater()` to always construct the updater (polling with an empty key is harmless and the server tolerates it), plus a regression test "no key → updater constructed → claim consumed". Note also the updater is constructed only in `is_admin() || WP_CLI` contexts while `includes()` loads the file for `DOING_CRON` too — polling cadence currently depends on admin visits; align the two gates while there.

### B-4. Ed25519 raises the bar only on the path where the attacker gains least — **Medium** · licensing threat model

**Evidence:** `is_license_valid()` = `! empty( $license_key ) && has_status( 'valid' )`; status comes from `Woodev_License` persisted as a plain WP option (`licensing/class-license-store.php`). The signed claim gates only the *license-free* short-circuit.

**Why it matters:** a pirate targeting a **paid** plugin doesn't touch `is_need_license` or forge signatures — they set the status option to `valid`. The crypto correctly prevents the new free-product path from becoming an *additional* hole (good), but it does not change the paid-piracy story at all. The honest-boundary statement in spec §1.2 should say this explicitly so future sessions don't over-invest in client-side crypto.

**Recommendation:** one paragraph in the spec naming the asymmetry; keep all genuinely valuable enforcement server-side (update download gating, server APIs — consistent with the operator's standing rule). If symmetry is ever wanted, the S3.3 webhook work reusing the same primitive is the cheap moment to sign the whole license-state envelope — optional, not urgent.

### B-5. Signing-key operations: lazy re-generation, DB-resident secret, no rotation path — **Medium** · licensing/server ops

**Evidence:** woodev-core spec resolved decision 1: option `woodev_license_authority_keys`, generated lazily by `ensure_keys()` on first signing; "deleting the option rotates the key and invalidates every issued claim." Envelope has no key id; framework embeds a single pubkey constant.

**Why it matters:** a DB restore, accidental option deletion, or environment clone **silently mints a new keypair** — every shipped framework then rejects all claims (fail-safe, but fleet-wide: free products start nagging and the only fix is a framework re-release with a new embedded key). The secret also lives in DB backups. There is no flag-day-free rotation mechanism.

**Recommendation:** make key absence at signing time an alert + refuse-to-sign (generation should be an explicit one-time operation, not a lazy side effect); store an offline backup of the keypair; add an optional `kid` to the envelope and let the framework accept a short list of embedded pubkeys so a future rotation doesn't strand old clients.

### B-6. `site` claim binding has no defined normalization — **Medium** · licensing protocol

**Evidence:** framework spec §4.2 verify step 2: `payload.site === home_url()` (strict). Server spec: "the requesting site's home_url, **normalized**" — normalization never defined; server signs "the `url` the client sent".

**Why it matters:** trailing slash, http→https migration, www vs apex, IDN, multisite subsites — any divergence between what was sent at issue time and `home_url()` at verify time fails verification. Weekly refresh self-heals *if* issue-side and verify-side use the same function, but nothing pins that down; a permanent mismatch (e.g. EDD records a normalized URL, client compares raw `home_url()`) silently relocks legitimately-free sites.

**Recommendation:** define one normalization function (e.g. `untrailingslashit( home_url() )`, lowercase scheme+host), use it byte-identically on send, sign, and verify; extend the published test vector with a normalization example; decide multisite semantics explicitly.

### B-7. Licensing UI is WooCommerce-only while licensing is a base service — **Medium** · platform neutrality / S4

**Evidence:** `Woodev_Plugin::load_license_settings_fields()` (class-plugin.php:207-230) returns early unless `Woodev_Helper::is_woocommerce_active()`; the only license-fields renderer is `Woodev_Woocommerce_License_Settings`.

**Why it matters:** enforcement, cron check, and updater are platform-neutral, but the *only way to enter a key* requires WooCommerce. The first pure-WP (or EDD) plugin shipped on v2 has updates gated on a license the user cannot activate. Latent today (all plugins are WC), blocking for the platform goal.

**Recommendation:** make "license page renders with WooCommerce absent" an explicit acceptance criterion of S3 sub-stage 2 (the React rework is the natural fix); until then record the limitation in the tracker so S4 doesn't start before it.

### B-8. S3.2 (React license page) will de-facto define the S5 React architecture without a design — **Medium** · sequencing

**Evidence:** stage map (program tracker): S3.2 "modern license-page UI — React/`@wordpress/*`" next; S5 "React admin UI" deferred post-v2.0 with no plan.

**Why it matters:** the first React surface will set build tooling, data-fetch conventions, and component patterns by accident. If S5 later picks differently, the license page gets rebuilt or stays a stylistic orphan.

**Recommendation:** before S3.2 coding, a one-page "React baseline" decision (per PLANS §6: WP/WC-bundled React; `@wordpress/scripts` vs custom Vite; `apiFetch`+REST conventions; where components live in a *vendored* framework — note `@wordpress/element` availability varies with WP version). Treat the license page as the S5 pilot explicitly.

### B-9. Base `includes()` eagerly loads every module for every plugin — **Medium** · base design

**Evidence:** `Woodev_Plugin::includes()` (class-plugin.php:443-540): ~45 unconditional `require_once`, including the full box-packer (14 files), settings-api, REST controllers, async/background-job utilities — for every plugin, including a future pure-WP/EDD plugin that ships none of it.

**Why it matters:** runtime cost is modest (opcache), but the "modules" story is currently directory-level, not load-level, and the list grows with every stage. It also undercuts the capability mechanism: capabilities gate *early base classes* but not module loading.

**Recommendation:** don't retro-split now (churn > value). Adopt a policy for *new* modules: include behind capability/feature checks (the `is_woocommerce_active()` gate on the WC packer dispatcher is the in-repo model). Revisit wholesale only if S4/S5 measurably suffer.

### B-10. `invoke_plugin()` never validates main class against declared platform/capabilities — **Medium-Low** · resolver

**Evidence:** `Framework_Resolver::invoke_plugin()` (class-framework-resolver.php:576-611) — only `class_exists`; ADR-004 says "the resolver should validate the loaded main class against the declared platform and specialized contracts when feasible". A definition declaring `platform: wordpress` whose main class extends `Woocommerce_Plugin` (or a `shipping_method` capability on a non-`Shipping_Plugin` class) loads silently.

**Why it matters:** during ~12 plugin rewrites this drift class is exactly the copy-paste mistake people make, and it surfaces as weird runtime behavior rather than a clear notice.

**Recommendation:** cheap post-instantiation `instanceof` check → route mismatches into `invalid_loader_definitions` (or `_doing_it_wrong` in debug). One test per capability.

### B-11. Data-contract enforcement at rewrite time is prose, not machine — **Medium** · contract integrity

The recorded P2 deviation (fixtures prove architecture, not live data) is **not** re-reported. The additional gap: the per-plugin checklists are hand-maintained markdown, and contract strings are *not derivable* from plugin id (gotcha [[contract-string-not-derivable]] proves it), so nothing fails loudly if a rewrite drifts from its checklist.

**Recommendation:** when the first real plugin migrates, convert its checklist into an executable contract test in that plugin's repo (assert exact option keys / method ids / cron hooks / meta prefixes against the rewritten code — `YandexPilotFixtureTest` is the in-repo template) and make "contract test green" part of the migration definition-of-done. One-time template effort, reused 12 times.

### B-12. `is_active()` with no license data returns `true` — **Low** · licensing semantics

**Evidence:** `is_active()` = "not expired && not disabled && not invalid" — an empty/never-activated license passes. With the new `is_license_required()` short-circuit there are now two distinct "true" meanings (genuinely-active vs not-known-bad vs license-free). Callers mixing `is_active()`/`is_license_valid()` can disagree about a keyless paid install. Pre-existing; clarify semantics in docblocks during S3.2 rather than changing behavior.

## C. What's done right (preserve these)

- **The L1/L2 licensing split with the safe-scaffold** — `is_license_required()` as a literal `true` (no option read = no tamper vector created before signing exists) is textbook incremental security; the corrected cron decision (§3.2: keep running, tolerate outages) is also right.
- **Installed-site contract discipline** — exact-string guard tests, per-plugin checklists, and gotchas codifying *why* contract strings aren't derivable. Most teams don't have this; it's the backbone of the whole migration bet.
- **Resolver minimization with receipts** — the ADR-003 responsibility table, the boundary negative test, and the *documented refusal* to extract the reporting cluster ("indirection without clarity") show real architectural judgment, as do the two cancelled extractions in the P4 sub-plan.
- **ADR-006 Capability-Gated Feature Seam** with explicit "when NOT to use it" boundaries, and the "framework never sums per-parcel prices" rule — the base consistently declines domain decisions it can't make correctly.
- **Clean break (D-2) itself** — separating internal-API freedom from installed-site data is the correct axis, and it visibly removed the shim tax.

## D. Not covered (no silent truncation)

- `payment-gateway/` internals (symbol-level overview only, per prompt — known debt) and `shipping-method/` deep-dive (fresh s4 conformance audit; nothing rechecked beyond its `Shipping_Plugin` loading path).
- settings-api, REST controllers, admin, utilities (async/background jobs) subsystem internals.
- `Cron_Handler::weekly_license_check()` outage guard taken on trust of the s5 critic + 275-test gate, not re-read.
- Integration-test/wp-env infrastructure.

## Related

- [fable5-architecture-review-prompt.md](../fable5-architecture-review-prompt.md) — the prompt this review executed
- [platform-v2-program-tracker.md](../platform-v2-program-tracker.md) — live program status
- [platform-v2-s3-licensing-need-license-spec.md](../platform-v2-s3-licensing-need-license-spec.md) — S3.1 client spec (B-3/B-4/B-6 touch it)
- [adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) · [adr/003-platform-v2-minimal-framework-resolver.md](../adr/003-platform-v2-minimal-framework-resolver.md) · [adr/004-platform-v2-plugin-loader-api.md](../adr/004-platform-v2-plugin-loader-api.md)
