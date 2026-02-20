# Handlers Module

The Handlers module contains focused components that connect your plugin to WordPress and WooCommerce events. It includes handlers for WooCommerce Blocks compatibility and script management.

## Overview

The Handlers module handles:

- WooCommerce Cart/Checkout block detection
- Block compatibility management
- Inline script output
- PHP to JavaScript data passing

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Blocks_Handler` | `handlers/blocks-handler.php` | Blocks compatibility detection |
| `Woodev_Script_Handler` | `handlers/class-script-handler.php` | Inline script helper |

## Blocks Handler

### Overview

`Woodev_Blocks_Handler` automatically:

1. Detects WooCommerce Cart block (`woocommerce/cart`) and Checkout block (`woocommerce/checkout`)
2. Verifies plugin has declared support for active blocks
3. Displays admin notices for incompatibilities

### Declaring Block Support

Declare block support in your plugin constructor:

```php
parent::__construct(
    'my-plugin',
    '1.0.0',
    [
        'text_domain'        => 'my-plugin',
        'supported_features' => [
            'blocks' => [
                'cart'     => true,   // Compatible with Cart block
                'checkout' => true,   // Compatible with Checkout block
            ],
        ],
    ]
);
```

**Block support options:**

| Block Key | Description |
| --- | --- |
| `cart` | WooCommerce Cart block (`woocommerce/cart`) |
| `checkout` | WooCommerce Checkout block (`woocommerce/checkout`) |

**Values:**

- `true` — Plugin is compatible
- `false` or omitted — Plugin is NOT compatible (triggers notice)

### Full Plugin Example

```php
class My_Plugin extends Woodev_Plugin {

    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        parent::__construct(
            'my-plugin',
            '1.0.0',
            [
                'text_domain' => 'my-plugin',
                'supported_features' => [
                    'hpos' => true,
                    'blocks' => [
                        'cart'     => true,
                        'checkout' => false,  // Not yet compatible
                    ],
                ],
            ]
        );
    }

    public function get_file(): string {
        return __FILE__;
    }

    public function get_plugin_name(): string {
        return __( 'My Plugin', 'my-plugin' );
    }

    public function get_download_id(): int {
        return 0;
    }
}
```

### Checking Block Presence

```php
// Static methods for quick checks
if ( Woodev_Blocks_Handler::is_cart_block_in_use() ) {
    // Cart block is active
    error_log( 'Cart block detected' );
}

if ( Woodev_Blocks_Handler::is_checkout_block_in_use() ) {
    // Checkout block is active
    error_log( 'Checkout block detected' );
}

// Instance methods for compatibility info
$blocks_handler = $plugin->get_blocks_handler();

// Check if plugin declared compatibility
if ( $blocks_handler->is_cart_block_compatible() ) {
    // Plugin declared cart block support
    $this->load_cart_block_integration();
}

if ( $blocks_handler->is_checkout_block_compatible() ) {
    // Plugin declared checkout block support
    $this->load_checkout_block_integration();
}
```

### Block Detection Logic

The handler scans all posts and pages for block patterns:

```php
// Internally searches for:
// - <!-- wp:woocommerce/cart -->
// - <!-- wp:woocommerce/checkout -->

// In post_content across all post types
```

### Admin Notices

When a block is detected but compatibility is not declared:

```text
⚠️ Warning: My Plugin may not be compatible with the WooCommerce Cart block.

The Cart block is currently used on your site, but My Plugin has not declared
compatibility with it. This may cause issues for your customers.

Please contact the plugin developer or update to a version that supports blocks.
```

**Notice properties:**

- Dismissible by each user
- Shown on all admin pages
- Reappears if block is added to new page

### Creating Block-Compatible Features

```php
class Block_Integration {

    private Woodev_Blocks_Handler $blocks_handler;

    public function __construct( Woodev_Blocks_Handler $blocks_handler ) {
        $this->blocks_handler = $blocks_handler;
        $this->init();
    }

    private function init() {
        // Only load Cart block integration if compatible
        if ( $this->blocks_handler->is_cart_block_compatible() ) {
            add_action( 'init', [ $this, 'register_cart_block' ] );
        }

        // Only load Checkout block integration if compatible
        if ( $this->blocks_handler->is_checkout_block_compatible() ) {
            add_action( 'init', [ $this, 'register_checkout_block' ] );
        }
    }

