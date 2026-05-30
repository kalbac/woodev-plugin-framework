# Platform v2 Migration Contract Template
> Status: template, reference-validated
> Date: 2026-05-30

## Purpose

This template is the Phase 6 entry artifact for production plugin migrations.

Use it to create one plugin-specific migration contract before any production plugin PHP rewrite begins. A filled contract is required before changing a production plugin loader, class inheritance, option layout, lifecycle migration routine, or installed-site behavior.

This template is not itself a migration contract for any plugin.

## Phase 6 Entry Constraints

- Create a plugin-specific contract document before rewriting that production plugin.
- Do not guess the first migration target from framework examples or illustrative loader snippets.
- Do not expand `woodev/bootstrap.php` or `Framework_Resolver` scope while drafting migration contracts.
- Do not move payment, shipping, licensing, lifecycle, REST, settings, or runtime behavior into the resolver.
- Keep production plugin runtime loading explicit and include-based.
- Preserve multi-version safety for early-loaded classes and guarded aliases.
- Preserve installed-site contracts even when plugin internals are rewritten.
- Treat missing plugin-specific evidence as a stop condition, not as a placeholder to fill later.
- Treat copied reference plugins as evidence for methodology only. Do not edit copied reference code and do not treat a reference draft as a production migration contract.

## Candidate Selection Gate

Complete this section before copying the rest of the template into a plugin-specific contract.

| Field | Required evidence | Status |
|-------|-------------------|--------|
| Selected plugin repository | Absolute path or repository name for the production plugin. | TODO |
| Current production release source | Git tag, release artifact, or deployed version reference. | TODO |
| Target migration version | Planned version that will ship the Platform v2 migration. | TODO |
| Plugin type | WordPress, WooCommerce, payment gateway, shipping method, or mixed. | TODO |
| Contract owner | Person/session responsible for confirming installed-site contracts. | TODO |
| Evidence completeness | All required contract sections below can be answered from source, docs, release history, or installed-site data. | TODO |

Stop if the selected plugin, production version, target version, or installed-site evidence is unknown.

## Contract Header

```markdown
# Platform v2 Migration Contract: {Plugin Name}
> Status: draft | ready-for-rewrite | rewrite-in-progress | verified | shipped
> Date: YYYY-MM-DD
> Plugin ID: {stable plugin id}
> Plugin slug: {stable plugin slug}
> Source version: {current production version}
> Target version: {target migration version}
> Repository: {production plugin repo/path}
```

## Installed-Site Identity

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Stable plugin ID | TODO | TODO | TODO | Preserve / migrate |
| Plugin slug | TODO | TODO | TODO | Preserve / migrate |
| Plugin basename | TODO | TODO | TODO | Preserve / migrate |
| Main plugin file | TODO | TODO | TODO | Preserve / migrate |
| Update identity | TODO | TODO | TODO | Preserve / migrate |
| Text domain | TODO | TODO | TODO | Preserve / migrate; identify whether it is explicit, header-derived, or implicit |

## Loader Contract

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Loader entry path | TODO | TODO | TODO | Preserve include path |
| Framework include path | TODO | TODO | TODO | Preserve include-based loading |
| Framework version | TODO | TODO | TODO | Update with vendored framework |
| Platform value | TODO | TODO | TODO | Use closed Platform v2 value |
| Main class | TODO | TODO | TODO | Validate inheritance/contract |
| Callback | TODO | TODO | TODO | Preserve boot timing |
| Early capabilities | TODO | TODO | TODO | Use only for class availability |
| Legacy `register_plugin()` args | TODO | TODO | TODO | Map only if migration window requires it |

## Options And Settings

| Contract item | Current key/value shape | Target key/value shape | Evidence | Migration action |
|---------------|-------------------------|------------------------|----------|------------------|
| Plugin options | TODO | TODO | TODO | Preserve / migrate idempotently |
| Settings arrays | TODO | TODO | TODO | Preserve / migrate idempotently |
| Feature flags | TODO | TODO | TODO | Preserve / migrate idempotently |
| Transients | TODO | TODO | TODO | Preserve / regenerate safely |
| Stored notices/admin dismissals | TODO | TODO | TODO | Preserve / reset intentionally |

