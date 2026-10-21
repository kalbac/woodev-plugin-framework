---
title: Calling external APIs
description: Learn how to build an API client on the framework, handle errors and timeouts, cache responses and keep secrets out of the logs.
---

In this page you will learn how to build a client for a remote service on top of `Woodev_API_Base`, how to describe requests and responses, how errors are classified, how to cache answers, and how request logging hides credentials.

## The pieces

An API integration is three small classes plus the error types the framework already provides.

| Piece | You extend | What it does |
|---|---|---|
| Client | `Woodev_API_Base` (or `Woodev_Cacheable_API_Base` for cached reads) | Holds the base URL and headers, sends the request, validates and parses the answer. |
| Request | `Woodev_API_JSON_Request` or `Woodev_API_XML_Request` | Describes one call: method, path and body. Implements `Woodev_API_Request`. |
| Response | `Woodev_API_JSON_Response` or `Woodev_API_XML_Response` | Wraps the raw body and gives your code typed accessors. Implements `Woodev_API_Response`. |
| Exceptions | nothing | `Woodev_API_Exception` and its two subclasses tell your code what kind of failure happened. |

The client never builds the HTTP request by hand. It sends it with `wp_safe_remote_request()`, so WordPress refuses private network addresses and unusual ports. Your code only says *what* to call.

## A complete client

