# gotcha: `wp wc shipping_zone_method delete <zone> <instance>` deletes instance `<zone>`

**Namespace:** `[rig/probes]`
**Discovered:** s163 (2026-10-09), recurred s165 (2026-10-09), WooCommerce 11.1.0 CLI on the rig

## What happened

Twice in one day a rig agent removed the wrong shipping method. The intent was «delete instance 283 of zone 1»; the
command was `wp wc shipping_zone_method delete 1 283 --force=true --user=admin`. The WC CLI read the FIRST positional
number as the instance id, so it deleted instance **1** — the rig's «Free shipping» in zone «Russia» — and left 283 in
place. Both times the agent noticed only because it listed the zone afterwards, and restored the method from its
snapshot; the restored method gets a NEW instance id (s165: 1 → 284), so the rig no longer matches older notes.

In s165 the wrong order came from the coordinator's own brief, which spelled the command as `delete <zone> 283`.

## ❌ Wrong

```bash
wp wc shipping_zone_method delete 1 283 --force=true --user=admin   # deletes instance 1
```

## ✅ Right

Delete through REST, after a GET that proves the identity of the row:

```bash
# inside the CLI container, as admin
wp eval 'print_r( rest_do_request( new WP_REST_Request( "GET", "/wc/v3/shipping/zones/1/methods/283" ) )->get_data() );'
wp eval '$r = new WP_REST_Request( "DELETE", "/wc/v3/shipping/zones/1/methods/283" ); $r->set_param( "force", true ); print_r( rest_do_request( $r )->get_status() );'
```

Never put a `wp wc shipping_zone_method delete` line into a brief. The rig's tests match methods by method id
(`free_shipping`), not instance id, so a recreated method breaks nothing — but snapshots and notes drift.

## Related

- [a-rig-option-left-switched-after-an-acceptance-reads-as-stale-e2e-tests](a-rig-option-left-switched-after-an-acceptance-reads-as-stale-e2e-tests.md)
- `../gotcha-index/rig.md`
