# gotcha: `gh project item-edit` takes the project number and `--url` now; `--project-id PVT_…` fails

**Namespace:** `[tooling/gh]` · **Discovered:** s206 (31.10.2026)

The node-id form written in `AGENTS.md` → Backlog rule (`gh project item-edit --id <item> --project-id PVT_… --field-id …
--single-select-option-id …`) now fails with `invalid number: PVT_kwHOAIbGB84BeLao` and prints usage: the installed `gh` reads the
first positional / `--project-id` as the project NUMBER. A script that ignores the exit code reports the card as filed while its
Status and «Приоритет» stay empty (seven Ozon cards in s206 until re-set).

❌ `gh project item-edit --id <item-id> --project-id PVT_… --field-id PVTSSF_… --single-select-option-id <option>`
✅ `gh project item-edit 6 --owner kalbac --url <issue-url> --field "Status" --value "Бэклог"` (one field per call; same for
`--field "Приоритет" --value "Следом"`). Check the exit code of every call.

## Related

- [gh-project-item-list-json-mangles-the-cyrillic-field-key](gh-project-item-list-json-mangles-the-cyrillic-field-key.md)
