# Core Framework

The Core module provides the foundational infrastructure for all Woodev-based plugins. This document covers the bootstrap process, plugin base class, and lifecycle management.

## Overview

The Core module handles:

- Plugin bootstrap and version management
- Plugin lifecycle (installation, upgrades, activation, deactivation)
- Dependency checking (PHP extensions, functions, settings)
- Text domain loading and internationalization
- Logging and debugging utilities
- Hook deprecation management

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Plugin_Bootstrap` | `bootstrap.php` | Singleton that loads the highest framework version |
| `Woodev_Plugin` | `class-plugin.php` | Abstract base class for all plugins |
| `Woodev_Lifecycle` | `class-lifecycle.php` | Handles install/upgrade/activation routines |
| `Woodev_Plugin_Dependencies` | `class-woodev-plugin-dependencies.php` | PHP dependency checker |
| `Woodev_Hook_Deprecator` | `class-woodev-hook-deprecator.php` | Manages deprecated hooks |

## Bootstrap Process

### How Bootstrap Works

The `Woodev_Plugin_Bootstrap` class ensures only one version of the framework is loaded:

```php
class Woodev_Plugin_Bootstrap {

    protected static $instance = null;
    protected array $registered_plugins = [];
    protected array $active_plugins = [];

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load_plugins' ] );
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_plugin(
        string $framework_version,
        string $plugin_name,
        string $path,
        callable $callback,
        array $args = []
    ) {
        $this->registered_plugins[] = [
            'version'     => $framework_version,
            'plugin_name' => $plugin_name,
            'path'        => $path,
            'callback'    => $callback,
            'args'        => $args,
        ];
    }
}
```

### Bootstrap Flow

```text
1. Plugin A calls register_plugin('1.4.0', ...)
2. Plugin B calls register_plugin('1.3.0', ...)
3. plugins_loaded (priority 0)
4. Sort plugins by version (highest first)
5. Load class-plugin.php from Plugin A (1.4.0)
6. Call each plugin's callback
7. All plugins share framework 1.4.0
```

### Registration Example

```php
add_action( 'plugins_loaded', 'init_my_plugin', 0 );

function init_my_plugin() {
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.0',  // Framework version
        'My Plugin',
        __FILE__,
        'my_plugin_init',
        [
            'minimum_wc_version'   => '8.0',
            'minimum_wp_version'   => '5.9',
            'backwards_compatible' => '1.4.0',
        ]
    );
}

function my_plugin_init() {
    final class My_Plugin extends Woodev_Plugin {}
    return My_Plugin::instance();
}
```

## Woodev_Plugin Base Class

### Class Structure

```php
abstract class Woodev_Plugin {

    const VERSION = '1.4.0';

    protected static $instance;
    protected $id;
    protected $version;
    protected $plugin_path;
    protected $plugin_url;
    protected $text_domain;
    protected $supported_features;
    protected $logger;
    protected $license;
    protected $message_handler;
    protected $admin_notice_handler;
    protected $dependency_handler;
    protected $lifecycle_handler;
    protected $rest_api_handler;
    protected $blocks_handler;
    protected $setup_wizard_handler;

    public function __construct( string $id, string $version, array $args = [] ) {
        // Initialization
    }

    // Abstract methods (must be implemented)
    abstract public function get_file(): string;
    abstract public function get_plugin_name(): string;
    abstract public function get_download_id(): int;
}
```

### Constructor

```php
public function __construct( string $id, string $version, array $args = [] ) {
    $this->id      = $id;
    $this->version = $version;

    $args = wp_parse_args( $args, [
        'text_domain'        => '',
        'dependencies'       => [],
        'supported_features' => [
            'hpos'   => false,
            'blocks' => [
                'cart'     => false,
                'checkout' => false,
            ],
        ],
    ] );

    $this->text_domain        = $args['text_domain'];
    $this->supported_features = $args['supported_features'];

    // Load required files
    $this->includes();

    // Initialize handlers
    $this->init_dependencies( $args['dependencies'] );
    $this->init_admin_message_handler();
    $this->init_admin_notice_handler();
    $this->init_license_handler();
    $this->init_hook_deprecator();
    $this->init_lifecycle_handler();
    $this->init_rest_api_handler();
    $this->init_blocks_handler();

    // Add hooks
    $this->add_hooks();
}
```

### Required Methods

Every plugin **must** implement these three methods:

```php
class My_Plugin extends Woodev_Plugin {

