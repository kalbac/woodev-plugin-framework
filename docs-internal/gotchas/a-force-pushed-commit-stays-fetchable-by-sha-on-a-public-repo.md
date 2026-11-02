# Gotcha: [tooling/github] — a force-pushed commit stays fetchable by SHA on a public repo

> Tags: github, security, public-repo, workers | Session: s183

## What happens

A worker committed FULL v1 sources of the paid CDEK/Yandex plugins as test fixtures (~3,800 lines, incl. the CDEK partner
`developer_key` constant) to a branch of this PUBLIC repo and pushed it. Rewriting the branch history and force-pushing
removed it from every ref — but GitHub keeps serving the old commit by its SHA until garbage collection, and anyone who
fetched in between has it.

## Root cause

Force-push changes refs, not objects. Dangling commits on github.com stay reachable by SHA (and via the PR/compare UI)
until GitHub's GC runs; only GitHub Support can purge them sooner.

## ✅ Correct

- Never let commercial source, credentials or keys reach a pushed branch here — not even a worker branch. Brief every
  worker that touches fixtures: «public repo — excerpts/signatures only, no keys».
- Fixtures copied from a paid plugin: keep only the literals a test pins (`tests/_fixtures/legacy-v1/README.md`).
- If it happened anyway: treat the content as exposed (rotate a real secret), and ask GitHub Support to purge the SHA if
  it matters.

## Related

- [public-repo-third-party-credentials](public-repo-third-party-credentials.md) — the same public-repo boundary for secrets in tracked files
- Operator memory «server details never go to the public tracker» — the same boundary for prod facts
