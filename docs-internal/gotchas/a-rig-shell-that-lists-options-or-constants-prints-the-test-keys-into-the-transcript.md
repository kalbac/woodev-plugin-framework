# Gotcha: [rig/secrets] — a rig shell that lists options or constants prints the test keys into the worker's transcript; read lengths only

> Tags: rig, secrets, wp-env, dadata, cdek, transcript | Session: s208 (01.11.2026), first seen s207
> **Seen twice in two nights:** s207 (re-check worker) and s208 (screenshots worker).

## What happens

A rig worker checks "are the DaData / CDEK test keys configured?" with a command that dumps values:
`wp option list --search='woodev_location_*'`, `wp config get <CONSTANT>` without a filter, or `wp eval 'var_dump(...)'`. The
output carries the DaData test token and secret and the CDEK test client id and secret in clear text. They land in the worker's own
transcript (the s207 check found nothing in any repo, PR or card), the keys then count as exposed and go on the rotation list.
The rig's DaData keys live as `wp-env` constants, so a plain constant dump prints them as well.

## Root cause

The question "is a key set?" is answered by the same command that returns the value. Nothing on the rig masks it, and an agent
transcript keeps everything it printed.

## Fix

❌ Wrong:

```bash
wp option list --search='woodev_location_*'
wp config get WOODEV_DADATA_TOKEN
```

✅ Correct — print the length (or `set` / `empty`), never the value:

```bash
wp eval 'echo defined("WOODEV_DADATA_TOKEN") ? strlen(WOODEV_DADATA_TOKEN) : "unset";'
wp option get woodev_location_token --format=json | wc -c
```

State the rule in every rig brief that can touch the location, DaData or CDEK settings: "read lengths only; no unfiltered option or
constant listing". A key that was printed is rotated, not forgotten.

## Related

- [local-ci-github-token-flag-puts-the-token-on-argv](local-ci-github-token-flag-puts-the-token-on-argv.md) — the same class: a secret on an output channel an agent keeps
- [the-cdek-fixture-credentials-are-not-the-option-they-look-like](the-cdek-fixture-credentials-are-not-the-option-they-look-like.md) — where the CDEK test credentials actually live
