# API Module

The API module provides base classes for making HTTP requests to external services. It includes request/response handling, error management, and optional response caching via WordPress transients.

## Overview

The API module provides:

- HTTP client base class (`Woodev_API_Base`)
- Request/response interfaces and abstract implementations for JSON and XML
- Error handling and validation hooks
- Optional response caching (transient-based)
- Request broadcasting (logging hook)

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_API_Base` | `api/class-api-base.php` | Abstract HTTP client base class |
| `Woodev_API_Request` | `api/interface-api-request.php` | Request interface |
| `Woodev_API_Response` | `api/interface-api-response.php` | Response interface |
| `Woodev_API_JSON_Request` | `api/abstract-api-json-request.php` | Abstract JSON request |
| `Woodev_API_JSON_Response` | `api/abstract-api-json-response.php` | Abstract JSON response |
| `Woodev_API_XML_Request` | `api/abstract-api-xml-request.php` | Abstract XML request |
| `Woodev_API_XML_Response` | `api/abstract-api-xml-response.php` | Abstract XML response |
| `Woodev_Cacheable_API_Base` | `api/abstract-cacheable-api-base.php` | HTTP client with transient caching |
| `Woodev_Cacheable_Request_Trait` | `api/traits/cacheable-request-trait.php` | Trait for cacheable requests |
| `Woodev_API_Exception` | `api/class-api-exception.php` | API exception class |

## Interfaces

### Woodev_API_Request

All request classes must implement this interface:

```php
interface Woodev_API_Request {
    public function get_method();
    public function get_path();
    public function to_string();
    public function to_string_safe();
}
```

### Woodev_API_Response

All response classes must implement this interface:

```php
interface Woodev_API_Response {
    public function to_string();
    public function to_string_safe();
}
```

## Basic Usage

### Extending Woodev_API_Base

`Woodev_API_Base` has two abstract methods that child classes must implement:

- `get_new_request( $args )` — creates and returns a request object implementing `Woodev_API_Request`
- `get_plugin()` — returns the plugin instance

The base URL is set via the `$request_uri` property. The `get_api_id()` method defaults to returning the plugin ID and can be overridden.

```php
class My_API extends Woodev_API_Base {

    public function __construct() {
        $this->request_uri = 'https://api.example.com/v1';
    }

    protected function get_plugin(): My_Plugin {
        return My_Plugin::instance();
    }

    protected function get_new_request( $args = [] ): My_API_Request {
        return new My_API_Request( $args );
    }

    public function get_users(): My_API_Response {
        $request = $this->get_new_request( [ 'method' => 'GET', 'path' => '/users' ] );

        $this->set_response_handler( 'My_API_Response' );

        return $this->perform_request( $request );
    }
}
```

### Request Flow

`perform_request( $request )` handles the full lifecycle:

1. Sets `$this->request = $request`
2. Builds the request URI: `$this->request_uri . $request->get_path()`
3. Calls `wp_safe_remote_request()` with args from `get_request_args()`
4. On `WP_Error` — throws `Woodev_API_Exception` immediately
5. Populates `$this->response_code`, `$this->response_message`, `$this->response_headers`, `$this->raw_response_body`
6. Calls `do_pre_parse_response_validation()`
7. Instantiates response handler: `new $this->response_handler( $raw_response_body )`
8. Calls `do_post_parse_response_validation()`
9. Fires `broadcast_request()` (action hook)
10. Returns the response object

**Important:** `perform_request()` already returns the parsed response object. Do not call `get_parsed_response()` on the result externally.

## Request Classes

### JSON Request

Extend `Woodev_API_JSON_Request` for JSON APIs:

```php
class My_API_Request extends Woodev_API_JSON_Request {

    public function __construct( array $args ) {
        $this->method = $args['method'] ?? 'GET';
        $this->path   = $args['path'] ?? '';
        $this->params = $args['params'] ?? [];
    }
}
```

`to_string()` returns `json_encode( $this->get_params() )` when params are set.

### XML Request

Extend `Woodev_API_XML_Request` for XML APIs. Must implement `get_root_element()`:

```php
class My_XML_Request extends Woodev_API_XML_Request {

    public function __construct( array $data ) {
        $this->request_data = $data;
    }

    protected function get_root_element(): string {
        return 'Request';
    }
}
```

`to_string()` serializes `$request_data` to XML using `Woodev_Helper::array_to_xml()`.

## Response Classes

### JSON Response

Extend `Woodev_API_JSON_Response` for JSON APIs. The constructor decodes the raw JSON body:

```php
class My_API_Response extends Woodev_API_JSON_Response {