    public function register_cart_block() {
        register_block_type( 'my-plugin/cart-extra-fields', [
            'render_callback' => [ $this, 'render_cart_extra_fields' ],
        ] );
    }

    public function register_checkout_block() {
        register_block_type( 'my-plugin/checkout-fields', [
            'render_callback' => [ $this, 'render_checkout_fields' ],
        ] );
    }
}

// Initialize
add_action( 'wp_loaded', function() {
    $plugin = My_Plugin::instance();
    new Block_Integration( $plugin->get_blocks_handler() );
} );
```

## Script Handler

### Overview

`Woodev_Script_Handler` simplifies outputting inline JavaScript that depends on PHP values (AJAX URLs, nonces, localized strings).

### Creating a Script Handler

```php
class My_Script_Handler extends Woodev_Script_Handler {

    /**
     * JavaScript class name
     */
    protected $js_handler_base_class_name = 'MyPlugin';

    /**
     * Get unique ID
     */
    public function get_id(): string {
        return 'my-plugin';
    }

    /**
     * Values passed to JS constructor
     */
    protected function get_js_handler_args(): array {
        return [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'my-plugin-nonce' ),
            'rest_url'   => rest_url( 'my-plugin/v1' ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'       => [
                'loading' => __( 'Loading…', 'my-plugin' ),
                'error'   => __( 'Something went wrong.', 'my-plugin' ),
                'success' => __( 'Success!', 'my-plugin' ),
            ],
            'settings'   => [
                'debug_mode'    => $this->is_debug_enabled(),
                'api_key'       => get_option( 'my_plugin_api_key' ),
                'cache_timeout' => 300,
            ],
        ];
    }

    /**
     * Check debug mode
     */
    private function is_debug_enabled(): bool {
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }

    /**
     * Output inline script
     */
    public function output_inline_script(): void {
        echo '<script>' . $this->get_safe_handler_js() . '</script>';
    }
}
```

### How get_safe_handler_js() Works

Generates JavaScript that:

1. Creates a global object with your handler's class name
2. JSON-encodes arguments from `get_js_handler_args()`
3. Escapes output for safe HTML insertion

**Generated output:**

```javascript
window.MyPlugin = new MyPlugin({"ajax_url":"https:\/\/example.com\/wp-admin\/admin-ajax.php","nonce":"abc123","rest_url":"https:\/\/example.com\/wp-json\/my-plugin\/v1","i18n":{"loading":"Loading…","error":"Something went wrong.","success":"Success!"},"settings":{"debug_mode":false,"api_key":"","cache_timeout":300}});
```

### Client-Side JavaScript

```javascript
(function() {
    'use strict';

    window.MyPlugin = window.MyPlugin || class MyPlugin {
        constructor( config ) {
            this.config = config;
            this.init();
        }

        init() {
            console.log( 'MyPlugin initialized', this.config );
            this.bindEvents();
        }

        bindEvents() {
            document.addEventListener( 'click', ( e ) => {
                if ( e.target.matches( '.my-plugin-button' ) ) {
                    this.handleButtonClick( e );
                }
            } );
        }

        async handleButtonClick( event ) {
            event.preventDefault();

            this.log( 'Button clicked' );

            try {
                const response = await fetch( this.config.ajax_url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-WP-Nonce': this.config.nonce,
                    },
                    body: new URLSearchParams( {
                        action: 'my_plugin_action',
                        data: event.target.dataset.id,
                    } ),
                } );

                const result = await response.json();

                if ( result.success ) {
                    this.log( this.config.i18n.success );
                } else {
                    this.log( this.config.i18n.error, 'error' );
                }

            } catch ( error ) {
                this.log( this.config.i18n.error, 'error' );
            }
        }

        log( message, type = 'info' ) {
            if ( this.config.settings.debug_mode ) {
                console[ type ]( '[MyPlugin]', message );
            }
        }
    };
})();
```

### Registering with WordPress

```php
class My_Plugin extends Woodev_Plugin {

    private $script_handler;

