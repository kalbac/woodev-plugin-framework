# `gh api rate_limit` showed GraphQL at 4999/5000 while every GraphQL call was refused as RATE_LIMIT

**Namespace:** `[tooling/gh]` · **Discovered:** s151 (04.10.2026) · **Measured on:** macOS, gh CLI

## What happens

Mid-session every `gh project …` call failed — `item-add` printed only `unknown owner type`, `item-edit`
printed `project-id must be provided` — and raw `gh api graphql` returned
`{"type":"RATE_LIMIT","code":"graphql_rate_limit"}`. At the same moment
`gh api rate_limit --jq .resources.graphql` reported `remaining: 4999` with a reset ~58 min away.
REST (`gh api repos/...`) kept working throughout, including PR merges.

## Root cause

Not proven. The refusal is real and the `rate_limit` endpoint did not reflect it, so the endpoint is
not evidence that GraphQL is usable. `gh project` hides the refusal behind misleading errors because
its first call (owner lookup) fails and the follow-up commands get empty ids.

## Fix

- Treat `unknown owner type` from `gh project` as "GraphQL refused", not as a bad `--owner`.
- Probe with one real call (`gh api graphql -f query='{viewer{login}}'`), not `rate_limit`.
- Fall back to REST for anything REST can do: PR merge `gh api -X PUT repos/<o>/<r>/pulls/<n>/merge`,
  check runs `gh api repos/<o>/<r>/commits/<sha>/check-runs`, issue state, comments. Board edits have no
  REST route — queue them and wait for the reset `rate_limit` reports (it was right about the reset time).

## Related

- [gh-project-item-list-json-mangles-the-cyrillic-field-key](gh-project-item-list-json-mangles-the-cyrillic-field-key.md) — the other `gh project` trap
