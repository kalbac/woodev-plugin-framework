# ADR-003: Platform v2 Minimal Framework Resolver
> Status: Accepted
> Date: 2026-05-29

## Context

`PLANS.md` defines the target framework as platform-first: a pure WordPress base plugin class, WooCommerce-specific subclasses, and future EDD support. `platform-v2-strategy-alignment.md` later selected a hybrid roadmap: v2.0 keeps a minimal resolver, while SkyVerge-style versioned namespaces remain a future v2.x/v3 track.

ADR-001 accepted keeping `bootstrap.php` as a broad platform-aware loader. That was a safe spike decision, but it now conflicts with the rewrite-first strategy because it preserves too much legacy entry-file behavior as architecture.

The current `woodev/bootstrap.php` combines framework version arbitration, plugin registration, WordPress and WooCommerce requirement checks, payment/shipping early module loading, incompatibility notices, deactivation recovery, and a WooCommerce-active helper. Those are not all the same architectural responsibility.

## Decision

Use a minimal framework resolver for Platform v2.0.

`woodev/bootstrap.php` remains the compatibility entry point, but the real logic should move behind it into a namespaced resolver class or resolver service set under `Woodev\Framework\*`. `Woodev_Plugin_Bootstrap::instance()` may remain as a global facade for existing plugin entry files.

New Platform v2 support classes should not be added as new global symbols. Use global classes only for existing public APIs, compatibility facades, or explicit guarded aliases required by installed-site contracts.

Namespaced resolver classes must still be loaded explicitly by the compatibility entry path or the selected framework copy. Production plugins do not rely on Composer autoload at runtime.

The resolver owns:

- Plugin registration normalization.
- Framework version and path arbitration.
- Highest-compatible-framework selection.
- PHP, WordPress, framework, and platform requirement checks.
- Early platform class availability before plugin callbacks execute.
- Incompatible registration tracking.
- Admin notices and deactivation recovery for skipped registrations.
- The final `woodev_plugins_loaded` action timing.

The resolver does not own:

- Payment gateway internals.
- Shipping method internals.
- Licensing behavior.
- Plugin data migrations.
- Runtime WooCommerce behavior such as HPOS, Blocks, logger, templates, REST status, or settings wrappers.
- EDD runtime behavior.

ADR-001 should be treated as superseded for future implementation planning once this ADR is accepted.

## Alternatives Considered

- **Keep ADR-001 unchanged:** rejected because a broad platform-aware loader would keep accumulating platform decisions in the early bootstrap layer.
- **Delete `bootstrap.php` in v2.0:** rejected because production plugins include the vendored framework entry path and multi-plugin framework arbitration remains an installed-site risk.
- **Adopt SkyVerge-style versioned namespaces immediately:** rejected for v2.0 because platform boundaries are not stable enough and release automation/smoke tests are not yet defined.

## Consequences

Positive:

- Preserves the installed entry path while allowing the architecture to move away from a monolithic bootstrap class.
- Keeps multi-plugin framework arbitration as a first-class v2.0 requirement.
- Makes pure WordPress plugin loading possible without treating WooCommerce as globally required.
- Gives the next implementation spec a clear responsibility boundary.

Negative:

- Requires a careful facade design so old `register_plugin()` callers do not break before production plugins are migrated.
- Adds a new internal resolver abstraction that must be tested independently from WordPress hook timing.
- Requires explicit discipline to prevent the resolver from becoming a second framework kernel.
- Requires an early namespace refactor of the initial resolver implementation slice before expanding platform behavior.
- Requires include-path discipline so namespaced files are available without Composer autoload in vendored production plugins.

Follow-up requirements:

- Write `platform-v2-implementation-spec.md` before PHP implementation.
- Define resolver tests for multi-version arbitration, platform requirement checks, pure WordPress loading without WooCommerce, and WooCommerce plugin skipping when WooCommerce is unavailable.
- Decide the minimal legacy adapter surface that remains necessary during production plugin rewrites.

## Post-Implementation Verification — P5 minimization pass (2026-06-04)

