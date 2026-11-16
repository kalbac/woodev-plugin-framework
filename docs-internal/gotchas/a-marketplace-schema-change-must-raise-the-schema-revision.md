# A marketplace schema change must raise `Schema::REVISION` — and declare the columns it needs

**Namespace:** `[framework/marketplace-schema]`
**Discovered:** 2026-11-17 (s234, card D #1797, its round-2/3 critics; fixed by #1829)

## The trap

The marketplace tables (`links`, `sync_state`, `bulk_runs`, …) used to be created only by the consuming plugin's
install/upgrade, which runs when THAT plugin's version changes, and only on an admin request. But the loader runs the
highest framework copy among ALL active plugins (gotcha
[an-unbumped-plugin-on-the-rig-pins-an-older-framework-for-every-plugin](an-unbumped-plugin-on-the-rig-pins-an-older-framework-for-every-plugin.md)):
a newer framework can arrive inside another plugin while Ozon Seller's version stays the same. Its new code then reads a
column or table the site never got — stock/price recording throws, and a price pull read «nothing queued» and reported
success while an old send stayed due.

Since 2.1.10 the framework owns a schema revision (`Schema::REVISION`, option `woodev_{plugin id}_schema_revision`,
`Lifecycle::bring_schema_up_to_date()` on `init` priority 2, any request). It only works if every schema change raises it.
And `dbDelta` can fail an `ALTER … ADD COLUMN` silently while every table still exists, so the revision is saved only after
the declared required columns are verified (`Schema::REQUIRED_COLUMNS`); a missing one raises `Schema_Exception` into the
notice + five-minute retry path.

## Wrong

Add a column to a `CREATE TABLE` statement in `class-schema.php` and rely on the plugin's next upgrade to `dbDelta` it.

## Correct

In the same change: raise `Schema::REVISION`, add the new column to `Schema::REQUIRED_COLUMNS`, and add an upgrade test that
starts from the previous table shape with an UNCHANGED plugin version on a non-admin request
(`tests/integration/Marketplace/SchemaRevisionUpgradeTest.php` is the pattern, including a refused `ALTER`).

## Related

- [an-unbumped-plugin-on-the-rig-pins-an-older-framework-for-every-plugin](an-unbumped-plugin-on-the-rig-pins-an-older-framework-for-every-plugin.md)
- Session [s234](../sessions/s234.md)
