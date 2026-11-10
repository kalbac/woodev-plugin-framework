# Gotcha: [i18n/catalogue] — Two parallel PRs that both touch the `.po` conflict on merge; resolve by hand-union, not by regenerating

> **Namespace:** `[i18n/catalogue]` | **Discovered:** s225 (10.11.2026) | Session: s225

## What happens

Two framework PRs cut from the same `main` each add catalogue entries to
`woodev/languages/woodev-plugin-framework-ru_RU.po` and to
`woodev/languages/woodev-plugin-framework.pot`. The first PR merges cleanly. The second
one conflicts on both catalogue files when it is merged. In s225 this happened across the
parallel marketplace PRs (F2 #1700 was the one brought up to date with `main`).
`woodev/class-map.php` in the same set of PRs auto-merged without trouble.

## Root cause

The catalogue files are generated, and each generator answers only from the tree it runs in,
not from the merge:

- `wp i18n make-pot` regenerates the `.pot` from the working tree and sweeps in strings that
  belong to backlog work, which is not on this branch. The diff gains msgids that have
  nothing to do with the PR.
- `wp i18n update-po` rebuilds the `.po` from the `.pot` and keeps only the entries the
  `.pot` names. Entries that the other PR added, and that this branch's `.pot` does not carry,
  are deleted. The obsolete `#~` tail goes with them (see the related gotcha).

So regenerating either file to "resolve" the conflict silently drops one side or pulls in
unrelated strings, and the output is still well-formed. `class-map.php` does not need this
treatment: it is an ordered list that git line-merges.

## Fix

❌ Wrong — regenerating the conflicted catalogue:

```bash
wp i18n make-pot . woodev/languages/woodev-plugin-framework.pot          # sweeps backlog strings in
wp i18n update-po woodev/languages/woodev-plugin-framework.pot \
  woodev/languages/woodev-plugin-framework-ru_RU.po                      # drops the other PR's entries
```

✅ Correct — hand-union both sides, then prove nothing was lost:

1. Keep every entry from `main` and every new entry from this branch. Match entries by
   `msgctxt` + `msgid`; where both sides carry the same entry, keep one copy.
2. Count before committing, per side: active entries and `#~` obsolete entries in `main`,
   in this branch, and in the merged file. The merged count must equal the union, not the sum.
3. Rebuild the binary and gate it: `wp i18n make-mo`, then `npm run lint:i18n` and `npm run lint:mo`.

## Related

- [a-po-merge-that-drops-obsolete-entries-still-looks-well-formed](a-po-merge-that-drops-obsolete-entries-still-looks-well-formed.md) — why `update-po` loses the `#~` tail, and the count-in-and-out check this fix relies on
- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — the `.mo` is rebuilt from the merged `.po`, so a hand-union is verified by `make-mo`
- [parallel-cards-that-add-a-line-to-class-plugin-php-still-conflict](parallel-cards-that-add-a-line-to-class-plugin-php-still-conflict.md) — the other shared-file conflict between parallel cards
