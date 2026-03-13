# REST API

The REST API module provides integration with the WooCommerce REST API, allowing your plugin to expose settings and data through REST endpoints.

## Overview

The REST API module handles:

- Registering custom REST routes
- Adding data to system status endpoints
- Settings API integration
- REST authentication

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_REST_API` | `rest-api/class-plugin-rest-api.php` | Base REST API handler |
| `Woodev_REST_API_Settings` | `rest-api/controllers/class-plugin-rest-api-settings.php` | Settings REST controller |

## Basic Usage

### Extending REST API Handler

```php
<?php
class My_REST_API extends Woodev_REST_API {

    /**
     * Register REST routes
     */
    public function register_routes() {
        // Register settings routes (automatic if settings handler exists)
        parent::register_routes();

        // Register custom routes
        register_rest_route( 'my-plugin/v1', '/data', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_data' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );
    }

    /**
     * Get data callback
     */
    public function get_data( WP_REST_Request $request ): WP_REST_Response {
        $data = $this->get_plugin_data();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $data,
        ] );
    }

    /**
     * Check permissions
     */
    public function check_permissions( WP_REST_Request $request ): bool {
        return current_user_can( 'manage_woocommerce' );
    }
}
```

### Connecting to Plugin

```php
<?php
class My_Plugin extends Woodev_Plugin {

    protected function init_rest_api_handler() {
        $this->rest_api_handler = new My_REST_API( $this );
    }
}
```

## System Status Integration

### Adding System Status Data

```php
<?php
class My_REST_API extends Woodev_REST_API {

    /**
     * Get system status data
     */
    protected function get_system_status_data(): array {
        return [
            'api_key_configured' => ! empty( get_option( 'my_plugin_api_key' ) ),
            'license_active'     => $this->get_plugin()->get_license_instance()->is_active(),
            'version'            => $this->get_plugin()->get_version(),
            'database_version'   => get_option( 'my_plugin_db_version' ),
        ];
    }
}
```

### System Status Response

The data appears in:
`WooCommerce > Status > [Your Plugin]`

```json
{
  "wc_{plugin_id}": {
    "is_payment_gateway": false,
    "api_key_configured": true,
    "license_active": true,
    "version": "1.0.0",
    "database_version": "1.0.0"
  }
}
```

## Settings REST API

### Automatic Settings Routes

When you connect a settings handler to your plugin, the following routes are automatically available:

```text
GET    /wp-json/wc/v3/{plugin_id}/settings
GET    /wp-json/wc/v3/{plugin_id}/settings/{setting_id}
PUT    /wp-json/wc/v3/{plugin_id}/settings/{setting_id}
```

### Get All Settings

```bash
curl -X GET \
  https://example.com/wp-json/wc/v3/{plugin_id}/settings \
  -H 'Authorization: Bearer <token>'
```

**Response:**

```json
[
  {
    "id": "api_key",
    "type": "string",
    "name": "API Key",
    "description": "Your API key.",
    "is_multi": false,
    "options": [],
    "default": "",
    "value": "sk_live_...",
    "control": {
      "type": "password",
      "name": "API Key",
      "description": "Your API key.",
      "options": []
    }
  },
  {
    "id": "debug_mode",
    "type": "boolean",
    "name": "Debug Mode",
    "description": "Enable debug logging.",
    "is_multi": false,
    "options": [],
    "default": false,
    "value": false,
    "control": {
      "type": "checkbox",
      "name": "Debug Mode",
      "description": "Enable debug logging.",
      "options": []
    }
  }
]
```

### Update a Setting

```bash
curl -X PUT \
  https://example.com/wp-json/wc/v3/{plugin_id}/settings/api_key \
  -H 'Authorization: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "value": "new_key"
  }'
```

## Custom Routes

### Registering Routes

```php
<?php
class My_REST_API extends Woodev_REST_API {

    public function register_routes() {
        parent::register_routes();

        // List items
        register_rest_route( 'my-plugin/v1', '/items', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_items' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => $this->get_items_args(),
        ] );

        // Get single item
        register_rest_route( 'my-plugin/v1', '/items/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_item' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // Create item
        register_rest_route( 'my-plugin/v1', '/items', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'create_item' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => $this->get_create_args(),
        ] );

        // Update item
        register_rest_route( 'my-plugin/v1', '/items/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'update_item' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => $this->get_update_args(),
        ] );

        // Delete item
        register_rest_route( 'my-plugin/v1', '/items/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_item' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args'                => [
                'id'    => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'force' => [
                    'default'           => false,
                    'type'              => 'boolean',
                    'sanitize_callback' => 'wc_string_to_bool',
                ],
            ],
        ] );
    }
}
```

### Route Callbacks

```php
<?php
class My_REST_API extends Woodev_REST_API {

