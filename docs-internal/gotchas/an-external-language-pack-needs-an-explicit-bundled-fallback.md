# Gotcha: [i18n/loading] — An external language pack needs an explicit bundled fallback
> Tags: i18n, WordPress, lifecycle, locale | Session: s188

## What happens

A Russian shop can show English framework milestone or startup-failure notices even with a complete
catalogue. A stale external pack can hide newly added bundled translations on WordPress 6.7+.

## Root cause

`load_plugin_textdomain()` only registers a JIT path since WordPress 6.7. An already-loaded domain
never invokes that path, so its external pack's missing entries stay untranslated. Core's translation
controller searches files in insertion order: the first matching entry wins. The locale switcher may
JIT-load one catalogue before `change_locale`, reversing intended override precedence.

A per-plugin handler also cannot translate resolver notices when every plugin is refused. Registering
lifecycle notices and translations at the same init priority can assemble notices before any catalogue
loads. Persisting a translated milestone fragment additionally freezes the producer's locale.

## Fix

Wrong: load an external pack, then rely only on `load_plugin_textdomain()` for the bundled fallback.

Correct: the selected resolver copy registers `Framework_Translations` at `init` priority 0, before
lifecycle notices at 10. The selected autoloader also registers it (idempotently), because an older
framework copy can own the resolver while selecting newer runtime files. Explicitly `load_textdomain()` each external pack, then the bundled `.mo`, using
one resolved locale for both filenames and locale arguments. On `change_locale`, unload and rebuild
that order; the hook covers `switch_to_locale()` and restoration. Plugin domains keep their own loader.

Store known gateway milestone keys in the existing `id => string` option and resolve them at display
time. Preserve historical string values. Defer default pickup labels until the error renderer needs them.
References: `woodev/handlers/class-framework-translations.php`, `woodev/class-lifecycle.php`; #1361.
Measured against the rig's WordPress 7.1 `l10n.php`, translation controller and locale-switcher source.

## Related

- [the-mo-is-reproducible-from-the-po.md](the-mo-is-reproducible-from-the-po.md) — catalogue freshness
- [get-plugin-name-runs-before-init-so-a-translated-name-warns.md](get-plugin-name-runs-before-init-so-a-translated-name-warns.md) — avoid constructor-time translation
