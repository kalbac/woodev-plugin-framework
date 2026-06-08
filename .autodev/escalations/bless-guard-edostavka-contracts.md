# ESCALATION bless-guard-edostavka-contracts -- bless new contract guards

**Task:** guard-edostavka-contracts -- Write mutation-verified edostavka contract guards (shipping method id + settings option key)
**Type:** constitution
**What happened:** Committed 6147853: mutation-verified guards + GUARDS.md rows (blessed_by pending-operator). Until you bless, those zones still escalate.
**Decision you need to make:** Bless these guards so their contract zones become autonomous?
**Option A:** Bless (set blessed_by to your name in GUARDS.md)
**Option B:** Reject / keep human-only
**Cost of being wrong:** a wrong guard would auto-pass a real contract break

**Evidence:**
```
{
    "task_id":  "guard-edostavka-contracts",
    "composer_green":  true,
    "constitution_touched":  [
                                 "autodev/GUARDS.md"
                             ],
    "zones_touched":  [
                          {
                              "id":  "option_keys",
                              "auto_guardable":  true,
                              "guarded":  true,
                              "guard_test":  "tests/unit/Contract/SettingsOptionKeyContractTest.php",
                              "mutation_passed":  true,
                              "blessed":  false
                          },
                          {
                              "id":  "shipping_method_id",
                              "auto_guardable":  true,
                              "guarded":  true,
                              "guard_test":  "tests/unit/Contract/ShippingMethodIdContractTest.php",
                              "mutation_passed":  true,
                              "blessed":  false
                          }
                      ],
    "decision":  "ESCALATE",
    "reasons":  [
                    "constitution path(s) changed: autodev/GUARDS.md",
                    "zone \u0027option_keys\u0027 guarded + mutation-proven but guard not yet blessed by operator",
                    "zone \u0027shipping_method_id\u0027 guarded + mutation-proven but guard not yet blessed by operator"
                ],
    "changed_files":  [
                          "autodev/GUARDS.md",
                          "serena/project.yml",
                          "tests/unit/Contract/SettingsOptionKeyContractTest.php",
                          "tests/unit/Contract/ShippingMethodIdContractTest.php",
                          "tests/unit/Contract/recipes/settings-option-key-edostavka.recipe.json",
                          "tests/unit/Contract/recipes/shipping-method-id-edostavka.recipe.json",
                          "tools/autodev/_common.ps1",
                          "tools/autodev/conductor.ps1",
                          "tools/autodev/invoke-critic.ps1",
                          "autodev/queue/active/guard-edostavka-contracts.md",
                          "serena/memories/memory_maintenance.md",
                          "tools/autodev/anti-drift.ps1"
                      ]
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
