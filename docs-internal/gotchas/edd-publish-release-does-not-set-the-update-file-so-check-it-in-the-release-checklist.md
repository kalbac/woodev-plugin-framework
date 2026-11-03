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

✅ **Correct** — in every release set `_edd_sl_upgrade_file_key` to the key of the file just uploaded, ALWAYS (operator rule,
03.11.2026), and name that file entry `<plugin name> <full version>`. Third field: «Обновлён» (`post_modified`) = the release date. Read **all three back** after every publish (file entry name,
`_edd_sl_upgrade_file_key`, «Обновлён»), then verify with the public update API (`get_version`); keep the previous file ready for
rollback. s213 ran the three-field checklist on seven publishes (Yandex 2.0.13/2.0.14, CDEK 2.3.0.14/15, Russian Post 2.0.11/12,
Ozon 2.0.3) — the operator had been fixing them by hand before. Open idea: let `publish-release` set the three fields itself
(woodev.ru `class-woodev-mcp-release-abilities.php`, `publish()`); the operator has not answered.

## Related

- [Releasing a plugin (skill)](../../.ai/skills/woodev-plugin-core/layout-and-testing.md#releasing-a-plugin)
- [build topic index](../gotcha-index/build.md)
