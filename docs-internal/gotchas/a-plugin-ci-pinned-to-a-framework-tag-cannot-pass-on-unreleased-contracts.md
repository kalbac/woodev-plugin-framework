# gotcha: a plugin's CI pins a released framework tag, so a PR adopting unreleased framework contracts cannot pass it

**Namespace:** `[tooling/local-ci]` · **Discovered:** s238 (19.11.2026, ozon PR #113)

## What happens

Ozon Seller's `ci.yml` fetches the framework by `WOODEV_FRAMEWORK_REF: v2.1.12` (a codeload tarball). A plugin PR that
implements interfaces existing only on framework `main` (2.1.13 not yet released) fails local-ci and GitHub CI with
`Interface "…\Card_List_Source" not found` in every job — the code is fine, the pin is older than the contract.

## ❌ Wrong

Read the red run as a code defect, or commit the pin moved to a branch/sha of the framework.

## ✅ Correct

For evidence before the release, edit `WOODEV_FRAMEWORK_REF` to the framework `main` sha in the working tree only (local-ci
rsyncs the workspace, so an uncommitted edit applies), run `npx -y run-local-ci run -w .github/workflows/ci.yml` WITHOUT
`--commit-status`, record the result on the PR, then `git checkout -- .github/workflows/ci.yml`. Park the PR; after the
framework release move the pin in the PR to the new tag and run the merge-evidence local-ci with `--commit-status`.

## Related

- [run-local-ci-requires-run-subcommand](run-local-ci-requires-run-subcommand.md)
- [local-ci-on-orbstack-needs-local-ci-docker-host-not-docker-host](local-ci-on-orbstack-needs-local-ci-docker-host-not-docker-host.md)
- [../gotcha-index/tooling.md](../gotcha-index/tooling.md)
