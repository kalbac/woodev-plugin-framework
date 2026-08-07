# A credential that is public elsewhere is still not ours to commit here

**Namespace:** `[build/ci]`
**Found:** s55 (2026-08-07), wiring the live Yandex.Delivery point source.

## What happened

A subagent hardcoded Yandex.Delivery's sandbox bearer token into a fixture class, committed
it, and pushed the branch. The token was genuinely published — it sits in the reference
plugin at `plugins-reference/woocommerce-yandex-delivery/woocommerce-yandex-delivery.php`,
ships inside every install of that plugin, and the operator later confirmed it is in
Yandex's own public documentation.

None of that made committing it correct, because of the combination that made this repo
different from the places it was already public:

- **`plugins-reference/` is gitignored** (`.gitignore:25`). The token had therefore never
  been in this repository's history.
- **This repository is PUBLIC.**

So the commit was the first time a third party's credential entered a public repository's
git history — a different exposure surface from "shipped inside a paid plugin": indexable,
scrapeable by credential scanners, and a plausible route to the token being **revoked**,
which would have broken the reference plugin's test mode for its actual customers.

## The instruction that caused it

The dispatching brief said:

> Do NOT commit the sandbox token **as if it were a secret of ours** — it is the reference
> plugin's own published test token.

That sentence licenses committing it. The agent followed it exactly. The correct instruction
is unconditional: **do not commit it at all.**

## Correct practice

- A credential goes behind a constant read at call time, never a literal — even a "public"
  one. The cost is one line in a gitignored file; the cost of the habit failing once on a
  real key is unbounded.
- The guard must **refuse to make the request** when the constant is missing, not merely
  throw somewhere. Pin that with a test asserting the transport is called `never()` —
  asserting only on the exception still passes if the guard later drifts below the request.
- Testing "the constant is absent" needs `@runInSeparateProcess` + `@preserveGlobalState
  disabled`, because a PHP constant cannot be un-defined; putting the guard test in the same
  class as tests that define a dummy makes it order-dependent.
- Before deciding a leak's severity, check **two** facts, not one: is the source directory
  gitignored, and is the repository public. Either alone gives the wrong answer.
- Deleting the branch does **not** remove the object. The commit stayed retrievable by SHA
  through the API after the ref was gone (`gh api repos/OWNER/REPO/commits/<sha>` still
  resolved). Only GitHub's own GC, or a support request, clears it. Say so plainly rather
  than reporting the leak as erased.

## Repository hardening applied

Secret scanning and push protection are now enabled. Note the limit discovered while doing
it: **"non-provider patterns" is not available on a free public repository** — it is part of
paid GitHub Secret Protection, and neither the API nor the UI exposes the toggle without it.
So provider-format keys (AWS, Stripe, GitHub tokens…) are now blocked at push time, but a
custom vendor token like this one would **still** not be caught. A CI secret scanner
(gitleaks or similar) is the free way to close that gap; tracked separately.

## Related

- [[framework-classmap-autoload-vendored-boot]] — the other class of "works locally, breaks
  where it actually ships"
- [[jest-scans-agent-worktrees-inside-the-repo]] — also from s55: a check that reports
  success while measuring the wrong thing
