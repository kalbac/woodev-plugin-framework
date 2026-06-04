# ESCALATION bless-guard-yandex-contracts -- bless new contract guards

**Task:** guard-yandex-contracts -- Write mutation-verified yandex contract guards (second pilot)
**Type:** constitution
**What happened:** Committed 013b0fb: mutation-verified guards + GUARDS.md rows (blessed_by pending-operator). Until you bless, those zones still escalate.
**Decision you need to make:** Bless these guards so their contract zones become autonomous?
**Option A:** Bless (set blessed_by to your name in GUARDS.md)
**Option B:** Reject / keep human-only
**Cost of being wrong:** a wrong guard would auto-pass a real contract break

**Evidence:**
```
{
    "task_id":  "guard-yandex-contracts",
    "composer_green":  true,
    "constitution_touched":  [

                             ],
    "zones_touched":  [
                          {
                              "id":  "option_keys",
                              "auto_guardable":  true,
                              "guarded":  true,
                              "guard_test":  "tests/unit/Contract/SettingsOptionKeyContractTest.php",
                              "mutation_passed":  true,
                              "blessed":  true
                          },
                          {
                              "id":  "shipping_method_id",
                              "auto_guardable":  true,
                              "guarded":  true,
                              "guard_test":  "tests/unit/Contract/ShippingMethodIdContractTest.php",
                              "mutation_passed":  true,
                              "blessed":  true
                          },
                          {
                              "id":  "cron",
                              "auto_guardable":  false,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          },
                          {
                              "id":  "rest",
                              "auto_guardable":  true,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          },
                          {
                              "id":  "log_source",
                              "auto_guardable":  true,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          },
                          {
                              "id":  "order_session_meta",
                              "auto_guardable":  true,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          },
                          {
                              "id":  "db_schema",
                              "auto_guardable":  false,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          }
                      ],
    "decision":  "ESCALATE",
    "reasons":  [
                    "zone \u0027cron\u0027 touched but is NOT auto_guardable (human-only: Scheduled cron hook names + recurrence + PAYLOAD SHAPE. Payload shape is NOT mechanically mutatable -\u003e human-only.)",
                    "zone \u0027rest\u0027 touched but no mutation-verified guard covers it (needs guard)",
                    "zone \u0027log_source\u0027 touched but no mutation-verified guard covers it (needs guard)",
                    "zone \u0027order_session_meta\u0027 touched but no mutation-verified guard covers it (needs guard)",
                    "zone \u0027db_schema\u0027 touched but is NOT auto_guardable (human-only: Custom DB tables/schemas. Schema diffs are NOT mechanically mutatable -\u003e human-only.)"
                ],
    "changed_files":  [
                          "autodev/queue/pending/guard-yandex-contracts.md",
                          "serena/project.yml",
                          "tools/autodev/_common.ps1",
                          "tools/autodev/invoke-worker.ps1",
                          "tools/autodev/watchdog.ps1",
                          "autodev/queue/active/guard-yandex-contracts.md",
                          "serena/memories/memory_maintenance.md"
                      ]
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
