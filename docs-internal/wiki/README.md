# Wiki — Compiled Topic References

Deep-dive articles on specific framework topics, patterns, and conventions.
Unlike gotchas (which are "don't do X, do Y"), wiki articles explain concepts
and provide reference material.

## Articles

- [v2 extension-point pattern: WooCommerce hook registration](v2-extension-point-pattern.md) — WC hooks are registered by `Woocommerce_Plugin` from its own constructor; the platform-neutral base declares no WC-named method (the old `add_woocommerce_hooks()` stub was removed in P4 Task 5)
- [Capability-Gated Feature Seam](capability-gated-feature-seam.md) — the reference pattern for optional behaviour: feature logic at a guaranteed invocation point, gated by `supports( FEATURE_* )`, delegating to a named seam, inert by default. `payment-gateway` is the exemplar; the s3 shipping box-packing seam is the newest instance (ADR-006)


## Article Format

```markdown
# {Title}

## Overview

{What this article covers}

## Details

{In-depth explanation with code examples}

## Related

- Links to related gotchas, ADRs, and other wiki articles
```

## Related

- [[../GOTCHAS.md]] — gotcha index
- [[../adr/README.md]] — architecture decisions
