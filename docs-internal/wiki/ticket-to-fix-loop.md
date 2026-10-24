# Ticket → fix → reply: the development half of the support loop — Woodev Framework Wiki
> Compiled reference. Last compiled: 2026-10-23 (s195, card #780).

## Overview

The support loop has two halves in two projects (operator decision s184, 16.10.2026):

| Half | Project | Owner of |
|---|---|---|
| Support | `woodev-support-ai-agent` (Homelab), ADR-004 there | triage in FreeScout, filing the issue, linking ticket ↔ issue with the `freescout-github` module, placing the customer draft |
| Development | this repo + the plugin repos | verifying the issue, the fix, the PR, the release, the customer-reply draft text |

The two halves meet at **one GitHub issue in the plugin's repo** (`woocommerce-edostavka`, `woocommerce-yandex-delivery`,
`woodev-russian-post`). Since s195 every v2 plugin repo has `main` as its default branch, so `Closes #N` in a merged plugin
PR closes the linked issue, and `freescout-github`'s webhook carries that back to the ticket.

## Where the issue lives

- **The bug is in the plugin** → fix in the plugin repo; the linked issue is closed by the plugin PR.
- **The bug is in the framework** → the linked plugin issue stays the customer-facing tracker. The fix lands as a framework
  card + PR (framework `Closes #N` closes the framework card only). The plugin issue is closed by the plugin PR that bumps the
  bundled framework and releases — that is the moment the customer can actually update.
- **The framework repo is PUBLIC.** A framework card carries the technical substance only: no customer name, domain, e-mail,
  order data, licence key or quote that identifies the shop. Ticket numbers are fine. Plugin repos are private.

## Development steps (coordinator)

1. **Verify against released code**, never on the customer's word: read the released tag of each plugin and of the
   bundled framework, give a verdict per item (bug confirmed / likely / by design / cannot tell) with `repo@tag:file:line`.
   s195 is the worked example: three read-only verifiers in parallel, one per area, then cards.
2. **Reproduce** on the rig unless the code read already proves the mechanism or the customer sent working code.
3. **Fix** through the normal pipeline: worker brief → worker → critic of another lineage → merge. One card per mechanism,
   not per ticket — list every ticket number the card answers.
4. **PR body** carries, besides the usual summary:
   - **Customer impact** — who hits it (which setup), severity, the workaround that works today;
   - **Areas for human testing** — what only a person on the rig or a real shop can confirm;
   - **Docs to update** — merchant docs (woodev.ru) or public framework docs, or «none».
5. **Release** (framework tag → plugin bundle bump → plugin release), then close the plugin issue.
6. **Customer-reply draft**: a comment on the plugin issue headed `Черновик ответа клиенту`, in Russian, for a WordPress
   novice (merchant-docs tone): what was wrong in plain words, the version that fixes it, what to do (update; any setting to
   re-check). Two shapes only: «исправлено в версии X» and «это не ошибка — вот как настроить». The Support AI agent turns it
   into a FreeScout draft; **Maksim sends** (Support AI ADR-002). The development side never writes to FreeScout.

## What the development side never does

- Answers a customer, places a FreeScout draft or note (notes there are permanent and cannot be deleted).
- Copies customer data into a public card.
- Closes a plugin issue before the release that contains the fix is published.

## Related

- [orchestrating-agents-with-orca.md](orchestrating-agents-with-orca.md) — the worker/critic pipeline used in step 3
- [local-rig.md](local-rig.md) — where step 2 reproduces
- [../adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md) — why v1 plugins stop next to v2 ones (a frequent «not a bug» answer)
