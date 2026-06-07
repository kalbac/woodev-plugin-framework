---
id: s1-test-scaffold-extract
title: Extract shared pilot-fixture test scaffold (resolver + WP stubs)
phase: P6 Wiring + Gate
type: refactor
touches_contract_zone: false
writes_guard: false
file_set:
  - tests/unit/Support/Pilot_Testable_Framework_Resolver.php
  - tests/unit/Support/Pilot_Fixture_WP_Stubs.php
  - tests/unit/EdostavkaPilotFixtureTest.php
  - tests/unit/RealisticShippingFixtureTest.php
  - tests/unit/RealisticPaymentFixtureTest.php
depends_on: []
needs_guard: no
acceptance:
  - composer test green
  - no `*_Testable_Framework_Resolver` subclass and no `mock_wordpress_runtime_functions()` body is defined more than once across tests/unit/
---

# Task

Precursor split from `s1-fixture-yandex` (operator approved the worker's TOO_BIG decomposition,
2026-06-07). Test-only refactor — touches NO production code and asserts NO live contract strings.

Each pilot fixture test currently DUPLICATES the same test scaffold (verified by the worker):
- `tests/unit/EdostavkaPilotFixtureTest.php` (resolver subclass + `mock_wordpress_runtime_functions()` + `class_alias` WC/REST stubs)
- `tests/unit/RealisticShippingFixtureTest.php` (same shape)
- `tests/unit/RealisticPaymentFixtureTest.php` (same shape)

`docs-internal/platform-v2-program-tracker.md` flagged: "When a 3rd such fixture lands, extract a
shared trait/base under `tests/unit/` instead of copying again." This is that extraction, done
BEFORE the 3rd fixture (`s1-fixture-yandex`, Task B) lands.

## What to build

Extract the duplicated scaffold into TWO shared, PSR-4-autoloadable units under
`tests/unit/Support/` (autoload-dev already maps `Woodev\Tests\Unit\` -> `tests/unit/`, so NO
composer.json change is needed; keep ONE class/trait per file, filename === type name):

1. `tests/unit/Support/Pilot_Testable_Framework_Resolver.php`
   - `namespace Woodev\Tests\Unit\Support;` — a base `Pilot_Testable_Framework_Resolver` that
     carries the `get_plugin_path()` / `get_wc_version()` (etc.) overrides the three fixtures copy.
     Keep it parameterizable (constructor or setters) so each fixture supplies its own plugin path.
2. `tests/unit/Support/Pilot_Fixture_WP_Stubs.php`
   - `namespace Woodev\Tests\Unit\Support;` — a `Pilot_Fixture_WP_Stubs` trait exposing
     `mock_wordpress_runtime_functions()` and the idempotent `class_alias` WC/REST stub installer
     (each stub guarded by `class_exists( ..., false )` so re-use across tests is safe).

Then RETROFIT the three existing fixture tests to consume these (delete their local copies; use the
shared base/trait). Behavior must be identical — `composer test` stays green with the same coverage.

## Constraints
- Pure test refactor. Do NOT touch `woodev/` production code. Do NOT change any asserted contract
  string. Do NOT alter what the tests prove — only DE-DUPLICATE the scaffolding.
- Keep one class/trait per file (PSR-4); do not add a classmap or edit composer.json (verify the
  existing `Woodev\Tests\Unit\` autoload-dev mapping covers `Support/` first — it does).

<!-- committed: e3e31ac -->
