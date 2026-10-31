# Gotcha: [lifecycle/schema-upgrade] — `ALTER TABLE … DROP COLUMN` on MariaDB / MySQL 8.0.29+ is INSTANT and frees no disk space

> Tags: lifecycle, schema, mariadb, mysql, disk | Session: s207 (31.10.2026)
> **Measured on:** the Mac rig, MariaDB 12.3, Ozon catalogue table `wp_woodev_ozon_points` (93 053 rows).

## What happens

Ozon 2.0.0 slimmed its catalogue table by dropping the 152.5 MB `schedule` column (and the unused `updated_at`) in an upgrade
routine. On the rig the migration ran and reported success, the columns were gone — and the table was still **247.39 MB** before
and after. After a full re-sync it was 247.36 MB. Only `OPTIMIZE TABLE` brought it to **34.58 MB**.

## Root cause

MariaDB 10.3+ and MySQL 8.0.29+ drop a column with the INSTANT algorithm: the dictionary changes, the rows are not rewritten, and
the old bytes stay in the pages until the table is rebuilt. A unit test that checks «the column no longer exists» passes while
the disk figure that motivated the change is unchanged. A fresh install is unaffected, so only upgraded sites keep the weight.

## Fix

❌ Wrong — dropping a column and calling the table slimmed:

```php
$wpdb->query( "ALTER TABLE {$table} DROP COLUMN schedule, DROP COLUMN updated_at" );
```

✅ Correct — when the point of the upgrade is space, rebuild the table (or truncate and re-fill it, when the data is a cache):

```php
$wpdb->query( "ALTER TABLE {$table} DROP COLUMN schedule, DROP COLUMN updated_at, FORCE" ); // or: OPTIMIZE TABLE {$table}
```

Measure `data_length + index_length` BEFORE and AFTER the upgrade on a real site, not the column list. Do not trust
`information_schema` row estimates either (69 473 estimated against 93 053 counted).

## Related

- [lifecycle-install-upgrade-detection](lifecycle-install-upgrade-detection.md) — how the upgrade routine is reached at all