    public function get_user_id(): ?int {
        return isset( $this->response_data->id ) ? (int) $this->response_data->id : null;
    }
}
```

Access response properties via the magic `__get()` method or directly on `$response->response_data`.

### XML Response

Extend `Woodev_API_XML_Response` for XML APIs. The constructor parses XML using `SimpleXMLElement`:

```php
class My_XML_Response extends Woodev_API_XML_Response {

    public function get_status(): ?string {
        return $this->Status ? (string) $this->Status : null;
    }
}
```

## Request Configuration

### Request Headers

```php
// Set a single header
$this->set_request_header( 'X-Custom-Header', 'value' );

// Set multiple headers
$this->set_request_headers( [
    'Accept'     => 'application/json',
    'User-Agent' => 'My Plugin/1.0',
] );

// Set content type header
$this->set_request_content_type_header( 'application/json' );

// Set accept header
$this->set_request_accept_header( 'application/json' );
```

### Authentication

```php
// Basic Auth — sets Authorization header with base64-encoded credentials
$this->set_http_basic_auth( $username, $password );

// Bearer Token
$this->set_request_header( 'Authorization', 'Bearer ' . $token );

// API Key in header
$this->set_request_header( 'X-API-Key', $api_key );
```

### Default Request Args

`get_request_args()` builds the args array passed to `wp_safe_remote_request()`:

```php
[
    'method'      => $this->get_request_method(),   // from request object or $request_method property
    'timeout'     => MINUTE_IN_SECONDS,              // 60 seconds
    'redirection' => 0,
    'httpversion' => $this->request_http_version,    // '1.0' by default
    'sslverify'   => true,                           // filtered via woodev_sl_api_request_verify_ssl
    'blocking'    => true,
    'user-agent'  => $this->get_request_user_agent(),
    'headers'     => $this->get_request_headers(),
    'body'        => $this->get_request_body(),
    'cookies'     => [],
]
```

Override `get_request_args()` to add custom defaults (e.g. timeout):

```php
protected function get_request_args(): array {
    return array_merge( parent::get_request_args(), [
        'timeout' => 30,
    ] );
}
```

## Response Handling

### Accessing Response Data

After `perform_request()` returns, use the getter methods to inspect the response:

```php
// Response HTTP code (string)
$code = $this->get_response_code();

// Response HTTP message
$message = $this->get_response_message();

// Response headers array
$headers = $this->get_response_headers();

// Raw response body string
$body = $this->get_raw_response_body();

// Parsed response object (set internally)
$response = $this->get_response();
```

### Response Validation Hooks

Override these no-argument methods to validate before and after parsing:

```php
class My_API extends Woodev_API_Base {

    /**
     * Called after raw HTTP response is received, before response handler is instantiated.
     * Throw Woodev_API_Exception to abort.
     */
    protected function do_pre_parse_response_validation() {
        $code = (int) $this->get_response_code();

        if ( $code >= 400 ) {
            $body    = json_decode( $this->get_raw_response_body(), true );
            $message = $body['message'] ?? $this->get_response_message();

            throw new Woodev_API_Exception( $message, $code );
        }
    }

    /**
     * Called after response handler is instantiated.
     * Use $this->get_response() to access the parsed response.
     * Throw Woodev_API_Exception to abort.
     */
    protected function do_post_parse_response_validation() {
        $response = $this->get_response();

        if ( $response->error ) {
            throw new Woodev_API_Exception( (string) $response->error, 0 );
        }
    }
}
```

### Response Handler

Set the response handler class name before calling `perform_request()`:

```php
protected function set_response_handler( $handler ) {
    $this->response_handler = $handler;
}
```

The handler class is instantiated with the raw response body string as its only constructor argument:

```php
new $handler_class( $raw_response_body );
```

## Caching

### Using Woodev_Cacheable_API_Base

Extend `Woodev_Cacheable_API_Base` instead of `Woodev_API_Base`. Responses are cached as WordPress transients automatically when the request uses `Woodev_Cacheable_Request_Trait`.

```php
class My_Cached_API extends Woodev_Cacheable_API_Base {

    public function __construct() {
        $this->request_uri = 'https://api.example.com/v1';
    }

    protected function get_plugin(): My_Plugin {
        return My_Plugin::instance();
    }

    protected function get_new_request( $args = [] ): My_Cached_Request {
        return new My_Cached_Request( $args );
    }
}
```

### Making Requests Cacheable

The request class must use `Woodev_Cacheable_Request_Trait`:

```php
class My_Cached_Request extends Woodev_API_JSON_Request {

