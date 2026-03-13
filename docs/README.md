---
template: home.html
title: Woodev Plugin Framework
hide:
  - navigation
  - toc
---

# Woodev Plugin Framework — Developer Documentation

Welcome to the complete developer documentation for the Woodev Plugin Framework. This manual provides comprehensive guidance for building WooCommerce plugins using the Woodev framework.

## What is Woodev Framework?

Woodev Framework is a scaffold for developing WooCommerce plugins. It handles infrastructure concerns so that each plugin can focus on business logic. The framework provides:

- Unified plugin bootstrap and lifecycle management
- Dependency checking (PHP, WordPress, WooCommerce)
- Admin pages and settings infrastructure
- REST API integration
- Shipping method and payment gateway base classes
- Background job processing
- HPOS (High-Performance Order Storage) compatibility
- WooCommerce Blocks compatibility

## Quick Start

### 1. Register Your Plugin

In your main plugin file:

```php
<?php
/**
 * Plugin Name: My Plugin
 * Plugin URI: https://example.com/my-plugin
 * Description: My WooCommerce plugin
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: my-plugin
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * WC requires at least: 5.6
 */

defined( 'ABSPATH' ) || exit;

// Include the bootstrap file
if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'woodev/bootstrap.php';
}

// Initialize the plugin
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

### 2. Create Your Plugin Class

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
            [ 'text_domain' => 'my-plugin' ]
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

## Documentation Structure

### Getting Started

| Document | Description |
| --- | --- |
| [Getting Started](getting-started.md) | Installation, requirements, and architecture overview |
| [Core Framework](core-framework.md) | Bootstrap, plugin base class, lifecycle management |

### Core Modules

| Document | Description |
| --- | --- |
| [Admin Module](admin-module.md) | Admin pages, notices, and message handlers |
| [Settings API](settings-api.md) | Typed settings framework |
| [Helpers](helpers.md) | Utility functions and helpers |

### API & Integration

| Document | Description |
| --- | --- |
| [API Module](api-module.md) | HTTP client base classes |
| [REST API](rest-api.md) | REST endpoints and system status integration |

### Specialized Plugins

| Document | Description |
| --- | --- |
| [Shipping Method](shipping-method.md) | Building shipping plugins |
| [Payment Gateway](payment-gateway.md) | Building payment gateway plugins |
| [Box Packer](box-packer.md) | Product packing algorithms for shipping |

### Utilities & Compatibility

| Document | Description |
| --- | --- |
| [Utilities](utilities.md) | Background jobs and async requests |
| [Compatibility](compatibility.md) | HPOS and WooCommerce version compatibility |
| [Handlers](handlers.md) | Blocks and script handlers |

## Framework Architecture

```text
woodev/
├── bootstrap.php                # Singleton bootstrap loader
├── class-plugin.php             # Woodev_Plugin abstract base class
├── class-lifecycle.php          # Install / upgrade lifecycle handler
├── class-helper.php             # Static utility helpers
├── class-admin-notice-handler.php  # Dismissible admin notices
├── class-admin-message-handler.php # Flash message handler
├── admin/                       # Admin pages (Licenses, Plugins)
├── api/                         # HTTP API base classes
├── box-packer/                  # Box packing algorithm abstractions
├── compatibility/               # HPOS and WooCommerce compatibility
├── handlers/                    # Gutenberg blocks handler
├── rest-api/                    # Settings REST API integration
├── settings-api/                # Typed settings framework
├── shipping-method/             # Shipping plugin and method base classes
├── payment-gateway/             # Payment gateway base classes
└── utilities/                   # Background job queue
```

## Key Concepts

### Plugin Bootstrap

The `Woodev_Plugin_Bootstrap` singleton ensures only one version of the framework is loaded, even when multiple plugins bundle different versions. It selects the highest registered framework version and initializes all compatible plugins.

### Plugin Lifecycle

1. **Registration** — Plugin calls `register_plugin()` with framework version
2. **Bootstrap** — Highest framework version is loaded at `plugins_loaded` priority 0
3. **Initialization** — Plugin factory callback creates plugin instance
4. **Setup** — Plugin hooks into WordPress and WooCommerce actions
5. **Lifecycle** — Install, upgrade, activation, deactivation routines

### Dependency Management

The framework automatically checks:

- PHP extensions (curl, json, mbstring, etc.)
- PHP functions (gzinflate, base64_decode, etc.)
- PHP settings (allow_url_fopen, max_execution_time, etc.)
- WordPress version
- WooCommerce version

### HPOS Compatibility

All plugins should declare HPOS support:

```php
parent::__construct(
    'my-plugin',
    '1.0.0',
    [
        'supported_features' => [
            'hpos' => true,
        ],
    ]
);
```

### Blocks Compatibility

Declare compatibility with WooCommerce Cart and Checkout blocks:

```php
parent::__construct(
    'my-plugin',
    '1.0.0',
    [
        'supported_features' => [
            'blocks' => [
                'cart'     => true,
                'checkout' => true,
            ],
        ],
    ]
);
```

## Recommended Reading Order

For developers new to Woodev Framework:

1. **[Getting Started](getting-started.md)** — Understand the architecture
2. **[Core Framework](core-framework.md)** — Learn the plugin base class
3. **[Settings API](settings-api.md)** — Add plugin settings
4. **[Admin Module](admin-module.md)** — Create admin pages
5. **[Helpers](helpers.md)** — Use utility functions
6. **[Utilities](utilities.md)** — Implement background processing
7. **[Compatibility](compatibility.md)** — Ensure HPOS compatibility
8. **[API Module](api-module.md)** — Make HTTP requests
9. **[REST API](rest-api.md)** — Expose plugin data via REST
10. **[Shipping Method](shipping-method.md)** or **[Payment Gateway](payment-gateway.md)** — Build specialized plugins

## Support

- **Documentation:** [kalbac.github.io/woodev-plugin-framework](https://kalbac.github.io/woodev-plugin-framework/)
- **Woodev website:** [woodev.ru](https://woodev.ru)
- **Plugin shop:** [woodev.ru/shop](https://woodev.ru/shop)
- **Support desk:** [woodev.ru/support](https://woodev.ru/support)

## Version Information

| Component | Version |
| --- | --- |
| Framework | [%%FRAMEWORK_VERSION%%](https://github.com/kalbac/woodev-plugin-framework/releases/tag/v%%FRAMEWORK_VERSION%%) |
| Minimum PHP | 7.4 |
| Minimum WordPress | 5.9 |
| Minimum WooCommerce | 5.6 |

---

*This documentation is part of the Woodev Plugin Framework. For updates and additional resources, refer to the framework repository.*