    /**
     * Get the main plugin file path
     */
    public function get_file(): string {
        return __FILE__;
    }

    /**
     * Get the human-readable plugin name
     */
    public function get_plugin_name(): string {
        return __( 'My Plugin', 'my-plugin' );
    }

    /**
     * Get the EDD download ID for licensing
     * Return 0 if plugin doesn't use licensing
     */
    public function get_download_id(): int {
        return 0;
    }
}
```

### Plugin Properties

Access plugin information:

```php
$plugin = My_Plugin::instance();

// Get plugin ID
echo $plugin->get_id();           // 'my-plugin'
echo $plugin->get_id_dasherized(); // 'my-plugin'
echo $plugin->get_id_underscored(); // 'my_plugin'

// Get version
echo $plugin->get_version();      // '1.0.0'

// Get paths and URLs
echo $plugin->get_plugin_path();  // /path/to/plugin
echo $plugin->get_plugin_url();   // https://example.com/wp-content/plugins/my-plugin

// Get framework paths
echo $plugin->get_framework_path();    // /path/to/plugin/woodev
echo $plugin->get_framework_assets_path(); // /path/to/plugin/woodev/assets
```

## Lifecycle Management

### Woodev_Lifecycle Class

Handles plugin installation, upgrades, and deactivation:

```php
class Woodev_Lifecycle {

    protected $upgrade_versions = [];
    protected $milestone_version;
    private $plugin;

    public function __construct( Woodev_Plugin $plugin ) {
        $this->plugin = $plugin;
        $this->add_hooks();
    }

    protected function add_hooks() {
        add_action( 'admin_init', [ $this, 'handle_activation' ] );
        add_action( 'deactivate_' . $this->get_plugin()->get_plugin_file(), [ $this, 'handle_deactivation' ] );
        add_action( 'wp_loaded', [ $this, 'init' ] );
        add_action( 'init', [ $this, 'add_admin_notices' ] );
    }
}
```

### Lifecycle Hooks

```php
// After installation
add_action( 'woodev_my-plugin_installed', function() {
    // First install logic
} );

// After upgrade
add_action( 'woodev_my-plugin_updated', function( string $old_version ) {
    // Upgrade logic
}, 10, 1 );

// After activation
add_action( 'woodev_my-plugin_activated', function() {
    // Activation logic
} );

// After deactivation
add_action( 'woodev_my-plugin_deactivated', function() {
    // Deactivation logic
} );
```

### Custom Lifecycle Handler

```php
class My_Plugin extends Woodev_Plugin {

    public function get_lifecycle_handler(): Woodev_Lifecycle {
        return new class( $this ) extends Woodev_Lifecycle {

            protected function upgrade( string $installed_version ) {
                parent::upgrade( $installed_version );

                // Run upgrade routines
                if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
                    $this->upgrade_to_1_1_0();
                }

                if ( version_compare( $installed_version, '1.2.0', '<' ) ) {
                    $this->upgrade_to_1_2_0();
                }
            }

            private function upgrade_to_1_1_0() {
                // Migrate old options
                $old_value = get_option( 'my_plugin_old_setting' );
                if ( $old_value ) {
                    update_option( 'my_plugin_new_setting', $old_value );
                    delete_option( 'my_plugin_old_setting' );
                }
            }

            private function upgrade_to_1_2_0() {
                // Database changes
                global $wpdb;
                $wpdb->query(
                    "ALTER TABLE {$wpdb->prefix}my_table ADD COLUMN new_column VARCHAR(255)"
                );
            }
        };
    }
}
```

### Install Routine

```php
class My_Lifecycle extends Woodev_Lifecycle {

    protected function install() {
        // Set default options
        update_option( 'my_plugin_version', $this->get_plugin()->get_version() );
        update_option( 'my_plugin_installed', time() );

        // Create database tables
        $this->create_tables();

        // Schedule events
        wp_schedule_event( time(), 'daily', 'my_plugin_daily_event' );
    }

    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'my_plugin_table';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
```

## Dependency Management

### Woodev_Plugin_Dependencies

Handles PHP extension, function, and settings checks:

```php
class Woodev_Plugin_Dependencies {