    /**
     * Get items list
     */
    public function get_items( WP_REST_Request $request ): WP_REST_Response {
        $page     = $request->get_param( 'page' ) ?? 1;
        $per_page = $request->get_param( 'per_page' ) ?? 10;

        $items = $this->get_items_from_db( [
            'page'     => $page,
            'per_page' => $per_page,
        ] );

        return rest_ensure_response( [
            'success' => true,
            'data'    => $items,
        ] );
    }

    /**
     * Get single item
     */
    public function get_item( WP_REST_Request $request ): WP_REST_Response {
        $id = $request->get_param( 'id' );

        $item = $this->get_item_by_id( $id );

        if ( ! $item ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Item not found', 'my-plugin' ),
            ], 404 );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $item,
        ] );
    }

    /**
     * Create item
     */
    public function create_item( WP_REST_Request $request ): WP_REST_Response {
        $data = $request->get_json_params();

        // Validate
        $errors = $this->validate_item_data( $data );
        if ( ! empty( $errors ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'errors'  => $errors,
            ], 400 );
        }

        // Create
        $item_id = $this->create_item_in_db( $data );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'id' => $item_id ],
        ] );
    }

    /**
     * Update item
     */
    public function update_item( WP_REST_Request $request ): WP_REST_Response {
        $id   = $request->get_param( 'id' );
        $data = $request->get_json_params();

        $item = $this->get_item_by_id( $id );
        if ( ! $item ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Item not found', 'my-plugin' ),
            ], 404 );
        }

        // Update
        $this->update_item_in_db( $id, $data );

        return rest_ensure_response( [
            'success' => true,
            'data'    => $this->get_item_by_id( $id ),
        ] );
    }

    /**
     * Delete item
     */
    public function delete_item( WP_REST_Request $request ): WP_REST_Response {
        $id    = $request->get_param( 'id' );
        $force = $request->get_param( 'force' );

        $item = $this->get_item_by_id( $id );
        if ( ! $item ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Item not found', 'my-plugin' ),
            ], 404 );
        }

        // Delete
        $this->delete_item_from_db( $id, $force );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Item deleted', 'my-plugin' ),
        ] );
    }
}
```

### Route Arguments

```php
<?php
class My_REST_API extends Woodev_REST_API {

