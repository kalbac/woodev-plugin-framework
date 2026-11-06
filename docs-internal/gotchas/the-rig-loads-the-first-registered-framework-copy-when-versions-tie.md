# Gotcha: [rig/bootstrap] — with equal framework VERSION labels the rig loads the first-registered copy, so overwriting a plugin's `woodev/` does not test a framework PR branch

> Tags: rig, bootstrap, framework-version, loader, pr-testing | Session: s219 (06.11.2026)

## What happens

A worker copies a framework PR build into a plugin's `woodev/` in the rig container and checks the checkout. Nothing changes: the
rig still runs the old framework code. In s219 a rig acceptance (C19) was blocked on this.

## Root cause

Several active plugins bundle the framework. The loader picks the highest VERSION; when the labels are equal it keeps the copy
registered first — on this rig the one mounted from the framework primary checkout via the order-tracking plugin. A PR branch
carries the same VERSION as `main`, so it never wins.

## Fix

- Test a framework PR on the rig only through the primary checkout: merge first and test `main`, or have the coordinator check
  out the PR head in the primary for the rig run and return it to `main` afterwards.
- Do not raise the VERSION label of a copy just to win the tie — that hides exactly this kind of check.

## Related

- [sync-framework-while-the-primary-is-on-the-release-branch-bumps-the-plugin-pin](sync-framework-while-the-primary-is-on-the-release-branch-bumps-the-plugin-pin.md) — the other s219 framework-copy trap
- [Session s219](../sessions/s219.md) — where it was hit