## Legacy Migration Maps

Use this section when the current production plugin already migrated option, license, method, table, hook, or queue names in older releases.

| Legacy contract | Current replacement | Introduced/removed version | Evidence | Migration action |
|-----------------|---------------------|----------------------------|----------|------------------|
| Legacy option keys | TODO | TODO | TODO | Preserve existing migration or add idempotent map |
| Legacy license keys/state | TODO | TODO | TODO | Preserve existing migration or add idempotent map |
| Legacy hooks/actions/filters | TODO | TODO | TODO | Preserve wrapper or document removal evidence |
| Legacy queue hooks/groups | TODO | TODO | TODO | Preserve cleanup/reschedule behavior |
| Legacy method IDs/routes/tables | TODO | TODO | TODO | Preserve or migrate idempotently |

## Licensing And Updater State

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| License key option name | TODO | TODO | TODO | Preserve |
| License activation state option | TODO | TODO | TODO | Preserve |
| Instance ID option | TODO | TODO | TODO | Preserve |
| License status/cache options | TODO | TODO | TODO | Preserve / refresh safely |
| EDD download ID | TODO | TODO | TODO | Preserve unless release plan says otherwise |
| Updater state/options | TODO | TODO | TODO | Preserve continuity |
| Beta update opt-in | TODO | TODO | TODO | Preserve |

## WooCommerce Method Contracts

Use only the sections that apply to the selected plugin.

### Payment Gateway IDs

| Gateway | Current ID | Target ID | Evidence | Migration action |
|---------|------------|-----------|----------|------------------|
| TODO | TODO | TODO | TODO | Preserve unless explicitly migrated |

### Shipping Method IDs And Instance Settings

| Method | Current ID | Instance setting keys | Target ID/keys | Evidence | Migration action |
|--------|------------|-----------------------|----------------|----------|------------------|
| TODO | TODO | TODO | TODO | TODO | Preserve unless explicitly migrated |

## Public Extension Points

| Contract item | Current names | Target names | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Public actions | TODO | TODO | TODO | Preserve or add deprecated wrapper |
| Public filters | TODO | TODO | TODO | Preserve or add deprecated wrapper |
| Deprecated hook wrappers still used by sites | TODO | TODO | TODO | Preserve wrapper |
| Public PHP methods/classes called by integrations | TODO | TODO | TODO | Preserve or deprecate safely |

## Scheduled Work And Queues

| Contract item | Current shape | Target shape | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Cron hook names | TODO | TODO | TODO | Preserve / reschedule idempotently |
| Recurrence | TODO | TODO | TODO | Preserve / reschedule idempotently |
| Cron payload shape | TODO | TODO | TODO | Preserve / migrate idempotently |
| Action Scheduler hook names | TODO | TODO | TODO | Preserve / migrate idempotently |
| Action Scheduler recurrence/single mode | TODO | TODO | TODO | Preserve / reschedule idempotently |
| Action Scheduler payload shape | TODO | TODO | TODO | Preserve / migrate idempotently |
| Action Scheduler group names | TODO | TODO | TODO | Preserve / clean up intentionally |
| Queue identifiers | TODO | TODO | TODO | Preserve / migrate idempotently |
| Queue state options/tables | TODO | TODO | TODO | Preserve / migrate idempotently |
| Background job identifiers | TODO | TODO | TODO | Preserve / migrate idempotently |

## Stored Data Schemas

| Contract item | Current schema | Target schema | Evidence | Migration action |
|---------------|----------------|---------------|----------|------------------|
| Custom database tables | TODO | TODO | TODO | Preserve / migrate idempotently |
| Custom post types/statuses | TODO | TODO | TODO | Preserve / migrate idempotently |
| WooCommerce data-store keys | TODO | TODO | TODO | Preserve / migrate idempotently |
| Post meta keys | TODO | TODO | TODO | Preserve / migrate idempotently |
| Order meta keys | TODO | TODO | TODO | Preserve via HPOS-safe access |
| User meta keys | TODO | TODO | TODO | Preserve / migrate idempotently |

## Checkout And Frontend State

Use this section for non-option state that affects checkout continuity or custom integrations.

