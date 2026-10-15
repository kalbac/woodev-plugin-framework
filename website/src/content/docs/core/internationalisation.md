---
title: Internationalisation
description: Learn how to make a plugin translatable, which strings need an English source text, and how to ship translations for PHP and JavaScript.
---

In this page you will learn how to declare your own text domain, which language to write each kind of string in, how translations are loaded, and how to ship a `languages/` catalogue that covers PHP and JavaScript.

## Your own text domain

Every plugin uses its own text domain, normally the same as its plugin ID (`my-carrier`). Pass it to the base class:

```php
<?php
parent::__construct(
	'my-carrier',
	MY_CARRIER_VERSION,
	[
		'text_domain' => 'my-carrier',
	]
);
```

Use the same value in the `Text Domain:` line of the plugin header and in every `__()`, `_e()`, `_x()` and `_n()` call of your plugin.

:::caution
Never use the framework's own domain, `woodev-plugin-framework`, for your strings. It is reserved for the texts the framework ships. The same goes for the framework's `Woodev_Helper::f__()`, `f_e()` and `f_x()` wrappers: they translate only strings that already exist in the framework's catalogue, never yours.
:::

## How translations are loaded

You do not call `load_plugin_textdomain()`. On the `init` hook the framework loads two domains: its own and, when you passed a `text_domain`, yours. For your domain it looks for a compiled catalogue named `{domain}-{locale}.mo` in two places, in this order:

1. `wp-content/languages/{domain}/`, where a translator or a site owner can override your texts without touching the plugin.
2. The `languages/` folder of your plugin.

The locale is the current user's in the admin and the site's on the storefront. The `plugin_locale` filter can change it. If you pass no `text_domain`, the framework loads nothing for your plugin.

## Which language to write a string in

A shop's storefront may run in English while its admin runs in Russian, so the language of the source text depends on **who reads the string**.

| Where the string appears | Source text (msgid) | Notes |
|---|---|---|
| Storefront: anything a shopper sees, including cart, checkout, emails and order pages | **English** | The Russian comes from your catalogue. |
| Admin screens | Russian is fine | Do not spend time rewriting it in English. An admin string that *is* English must have a Russian entry in the catalogue. |
| Logs, exception messages, anything that never reaches a screen | Either language, **no wrapper** | A plain string is correct. Do not call `__()`. |

Classify a string by the path it takes to the screen, not by the folder its file lives in. A text in an "admin" class that ends up in a checkout field is a storefront string.

Data shown at checkout counts as storefront. If you hand the framework data that it renders for shoppers, such as the type, payment methods or services of a pickup point, map your carrier's raw codes to translated, shopper-friendly labels in your own code:

```php
<?php
namespace My_Carrier;

defined( 'ABSPATH' ) || exit;

class Labels {

	/**
	 * Shopper-facing name of a carrier point type. English source text: the catalogue translates it.
	 */
	public static function point_type( string $code ): string {
		switch ( $code ) {
			case 'locker':
				return __( 'Parcel locker', 'my-carrier' );
			case 'office':
				return __( 'Pickup office', 'my-carrier' );
			default:
				return __( 'Pickup point', 'my-carrier' );
		}
	}
}
```

An admin label and a log line, for comparison:

```php
<?php
$field_label = __( 'Ключ API', 'my-carrier' ); // Admin only: a Russian source text is fine.

throw new \Woodev_API_Exception( 'Carrier rejected the API key.' ); // Never shown on a screen: no wrapper.
```

### Rules for the strings themselves

- **One literal per call.** `__( 'a ' . 'b', ... )` is one text to gettext and zero to the tools that scan your code. Write the whole text as a single string.
- **Placeholders, not concatenation.** Build sentences with `sprintf()` and numbered placeholders: `sprintf( __( 'Delivery to %1$s in %2$d days', 'my-carrier' ), $city, $days )`.
- **Plurals** use `_n()`. For Russian, the catalogue entry needs all three plural forms. In JavaScript, write plurals with an English source text.

:::danger
An English source text without a catalogue entry is a bug on a Russian shop: the shopper sees English. Ship the catalogue from the very first storefront string, and keep it complete.
:::

## Ship a catalogue

Keep the translations in a `languages/` folder in your plugin, named after the domain:

```text
my-carrier/
└── languages/
    ├── my-carrier.pot
    ├── my-carrier-ru_RU.po
    └── my-carrier-ru_RU.mo
```

Create and compile them with WP-CLI:

```sh
wp i18n make-pot . languages/my-carrier.pot --domain=my-carrier
msgmerge --update languages/my-carrier-ru_RU.po languages/my-carrier.pot
wp i18n make-mo languages
```

Commit the `.mo` file as well: WordPress reads that, not the `.po`. A Russian catalogue needs a header with the three plural forms:

```po
msgid ""
msgstr ""
"Project-Id-Version: My Carrier 1.0.0\n"
"Language: ru_RU\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n"

msgid "Parcel locker"
msgstr "Постамат"

msgid "Pickup office"
msgstr "Пункт выдачи"
```

It pays to add a unit test that reads your storefront code and fails when an English source text has no Russian entry, or when a catalogue entry is no longer used. It is the only protection against the bug above.

## JavaScript translations

A JavaScript bundle that calls `__()` from `@wordpress/i18n` is **not** translated by the `.mo` file. WordPress answers it from a separate JSON file, and only after you register it with `wp_set_script_translations()`. Call it after the script is enqueued, with your own domain and your own `languages/` path:

```php
<?php
add_action(
	'admin_enqueue_scripts',
	static function (): void {
		wp_enqueue_script(
			'my-carrier-admin',
			plugins_url( 'assets/js/admin.js', MY_CARRIER_FILE ),
			[ 'wp-i18n' ],
			MY_CARRIER_VERSION,
			true
		);

		wp_set_script_translations( 'my-carrier-admin', 'my-carrier', plugin_dir_path( MY_CARRIER_FILE ) . 'languages' );
	}
);
```

Generate the JSON files from your catalogue with `wp i18n make-json languages --no-purge`. WordPress accepts two names for a script's file: one built from the script handle, `my-carrier-ru_RU-my-carrier-admin.json`, and one built from a hash of the script path. The handle form is the simplest to get right, because it does not depend on where the file is served from.

The framework has its own helper for this, `\Woodev\Framework\Handlers\Script_Translations::register( $plugin, $handle )`. It is tied to the framework's domain and folder, so it serves only the framework's own bundles. Use `wp_set_script_translations()` for yours.

## Related

- [Getting started: Plugin layout & conventions](../../getting-started/plugin-layout-and-conventions/) — where `languages/` sits in the plugin.
- [Logging & debugging](../logging-and-debugging/) — why log lines are not translated.
- [The plugin class](../plugin-class/) — the `text_domain` argument of the constructor.
