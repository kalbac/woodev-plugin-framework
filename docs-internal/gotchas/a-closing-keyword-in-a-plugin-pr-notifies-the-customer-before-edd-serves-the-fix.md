# gotcha: `Closes #N` in a plugin PR notifies the customer before EDD serves the fix

**Namespace:** `[build/release]` · **Discovered:** s203 (30.10.2026)

Plugin issues carry customer tickets; closing the issue is what makes Support AI tell the customer «исправлено». A fix PR
with `Closes #156` closed edostavka#156 on merge (22:57 UTC), while the release reached EDD about an hour later — the
customer could be told before the update existed. On a batched wave the gap is days.

❌ `Closes #N` in a plugin fix PR.
✅ `Refs #N` in the fix PR; the coordinator closes the issue right after EDD serves the release (the existing rule in the
Support AI loop).

## Related

- [edd-publish-release-does-not-set-the-update-file-so-check-it-in-the-release-checklist](edd-publish-release-does-not-set-the-update-file-so-check-it-in-the-release-checklist.md)