    public function init_admin() {
        parent::init_admin();

        // Create script handler
        $this->script_handler = new My_Script_Handler();

        // Enqueue scripts
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        // Output inline script
        add_action( 'admin_footer', [ $this->script_handler, 'output_inline_script' ] );
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_script(
            'my-plugin-admin',
            $this->get_plugin_url() . '/assets/js/my-plugin.js',
            [ 'jquery' ],
            $this->get_version(),
            true
        );

        wp_localize_script( 'my-plugin-admin', 'myPluginBase', [
            'pluginUrl' => $this->get_plugin_url(),
            'version'   => $this->get_version(),
        ] );
    }
}
```

### Frontend Script Handler

```php
class Frontend_Script_Handler extends Woodev_Script_Handler {

    protected $js_handler_base_class_name = 'MyPluginFrontend';

    public function get_id(): string {
        return 'my-plugin-frontend';
    }

    protected function get_js_handler_args(): array {
        return [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'my-plugin-frontend' ),
            'cart_url'     => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'i18n'         => [
                'add_to_cart' => __( 'Added to cart', 'my-plugin' ),
                'remove'      => __( 'Remove', 'my-plugin' ),
            ],
        ];
    }

    public function output_inline_script(): void {
        // Only on specific pages
        if ( is_cart() || is_checkout() ) {
            echo '<script>' . $this->get_safe_handler_js() . '</script>';
        }
    }
}

// Enqueue
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'my-plugin-frontend',
        plugins_url( 'assets/js/frontend.js', __FILE__ ),
        [ 'jquery', 'wc-cart-fragments' ],
        '1.0.0',
        true
    );

    $handler = new Frontend_Script_Handler();
    add_action( 'wp_footer', [ $handler, 'output_inline_script' ] );
} );
```

## Practical Examples

### Example 1: AJAX Form Handler

```php
class Form_Script_Handler extends Woodev_Script_Handler {

    protected $js_handler_base_class_name = 'MyPluginForm';

    public function get_id(): string {
        return 'my-plugin-form';
    }

    protected function get_js_handler_args(): array {
        return [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'my-plugin-form-submit' ),
            'i18n'     => [
                'submitting' => __( 'Submitting...', 'my-plugin' ),
                'success'    => __( 'Form submitted!', 'my-plugin' ),
                'error'      => __( 'Submission failed.', 'my-plugin' ),
            ],
        ];
    }

    public function output_inline_script(): void {
        echo '<script>' . $this->get_safe_handler_js() . '</script>';
    }
}