    protected $php_extensions = [];
    protected $php_functions = [];
    protected $php_settings = [];
    protected $plugin;

    public function __construct( Woodev_Plugin $plugin, array $args = [] ) {
        $this->plugin = $plugin;

        $dependencies = wp_parse_args( $args, [
            'php_extensions' => [],
            'php_functions'  => [],
            'php_settings'   => [],
        ] );

        $this->php_extensions = (array) $dependencies['php_extensions'];
        $this->php_functions  = (array) $dependencies['php_functions'];
        $this->php_settings   = (array) $dependencies['php_settings'];

        $this->add_hooks();
    }

    protected function add_hooks() {
        add_action( 'admin_init', [ $this, 'add_admin_notices' ] );
    }
}
```

### Declaring Dependencies

```php
parent::__construct(
    'my-plugin',
    '1.0.0',
    [
        'dependencies' => [
            'php_extensions' => [ 'curl', 'json', 'mbstring' ],
            'php_functions'  => [ 'gzinflate', 'base64_decode' ],
            'php_settings'   => [
                'allow_url_fopen' => '1',
                'max_execution_time' => '30',
            ],
        ],
    ]
);
```

### Checking Dependencies Manually

```php
$plugin = My_Plugin::instance();

// Get missing extensions
$missing_extensions = $plugin->get_missing_extension_dependencies();
if ( ! empty( $missing_extensions ) ) {
    echo 'Missing extensions: ' . implode( ', ', $missing_extensions );
}

// Get missing functions
$missing_functions = $plugin->get_missing_function_dependencies();
if ( ! empty( $missing_functions ) ) {
    echo 'Missing functions: ' . implode( ', ', $missing_functions );
}

// Get incompatible settings
$incompatible_settings = $plugin->get_incompatible_php_settings();
if ( ! empty( $incompatible_settings ) ) {
    echo 'Incompatible settings detected';
}
```

## Logging

### Using the Logger

```php
$plugin = My_Plugin::instance();

// Log info message
$plugin->log( 'Processing order #' . $order_id );

// Log error
$plugin->log( 'API request failed: ' . $error_message );

// Log with context
$plugin->log( sprintf(
    'Shipping rate calculated: %s kg = %s RUB',
    $weight,
    $rate
) );
```

### Accessing WC_Logger

```php
$logger = $plugin->logger();

// Log with level
$logger->info( 'Information message' );
$logger->warning( 'Warning message' );
$logger->error( 'Error message' );
$logger->debug( 'Debug message' );

// View logs: WooCommerce > Status > Logs
```

### Custom Log File

```php
class My_Plugin extends Woodev_Plugin {

    public function log( string $message ) {
        $logger = wc_get_logger();
        $context = [ 'source' => 'my-plugin' ];
        $logger->info( $message, $context );
    }
}
```

## Hook Deprecator

### Managing Deprecated Hooks

```php
class My_Plugin extends Woodev_Plugin {

