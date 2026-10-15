---
title: "Admin notices & messages"
description: Show dismissible admin notices and inline messages from a plugin.
---

In this page you will learn the three ways a plugin talks to a merchant in wp-admin: notices at the top of the screen, short messages that survive a redirect, and WooCommerce inbox notes. You will also learn what to keep out of them.

| You want to… | Use |
|---|---|
| Tell the merchant something on every admin screen, and let them dismiss it | an [admin notice](#admin-notices) |
| Confirm the result of a form the merchant just submitted | a [flash message](#flash-messages) |
| Leave a note in the WooCommerce inbox | an [inbox note](#woocommerce-inbox-notes) |

All the text is read by merchants, so keep it short and name the plugin. Write it in Russian, like the rest of the plugin's admin text.

## Admin notices

Every plugin has an admin notice handler. Reach it with `$this->get_admin_notice_handler()` inside the plugin class, or `My_Carrier_Plugin::instance()->get_admin_notice_handler()` anywhere else. Add a notice with:

```php
add_admin_notice( string $message, string $message_id, array $params = [] ): void
```

`$message` is the text (a small set of HTML tags is allowed, such as links). `$message_id` identifies the notice. It must be unique per notice, because the merchant's dismissal is stored under it.

### When to add a notice

Add notices while WordPress prepares the admin screen, before the framework prints them. The base plugin class has two methods for this. Override them in your main class:

- `add_admin_notices()` runs on `admin_notices`. Use it for notices that do not depend on a form being saved.
- `add_delayed_admin_notices()` runs in the page footer, after a settings save in the same request. Use it for notices that depend on saved state. The framework moves them to the top of the page. Always call the parent method; it adds the framework's own notices.

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the constructor, instance(), get_file() and the rest from "Your first plugin"...

	/**
	 * Adds the plugin's admin notices.
	 *
	 * @return void
	 */
	public function add_admin_notices() {
		parent::add_admin_notices();

		$this->get_admin_notice_handler()->add_admin_notice(
			__( 'My Carrier подключён. Выберите способ доставки в зоне доставки.', 'my-carrier' ),
			'my-carrier-welcome',
			[
				'dismissible'  => true,
				'notice_class' => 'updated',
			]
		);
	}
}
```

### Parameters

All keys are optional.

| Key | Default | Meaning |
|---|---|---|
| `dismissible` | `true` | Shows a dismiss button. A dismissed notice stays hidden for that user. With `false`, the notice shows every time. |
| `always_show_on_settings` | `true` | On the plugin's own settings screen the notice shows even if it was dismissed, and has no dismiss button. |
| `notice_class` | `'updated'` | The WordPress notice style: `'updated'` (green), `'error'` (red), `'notice-warning'` (yellow) or `'notice-info'` (blue). |
| `capability` | `'manage_woocommerce'` | The capability a user needs to see the notice. Pass another one when your plugin's settings need it. |

The notice is skipped for a user who lacks the capability, so a shop manager is not shown a notice about something only an administrator can fix.

:::note
`always_show_on_settings` depends on the plugin telling the framework which screen is its settings screen. The base class returns `false` from `is_plugin_settings()`, so override it in your plugin class if you want this behaviour. A shipping plugin already does.
:::

### Dismissal

The handler remembers dismissals per user. A merchant who clicks the dismiss button is not shown that notice again.

| Method | What it does |
|---|---|
| `dismiss_notice( $message_id, $user_id = null )` | Marks the notice as dismissed. The current user by default. |
| `undismiss_notice( $message_id, $user_id = null )` | Makes the notice appear again. |
| `is_notice_dismissed( $message_id, $user_id = null )` | Tells whether it was dismissed. |
| `get_dismissed_notices( $user_id = null )` | Returns all of the user's dismissals for this plugin, `id => bool`. |

Whenever a notice is dismissed, the framework fires the action `woodev_{id}_dismiss_notice`, where `{id}` is the plugin id. It passes the notice id and the user id. Use it to react once, for example to stop a recurring reminder:

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Remembers that the welcome notice was dismissed.
 *
 * @param string $message_id The dismissed notice id.
 * @param int    $user_id    The user who dismissed it.
 * @return void
 */
function my_carrier_on_notice_dismissed( $message_id, $user_id ) {
	if ( 'my-carrier-welcome' === $message_id ) {
		update_user_meta( (int) $user_id, 'my_carrier_welcome_seen', 'yes' );
	}
}
add_action( 'woodev_my-carrier_dismiss_notice', 'my_carrier_on_notice_dismissed', 10, 2 );
```

### A notice for an unfinished setup

If all you need is "the plugin is not configured yet", do not write the notice yourself. Override `is_configured()` and answer from your settings. While it returns `false`, the framework shows a warning that links to the plugin's settings. The warning is not dismissible, and it disappears the moment `is_configured()` returns `true`.

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the rest of the class...

	/**
	 * Tells whether the plugin has what it needs to work.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== (string) $this->get_settings_handler()->get_value( 'api_key' );
	}
}
```

Keep `is_configured()` cheap and free of remote calls. It runs on every wp-admin page.

## Flash messages

A flash message is a result you show on the next page, after a redirect. The classic case is a form handler: do the work, add a message, redirect, and the page you land on prints it.

The message handler is `$this->get_message_handler()`. It has four kinds of message, each added with its own method:

| Method | Shown as |
|---|---|
| `add_message( $text )` | success (green) |
| `add_info( $text )` | information (blue) |
| `add_warning( $text )` | warning (yellow) |
| `add_error( $text )` | error (red) |

The handler stores the messages briefly, adds a short token to the redirect address, and loads them again on the page you land on. You do nothing for the redirect to work: use `wp_safe_redirect()` as usual.

The form handler:

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the rest of the class...

	/**
	 * Clears the cached rates, then returns to the settings screen.
	 *
	 * Hooked to `admin_post_my_carrier_clear_cache`.
	 *
	 * @return void
	 */
	public function handle_clear_cache(): void {
		check_admin_referer( 'my_carrier_clear_cache' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'У вас нет прав для этого действия.', 'my-carrier' ), '', [ 'response' => 403 ] );
		}

		delete_transient( 'my_carrier_rates_cache' );

		$this->get_message_handler()->add_message( __( 'Кэш тарифов очищен.', 'my-carrier' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) );
		exit;
	}
}
```

Register it in `init_plugin()` with `add_action( 'admin_post_my_carrier_clear_cache', [ $this, 'handle_clear_cache' ] );`.

On the page you land on, print the messages where you want them:

```php
<?php
defined( 'ABSPATH' ) || exit;