    /**
     * Get items arguments
     */
    protected function get_items_args(): array {
        return [
            'page' => [
                'description'       => __( 'Current page', 'my-plugin' ),
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'per_page' => [
                'description'       => __( 'Items per page', 'my-plugin' ),
                'type'              => 'integer',
                'default'           => 10,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'search' => [
                'description'       => __( 'Search query', 'my-plugin' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'orderby' => [
                'description'       => __( 'Order by field', 'my-plugin' ),
                'type'              => 'string',
                'default'           => 'date',
                'enum'              => [ 'date', 'name', 'modified' ],
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'order' => [
                'description'       => __( 'Order direction', 'my-plugin' ),
                'type'              => 'string',
                'default'           => 'DESC',
                'enum'              => [ 'ASC', 'DESC' ],
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    /**
     * Get create arguments
     */
    protected function get_create_args(): array {
        return [
            'name' => [
                'description'       => __( 'Item name', 'my-plugin' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'value' => [
                'description'       => __( 'Item value', 'my-plugin' ),
                'type'              => 'number',
                'required'          => true,
                'sanitize_callback' => 'floatval',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }
}
```

## Permission Callbacks

### Basic Permissions

```php
<?php
public function check_permissions( WP_REST_Request $request ): bool {
    return current_user_can( 'manage_woocommerce' );
}
```

### Custom Permissions

```php
<?php
public function check_item_permissions( WP_REST_Request $request ): bool {
    $id = $request->get_param( 'id' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return false;
    }

    // Check if user can access this specific item
    return $this->user_can_access_item( get_current_user_id(), $id );
}
```

### OAuth Permissions

```php
<?php
public function check_oauth_permissions( WP_REST_Request $request ): bool {
    // Check if request is from OAuth app
    if ( ! is_user_logged_in() ) {
        return false;
    }

    // Check specific capability
    return current_user_can( 'edit_posts' );
}
```

## Response Formatting

### Schema Definition

```php
<?php
class My_REST_API extends Woodev_REST_API {

    /**
     * Get item schema
     */
    public function get_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'item',
            'type'       => 'object',
            'properties' => [
                'id' => [
                    'description' => __( 'Unique identifier', 'my-plugin' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'name' => [
                    'description' => __( 'Item name', 'my-plugin' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'required'    => true,
                ],
                'value' => [
                    'description' => __( 'Item value', 'my-plugin' ),
                    'type'        => 'number',
                    'context'     => [ 'view', 'edit' ],
                ],
                'date_created' => [
                    'description' => __( 'Creation date', 'my-plugin' ),
                    'type'        => 'date-time',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
            ],
        ];
    }
}
```

### Preparing Response

```php
<?php
public function prepare_item_for_response( $item, WP_REST_Request $request ): WP_REST_Response {
    $data = [
        'id'           => $item->get_id(),
        'name'         => $item->get_name(),
        'value'        => $item->get_value(),
        'date_created' => $item->get_date_created()->date( 'c' ),
    ];

    $context = $request->get_param( 'context' ) ?? 'view';

    // Remove fields based on context
    if ( 'view' === $context ) {
        unset( $data['internal_data'] );
    }

    $response = rest_ensure_response( $data );

    // Add links
    $response->add_link( 'self', rest_url( 'my-plugin/v1/items/' . $item->get_id() ) );
    $response->add_link( 'collection', rest_url( 'my-plugin/v1/items' ) );

    return $response;
}
```

## Complete Example

```php
<?php

class My_REST_API extends Woodev_REST_API {

    public function register_routes() {
        parent::register_routes();

        register_rest_route( 'my-plugin/v1', '/orders', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_orders' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => $this->get_orders_args(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_order' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => $this->get_create_order_args(),
            ],
        ] );

        register_rest_route( 'my-plugin/v1', '/orders/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_order' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_order' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => $this->get_update_order_args(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_order' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );
    }

    public function get_orders( WP_REST_Request $request ): WP_REST_Response {
        $args = [
            'limit' => $request->get_param( 'per_page' ),
            'page'  => $request->get_param( 'page' ),
        ];

        $orders = wc_get_orders( $args );

        $data = array_map( [ $this, 'prepare_order_for_response' ], $orders );

        return rest_ensure_response( [
            'success' => true,
            'data'    => $data,
        ] );
    }

    public function get_order( WP_REST_Request $request ): WP_REST_Response {
        $order = wc_get_order( $request->get_param( 'id' ) );

        if ( ! $order ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Order not found', 'my-plugin' ),
            ], 404 );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $this->prepare_order_for_response( $order ),
        ] );
    }

    public function create_order( WP_REST_Request $request ): WP_REST_Response {
        $data = $request->get_json_params();

        $order = wc_create_order();
        $order->set_status( 'pending' );

        foreach ( $data['line_items'] ?? [] as $item ) {
            $order->add_product( wc_get_product( $item['product_id'] ), $item['quantity'] );
        }

        $order->save();

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'id' => $order->get_id() ],
        ] );
    }

    public function update_order( WP_REST_Request $request ): WP_REST_Response {
        $order = wc_get_order( $request->get_param( 'id' ) );

        if ( ! $order ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Order not found', 'my-plugin' ),
            ], 404 );
        }

        $data = $request->get_json_params();

        if ( isset( $data['status'] ) ) {
            $order->set_status( $data['status'] );
        }

        $order->save();

        return rest_ensure_response( [
            'success' => true,
            'data'    => $this->prepare_order_for_response( $order ),
        ] );
    }

    public function delete_order( WP_REST_Request $request ): WP_REST_Response {
        $order = wc_get_order( $request->get_param( 'id' ) );

        if ( ! $order ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => __( 'Order not found', 'my-plugin' ),
            ], 404 );
        }

        $order->delete( $request->get_param( 'force' ) );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Order deleted', 'my-plugin' ),
        ] );
    }

    private function prepare_order_for_response( WC_Order $order ): array {
        return [
            'id'           => $order->get_id(),
            'status'       => $order->get_status(),
            'total'        => $order->get_total(),
            'date_created' => $order->get_date_created()->date( 'c' ),
        ];
    }

    private function get_orders_args(): array {
        return [
            'page' => [
                'type'    => 'integer',
                'default' => 1,
            ],
            'per_page' => [
                'type'    => 'integer',
                'default' => 10,
                'maximum' => 100,
            ],
        ];
    }

    public function check_permissions(): bool {
        return current_user_can( 'edit_shop_orders' );
    }
}
```

## Best Practices

### 1. Use Proper HTTP Methods

```php
<?php
// GET - Retrieve data
'methods' => 'GET'

// POST - Create data
'methods' => 'POST'

// PUT - Update data
'methods' => 'PUT'

// DELETE - Delete data
'methods' => 'DELETE'
```

### 2. Validate All Input

```php
<?php
'args' => [
    'id' => [
        'required'          => true,
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'validate_callback' => 'rest_validate_request_arg',
    ],
]
```

### 3. Use Proper Status Codes

```php
<?php
// Success
return rest_ensure_response( $data ); // 200

// Created
return new WP_REST_Response( $data, 201 );

// Not Found
return new WP_REST_Response( [ 'message' => 'Not found' ], 404 );

// Bad Request
return new WP_REST_Response( [ 'message' => 'Invalid data' ], 400 );
```

### 4. Add Permission Checks

```php
<?php
'permission_callback' => function() {
    return current_user_can( 'manage_woocommerce' );
}
```

### 5. Document Your API

```php
<?php
/**
 * @WP_REST_Route /my-plugin/v1/items
 * @method GET
 * @description Get list of items
 */
```

## Related Documentation

- [Settings API](settings-api.md) — Settings handling
- [Core Framework](core-framework.md) — Plugin base class
- [API Module](api-module.md) — HTTP client

---

*For more information, see [README.md](README.md).*
