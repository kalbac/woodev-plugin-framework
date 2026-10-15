---
title: "Logging & debugging"
description: Learn how to write log entries, what the framework logs for you, and how to keep diagnostics behind the merchant's logging switch.
---

In this page you will learn how to write log entries from a plugin, which classes offer which logging methods, what the framework logs on its own, and how to show diagnostics without worrying a merchant.

## Writing to the log

Every plugin has a `log()` method. In a WooCommerce plugin it writes to the WooCommerce logger, so the lines appear under **WooCommerce → Status → Logs** in a log file whose *source* is your plugin ID.

```php
<?php
$this->log( 'Order 1042 exported as shipment 77.' );
$this->log( 'Pickup points refreshed.', 'my-carrier-points' );
```

The second argument is an optional log ID. It becomes the source name, so a different ID gives you a separate file. Keep source names stable: merchants and support look for them by name.

Log lines are for developers, not for the shop's visitors. Write them in English or Russian, and do not wrap them in `__()`; see [Internationalisation](../internationalisation/).

### Which class offers which method

Your base class decides what is available.

| Method | `Woodev_Plugin` | `Woocommerce_Plugin` | `Shipping_Plugin` |
|---|---|---|---|
| `log( $message, $log_id = null )` | Does nothing. | Writes to the WooCommerce logger. | Inherited from `Woocommerce_Plugin`. |
| `log_debug( $message, $log_id = null )` | Calls `log()`. | Calls `log()`. | Writes at the DEBUG level, and **only while the merchant's logging switch is on**. |
| `log_error( $message, $log_id = null )` | Not available. | Not available. | Writes at the ERROR level, always. |
| `is_debug_enabled()` | Not available. | Not available. | Tells whether the logging switch is on. |

Here `Woocommerce_Plugin` is `\Woodev\Framework\Woocommerce_Plugin` and `Shipping_Plugin` is `\Woodev\Framework\Shipping\Shipping_Plugin`. A plain `Woodev_Plugin` has no log backend at all: its `log()` is empty, so a plugin without WooCommerce overrides `log()` itself if it needs one.

Why `log_error()` exists: `log()` writes at WooCommerce's default severity, which a shop that raised its logging threshold to *Error* silently drops. A failure that someone must be able to find belongs in `log_error()`.

A shipping plugin therefore uses the three methods like this:

```php
<?php
namespace My_Carrier;

defined( 'ABSPATH' ) || exit;

class Exporter {

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function export( int $order_id ): void {
		// Written only when the merchant switched logging on.
		$this->plugin->log_debug( sprintf( 'Exporting order %d.', $order_id ) );

		try {
			$this->plugin->get_client()->create_shipment( $order_id );
		} catch ( \Woodev_API_Exception $e ) {
			// Always written. The message comes from outside, so redact it first.
			$this->plugin->log_error(
				sprintf(
					'Export of order %d failed: %s',
					$order_id,
					\Woodev_API_Base::redact_secret_log_text( $e->getMessage(), [], true )
				)
			);
		}
	}
}
```

`get_client()` is your own method that returns the API client from [Calling external APIs](../calling-external-apis/). The third argument `true` is correct here only because the result goes to a log and nowhere else.

### Never log secrets or personal data

A log file is read by support staff and often pasted into tickets. Do not put tokens, passwords, full card numbers, phone numbers or full addresses in a log line. Text that comes from outside, such as an exception message from a third-party library, may contain a credential, so pass it through `Woodev_API_Base::redact_secret_log_text()` before you log it, as above.

## The merchant's logging switch

A carrier plugin built on `Shipping_Plugin` gets a ready-made switch. It is called «Логирование» (Logging) and sits in the «Дополнительно» (Advanced) section of the plugin's tab on the «Woodev → Настройки» (Settings) page. You write no code for it.

| Switch | What reaches the log |
|---|---|
| Off (the default) | Errors only: `log_error()` and anything written with plain `log()`. |
| On | Also the `log_debug()` lines and every carrier API request and response. |

`WP_DEBUG` does not switch it on. Read the state with `$plugin->is_debug_enabled()` when you need to decide something yourself.

## What the framework logs for you

Every call made through an API client is logged automatically. The plugin base class listens to the client's `woodev_{api_id}_api_request_performed` action and writes one "request" entry and one "response" entry. You do not write that code and you must not log request bodies a second time.

- The request entry carries the method, address, user agent, headers, body and duration.
- The response entry carries the status code, message, headers and body.
- Credentials are redacted before the entry is written: sensitive headers, query parameters and body fields are masked, and a body the framework cannot inspect is withheld. [Calling external APIs](../calling-external-apis/#request-logging-and-secrets) lists the rules and shows how to extend them.
- In a shipping plugin the entries are written at the DEBUG level and only while the logging switch is on. A payment gateway plugin routes them through the gateway's own debug setting instead.

To find a log file from your code, `Woodev_Helper::get_wc_log_file_url( $handle )` returns the admin URL of the WooCommerce log for a source name:

```php
<?php
$url = \Woodev_Helper::get_wc_log_file_url( 'my-carrier' );
```

## Diagnostics for merchants

Sometimes a plugin could explain a behaviour that looks like a fault, for example *"this delivery method is hidden because the order weight is missing"*. Such hints help whoever debugs a shop. For an ordinary merchant, who is usually not technical, the same hint reads like a breakage warning.

Follow one rule: **show diagnostics only while the logging switch is on.** With logging off, the admin stays quiet and the plugin behaves as designed. When the switch is on, the explanation may go to the log, and to a screen if you add one:

```php
<?php
if ( $this->plugin->is_debug_enabled() ) {
	$this->plugin->log_debug( 'Method hidden: the package has no weight.' );
}
```

`log_debug()` already checks the switch in a shipping plugin, so a log line needs no extra condition. The explicit check matters when you also print something on a screen.

In a plugin that is not a shipping plugin there is no built-in switch. `log_debug()` then writes every time, so give such a plugin its own setting and check it before calling `log_debug()`.

## Related

- [Calling external APIs](../calling-external-apis/) — the automatic request logging and the redaction rules.
- [Internationalisation](../internationalisation/) — why log lines are not translated.
- [The plugin class](../plugin-class/) — the base classes that carry these methods.
