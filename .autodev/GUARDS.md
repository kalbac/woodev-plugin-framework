# GUARDS — the operator's trust list

> Registry of **blessed, mutation-verified** contract guards. A contract listed here
> with `mutation_verified: yes` AND a `blessed_by` operator is **autonomous**: the loop
> may commit changes touching that contract zone without escalation, because a test
> provably goes RED if the contract is broken. Everything not listed here escalates.
>
> A guard earns a row only after `tools/autodev/mutation-check.ps1` proves it goes RED
> when its `mutation-recipe.json` flips the contract, then GREEN when reverted. A guard
> that stays green on mutation, or ships no machine-checkable recipe, is **rejected** —
> the contract stays human-only (do not silently treat it as "guarded").
>
> `blessed_by` = the operator who approved the guard via escalation. `pending-operator`
> means the guard is mutation-proven and committed but awaiting the operator's A/B
> blessing reply — until blessed, the conductor still escalates that zone.

| contract_id | contract_value | guard_test | recipe | mutation_verified | blessed_by | date |
|-------------|----------------|------------|--------|-------------------|------------|------|
| shipping_method_id_edostavka | `edostavka` | `tests/unit/Contract/ShippingMethodIdContractTest.php` | `tests/unit/Contract/recipes/shipping-method-id-edostavka.recipe.json` | yes (red on flip) | maksim | 2026-06-04 |
| settings_option_key_edostavka | `woocommerce_edostavka_settings` | `tests/unit/Contract/SettingsOptionKeyContractTest.php` | `tests/unit/Contract/recipes/settings-option-key-edostavka.recipe.json` | yes (red on flip) | maksim | 2026-06-04 |
| shipping_method_id_yandex | `yandex_delivery_express` + `yandex_delivery_other_day` | `tests/unit/Contract/YandexShippingMethodIdContractTest.php` | `tests/unit/Contract/recipes/yandex-shipping-method-id.recipe.json` | yes (red on flip) | maksim | 2026-06-04 |
| settings_option_key_yandex | `woocommerce_yandex_delivery_settings` | `tests/unit/Contract/YandexSettingsOptionKeyContractTest.php` | `tests/unit/Contract/recipes/yandex-settings-option-key.recipe.json` | yes (red on flip) | maksim | 2026-06-04 |
| warehouse_table_name_yandex | `wc_yandex_delivery_warehouses` (name only; schema human-only) | `tests/unit/Contract/YandexWarehouseTableContractTest.php` | `tests/unit/Contract/recipes/yandex-warehouse-table.recipe.json` | yes (red on flip) | maksim | 2026-06-04 |
| order_meta_prefix_yandex | `_yandex_delivery_` | `tests/unit/Contract/YandexOrderMetaPrefixContractTest.php` | `tests/unit/Contract/recipes/yandex-order-meta-prefix.recipe.json` | yes (red on flip) | maksim | 2026-06-04 |

## Notes
- `mutation_verified: yes (red on flip)` is recorded only after a real run of
  `mutation-check.ps1`. See that run's output in the commit that adds the guard.
- Contracts with no machine-checkable recipe (cron-payload shape, DB schema) are
  **human-only** and must NOT appear here as guarded; list them in INVARIANTS.md with
  `auto_guardable: no` instead.
- The 4 yandex rows are **mutation-run and PASS** (GREEN → RED-on-flip → GREEN-on-revert,
  verified 2026-06-04 via `tools/autodev/mutation-check.ps1`) and **blessed by maksim
  (2026-06-04)** — their zones (`shipping_method_id`, `option_keys`, `order_session_meta`,
  and the warehouse table NAME) are now autonomous; the conductor commits changes touching
  them without escalation. (Warehouse 15-column schema stays human-only.)
- **Yandex REST namespace contract — RESOLVED (operator maksim, 2026-06-04).** The worker
  correctly refused to guard the recorded value `wc-yandex-delivery`: the namespace is
  `$plugin->get_id_dasherized()` of id `yandex_delivery` (`woocommerce-yandex-delivery.php:62`)
  → real live value is **`yandex-delivery`**. The operator corrected the constitution
  (INVARIANTS.md `rest.exact_strings` + yandex checklist) to `yandex-delivery`; the same
  id-error was fixed for the activation/milestone/setup-wizard option keys. The REST guard
  itself is **not yet written** — a follow-up task can now assert `yandex-delivery` at the
  id source. (Admin slug `wc-yandex-orders` and script handles keep the `wc-` prefix — those
  are literals, not id-derived.)