// Enqueue on form pages
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_page( 'contact' ) ) {
        return;
    }

    wp_enqueue_script(
        'my-plugin-form',
        plugins_url( 'assets/js/form.js', __FILE__ ),
        [],
        '1.0.0',
        true
    );

    $handler = new Form_Script_Handler();
    add_action( 'wp_footer', [ $handler, 'output_inline_script' ] );
} );
```

**Client-side form handler:**

```javascript
window.MyPluginForm = window.MyPluginForm || class MyPluginForm {
    constructor( config ) {
        this.config = config;
        this.form = document.querySelector( '#my-plugin-contact-form' );

        if ( this.form ) {
            this.init();
        }
    }

    init() {
        this.form.addEventListener( 'submit', ( e ) => this.handleSubmit( e ) );
    }

    async handleSubmit( event ) {
        event.preventDefault();

        const submitBtn = this.form.querySelector( '[type="submit"]' );
        const originalText = submitBtn.textContent;
        submitBtn.textContent = this.config.i18n.submitting;
        submitBtn.disabled = true;

        try {
            const formData = new FormData( this.form );
            formData.append( 'action', 'my_plugin_submit_form' );
            formData.append( 'nonce', this.config.nonce );

            const response = await fetch( this.config.ajax_url, {
                method: 'POST',
                body: formData,
            } );

            const result = await response.json();

            if ( result.success ) {
                alert( this.config.i18n.success );
                this.form.reset();
            } else {
                alert( this.config.i18n.error );
            }

        } catch ( error ) {
            alert( this.config.i18n.error );
        } finally {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    }
};
```

### Example 2: Map Integration

```php
class Map_Script_Handler extends Woodev_Script_Handler {

    protected $js_handler_base_class_name = 'MyPluginMap';

    public function get_id(): string {
        return 'my-plugin-map';
    }

    protected function get_js_handler_args(): array {
        return [
            'api_key'      => get_option( 'my_plugin_maps_api_key' ),
            'default_lat'  => get_option( 'my_plugin_default_lat', 55.7558 ),
            'default_lng'  => get_option( 'my_plugin_default_lng', 37.6173 ),
            'default_zoom' => (int) get_option( 'my_plugin_default_zoom', 10 ),
            'i18n'         => [
                'loading' => __( 'Loading map...', 'my-plugin' ),
                'error'   => __( 'Failed to load map', 'my-plugin' ),
            ],
        ];
    }

    public function output_inline_script(): void {
        if ( has_block( 'my-plugin/map' ) || is_page_template( 'template-store-locator.php' ) ) {
            echo '<script>' . $this->get_safe_handler_js() . '</script>';
        }
    }
}

// Enqueue
add_action( 'wp_enqueue_scripts', function() {
    if ( ! has_block( 'my-plugin/map' ) && ! is_page_template( 'template-store-locator.php' ) ) {
        return;
    }

    wp_enqueue_script(
        'my-plugin-map',
        plugins_url( 'assets/js/map.js', __FILE__ ),
        [],
        '1.0.0',
        true
    );

    $api_key = get_option( 'my_plugin_maps_api_key' );
    if ( $api_key ) {
        wp_enqueue_script(
            'google-maps-api',
            'https://maps.googleapis.com/maps/api/js?key=' . $api_key,
            [],
            null,
            true
        );
    }

    $handler = new Map_Script_Handler();
    add_action( 'wp_footer', [ $handler, 'output_inline_script' ] );
} );
```

### Example 3: Admin Settings Page

```php
class Settings_Script_Handler extends Woodev_Script_Handler {

    protected $js_handler_base_class_name = 'MyPluginSettings';

    public function get_id(): string {
        return 'my-plugin-settings';
    }

    protected function get_js_handler_args(): array {
        return [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'my-plugin-settings' ),
            'settings' => [
                'api_key'     => get_option( 'my_plugin_api_key' ),
                'debug_mode'  => get_option( 'my_plugin_debug_mode', 'no' ) === 'yes',
                'webhook_url' => get_option( 'my_plugin_webhook_url' ),
            ],
            'i18n'     => [
                'saving'       => __( 'Saving...', 'my-plugin' ),
                'saved'        => __( 'Settings saved', 'my-plugin' ),
                'error'        => __( 'Error saving', 'my-plugin' ),
                'confirmReset' => __( 'Reset all settings?', 'my-plugin' ),
            ],
            'urls'     => [
                'test_connection' => admin_url( 'admin-ajax.php?action=my_plugin_test_connection' ),
                'reset_settings'  => admin_url( 'admin-ajax.php?action=my_plugin_reset_settings' ),
            ],
        ];
    }

    public function output_inline_script(): void {
        echo '<script>' . $this->get_safe_handler_js() . '</script>';
    }
}

// Load on settings page
add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
    if ( 'woocommerce_page_my-plugin-settings' !== $hook_suffix ) {
        return;
    }

    wp_enqueue_script(
        'my-plugin-settings',
        plugins_url( 'assets/js/settings.js', __FILE__ ),
        [ 'jquery', 'wp-color-picker' ],
        '1.0.0',
        true
    );

    wp_enqueue_style( 'wp-color-picker' );

    $handler = new Settings_Script_Handler();
    add_action( 'admin_footer', [ $handler, 'output_inline_script' ] );
} );
```

### Example 4: Real-time Cart Updates

```php
class Cart_Script_Handler extends Woodev_Script_Handler {

    protected $js_handler_base_class_name = 'MyPluginCart';

    public function get_id(): string {
        return 'my-plugin-cart';
    }

