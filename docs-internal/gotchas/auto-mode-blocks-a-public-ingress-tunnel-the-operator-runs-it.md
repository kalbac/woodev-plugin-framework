# Gotcha: [tooling/claude] — Auto mode refuses a public ingress (Tailscale Funnel); the operator runs it
> Tags: tooling/claude, funnel, cdek-webhooks | Session: s167

## What happens

In Claude Code's auto mode, any command that opens the machine to the internet — `tailscale funnel …`, and even
starting a local HTTP receiver in the same command meant to sit behind it — is denied by the auto-mode classifier
(«External Ingress Tunnel»). Once denied, the classifier also refused an unrelated follow-up read in the same
context. Retrying or rewording is a workaround of a safety control, not a fix.

## Fix

❌ Re-run the same command split differently until it passes.

✅ Write a `start.sh` / `stop.sh` pair to the scratchpad (receiver + Funnel on ONE random path + the subscription;
stop deletes only our subscription, verifies by re-reading, turns Funnel and the receiver off) and hand the
operator one line: `! bash <scratchpad>/…/start.sh`. Closing the ingress (`stop.sh`) ran fine from the agent.
Used for the OFFICE_AVAILABILITY production measurement (edostavka#27, s167).

## Related

- `docs-internal/sessions/s167.md`
- the plugin's `tests/acceptance/README.md` → «Real CDEK deliveries» (the harness's own Funnel mode)
