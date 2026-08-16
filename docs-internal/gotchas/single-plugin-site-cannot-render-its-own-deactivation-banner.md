# [licensing/remote-deactivation] A single-plugin site cannot render its own deactivation banner — accepted by design

> Namespace: `licensing/*` — added session 12; promoted from an index-only note to a file at the s75 docs cleanup.

## The behaviour

When a remote deactivation kills the only v2 plugin on a site, that plugin cannot render its own
`admin_notices` banner explaining why. The reason is structural, not a bug: **an inactive plugin
loads no framework code**, and the banner is framework code.

The banner therefore appears only when *another* active v2 plugin renders it — i.e. on sites
running two or more v2 plugins.

## Why it is accepted rather than fixed

The kill-switch targets licence violators. On a single-plugin site the lost banner costs nothing
that matters: the plugin is off either way, and the operator's own channel (the store account)
carries the explanation.

An s12 attempt to leave a breadcrumb via a WC Admin note was built and then **reverted by the
operator** — it put licensing state into an inbox that outlives the deactivation, which is worse
than silence.

## Do not "fix" this without new information

Any future attempt has to answer where the rendering code lives when no framework is loaded. A
must-use plugin or an option-driven core hook would be a new persistent surface on every install
to serve the violator case only — the reason it was rejected the first time.

## Related

- [[license-need-vs-required]] — the licence state this path acts on.
- Session detail: `sessions/s12.md`.