    protected function get_js_handler_args(): array {
        return [
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'my-plugin-cart' ),
            'cart_url'         => wc_get_cart_url(),
            'fragment_refresh' => get_option( 'my_plugin_cart_refresh', 'yes' ) === 'yes',
            'i18n'             => [
                'updating'     => __( 'Updating cart...', 'my-plugin' ),
                'empty'        => __( 'Your cart is empty', 'my-plugin' ),
                'item_added'   => __( 'Item added to cart', 'my-plugin' ),
                'item_removed' => __( 'Item removed from cart', 'my-plugin' ),
            ],
        ];
    }

    public function output_inline_script(): void {
        echo '<script>' . $this->get_safe_handler_js() . '</script>';
    }
}

// Load on cart/checkout
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_cart() && ! is_checkout() ) {
        return;
    }

    wp_enqueue_script(
        'my-plugin-cart',
        plugins_url( 'assets/js/cart.js', __FILE__ ),
        [ 'jquery', 'wc-cart-fragments' ],
        '1.0.0',
        true
    );

    $handler = new Cart_Script_Handler();
    add_action( 'wp_footer', [ $handler, 'output_inline_script' ] );
} );
```

## Best Practices

### 1. Escape Output

```php
// ✅ Framework handles escaping automatically
protected function get_js_handler_args(): array {
    return [
        'message' => __( 'Hello World', 'my-plugin' ),
        'url'     => admin_url( 'admin-ajax.php' ),
    ];
}
```

### 2. Use Unique Handler IDs

```php
public function get_id(): string {
    return 'my-plugin-' . $this->get_handler_type();
}
```

### 3. Minimize Data

```php
// ✅ Only pass necessary data
protected function get_js_handler_args(): array {
    return [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'my-plugin' ),
    ];
}
```

### 4. Conditionally Output

```php
public function output_inline_script(): void {
    if ( is_cart() || is_checkout() ) {
        echo '<script>' . $this->get_safe_handler_js() . '</script>';
    }
}
```

### 5. Handle Missing JavaScript

```javascript
// Check if handler is available
if ( window.MyPlugin && typeof window.MyPlugin.init === 'function' ) {
    window.MyPlugin.init();
} else {
    console.warn( 'MyPlugin not loaded' );
}
```

### 6. Use Namespaced Globals

```javascript
// ✅ Good - namespaced
window.MyPlugin = window.MyPlugin || {};

// ❌ Bad - pollutes global scope
var myPluginData = {};
```

## Troubleshooting

### Script Handler Not Outputting

1. **Check handler registration:**

   ```php
   $handler = new My_Script_Handler();
   add_action( 'wp_footer', [ $handler, 'output_inline_script' ] );
   ```

2. **Verify get_id() returns unique value:**

   ```php
   public function get_id(): string {
       return 'my-plugin-unique';  // Must be unique
   }
   ```

3. **Check if footer action runs:**

   ```php
   add_action( 'wp_footer', function() {
       error_log( 'Footer action fired' );
   }, 5 );
   ```

### JavaScript Not Initializing

1. **Check script load order:**

   ```php
   wp_enqueue_script( 'my-plugin', '...', [], '1.0.0', true );
   add_action( 'wp_footer', [ $handler, 'output_inline_script' ], 20 );
   ```

2. **Verify JavaScript class name matches:**

   ```php
   // PHP
   protected $js_handler_base_class_name = 'MyPlugin';

   // JavaScript
   window.MyPlugin = window.MyPlugin || class MyPlugin { ... };
   ```

### Data Not Available

1. **Check get_js_handler_args() output:**

   ```php
   protected function get_js_handler_args(): array {
       error_log( print_r( $this->get_js_handler_args(), true ) );
       return [ 'key' => 'value' ];
   }
   ```

2. **Inspect generated JavaScript:**

   ```php
   // View page source and check:
   // window.MyPlugin = new MyPlugin({...});
   ```

### Block Detection Not Working

1. **Clear block cache:**

   ```php
   delete_transient( 'woodev_blocks_detection' );
   ```

2. **Verify block syntax:**

   ```html
   <!-- Correct block comment format -->
   <!-- wp:woocommerce/cart /-->
   <!-- wp:woocommerce/checkout /-->
   ```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [Admin Module](admin-module.md) — Admin pages
- [Settings API](settings-api.md) — Settings handling

---

*For more information, see [README.md](README.md).*