    use Woodev_Cacheable_Request_Trait;

    public function __construct( array $args ) {
        $this->method       = $args['method'] ?? 'GET';
        $this->path         = $args['path'] ?? '';
        $this->params       = $args['params'] ?? [];
        $this->cache_lifetime = $args['cache_lifetime'] ?? DAY_IN_SECONDS;
    }
}
```

### Cache Trait Methods

The `Woodev_Cacheable_Request_Trait` provides these methods on the request object:

```php
// Set cache lifetime in seconds (default: 86400 — 24 hours)
$request->set_cache_lifetime( HOUR_IN_SECONDS );

// Get current cache lifetime
$request->get_cache_lifetime();

// Force a fresh HTTP request even if cached
$request->set_force_refresh( true );
$request->should_refresh(); // bool

// Control whether the response is stored in cache
$request->set_should_cache( false );
$request->should_cache(); // bool

// Shortcut: force refresh + disable caching for this request
$request->bypass_cache();
```

### Cache Internals

The transient key is built from the plugin ID, request URI, request body, and cache lifetime:

```php
sprintf(
    'woodev_%s_api_response_%s',
    $plugin->get_id(),
    md5( implode( '_', [ $request_uri, $request_body, $cache_lifetime ] ) )
);
```

Check if a response was served from cache:

```php
$api->is_response_loaded_from_cache(); // bool
```

### Cache Filters

```php
// Control whether a request is cacheable
add_filter( 'woodev_plugin_{plugin_id}_api_request_is_cacheable', function( $cacheable, $request ) {
    return $cacheable;
}, 10, 2 );

// Override the cache lifetime
add_filter( 'woodev_plugin_{plugin_id}_api_request_cache_lifetime', function( $lifetime, $request ) {
    return $lifetime;
}, 10, 2 );
```

## Error Handling

### API Exceptions

`Woodev_API_Exception` extends `Woodev_Plugin_Exception`. It is thrown automatically on network errors (`WP_Error`) and can be thrown manually in the validation hooks.

```php
public function get_user( int $id ): My_API_Response {
    try {
        $request = $this->get_new_request( [
            'method' => 'GET',
            'path'   => '/users/' . $id,
        ] );

        $this->set_response_handler( 'My_API_Response' );

        return $this->perform_request( $request );

    } catch ( Woodev_API_Exception $e ) {
        $this->get_plugin()->log( 'API Error: ' . $e->getMessage() );
        throw $e;
    }
}
```

```php
try {
    $response = $api->get_user( 123 );
} catch ( Woodev_API_Exception $e ) {
    echo 'Code: '    . $e->getCode();
    echo 'Message: ' . $e->getMessage();
}
```

## Request Broadcasting

After every request (including failed ones), `broadcast_request()` fires a WordPress action:

```php
do_action(
    'woodev_' . $this->get_api_id() . '_api_request_performed',
    $request_data,
    $response_data,
    $this
);
```

The `$request_data` array contains:

| Key | Value |
| --- | --- |
| `method` | HTTP method |
| `uri` | Full request URI |
| `user-agent` | Auto-generated user agent string |
| `headers` | Sanitized request headers (Authorization is masked) |
| `body` | Sanitized request body |
| `duration` | Request duration in seconds |

The `$response_data` array contains:

| Key | Value |
| --- | --- |
| `code` | HTTP response code |
| `message` | HTTP response message |
| `headers` | Response headers |
| `body` | Response body (sanitized via `to_string_safe()` if available) |

For cacheable requests, both arrays include additional cache flags (`force_refresh`, `should_cache`, `from_cache`).

Hook into this action for request logging:

```php
add_action( 'woodev_my_plugin_api_request_performed', function( $request_data, $response_data, $api ) {
    My_Plugin::instance()->log( sprintf(
        '%s %s — HTTP %s (%ss)',
        $request_data['method'],
        $request_data['uri'],
        $response_data['code'],
        $request_data['duration']
    ) );
}, 10, 3 );
```

## Available Filters

| Filter | Description |
| --- | --- |
| `woodev_{api_id}_api_request_uri` | Modify the final request URI |
| `woodev_{api_id}_http_request_args` | Modify `wp_safe_remote_request()` args |
| `woodev_sl_api_request_verify_ssl` | Control SSL certificate verification |
| `woodev_{plugin_id}_api_is_tls_1_2_available` | Override TLS 1.2 availability check |
| `woodev_plugin_{plugin_id}_api_request_is_cacheable` | Control whether a request is cached |
| `woodev_plugin_{plugin_id}_api_request_cache_lifetime` | Override cache lifetime in seconds |

## TLS 1.2 Support

```php
// Check TLS 1.2 availability
if ( $api->is_tls_1_2_available() ) {
    // TLS 1.2 is supported
}
```

TLS 1.2 is enforced automatically when `$plugin->require_tls_1_2()` returns `true`. In that case, `perform_request()` hooks `set_tls_1_2_request()` into `http_api_curl` before each request.

## Complete Example

### API Client

```php
<?php

