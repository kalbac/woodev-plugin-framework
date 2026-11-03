# Gotcha: [build/release] — Homelab `gh` token expired: hand the EDD worker a pre-signed asset URL

> Tags: homelab, gh, edd, release | Session: s213
> **Platform:** the Homelab (ACEMAGIC) only.

## What happens

The Homelab EDD-publish worker could not download a private release asset: `gh release download` answered 401, because the
`gh` login on that machine had expired. The release could not reach EDD until the file arrived another way.

## Root cause

The Homelab's own `gh` credential is separate from the Mac's and expires on its own. The release repos are private, so the
asset needs an authenticated request.

## Fix

❌ **Wrong** — copy a token into the worker brief or onto the command line (argv and transcripts keep it).

✅ **Correct** — the coordinator, who is logged in, asks the API for the asset (`gh api -H 'Accept: application/octet-stream' repos/<owner>/<repo>/releases/assets/<id>`
without following the redirect) and passes the worker GitHub's pre-signed redirect URL. It is valid for about five minutes and
carries no credential. Download at once, check the sha256 against the release, publish. Fix the Homelab login afterwards (the operator's chore, not a card).

## Related

- [EDD publish does not set the update file](edd-publish-release-does-not-set-the-update-file-so-check-it-in-the-release-checklist.md) — the checklist the same worker follows
- [build topic index](../gotcha-index/build.md)
