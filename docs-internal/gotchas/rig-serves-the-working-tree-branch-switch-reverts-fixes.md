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

## The same root cause, a second way: a concurrent uncommitted edit takes the whole rig down (s81)

Branch switching is not the only way the working tree moves under the rig. **An agent editing the
tree right now is also changing what the rig serves, statement by statement.** There is no build
step, so there is no such thing as a half-applied edit being invisible: a subagent's intermediate
state *is* the rig's state.

Hit on 2026-08-19 while two subagents ran in parallel — one implementing task 10 of #362, one
setting up a pickup method on the rig. The implementer, working TDD, had added
`is_address_suggestions_available()` to `Location_Provider_Registry` (which runs on `init`) calling
`Location_Service::is_level_servable()` — a method it had not written yet. Every single request to
the rig then died before anything else ran:

```
PHP Fatal error: Uncaught Error: Call to undefined method
Woodev\Framework\Shipping\Location\Location_Service::is_level_servable()
in .../location/class-location-provider-registry.php:1059
#2 ...->register_settings()  #3 class-wp-hook.php(341): ->collect('')  #6 wp-settings.php(742): do_action('init')
```

Not just the checkout — plain `wp eval-file` and `wp wc shipping_zone_method list` too, since they
all boot WordPress and fire `init`. The verifying agent correctly reported the rig as broken and
correctly identified that it was not its own doing, but the verification pass was wasted.

**The rule: never run a rig verification concurrently with an agent editing the tree the rig
serves.** Serialise — implement, commit, *then* verify. Parallelism across agents is fine when
they touch different FILES; it is not fine when one of them is the rig's live source and the other
is reading the rig. If the overlap is genuinely worth it, give the verifier its own worktree with
its own wp-env instance rather than the shared one.

**And the diagnostic:** when the rig suddenly fatals on `init` inside a file nobody asked you
about, run `git status --short` before you debug the rig. A fatal naming a method that "should
exist" is the signature of a tree caught mid-edit, not of a defect.

## The rule

**Before asking anyone to look at the rig, state what the rig is currently serving**: the branch,
and every `WOODEV_TEST_*` constant that changes which data source is live —
`WOODEV_TEST_PICKUP_STRATEGY`, `WOODEV_TEST_PICKUP_LIVE_YANDEX`, `WOODEV_TEST_PICKUP_LIVE_POCHTA`
and `WOODEV_TEST_POCHTA_SETTINGS_ID`, with precedence `LIVE_YANDEX` > `LIVE_POCHTA` > `STRATEGY`
(the three switches are defined in
`tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php`, the settings id in
the same fixture's `class-test-live-pochta-point-source.php`). One line is enough.

When a verification needs fixes from more than one branch, **do not ask for the check from a
branch that only has some of them** — merge, or rebase one onto the other, so the tree the
operator opens contains everything under discussion.

When the operator reports that something is or is not fixed, **check the rig's state before
believing either direction**. A "still broken" report and a "looks fixed now" report both deserve
the same question first: what is this rig actually running?

```bash
git branch --show-current
MSYS_NO_PATHCONV=1 npx wp-env run cli wp config list --fields=name,value | grep WOODEV_TEST
```

`MSYS_NO_PATHCONV=1` is required under Git-Bash — MSYS otherwise mangles the container paths, see
[[wpenv-windows-gitbash-path-mangling]]. And wp-env resolves the environment from the CURRENT
WORKING DIRECTORY: run this from the repo root — from a subdirectory it fails with
"Environment not initialized" (observed s60).

## Related

- [[jest-scans-agent-worktrees-inside-the-repo]] — the same family: local state that silently
  makes a measurement about something other than what you think you are measuring.
- [[playwright-mcp-does-not-fire-wc-checkout-ajax]] — rig observations need their harness named.
