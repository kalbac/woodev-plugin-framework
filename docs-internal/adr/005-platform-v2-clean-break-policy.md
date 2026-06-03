# ADR-005: Platform v2 Clean-Break Policy (internal break, preserve data)

**Status:** accepted

**Date:** 2026-06-03

## Context

`CLAUDE.md` / `AGENTS.md` mandated a strict deprecation cycle for every public method/class ("NEVER delete/rename without a deprecation cycle; ALWAYS add `@deprecated` + `_deprecated_function()`; minimum one full version before removal"), justified by "10+ dependent plugins." `PLANS.md` §2.4 states the opposite intent: Platform v2 is effectively a new framework, the old one will largely disappear, and the dependent plugins will be rewritten onto the new one ("поломки в плагинах на старых версиях фреймворка не страшны: их всё равно перепишем").

The 2026-06-03 direction audit (§4.2) identified this contradiction as the dominant source of wasted effort: every refactor paid a back-compat tax (a deprecation shim, often a global `class_alias`, plus a shim-specific test) for plugins that are going to be rewritten anyway. The operator resolved the open question (decision **D-2**).

## Decision

Adopt a **clean code break with data preservation**, on a dedicated branch (`refactor/platform-v2-clean-break`):

- **Internal code is free to break:** class names, method signatures, the plugin entry/registration shape, namespacing, file layout. Do **not** add `@deprecated` shims, `class_alias` files, or `_deprecated_function()` wrappers for moved/renamed internal APIs. Delete the existing ones.
- **Installed-site data contracts are release-blocking and must never change:** option keys & settings arrays, license key option names + activation state + instance IDs, updater identity, WC payment-gateway IDs, WC shipping-method IDs + instance setting keys, public action/filter hook names, scheduled cron hooks + recurrence + payload shape, custom DB tables/schemas, REST route namespaces, AJAX action names, admin page slugs, log source names, background-job IDs, order/session meta keys.
- Data preservation is enforced **per plugin, at rewrite time**, via `docs-internal/migration/<plugin>-data-preservation-checklist.md` — not as upfront paperwork (audit §4.3 demoted the migration-contract template to this role).
- `CLAUDE.md` / `AGENTS.md` are reconciled to this policy; ADR-002's metadata-bridge portion is superseded.

## Consequences

- **Easier:** refactors no longer carry shim + alias + shim-test overhead; the resolver sheds its legacy adapter; the base sheds WC seams and deprecated methods; the code reads as one framework, not a museum of compatibility layers.
- **Harder / accepted cost:** dependent plugins built against the old internal API break until they are rewritten onto v2. This is acceptable by operator decision — those plugins are being rewritten regardless. Removals happen on the branch; a released v2 ships only once the consuming plugins migrate.
- **Risk guarded:** the installed-site data list above is the hard boundary. Breaking any of it is a release blocker, independent of the internal-code freedom. The per-plugin checklist is the enforcement mechanism.

## Related

- [platform-v2-direction-audit-2026-06-03.md](../platform-v2-direction-audit-2026-06-03.md) — D-2 (§5) + evidence (§4.2)
- [ADR-002](002-plugin-type-inheritance-with-metadata-bridge.md) — metadata-bridge portion superseded here
- [platform-v2-execution-protocol.md](../platform-v2-execution-protocol.md) — §2 operationalizes this policy