    public function init_hook_deprecator() {
        $deprecated_hooks = [
            'my_plugin_old_action' => [
                'removed'     => false,
                'replacement' => 'my_plugin_new_action',
                'map'         => true,
            ],
            'my_plugin_removed_filter' => [
                'removed'     => true,
                'replacement' => 'my_plugin_replacement_filter',
                'map'         => true,
            ],
        ];

        $this->hook_deprecator = new Woodev_Hook_Deprecator(
            $this->get_plugin_name(),
            $deprecated_hooks
        );
    }
}
```

### Hook Deprecation Format

```php
$deprecated_hooks = [
    'old_hook_name' => [
        'removed'     => false,  // true if hook is removed
        'replacement' => 'new_hook_name',
        'map'         => true,   // true to map old to new
    ],
];
```

## Template Loading

### Loading Templates

```php
$plugin = My_Plugin::instance();

// Load template with variables
$plugin->load_template( 'emails/shipping-notice.php', [
    'order'           => $order,
    'tracking_number' => $tracking,
] );
```

### Template Hierarchy

Templates are loaded from:

1. `wp-content/themes/your-theme/woocommerce/my-plugin/`
2. `wp-content/uploads/woocommerce/my-plugin/`
3. `your-plugin/templates/`

### Creating Templates

```php
// templates/emails/shipping-notice.php
defined( 'ABSPATH' ) || exit;

/**
 * @var WC_Order $order
 * @var string $tracking_number
 */
?>
<h2><?php esc_html_e( 'Your order has shipped!', 'my-plugin' ); ?></h2>
<p>
    <?php printf(
        esc_html__( 'Order #%d has been shipped.', 'my-plugin' ),
        $order->get_id()
    ); ?>
</p>
<p>
    <?php printf(
        esc_html__( 'Tracking number: %s', 'my-plugin' ),
        $tracking_number
    ); ?>
</p>
```

## Helper Methods

### Plugin Information

```php
$plugin = My_Plugin::instance();

// Get plugin URLs
echo $plugin->get_settings_url();      // Settings page URL
echo $plugin->get_documentation_url(); // Documentation URL
echo $plugin->get_support_url();       // Support URL

// Check if on settings page
if ( $plugin->is_general_configuration_page() ) {
    // On settings page
}
```

### File Operations

```php
// Get uploads path
$upload_path = $plugin->get_woocommerce_uploads_path();

// Load class from file
$plugin->load_class( 'class-my-class.php', 'My_Class' );
```

### Compatibility Checks

```php
// Check if TLS 1.2 is available
if ( $plugin->is_tls_1_2_available() ) {
    // Use TLS 1.2
}

// Require TLS 1.2 for requests
if ( $plugin->require_tls_1_2() ) {
    add_action( 'http_api_curl', [ $api, 'set_tls_1_2_request' ], 10, 3 );
}
```

## Complete Example

```php
<?php
/**
 * Plugin Name: My Plugin
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Include bootstrap
if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'woodev/bootstrap.php';
}

// Register plugin
add_action( 'plugins_loaded', 'init_my_plugin', 0 );

function init_my_plugin() {
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.0',
        'My Plugin',
        __FILE__,
        'my_plugin_init',
        [
            'minimum_wc_version' => '8.0',
            'minimum_wp_version' => '5.9',
        ]
    );
}

// Initialize plugin
function my_plugin_init() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-my-plugin.php';
    return My_Plugin::instance();
}
```

```php
<?php
// includes/class-my-plugin.php

defined( 'ABSPATH' ) || exit;

final class My_Plugin extends Woodev_Plugin {

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
                'dependencies' => [
                    'php_extensions' => [ 'curl', 'json' ],
                ],
                'supported_features' => [
                    'hpos'   => true,
                    'blocks' => [
                        'cart'     => true,
                        'checkout' => true,
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

    public function get_lifecycle_handler(): Woodev_Lifecycle {
        return new class( $this ) extends Woodev_Lifecycle {
            protected function install() {
                parent::install();
                update_option( 'my_plugin_installed', time() );
            }
        };
    }
}
```

## Best Practices

### 1. Use Singleton Pattern

```php
final class My_Plugin extends Woodev_Plugin {

    private static $instance;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

### 2. Mark Class as Final

```php
// Prevent inheritance that could break the framework
final class My_Plugin extends Woodev_Plugin {}
```

### 3. Declare All Dependencies

```php
parent::__construct(
    'my-plugin',
    '1.0.0',
    [
        'dependencies' => [
            'php_extensions' => [ 'curl', 'json' ],
            'php_functions'  => [ 'gzinflate' ],
        ],
    ]
);
```

### 4. Implement Lifecycle Handlers

```php
public function get_lifecycle_handler(): Woodev_Lifecycle {
    return new class( $this ) extends Woodev_Lifecycle {
        protected function upgrade( string $installed_version ) {
            parent::upgrade( $installed_version );
            // Your upgrade logic
        }
    };
}
```

### 5. Use Logging

```php
public function process_order( int $order_id ) {
    $this->log( "Processing order #{$order_id}" );
    
    try {
        // Process order
    } catch ( Exception $e ) {
        $this->log( "Error: {$e->getMessage()}" );
        throw $e;
    }
}
```

## Related Documentation

- [Getting Started](getting-started.md) — Installation and setup
- [Helpers](helpers.md) — Utility functions
- [Admin Module](admin-module.md) — Admin pages and notices
- [Utilities](utilities.md) — Background processing

---

*For more information, see [README.md](README.md).*
