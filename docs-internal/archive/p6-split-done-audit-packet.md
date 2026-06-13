# External Audit Packet — S0 / Phase 6 (Split-Done Sign-Off)

> **For the operator:** paste to GPT-5.5 as the FINAL, holistic adversarial review of the whole platform split before it is tagged `platform-v2-split-done`. Per-phase audits (P2/P3/P4) already passed; this is the cross-cutting sign-off — find anything those missed. Return findings or "sign off".

## Role for the auditor
Independent adversarial reviewer. The team claims the Platform v2 "split" is done. Your job: try to prove it ISN'T — a residual coupling, a regression introduced across phases, or a broken installed-site contract that no single per-phase review caught. You did not write any of this.

## What "split done" claims (the definition being signed off)
1. `Woodev_Plugin` (base) is **genuinely platform-neutral** — no WooCommerce seams, loads/runs with WooCommerce absent.
2. The base is **no longer a god-object** — inline subsystems extracted to handlers; 1,435/87 → 1,296/77 lines/methods.
3. The **resolver is minimal** — only discovery/validation/invocation + reporting-of-those; legacy adapter gone (ADR-003 post-impl table).
4. **Clean break complete** — zero internal-API back-compat scaffolding (no `class_alias`, no deprecated shims, no legacy `register_plugin`).
5. **Installed-site data contracts preserved** byte-for-byte throughout (option keys, hook names, cron event + schedule, AJAX actions, REST namespaces, method/gateway IDs, meta keys, text domains, HPOS/Blocks declaration).

## Scope (cumulative diff — the whole split)
```
git diff platform-v2-pivot-baseline..7283aed     # 26 commits
```
Phase map (each already individually GPT-5.5-audited; packets in `docs-internal/reviews/`):
- **P1** clean-break policy reconciled (CLAUDE.md/AGENTS.md, ADR-005).
- **P2** edostavka-shaped pilot fixture proves the new load path (`register_loader_definition` → resolver → `Shipping_Plugin`).
- **P3** deleted all internal-API scaffolding (aliases, shims, legacy registration); resolver arbitration fixes (`backwards_compatible`, `loaded_framework` selection, invalid-`main_class` surfacing).
- **P4** extracted `Translation_Handler` + `Cron_Handler`; left `plugin_action_links`/api-logging on the base (polymorphic — overridden by payment gateway); removed `add_woocommerce_hooks` seam; HPOS/Blocks declaration moved early into `Woodev_Plugin_Bootstrap::register_loader_definition()` (load-order fix); orphaned `handle_features_compatibility` removed.
- **P5** resolver minimality verified + documented (ADR-003); no extraction needed.

State: `composer check` green — phpcs clean, phpstan L3 = 0 errors, phpunit **190 tests / 505 assertions**. Internal-API residue grep (`class_alias`/`register_legacy_plugin`/`from_legacy_registration` in `woodev/`): none.

Key files: `woodev/bootstrap.php`, `woodev/class-framework-resolver.php`, `woodev/class-framework-plugin-loader-definition.php`, `woodev/class-plugin.php`, `woodev/class-woocommerce-plugin.php`, `woodev/handlers/class-{translation,cron}-handler.php`.

## Questions for the auditor (cross-cutting — answer directly)
1. **Cumulative contract integrity:** Across ALL 26 commits, was any installed-site data contract broken or drifted — even transiently in a way the final state hides? Specifically re-verify: cron event `woodev_weekly_scheduled_events` + schedule `weekly`; AJAX `wp_ajax_woodev_verify_license`; api action `woodev_{id}_api_request_performed`; `plugin_action_links_{basename}` filter; text domains; the WC settings hooks re-homed in `register_woocommerce_hooks()`; HPOS/Blocks declaration semantics.
2. **Genuine platform-neutrality:** Beyond the "no woocommerce-named method" guard test — does ANY code path reachable from `Woodev_Plugin` construction or its handlers touch a `wc_*`/`WC_*`/WooCommerce symbol unguarded? Does the base + its 18 handlers + the 2 new handlers truly run with WooCommerce absent?
3. **Handler extraction correctness (whole):** Do `Translation_Handler` and `Cron_Handler` register exactly the hooks the base used to, with identical names/timing, and do their moved bodies behave identically (no `$this`→`$this->plugin` slip, no lost branch)?
4. **Resolver kernel discipline:** Is the resolver genuinely free of runtime platform behavior, or did the P4 HPOS fix (in the bootstrap) or anything else smuggle platform logic into the early layer in a way that will bite the future EDD platform?
5. **Cross-phase regressions:** Did a later phase silently undo or weaken an earlier phase's guarantee (e.g., did the P4 HPOS fix re-introduce a coupling P3/earlier-P4 removed)?
6. **Is it actually done?** Given the definition above, is there anything that should BLOCK tagging `platform-v2-split-done` and starting domain-module work (S1 shipping)? List must-fix vs nice-to-have.

Return: findings (severity + file:line) and a direct **sign-off: yes/no** on tagging the split done.
