# Gotcha: [tooling/ci] — gitleaks reads WooCommerce cart-item keys in a committed research log as API keys, and the tree scan then fails EVERY open pull request

> Tags: tooling, ci, secrets, research | Session: s142

## What happens

A research note (`research/2026-09-28-710-i0-measurement/logs/*.txt`) carried raw Store API / checkout
dumps. Each cart line has a `"key": "c20ad4d76fe97759aa27a0c99bff6710"` — WooCommerce's md5 cart-item
key, not a secret. CI's `Secret scan` job flagged all eight as `generic-api-key`:

```text
RuleID:      generic-api-key
File:        docs-internal/research/2026-09-28-710-i0-measurement/logs/store-api-checkout.txt
Finding:     ...ct"}, "REDACTED", {"key": "REDACTED", "product_id": 12, ...
WRN leaks found: 8
```

The job runs `gitleaks detect --no-git` — it scans the **tree**, and a pull request's checks run on
the merge ref, so once such a file is on `main` **every** open PR goes red on `Secret scan`, including
PRs that never touched it. A plain re-run does not help: it reuses the old merge commit — the PR branch
must be updated (`gh pr update-branch`) after `main` is fixed.

## ✅ Correct

Redact volatile hashes before committing a raw dump (the values carry no evidence):

```bash
sed -i '' -E 's/"key": "[a-f0-9]{32}"/"key": "<cart-item-key>"/g' logs/*.txt
```

Docs-only merges skip the critic, so this is the check a coordinator must do by hand: run the
`Secret scan` locally or grep the dump for 32-hex `"key"` values before merging raw logs.

## Related

- [public-repo-third-party-credentials](public-repo-third-party-credentials.md) — why the scan exists
- [../research/2026-09-28-710-i0-measurement/README.md](../research/2026-09-28-710-i0-measurement/README.md) — the note that tripped it
