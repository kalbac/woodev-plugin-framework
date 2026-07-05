# Adding a method to a Mockery-mocked class → stub it AND run the FULL unit suite

**Namespace:** `[testing/unit]`
**Discovered:** s40 (2026-07-05), conditional-fields `show_if`

## The trap

When you add a new public method to a class and then **call it from a shared code path** (e.g. a REST controller's `save()`), every existing unit test that builds that dependency as a **strict `Mockery::mock()`** breaks with:

```
Mockery\Exception\BadMethodCallException: Received Mockery_0_Woodev_Abstract_Settings::filter_visible_values(), but no expectations were specified
```

A strict Mockery mock throws on ANY un-stubbed method call. So adding `filter_visible_values()` and wiring it into `class-rest-api-settings-page.php` + `class-rest-api-setup.php` made 10 pre-existing controller unit tests (`SettingsRestControllerTest`, `SetupWizardRestControllerTest`) error — even though the production code and the integration test were correct.

## Why it slipped past a subagent

The task that added the REST wiring ran only its **targeted integration test** (`composer test:integration --filter …`) + `phpcs` — both green. It did **not** re-run the full `composer test:unit`, so the mock regression was invisible until the final all-green check. The integration test used a real handler (which has the method); the unit tests used strict mocks (which don't).

## The fix + the rule

1. **Stub the new method (pass-through) on every affected mock:**
   ```php
   $handler->shouldReceive( 'filter_visible_values' )->andReturnUsing( static fn( $values ) => $values );
   ```
   Add it WITHOUT `->once()` so it tolerates zero-or-more calls across the different test paths.
2. **After wiring a new call into any shared/production path, run the FULL `composer test:unit`** — never conclude from a targeted or integration-only run. This is a standing rule for implementer subagents (see `next-session-prompt.md` process notes). The same class of stub was also needed for `get_show_if_conditions()` in the `Field_Schema` mock tests (`FieldSchemaTest`, `SettingsPageRegistryTest`, `SetupWizardFieldSchemaTest`).

## Related

- [[../SESSION-LOG.md]] — s40 (both the crash-on-unregistered-id and this mock regression were caught by review/final-check, not CI-first)
- [[phpunit-multiple-file-args.md]] — another "your green run didn't actually run everything" trap
