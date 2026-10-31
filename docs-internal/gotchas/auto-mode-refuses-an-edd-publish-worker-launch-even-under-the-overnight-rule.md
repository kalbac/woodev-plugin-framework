# Gotcha: [tooling/claude] — auto mode refuses to launch an EDD publish worker, even when the operator's overnight rule allows it

> Tags: auto-mode, classifier, edd, overnight, release | Session: s207 (31.10.2026, Mac)
> **Measured on:** one overnight run (Orca coordinator, Claude Code auto mode); the refusal is the classifier's, not Orca's.

## What happens

The operator's overnight rule said «Yandex in EDD only if the critic says ship, local-ci is green and the rig keeps the city».
All three held for Yandex 2.0.12 (yandex#150). The coordinator then tried to start the Homelab `woodev-ru` worker that uploads the
ZIP to EDD (`woodev-release/*`) and the **auto-mode classifier blocked the launch as `[Production Deploy]`**. The release stayed
unpublished until morning: GitHub release `v2.0.12` was ready (sha256 `14f3cfff…aecc`), EDD product 821 was not updated, and
yandex#150 had to stay open.

## Root cause

The classifier judges the ACTION («publishes to the production store») against what the user typed in the current turn, not
against a standing rule written into a handoff. An overnight permission in `next-session-prompt.md` is context, not authority —
the same behaviour as the refusals recorded for public ingress and for a self-reviewed merge.

## Fix

❌ Plan an overnight wave that ends in an EDD upload and report the release as «done» in the morning.

✅ Treat EDD as an operator button even when the rule allows it overnight: finish everything up to the GitHub release (tag, ZIP,
sha256, customer reply draft with `fixed_in`), park the EDD step under «Ждёт кнопки оператора» with the exact command or worker
brief, and keep the card open (`Refs`, never `Closes`). If a release must go out unattended, the operator has to pre-approve the
specific launch in the session (an allow rule for that command), not only write it down.

## Related

- [auto-mode-refuses-a-self-reviewed-merge-and-a-branch-overlay-on-the-primary-checkout](auto-mode-refuses-a-self-reviewed-merge-and-a-branch-overlay-on-the-primary-checkout.md) — the same classifier judging a wave's own authority
- [auto-mode-blocks-a-public-ingress-tunnel-the-operator-runs-it](auto-mode-blocks-a-public-ingress-tunnel-the-operator-runs-it.md) — another action it keeps for the operator
- [a-closing-keyword-in-a-plugin-pr-notifies-the-customer-before-edd-serves-the-fix](a-closing-keyword-in-a-plugin-pr-notifies-the-customer-before-edd-serves-the-fix.md) — why the card stays open until EDD serves the fix
