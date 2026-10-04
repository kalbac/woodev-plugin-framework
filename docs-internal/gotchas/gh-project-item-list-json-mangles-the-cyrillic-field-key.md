# gotcha: `gh project item-list --format json` mangles the Cyrillic «Приоритет» key — select it by regex

**Namespace:** `[tooling/*]`
**Discovered:** s150 (2026-10-03), board №6 audit

## What happened

After setting «Приоритет» = «Следом» with `gh project item-edit` (success), reading it back with
`--jq '.items[] | .["приоритет"]'` printed `null` for every card, and `.["Приоритет"]` did too. `keys` showed the field
key as `"��риоритет"` — the first Cyrillic letter's bytes are broken in gh's JSON output. Piping the same output into
Python additionally failed with `Invalid control character` when a card body was echoed through a shell variable.
The writes were fine; only the read was wrong — easy to «fix» a field that was never broken.

## ❌ Wrong

```bash
gh project item-list 6 --owner kalbac --format json --jq '.items[] | .["Приоритет"]'   # null
```

## ✅ Correct

```bash
gh project item-list 6 --owner kalbac --limit 600 --format json \
  --jq '.items[] | [.content.number, (to_entries | map(select(.key | test("риоритет"))) | .[0].value)] | @tsv'
```

Use `--jq` inside gh instead of `echo "$json" | python3` — no shell round-trip of card bodies.

## Related

- [git-bash-mangles-cyrillic-in-curl-arguments](git-bash-mangles-cyrillic-in-curl-arguments.md) — a different Cyrillic-in-tooling trap
- `../gotcha-index/tooling.md`
