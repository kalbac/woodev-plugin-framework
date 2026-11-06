# Gotcha: [rig/sync] — `sync-framework.sh` run while the framework primary checkout is detached on the release branch copies the next VERSION into a plugin and bumps its pin

> Tags: rig, sync-framework, framework-version, release, plugin-pin | Session: s219 (06.11.2026)

## What happens

The coordinator detaches the framework primary checkout on the release branch (`kalbac/fw-release-2026`) to build bundles. A
plugin worker runs `WOODEV_FRAMEWORK_DIR=<primary> bash scripts/sync-framework.sh` at the same time. The plugin's `woodev/`
now reports the unreleased VERSION (2.0.26). `LoaderDefinitionTest` requires `framework_version` to equal the bundled VERSION,
so the worker "fixes" the test by bumping the loader pin to a tag that does not exist yet (range C17, PR #44).

## Root cause

The sync script copies whatever the primary checkout has checked out. It does not check for a branch or a released tag.

## Fix

- Never sync a plugin while the primary checkout is anywhere but `main`. Build bundles, push, `git checkout main` in one go.
- Say in every plugin brief: the loader pin moves only in the release PR, after the tag exists. A worker that sees the pin test
  fail stops and reports instead of bumping.
- On review, diff the plugin's main file for `framework_version` — a pin change outside the release PR is a revert.

## Related

- [the-rig-loads-the-first-registered-framework-copy-when-versions-tie](the-rig-loads-the-first-registered-framework-copy-when-versions-tie.md) — the other s219 rig/framework-copy trap
- [Session s219](../sessions/s219.md) — where it was hit
