# The rig serves the working tree, so switching branches silently un-fixes things

**Namespace:** `[rig/browser]` · **Discovered:** s56 (2026-08-08), twice in one session

## What happens

wp-env mounts the repository directory into the container. The rig therefore runs **whatever
branch is checked out right now** — there is no build step and no deploy for the framework's PHP
and frontend JS. Checking out a second feature branch instantly reverts every fix that lives only
on the first one.

Nobody sees a git operation. What the operator sees is a defect he was told is fixed.

## How it actually bit, twice on 2026-08-08

1. `#207` (short tab labels) was on `fix/live-yandex-point-short-name`; work then moved to
   `fix/accent-vars-on-stage`, branched from `main`. The operator opened the rig and reported
   «в табах по прежнему "Пункт выдачи заказов N"» — correctly, because that branch had never
   contained the fix.
2. Minutes later the rig was switched to the static viewport fixture to test another strategy.
   The operator saw that the `5post` address noise from `#214` was gone and concluded it had been
   fixed. It had not — that fixture simply has no `5post` points.

Both reads were entirely reasonable from what was on screen. In case 1 he reported a phantom
regression; in case 2 he nearly closed a live card.

## Why it is worse than it sounds

The two failure directions are opposites, so no single instinct catches both:

- switching to a branch that **lacks** a fix reads as **"you didn't fix it"**;
- switching to a fixture that **cannot show** a symptom reads as **"you fixed it"**.

And the second is the dangerous one — it produces false confidence, quietly.

## The rule

**Before asking anyone to look at the rig, state what the rig is currently serving**: the branch,
and every `WOODEV_TEST_*` constant that changes which data source is live
(`WOODEV_TEST_PICKUP_STRATEGY`, `WOODEV_TEST_PICKUP_LIVE_YANDEX`). One line is enough.

When a verification needs fixes from more than one branch, **do not ask for the check from a
branch that only has some of them** — merge, or rebase one onto the other, so the tree the
operator opens contains everything under discussion.

When the operator reports that something is or is not fixed, **check the rig's state before
believing either direction**. A "still broken" report and a "looks fixed now" report both deserve
the same question first: what is this rig actually running?

```bash
git branch --show-current
npx wp-env run cli wp config list --fields=name,value | grep WOODEV_TEST
```

## Related

- [[jest-scans-agent-worktrees-inside-the-repo]] — the same family: local state that silently
  makes a measurement about something other than what you think you are measuring.
- [[playwright-mcp-does-not-fire-wc-checkout-ajax]] — rig observations need their harness named.