class My_API extends Woodev_API_Base {

    public function __construct() {
        $this->request_uri = 'https://api.example.com/v1';
        $this->set_request_content_type_header( 'application/json' );
        $this->set_request_accept_header( 'application/json' );
    }

    protected function get_plugin(): My_Plugin {
        return My_Plugin::instance();
    }

    protected function get_new_request( $args = [] ): My_API_Request {
        return new My_API_Request( $args );
    }

    protected function do_pre_parse_response_validation() {
        $code = (int) $this->get_response_code();

        if ( $code >= 400 ) {
            $body    = json_decode( $this->get_raw_response_body(), true );
            $message = $body['message'] ?? $this->get_response_message();

            throw new Woodev_API_Exception( $message, $code );
        }
    }

    public function get_users( array $params = [] ): My_API_Response {
        $request = $this->get_new_request( [
            'method' => 'GET',
            'path'   => '/users',
            'params' => $params,
        ] );

        $this->set_response_handler( 'My_API_Response' );

        return $this->perform_request( $request );
    }

    public function create_user( array $data ): My_API_Response {
        $request = $this->get_new_request( [
            'method' => 'POST',
            'path'   => '/users',
            'params' => $data,
        ] );

        $this->set_response_handler( 'My_API_Response' );

        return $this->perform_request( $request );
    }
}
```

### Request Class

```php
class My_API_Request extends Woodev_API_JSON_Request {

    public function __construct( array $args ) {
        $this->method = $args['method'] ?? 'GET';
        $this->path   = $args['path'] ?? '';
        $this->params = $args['params'] ?? [];
    }
}
```

### Response Class

```php
class My_API_Response extends Woodev_API_JSON_Response {

    public function get_id(): ?int {
        return isset( $this->response_data->id ) ? (int) $this->response_data->id : null;
    }

    public function get_name(): ?string {
        return isset( $this->response_data->name ) ? (string) $this->response_data->name : null;
    }
}
```

### Service Class Usage

```php
class User_Service {

    private My_API $api;

    public function __construct() {
        $this->api = new My_API();
    }

    public function list_users( int $page = 1, int $per_page = 20 ): ?My_API_Response {
        try {
            return $this->api->get_users( [
                'page'     => $page,
                'per_page' => $per_page,
            ] );
        } catch ( Woodev_API_Exception $e ) {
            My_Plugin::instance()->log( 'API Error: ' . $e->getMessage() );
            return null;
        }
    }

    public function create_user( array $data ): ?int {
        try {
            $response = $this->api->create_user( $data );
            return $response->get_id();
        } catch ( Woodev_API_Exception $e ) {
            My_Plugin::instance()->log( 'Create user error: ' . $e->getMessage() );
            return null;
        }
    }
}
```

## Best Practices

### 1. Wrap API Calls in Try-Catch

```php
try {
    $response = $api->get_users();
} catch ( Woodev_API_Exception $e ) {
    $this->handle_error( $e );
}
```

### 2. Validate Responses in Validation Hooks

```php
protected function do_pre_parse_response_validation() {
    // Check HTTP status codes
}

protected function do_post_parse_response_validation() {
    // Check application-level errors in parsed response
}
```

### 3. Sanitize Sensitive Data in to_string_safe()

When your request or response contains credentials or PII, override `to_string_safe()` to return a redacted version for logging.

### 4. Use Caching for Read-Only Endpoints

Extend `Woodev_Cacheable_API_Base` and use `Woodev_Cacheable_Request_Trait` on request objects. Set appropriate `$cache_lifetime` values per request type.

### 5. Set Appropriate Timeouts

```php
protected function get_request_args(): array {
    return array_merge( parent::get_request_args(), [
        'timeout' => 30,
    ] );
}
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [REST API](rest-api.md) — REST endpoints
- [Utilities](utilities.md) — Background processing

---

*For more information, see [README.md](README.md).*
