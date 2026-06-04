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
<!-- empty at the start: almost everything escalates. Guard workloads fill this table; the operator blesses each row once. -->

## Notes
- `mutation_verified: yes (red on flip)` is recorded only after a real run of
  `mutation-check.ps1`. See that run's output in the commit that adds the guard.
- Contracts with no machine-checkable recipe (cron-payload shape, DB schema) are
  **human-only** and must NOT appear here as guarded; list them in INVARIANTS.md with
  `auto_guardable: no` instead.