My_Carrier_Plugin::instance()->get_message_handler()->show_messages();
```

`show_messages()` prints only for users with `manage_woocommerce`. To allow other roles, pass `[ 'capabilities' => [ 'manage_options' ] ]`; a user who has any of the listed capabilities sees the messages. The messages are cleared after they are loaded, so a refresh does not show them again.

## WooCommerce inbox notes

The WooCommerce Admin inbox is for things a merchant should come back to. The framework gives you a small helper, `Woodev_Notes_Helper`, to find and remove your notes. You create the note itself with WooCommerce's own `Note` class.

| Helper method | What it does |
|---|---|
| `Woodev_Notes_Helper::note_with_name_exists( $name )` | Tells whether a note with that name exists. |
| `Woodev_Notes_Helper::get_note_with_name( $name )` | Returns that note, or `null`. |
| `Woodev_Notes_Helper::get_note_ids_with_name( $name )` | Returns the ids of all notes with that name. |
| `Woodev_Notes_Helper::get_note_ids_with_source( $source )` | Returns the ids of all notes from one source. |
| `Woodev_Notes_Helper::delete_notes_with_source( $source )` | Deletes all notes from one source. |

A note's **name** is a unique key you choose. Use it to avoid adding the same note twice. A note's **source** groups all notes of your plugin; use the plugin id.

```php
<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds an inbox note once.
 *
 * @return void
 */
function my_carrier_add_inbox_note() {
	$note_class = '\Automattic\WooCommerce\Admin\Notes\Note';

	// The inbox needs WooCommerce Admin. Without it there is nothing to add the note to.
	if ( ! class_exists( $note_class ) || Woodev_Notes_Helper::note_with_name_exists( 'my-carrier-rates-ready' ) ) {
		return;
	}

	try {
		$note = new $note_class();

		$note->set_name( 'my-carrier-rates-ready' );
		$note->set_source( 'my-carrier' );
		$note->set_type( $note_class::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_title( __( 'My Carrier готов к работе', 'my-carrier' ) );
		$note->set_content( __( 'Тарифы рассчитываются на странице оформления заказа.', 'my-carrier' ) );
		$note->add_action(
			'open-shipping',
			__( 'Открыть настройки доставки', 'my-carrier' ),
			admin_url( 'admin.php?page=wc-settings&tab=shipping' )
		);
		$note->save();
	} catch ( \Exception $exception ) {
		// An inbox note is a courtesy. Never let it break the admin.
		return;
	}
}
```

When a note is no longer true, delete it. For example, remove all your notes when the plugin is uninstalled:

```php
<?php
defined( 'ABSPATH' ) || exit;

Woodev_Notes_Helper::delete_notes_with_source( 'my-carrier' );
```

## Diagnostics only behind the debug switch

A merchant who is not a developer reads a technical hint as "something is broken". So the rule is simple:

- **Show a merchant a notice only when they can act on it.** "Enter your API key" is a good notice. "A hidden method was skipped for this cart" is not.
- **Put diagnostics behind the plugin's «Логирование» (Logging) switch.** When logging is on, the merchant has asked for detail. When it is off, they see only what they need to do.
- **Never put secrets or customer data in a notice or a log line.**

A shipping plugin already has this switch. `$this->is_debug_enabled()` answers it, and `$this->log_debug( $message )` writes a line only while it is on. A failure belongs in `$this->log_error( $message )`, which always writes. Any other plugin reads its own logging setting.

```php
<?php
defined( 'ABSPATH' ) || exit;

final class My_Carrier_Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	// ...the rest of the class...

	/**
	 * Explains why the carrier was left out, but only while logging is on.
	 *
	 * @param string $reason Why the carrier was skipped.
	 * @return void
	 */
	protected function explain_skipped_carrier( string $reason ): void {
		if ( ! $this->is_logging_on() ) {
			return;
		}

		$this->get_admin_notice_handler()->add_admin_notice(
			sprintf(
				/* translators: %s: the reason a carrier was skipped. */
				__( 'My Carrier пропущен: %s', 'my-carrier' ),
				esc_html( $reason )
			),
			'my-carrier-skipped',
			[
				'notice_class' => 'notice-info',
			]
		);
	}

	/**
	 * Tells whether the merchant switched logging on.
	 *
	 * @return bool
	 */
	private function is_logging_on(): bool {
		return (bool) $this->get_settings_handler()->get_value( 'enable_logging' );
	}
}
```

## Where to go next

- [Setup wizard](../setup-wizard/) shows the first-run flow that these notices point to.
- [Typed settings](../typed-settings/) shows how to declare the settings that `is_configured()` reads.
- [Logging & debugging](../../core/logging-and-debugging/) covers the log that the debug switch controls.
- [The plugin class](../../core/plugin-class/) covers the other hooks the base class gives you.
