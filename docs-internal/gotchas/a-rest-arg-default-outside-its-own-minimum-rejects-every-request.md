# gotcha: a REST argument whose `default` is outside its own `minimum` rejects every request that omits it

**Namespace:** `[api/rest]`
**Discovered:** s170 (2026-10-12), the admin order wizard's pickup map (#1227)

## What happened

The admin points route gained an optional `instance_id` argument:

```php
'instance_id' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 0, 'validate_callback' => 'rest_validate_request_arg' ],
```

WordPress validates a declared default exactly like a sent value. `0` fails `minimum: 1`, so every
request returned `400 rest_invalid_param`, including requests that never sent `instance_id`. The
wizard showed «Не удалось загрузить пункты выдачи». The unit tests stayed green because they called
the handler directly, which skips argument validation.

## ❌ Wrong

A `default` that the argument's own schema rejects, plus handler-only tests.

## ✅ Correct

Leave the optional argument without a default and treat `null` as absent in the handler. Guard the
schema itself with a test over the registered route args: no numeric default may fall outside its
`minimum` / `maximum` (`PickupControllerAdminRoutesTest::test_no_admin_route_argument_defaults_outside_its_own_bounds`).
Prove a new route on the rig with `rest_do_request()`, which runs the real validation.

## Related

- [phpunit-takes-one-path-and-silently-ignores-the-rest](phpunit-takes-one-path-and-silently-ignores-the-rest.md)