The example below talks to an imaginary carrier API. The classes live in the `My_Carrier\Api` namespace, which the autoloader from [Plugin layout & conventions](../../getting-started/plugin-layout-and-conventions/#with-your-own-autoloader) maps to `includes/api/`.

### The request

`Woodev_API_JSON_Request` keeps the method, path and parameters in three protected properties. A request class only fills them in. Parameters are encoded as the JSON body; a `GET` request has no body, so put its query string into the path.

```php
<?php
namespace My_Carrier\Api;

defined( 'ABSPATH' ) || exit;

class Request extends \Woodev_API_JSON_Request {

	/**
	 * @param string $method HTTP method.
	 * @param string $path   Path below the client's base URL, starting with a slash.
	 * @param array  $params Body parameters, sent as JSON.
	 */
	public function __construct( string $method, string $path, array $params = [] ) {
		$this->method = $method;
		$this->path   = $path;
		$this->params = $params;
	}
}
```

### The response

`Woodev_API_JSON_Response` decodes the body for you. Any property you read that the class does not declare is looked up in the decoded JSON and is `null` when missing.

```php
<?php
namespace My_Carrier\Api;

defined( 'ABSPATH' ) || exit;

class Response extends \Woodev_API_JSON_Response {

	/**
	 * @return object[] One object per offered tariff.
	 */
	public function get_rates(): array {
		return is_array( $this->rates ) ? $this->rates : [];
	}

	public function get_error_message(): string {
		return (string) $this->message;
	}
}
```

### The client

```php
<?php
namespace My_Carrier\Api;

defined( 'ABSPATH' ) || exit;

class Client extends \Woodev_API_Base {

	private \My_Carrier\Plugin $plugin;

	public function __construct( \My_Carrier\Plugin $plugin, string $api_key ) {
		$this->plugin      = $plugin;
		$this->request_uri = 'https://api.example.com/v1';

		$this->set_request_content_type_header( 'application/json' );
		$this->set_request_accept_header( 'application/json' );
		$this->set_request_header( 'X-Api-Key', $api_key );
		$this->set_response_handler( Response::class );
	}

	public function get_rates( array $shipment ): Response {
		return $this->perform_request( $this->get_new_request( [
			'method' => 'POST',
			'path'   => '/rates',
			'params' => $shipment,
		] ) );
	}

	public function get_tracking( string $number ): Response {
		return $this->perform_request( $this->get_new_request( [
			'method' => 'GET',
			'path'   => '/tracking/' . rawurlencode( $number ),
		] ) );
	}

	protected function get_new_request( $args = [] ) {
		return new Request( $args['method'] ?? 'GET', $args['path'] ?? '/', $args['params'] ?? [] );
	}

	protected function get_plugin() {
		return $this->plugin;
	}

	/**
	 * Runs before the body is parsed. Refuse any HTTP error here.
	 */
	protected function do_pre_parse_response_validation() {
		$code = (int) $this->response_code;

		if ( $code >= 400 ) {
			throw new \Woodev_API_Exception( sprintf( 'My Carrier answered HTTP %d.', $code ), $code );
		}
	}

	/**
	 * Runs after the body is parsed. Refuse an error the service reports inside a 200 answer.
	 */
	protected function do_post_parse_response_validation() {
		$message = $this->get_response()->get_error_message();

		if ( '' !== $message ) {
			throw new \Woodev_API_Exception( $message );
		}
	}
}
```

What the base class does for you when you call `perform_request()`:

1. Builds the address from `$request_uri` plus the request's path, and the arguments from the request's method and body.
2. Sends the request and measures how long it took.
3. Calls `do_pre_parse_response_validation()`, creates your response class from the raw body, then calls `do_post_parse_response_validation()`.
4. Announces the finished call to the logger (see [Request logging and secrets](#request-logging-and-secrets)), also when validation failed.
5. Returns the response object, or throws.

A client has to provide two methods and one property: `get_new_request()` builds a request object, `get_plugin()` returns your plugin, and `$request_uri` holds the base URL. The default HTTP method is `POST`; a request that returns its own method overrides it.

### Header and authentication helpers

Call these from the client, usually in its constructor.

| Method | Purpose |
|---|---|
| `set_request_header( $name, $value )` | Set one header. |
| `set_request_headers( array $headers )` | Set several headers. |
| `set_http_basic_auth( $username, $password )` | Set the `Authorization: Basic ...` header. |
| `set_request_content_type_header( $type )` | Set `Content-Type`. |
| `set_request_accept_header( $type )` | Set `Accept`. |
| `set_response_handler( $class )` | The response class that receives the raw body. |

The `User-Agent` is built for you as `woodev-{plugin id}/{version} (WooCommerce/x; WordPress/y)`.

## Errors

Every failure the client reports is a `Woodev_API_Exception`. Two subclasses carry extra meaning, and the framework picks the subclass for you.

| Exception | When it is thrown | What your code should assume |
|---|---|---|
| `Woodev_API_Transport_Exception` | The request failed at the transport level (timeout, DNS, refused connection), or your validation refused an answer that looks like a transport failure: a 5xx status, no usable status, or an empty body on anything but a 4xx. | The outcome is **unknown**. The service may have acted before the answer was lost, so do not repeat a call that creates something. |
| `Woodev_API_Rate_Limit_Exception` | Your validation refused an HTTP 429 answer. | Nothing was done and the call is safe to repeat later. `get_retry_after()` returns the wait the service asked for, in seconds, or `null`. |
| `Woodev_API_Exception` | Any other failure you throw from your validation hooks, such as a 4xx or an error inside a 200 answer. | The service refused the request. |

You do not throw the two subclasses yourself, except that a failed transport (a timeout, for example) always raises a `Woodev_API_Transport_Exception`. For everything else, throw a plain `Woodev_API_Exception` from your validation hooks, as the client above does, and the framework re-types it according to the HTTP status it just received: a plain exception raised for a 429 becomes a `Woodev_API_Rate_Limit_Exception`, and one raised for a 5xx becomes a `Woodev_API_Transport_Exception`. The message, code and previous exception are kept. An answer that your validation accepts is returned as it is, whatever its status.

:::note
Only a *plain* `Woodev_API_Exception` is re-typed. If you throw your own subclass, it passes through unchanged and you classify it yourself.
:::

Catch the specific classes first, because both subclasses also match `Woodev_API_Exception`:

```php
<?php
try {
	$response = $client->get_rates( $shipment );
} catch ( \Woodev_API_Rate_Limit_Exception $e ) {
	// Safe to try again after $e->get_retry_after() seconds, when the service said so.
} catch ( \Woodev_API_Transport_Exception $e ) {
	// Unknown outcome. Read-only calls may be repeated; calls that create something must not be.
} catch ( \Woodev_API_Exception $e ) {
	// The service refused the request. $e->getCode() is the code you threw.
}
```

`Woodev_API_Exception` extends `Woodev_Plugin_Exception`, so a single `catch` of the parent type handles every framework exception.

## Timeouts and request purpose

How long a client should wait depends on who is waiting. A shopper at the checkout needs a rate within seconds, while an order export can take longer. The framework calls this the *purpose* of a request and sets the HTTP timeout from it.

| Purpose | Constant | Default timeout |
|---|---|---|
| Checkout rates | `Woodev_API_Request_Purpose::RATES` | 8 seconds |
| Reference data outside a checkout (pickup points, directories) | `Woodev_API_Request_Purpose::REFERENCE` | 20 seconds |
| Shipment export, cancel or update | `Woodev_API_Request_Purpose::EXPORT` | 30 seconds |
| Anything else | `Woodev_API_Request_Purpose::DEFAULT_PURPOSE` | 60 seconds |

The purpose is not a parameter of your API methods. You mark the stretch of code that makes the call, and every request inside it takes the purpose's timeout:

```php
<?php
$rates = \Woodev_API_Request_Purpose::run_at_checkout(
	static function () use ( $client, $shipment ) {
		return $client->get_rates( $shipment );
	}
);
```

`run_at_checkout( $callback )` is the checkout budget. `run_reference( bool $at_checkout, $callback )` picks the checkout budget when a shopper is waiting and the reference timeout otherwise. `run( $purpose, $callback )` sets any purpose. All of them return what the callback returns and restore the previous purpose afterwards, even when the callback throws.

Scopes nest, and an inner scope can only shorten the wait, never lengthen it. A pickup-point lookup made inside a checkout rate call keeps the rate timeout.

A point source that meters its calls (a daily quota with a part kept for confirmations) can tell a shopper choosing a point from one merely viewing its card. When the framework looks a point up to accept the choice (the `select` route, the checkout re-check of the posted point, saving the point on the order), it runs the call with `run_at_confirmation( $callback )`, which is the checkout budget plus a mark. Read it inside your source:

```php
<?php
public function fetch_details( string $point_id ): ?Pickup_Point {
	// `false` for a card view and for the admin; the timeout is 8 seconds either way at the checkout.
	$confirming = method_exists( 'Woodev_API_Request_Purpose', 'is_confirmation' )
		&& \Woodev_API_Request_Purpose::is_confirmation();

	return $this->fetch( $point_id, $confirming );
}
```

`\Woodev_API_Request_Purpose::current()` and the timeout do not change, so a source that ignores the mark behaves exactly as before. The `method_exists()` guard keeps a plugin working when an older framework copy wins the bootstrap.

To change the timeouts of your own client, override `get_request_timeout( string $purpose ): int` in it, or filter the result (see the hooks table below). A filter value that is not a positive number is ignored.

## Hooks around a request

These hooks exist for every client. `{api_id}` is your plugin ID as returned by `get_id()`, for example `my-carrier`, so the first hook is `woodev_my-carrier_api_request_performed`.

| Hook | Type | Arguments | Use it to |
|---|---|---|---|
| `woodev_{api_id}_api_request_performed` | action | `$request_data`, `$response_data`, `$api` | Log or inspect a finished call. The data is already redacted. |
| `woodev_{api_id}_api_request_uri` | filter | `$uri`, `$api` | Change the address. A non-string return is ignored. |
| `woodev_{api_id}_http_request_args` | filter | `$args`, `$api` | Change the arguments passed to WordPress (headers, timeout, body). |
| `woodev_{api_id}_request_timeout` | filter | `$timeout`, `$purpose`, `$api` | Change the timeout in seconds. |

For example, to send an extra header with every request of one plugin:

```php
<?php
add_filter(
	'woodev_my-carrier_http_request_args',
	static function ( array $args ): array {
		$args['headers']['X-Shop-Id'] = (string) get_current_blog_id();

		return $args;
	}
);
```

## Caching responses

Reference data such as pickup points or city directories rarely changes, so ask the remote service once and keep the answer. Two things turn caching on:

1. The client extends `Woodev_Cacheable_API_Base` instead of `Woodev_API_Base`.
2. The request class uses the trait `Woodev_Cacheable_Request_Trait`.

```php
<?php
namespace My_Carrier\Api;

defined( 'ABSPATH' ) || exit;

class Points_Request extends \Woodev_API_JSON_Request {

	use \Woodev_Cacheable_Request_Trait;

	public function __construct( string $city ) {
		$this->method = 'GET';
		$this->path   = '/points?city=' . rawurlencode( $city );

		$this->set_cache_lifetime( DAY_IN_SECONDS );
	}
}
```

:::caution
Put the trait on the request class itself. A request that only inherits the trait from a parent class is **not** cached, because the client checks the request class directly.
:::

The trait gives the request these methods:

| Method | Effect |
|---|---|
| `set_cache_lifetime( int $seconds )` | How long to keep the answer. The default is 86400 (one day); `0` means unlimited. |
| `set_force_refresh( bool $value )` | Skip the stored answer and ask the service, then store the new answer. |
| `set_should_cache( bool $value )` | Read the stored answer if there is one, but do not store a new answer. |
| `bypass_cache()` | Both of the above: always ask the service and never store the result. |
| `get_cache_lifetime()`, `should_refresh()`, `should_cache()` | Read the current settings. |

The setters return the request, so they chain.

Good to know:

- The answer is stored as a WordPress transient. The key is built from your plugin ID, the address, the body and the lifetime, so a different body is a different entry.
- Only answers that passed your validation are stored. A refused or failed call is never cached.
- An answer larger than 512 KB (as stored) is not cached. The call itself still succeeds, and a debug line is written to the log. Override `get_request_cache_max_bytes(): int` in the client to change the limit for that API; `0` or less removes it.
- The request and response data announced to the logger carry the extra flags `force_refresh`, `should_cache` and `from_cache`, so a log shows whether an answer came from the cache.

Three filters adjust caching per plugin. `{id}` is the plugin ID:

| Filter | Arguments | Effect |
|---|---|---|
| `woodev_plugin_{id}_api_request_is_cacheable` | `$cacheable`, `$request` | Return `false` to switch caching off, for example on a staging site. |
| `woodev_plugin_{id}_api_request_cache_lifetime` | `$seconds`, `$request` | Change the lifetime. |
| `woodev_plugin_{id}_api_request_cache_max_bytes` | `$bytes`, `$request` | Change the size limit. A non-numeric value is ignored. |

## XML services

For a service that speaks XML, extend `Woodev_API_XML_Request` and `Woodev_API_XML_Response` instead. The client stays the same.

A request fills `$request_data` with a nested array whose single top-level key is the root element, and names that element in `get_root_element()`. Inside the array:

- a plain key becomes a child element;
- the key `@attributes` holds the attributes of the element it sits in, so put it **first**;
- the key `@value` holds the text of an element that also has attributes;
- a list (numeric keys) repeats the parent element once per entry;
- a value that contains `<` or `>` is wrapped in CDATA for you.

```php
<?php
namespace My_Carrier\Api;

defined( 'ABSPATH' ) || exit;

class Order_Request extends \Woodev_API_XML_Request {

	public function __construct( string $number, array $items ) {
		$this->request_data = [
			'Order' => [
				'@attributes' => [ 'Number' => $number ],
				'Item'        => $items, // [ [ 'Sku' => 'A-1', 'Qty' => 2 ], [ 'Sku' => 'B-7', 'Qty' => 1 ] ]
			],
		];
	}

	protected function get_root_element() {
		return 'Order';
	}
}
```

That request produces `<Order Number="..."><Item><Sku>A-1</Sku><Qty>2</Qty></Item><Item>...</Item></Order>`. An XML request has no path and no method of its own, so the client's base URL is used as it is and the call is a `POST`.

The XML response parses the body with `SimpleXMLElement` and reads child elements as properties. An element that is missing or empty reads as `null`. A body that is not well-formed XML makes the constructor throw a plain `Exception`, so check the status code in `do_pre_parse_response_validation()` before the body is parsed.

## Request logging and secrets

You do not write logging code. The plugin base class subscribes its `log_api_request()` method to `woodev_{api_id}_api_request_performed`, so every call made through a client is written to the plugin log: method, address, user agent, headers, body and duration for the request; status, message, headers and body for the response. [Logging & debugging](../logging-and-debugging/) explains where the lines go and when they are written.

The data is redacted before any subscriber sees it:

- **Query parameters and body fields** whose name is `license`, `license_key`, `token`, `access_token`, `refresh_token`, `api_key`, `api-key`, `apikey`, `api_secret`, `client_secret`, `secret`, `password` or `instance_id` have their values replaced. The match ignores case and separators, so `apiKey`, `api_key` and `api-key` are all caught, at any depth in a JSON body.
- **Headers** such as `Authorization`, `Proxy-Authorization`, `Api-Key`, `X-Api-Key`, `X-Api-Secret`, `X-Auth-Token`, `X-Access-Token`, `X-Secret`, `Cookie` and `Set-Cookie` are masked in both directions.
- **A body that is not a JSON object or array** (XML, form data, plain text) is replaced as a whole with `[REDACTED: body format could not be parsed for logging]`, because the framework cannot tell where a secret sits in it. So an XML service logs the headers and status but not the body.
- **A body larger than 1 MiB** is not logged; the log line states its size instead.
- **Error messages** the framework builds from a transport failure or an HTTP reason phrase are redacted too.

By default a secret is replaced by `[REDACTED]`. A plugin type whose requests reach a log only, and never a screen, shows the start and end of a long value instead (`abcd****wxyz`), which helps to tell two keys apart. Shipping plugins do this.

### Hiding more

The default lists cannot know your service. If it uses a credential under another name, add that name in the client:

```php
<?php
namespace My_Carrier\Api;

defined( 'ABSPATH' ) || exit;

class Client extends \Woodev_API_Base {

	// ... the rest of the client ...

	protected function get_secret_header_names(): array {
		return array_merge( parent::get_secret_header_names(), [ 'X-Shop-Signature' ] );
	}

	protected function get_secret_param_names(): array {
		return array_merge( parent::get_secret_param_names(), [ 'partner_pin' ] );
	}
}
```

`get_secret_header_names()` covers headers, `get_secret_param_names()` covers query parameters and body fields. The lists are a safety net, not a guarantee: a secret sent under an unusual name that you did not add, or with no name at all (a bare path segment), is not caught.

### Redacting your own log text

When you log a message that comes from outside, such as an exception thrown by a third-party SDK, pass it through `Woodev_API_Base::redact_secret_log_text()` first:

```php
<?php
$plugin->log( 'Export failed: ' . \Woodev_API_Base::redact_secret_log_text( $e->getMessage() ) );
```

The method has the signature `redact_secret_log_text( string $text, array $extra_secret_names = [], bool $for_log = false ): string`. It masks `name=value` pairs and `<name>value</name>` elements for the default names plus any you pass. Pass `true` as the third argument only when the result goes straight to a log and nowhere else, so that long values are shown partially.

To add a credential name once for every call site, filter the list:

```php
<?php
add_filter(
	'woodev_api_log_text_secret_param_names',
	static function ( array $names ): array {
		$names[] = 'partner_pin';

		return $names;
	}
);
```

The filter receives `$names` and the `$text` about to be redacted. Names that are not strings are dropped, and if nothing usable is returned the default list is used, so a faulty filter cannot switch redaction off.

## Related

- [Logging & debugging](../logging-and-debugging/) — where API log lines end up and when they are written.
- [Background processing](../background-processing/) — keep slow or repeated calls out of the shopper's request.
- [The plugin class](../plugin-class/) — what `get_plugin()` returns and what the base class provides.
