# Gotcha: [build/release] — EDD publish-release does not set «Файл обновления»

> Tags: release, edd, software-licensing | Session: s202 (T-Bank download 1849)

## What happens

After a release goes through the woodev-release abilities, the EDD download has no «Файл обновления»
(`_edd_sl_upgrade_file_key`). It is unset on download 1849 (T-Bank) and on Russian Post 8772; only CDEK 216 has it.

## Root cause

`publish-release` uploads the file but never writes the upgrade-file key. EDD Software Licensing falls back to the first file
of the download, so nothing breaks while the download has exactly one file.

## Fix

❌ **Wrong** — assume the update API serves the new ZIP because the product page does.

✅ **Correct** — add a line to every release: read `_edd_sl_upgrade_file_key` on the download, and set it when the download has
more than one file or ever will. Verify with the update API (`get_version`) after publishing; keep the previous file ready for
rollback.

## Related

- [Releasing a plugin (skill)](../../.ai/skills/woodev-plugin-core/layout-and-testing.md#releasing-a-plugin)
- [build topic index](../gotcha-index/build.md)