After the P3 clean break (legacy adapter removed) and P4 base decomposition, `Framework_Resolver` is **641 lines / 15 public + 7 protected members**. Every member maps to an ADR-sanctioned responsibility; the resolver does **none** of the "does not own" list.

| Resolver member(s) | Owned responsibility (above) | Verdict |
|---|---|---|
| `register_loader_definition` | Plugin registration normalization | core ✓ |
| `load_plugins` | Highest-compatible selection + invocation + final `woodev_plugins_loaded` timing | core ✓ |
| `framework_compare`, `get_framework_version`, `get_plugin_path` | Framework version & path arbitration | core ✓ |
| `fails_php_requirement`, `fails_wordpress_requirement`, `fails_woocommerce_requirement`, `get_wc_version` | PHP/WP/framework/platform requirement checks | core ✓ |
| `load_early_capability_classes` | Early platform class availability before callbacks | core ✓ |
| `invoke_plugin` | Plugin callback / main-class invocation | core ✓ |
| `get_incompatible_{framework,php,wp,wc}_plugins`, `get_invalid_loader_definitions`, `has_update_notices` | Incompatible registration tracking | core ✓ |
| `render_update_notices`, `maybe_deactivate_framework_plugins` | Admin notices + deactivation recovery (renderers **injected** via constructor — H2, so the resolver emits no HTML itself) | core ✓ |
| `get_registered_plugins`, `get_active_plugins` | Registration/active state exposure | core ✓ |
| `__construct(?callable, ?callable)` | DI of the two notice renderers (decouples admin rendering from the kernel) | core ✓ |

**Does-not-own check:** no payment-gateway/shipping/licensing internals, no plugin data migrations, no runtime WooCommerce behavior (HPOS/Blocks/logger/templates/REST status/settings), no EDD runtime. All clear.

**Decisions (P5):**

- **No further extraction.** The real non-core debt was the legacy adapter (`register_legacy_plugin` / `from_legacy_registration` / the `is_payment_gateway`/`load_shipping_method` flag mapping) — removed in P3. The remaining members are all ADR-sanctioned core.
- **The compatibility-reporting cluster stays (not extracted into a `Compatibility_Report` object).** Extraction would only reorganize internal state *behind the `Woodev_Plugin_Bootstrap` facade that consumes these getters* — it would not shrink the effective public contract, and it adds indirection. Declined per `platform-v2-cleanbreak-plan.md` Step 5.2 ("if extraction adds indirection without clarity, document why the methods stay"). *Optional future internal tidy (not required for "minimal"):* collapse the four parallel `incompatible_*` arrays into one reason-keyed map — DRY only, no behavior change.
- **Bounded exception, recorded:** early WooCommerce HPOS/Blocks feature-compatibility declaration lives in `Woodev_Plugin_Bootstrap::register_loader_definition()` (NOT the resolver). ADR-003 lists HPOS/Blocks as runtime WC behavior the resolver must not own — and it does not. The bootstrap hosts it because `before_woocommerce_init` can fire before the resolver constructs plugin instances, so the early `add_action` must be registered at registration time when only the bootstrap is loaded. It is data-driven (reads loader-definition `supported_features`) and defers `FeaturesUtil` to the hook with a `class_exists` guard — the minimal platform decision that genuinely must live in the early layer.

**Kernel discipline (ADR Consequences "prevent the resolver from becoming a second framework kernel"):** the resolver-boundary negative test (resolver must not own runtime platform behavior) stays green; `composer check` green at 190 tests / 505 assertions.

## Related

- [PLANS.md](../../PLANS.md) — target platform-first architecture and open bootstrap question.
- [Platform v2 Strategy Alignment](../platform-v2-strategy-alignment.md) — hybrid roadmap and minimal resolver direction.
- [Platform v2 Next Analysis](../platform-v2-next-analysis.md) — detailed resolver responsibility mapping.
- [Platform v2 Dependency Matrix](../platform-v2-dependency-matrix.md) — current bootstrap risks and module dependency evidence.
- [ADR-001](001-bootstrap-platform-aware-loader.md) — previous bootstrap loader decision to supersede or reframe.
- [Platform v2 Epic 1 Spec](../platform-v2-epic1-spec.md) — existing implementation spec requiring revision.
