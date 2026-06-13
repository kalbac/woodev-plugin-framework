# Local e2e rig: `wp_safe_remote_request` blocks the private issuer host + non-standard port

**Namespace:** `testing/integration`
**Discovered:** session 11 (2026-06-13), local two-stack rig (issuer :8090 + stand :8888)

## The trap

The framework licensing transport (`Woodev_API_Base::do_remote_request`) uses
**`wp_safe_remote_request`**, whose `wp_http_validate_url`:
1. rejects **private/loopback/reserved** hosts (e.g. `host.docker.internal`, which
   resolves to the Docker host's private IP), and
2. restricts ports to **80 / 443 / 8080** only.

A local rig points the stand at the issuer on `http://host.docker.internal:8090`
→ `wp_safe_remote_request` returns `WP_Error` → `handle_response()` throws
`Woodev_API_Exception` → `dispatch()`/`validate_license()` swallow it silently →
the pull channel never runs, **with no error logged**. Symptom: the real
`validate_license` path "does nothing" while a hand-rolled `wp_remote_post`
(no `_safe_`) succeeds, sending you on a wild goose chase.

This is a **local-rig artifact, not a framework bug** — in production the issuer
(woodev.ru) is a public host on 443, so `wp_safe_remote_request` works.

## Fix (in the local stand plugin ONLY — never the framework)

```php
add_filter( 'http_request_host_is_external', static function ( $ext, $host ) {
    return ( 'host.docker.internal' === $host ) ? true : $ext;
}, 10, 2 );
add_filter( 'http_allowed_safe_ports', static function ( $ports ) {
    $ports[] = 8090; return $ports;
} );
```

Plus point licensing at the issuer via the `woodev_licensing_api_url` filter
(added in 2.0.1), and `define( 'WOODEV_LICENSE_AUTHORITY_PUBKEY', <local pubkey> )`
before the framework loads so the stand verifies the LOCAL issuer's signature.

## Cross-container note

Two wp-env stacks are isolated Docker networks: from the stand container,
`localhost:8090` is itself — reach the issuer via `host.docker.internal:8090`.
**Push** (issuer→stand) can't work cross-container (+ SSRF), so a local rig
exercises the **PULL** channel (stand→issuer, rides `check_license`). Also relax
the issuer's `Push_Delivery::is_safe_target()` under `wp_get_environment_type()
=== 'local'` so `Command_Queue::issue()` will queue a command for the private
stand host.

## Related
- [[wpenv-windows-gitbash-path-mangling]] — other wp-env-on-Windows gotchas
- [[wpenv-resolver-fixture-mapping]] — wp-env framework mapping
