# Bulk run scale test — the rig phase of #1795 (E2)

The acceptance of card E2 asks for a run over a 10 000-product catalogue with the per-tick wall time, peak memory, query count and
offers processed stated in the PR. Nothing here runs in CI; the coordinator runs it on the rig after the PR.

| File | What it does |
|---|---|
| `generate-catalogue.php` | `wp eval-file`: creates the catalogue and the link rows, **by direct SQL** (a `WC_Product::save()` per product would take hours) |
| `measure-mu-plugin.php` | copy to `wp-content/mu-plugins/`: appends one JSON line per tick to `wp-content/bulk-run-ticks.jsonl` |
| `start-run.php` | `wp eval-file`: starts a run through the table's provider, exactly as the REST apply does |
| `report.php` | `wp eval-file`: prints the ticks and the final counts of the run as a table |

## The catalogue (spec §6, E2)

10 000 parent products = 8 000 simple + 1 998 variable × 5 variations + 1 variable × 101 + 1 variable × 501 → **18 592 offers**.
Link rows (state `created`, both switches **off**) for the first 6 000 simple products and for every variation: **16 592** linked
offers. The last 2 000 simple products have no link row.

## Steps

```bash
# 0. The plugin id and the account id of the rig (the account must exist and be enabled).
PLUGIN=ozon-seller; ADAPTER=ozon; ACCOUNT=1

# 0b. The `bulk_runs` table is created by the plugin's install / upgrade, which runs only when the plugin version changes. On a rig
#     whose plugin version did not change, create it first (idempotent: dbDelta changes only the difference).
wp eval 'require_once ABSPATH . "wp-admin/includes/upgrade.php"; global $wpdb; dbDelta( array_values( ( new Woodev\Framework\Marketplace\Data\Schema( "'$ADAPTER'" ) )->statements( $wpdb->prefix, $wpdb->get_charset_collate() ) ) );'

# 1. Catalogue (idempotent: it refuses to run when the first fixture product already exists; add `reset` to delete and rebuild).
wp eval-file tests/integration/fixtures/bulk-run/generate-catalogue.php $ADAPTER $ACCOUNT

# 2. Measurement hook.
cp tests/integration/fixtures/bulk-run/measure-mu-plugin.php wp-content/mu-plugins/bulk-run-measure.php
rm -f wp-content/bulk-run-ticks.jsonl

# 3. Start a run (no filter = every product) and drive the queue until the RUN is finished.
#    Do not loop on the pending count of the hook: the recurring recovery sweep (args `[0,'']`) is always pending, so it never reaches 0.
wp eval-file tests/integration/fixtures/bulk-run/start-run.php $PLUGIN $ACCOUNT sync_stock_on
HOOK=woodev_${PLUGIN//-/_}_bulk_run
until [ "$(wp db query "SELECT state FROM $(wp db prefix)woodev_${ADAPTER}_bulk_runs WHERE account_id = $ACCOUNT" --skip-column-names)" != running ]; do
  wp action-scheduler run --hooks=$HOOK --batches=1
done

# 4. The table.
wp eval-file tests/integration/fixtures/bulk-run/report.php $PLUGIN $ACCOUNT
```

Expected (the acceptance): `sync_stock_on` by no filter ends with **products 10 000, accepted 16 592, skipped 2 000
(«не отправлялся на маркетплейс»), failed 0**; `set_warehouse` (pass the warehouse id as the 4th argument of `start-run.php`) the same
partition; `export` with the filter `state=unlinked` (5th argument `unlinked`) reaches exactly the 2 000 unlinked offers
(`accepted + skipped + failed = 2000`, products 2 000). Every tick under 20 s, peak memory flat across ticks. Between two runs rebuild the catalogue:
`wp eval-file .../generate-catalogue.php $ADAPTER $ACCOUNT reset` (it deletes the fixture products, their links and their sync state),
and clear the ticks: `rm -f wp-content/bulk-run-ticks.jsonl`. After the last run remove the fixture without rebuilding it —
`wp eval-file .../generate-catalogue.php $ADAPTER $ACCOUNT delete` — then the measurement mu-plugin and `bulk-run-ticks.jsonl`.

`start-run.php` arguments: `<plugin id> <account id> <action> [warehouse id or 0] [state filter]`.
