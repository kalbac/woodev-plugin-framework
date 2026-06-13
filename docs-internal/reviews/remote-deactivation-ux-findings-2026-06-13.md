# Remote-deactivation UX findings (operator manual run, s11, 2026-06-13)

Found during the operator's manual run on the local two-stack rig (issuer
woodev_theme :8090, stand framework :8888). The **happy path works** (issue →
deliver → execute → ack → replay-reject, both channels — see SESSION-LOG s11),
but the **lifecycle/UX around it has 3 real gaps**. None block production (no
prod plugin ships v2 yet), but they affect the just-shipped mechanism. Target
fix: **next session (s12)**, before edostavka.

## Operator's manual pipeline + observations
1. Activate stand → activate license → on issuer metabox click «Деактивировать» → reload → status «Доставлена», button → «Отменить».
2. On the client the plugin did NOT deactivate yet, **but the "plugin disabled" notice WAS shown**.
3. Tools → Woodev Stand → «Применить» → only then the plugin deactivates, **and the notice DISAPPEARS**.

## Operator's expected behavior (target spec)
- Clicking «Деактивировать» on woodev.ru deactivates the client plugin immediately. *(Already true in PROD via push — proven on pochta. The manual «Применить» is a LOCAL-RIG artifact: push can't cross Docker containers, so the rig is pull-only.)*
- The disable-notice shows **exactly when the plugin is deactivated**, and is **gone if the admin re-activates** the plugin.
- After a deactivation completes, the operator can deactivate **again** later (e.g. admin re-enabled it).

## Root causes (rig-artifact vs real)

### Finding A — notice not cleared on (re)activation  *(REAL, framework)*
The `woodev_license_remote_deactivation_notices` option is never cleared when the
plugin is re-activated → "you were disabled" persists after the admin re-enables
the plugin. **Fix:** on plugin activation (activation hook / lifecycle), remove
the plugin's own entry from the option.

### Finding B — notice can't render on a single-v2-plugin site  *(REAL, framework — design)*
`render_remote_deactivation_notices()` runs only when an ACTIVE
`Woodev_Plugins_License` engine calls `notices()`. A remotely-deactivated plugin
is inactive → it cannot render its own notice; only OTHER active v2 plugins can.
On a site whose ONLY v2 plugin is the deactivated one (incl. pochta, where the
siblings are v1, and the local rig), the notice never shows until reactivation —
and per Finding A it should be cleared on reactivation. Net: the admin of a
single-v2-plugin site never sees WHY it was disabled.
**Design options (operator decision):** (1) render pending notices from the
bootstrap / any loaded v2 copy independent of a specific plugin's active state;
(2) leave a core-surfaced breadcrumb (e.g. plugins-page row / transient) that
survives the plugin's deactivation; (3) accept sibling-render-only + rely on the
license page. (1) or (2) needed to meet the operator's spec.
*(The "notice shown before deactivation" in the manual run was a STALE entry from
prior test runs — the option wasn't cleared between runs; ties into Finding A.)*

### Finding C — issuer button stuck on «Отменить», can't re-deactivate  *(mixed)*
Metabox logic (`class-license-metabox.php`): `in_play (queued|delivered_pending_ack)
→ «Отменить»`; `terminal(executed) + can_deactivate → «Деактивировать»`.
- On the rig the row stuck in `delivered_pending_ack` because the **ack never
  arrived**: a self-deactivated single plugin can't send the ack (needs a 2nd
  `check_license`; in PROD the ack is synchronous in the push response, so the
  row goes terminal). → LOCAL-RIG artifact for the "stuck" part.
- **REAL UX:** «Отменить» wording for an already-delivered command ("what is
  there to cancel?"); and the re-issue-after-terminal + client-reactivation path
  must be verified/ensured so a second deactivation is possible.

## Proposed s12 scope
1. **Framework:** clear notice on (re)activation (Finding A); reliable notice render for the deactivated plugin (Finding B — pick a design).
2. **Deactivator (woodev_theme):** button/lifecycle — allow re-deactivation after a completed cycle + client reactivation; clarify «Отменить» semantics (Finding C).
3. Use the local rig to reproduce + verify each fix end-to-end.

## Related
- SESSION-LOG s11 — the proven happy path + the rig
- [[wp-safe-remote-request-local-rig]] — rig setup/gotchas
- docs-internal/next-session-prompt.md — s12 brief