| Contract item | Current shape | Target shape | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| WooCommerce session keys | TODO | TODO | TODO | Preserve or reset intentionally |
| Checkout POST field names | TODO | TODO | TODO | Preserve or add compatibility handling |
| Shipping package payload keys | TODO | TODO | TODO | Preserve or migrate idempotently |
| Shipping rate meta keys | TODO | TODO | TODO | Preserve or migrate idempotently |
| Frontend localized object names | TODO | TODO | TODO | Preserve when external scripts may depend on them |

## Web And Admin Surface

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| REST namespaces | TODO | TODO | TODO | Preserve or version intentionally |
| REST route names | TODO | TODO | TODO | Preserve or add compatibility route |
| WooCommerce API callback endpoints | TODO | TODO | TODO | Preserve webhook/payment callback URL shape |
| AJAX action names | TODO | TODO | TODO | Preserve |
| WooCommerce AJAX action names | TODO | TODO | TODO | Preserve |
| Admin page slugs | TODO | TODO | TODO | Preserve |
| Capability checks | TODO | TODO | TODO | Preserve or document intended change |
| System-status rows | TODO | TODO | TODO | Preserve source/name |

## Operational Surface

| Contract item | Current value | Target value | Evidence | Migration action |
|---------------|---------------|--------------|----------|------------------|
| Log source names | TODO | TODO | TODO | Preserve |
| Email IDs | TODO | TODO | TODO | Preserve |
| Email template paths | TODO | TODO | TODO | Preserve or document intentional override break |
| Email placeholders/template variables | TODO | TODO | TODO | Preserve or migrate intentionally |
| WooCommerce note sources | TODO | TODO | TODO | Preserve |
| Webhook identifiers | TODO | TODO | TODO | Preserve / migrate intentionally |
| Webhook callback URL/action names | TODO | TODO | TODO | Preserve / migrate intentionally |
| CLI commands | TODO | TODO | TODO | Preserve / migrate intentionally |

## Migration Routine Rules

- Every migration routine must be idempotent.
- Data writes must be non-destructive by default.
- Old-to-new option key maps must be explicit.
- Old-to-new license, hook, queue, table, route, and method-ID maps must be explicit when a historical production release already changed them.
- Failure behavior must be explicit: stop, retry, mark partial, or show admin notice.
- Migration diagnostics must be logged through lifecycle events or another documented support surface.
- Latest production-version upgrade path must have tests.
- At least one older supported production-version upgrade path must have tests when feasible.

## Release-Blocking Verification Gates

- Fresh install works.
- Upgrade from latest production works.
- Upgrade from at least one older supported production version works when feasible.
- License remains active after migration.
- Updater identity remains continuous.
- Existing settings remain unchanged unless an explicit migration says otherwise.
- Payment or shipping method IDs remain stable where user configuration depends on them.
- Public hooks still fire or deprecated wrappers exist.
- Scheduled events remain correct after activation, deactivation, and upgrade.
- Stored data schemas are preserved or migrated idempotently.
- Plugin-specific contract checklist passes in the production plugin repository.
- Framework checks and plugin checks pass before release.

## Stop Conditions

- Stop if the plugin target is not explicitly selected.
- Stop if the current production version or target migration version is unknown.
- Stop if option keys, license state, method IDs, public hooks, scheduled events, or stored schemas cannot be audited from evidence.
- Stop if the work starts changing production plugin PHP before the contract reaches `ready-for-rewrite`.
- Stop if the planned change expands resolver/bootstrap scope or moves runtime behavior into early loading.
- Stop if compatibility assumptions cannot be justified from source, docs, release history, or installed-site data.

## Related

- [Platform v2 Implementation Spec](platform-v2-implementation-spec.md) — active source of truth for Phase 6 gates.
- [Platform v2 Strategy Alignment](platform-v2-strategy-alignment.md) — rewrite-first policy and installed-site contract boundary.
- [Platform v2 Next Analysis](platform-v2-next-analysis.md) — migration contract model and checklist evidence.
- [ADR-003](adr/003-platform-v2-minimal-framework-resolver.md) — resolver responsibility boundary.
- [ADR-004](adr/004-platform-v2-plugin-loader-api.md) — explicit plugin loader API and metadata limits.
