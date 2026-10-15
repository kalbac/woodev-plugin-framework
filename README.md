# Woodev Plugin Framework — Developer Documentation

[![CI](https://github.com/kalbac/woodev-plugin-framework/actions/workflows/ci.yml/badge.svg)](https://github.com/kalbac/woodev-plugin-framework/actions/workflows/ci.yml)
[![Integration Tests](https://github.com/kalbac/woodev-plugin-framework/actions/workflows/integration-tests.yml/badge.svg)](https://github.com/kalbac/woodev-plugin-framework/actions/workflows/integration-tests.yml)
[![Docs](https://github.com/kalbac/woodev-plugin-framework/actions/workflows/docs.yml/badge.svg)](https://kalbac.github.io/woodev-plugin-framework/)
[![Latest Release](https://img.shields.io/github/v/release/kalbac/woodev-plugin-framework)](https://github.com/kalbac/woodev-plugin-framework/releases/latest)
[![PHP](https://img.shields.io/badge/PHP-7.4--8.2-777bb4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.3%2B-21759b)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a)](https://woocommerce.com/)
[![License](https://img.shields.io/github/license/kalbac/woodev-plugin-framework)](LICENSE)

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
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * WC requires at least: 7.0
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
            'minimum_wp_version'   => '6.3',
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

## Documentation

The developer documentation lives at <https://kalbac.github.io/woodev-plugin-framework/>. Its source is the Astro Starlight project in [`website/`](website/); see `docs-internal/DOCS-SCHEMA.md` → "Public docs" for how to edit, preview and build it.

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

## Building admin JS

The license management admin page uses a React app built with `@wordpress/scripts`.
The compiled bundle is committed to `woodev/assets/build/` and ships directly in the release ZIP — no build step is required to use the framework.

To regenerate the bundle after changing files under `src/`:

```bash
npm ci                 # install dependencies (Node version from .nvmrc)
npm run build          # compile src/ → woodev/assets/build/
```

CI runs the same commands on every PR and fails if the committed bundle does not
match what `npm run build` produces, so always commit the updated `woodev/assets/build/`
files alongside any `src/` changes.

## AI Development Tools

Agent and skill documents live in `.ai/` (`.ai/agents/`, `.ai/skills/`, `.ai/QUICK-REFERENCE.md`).
They are **plain documents an agent reads on request — no tool loads them automatically.**

Five reference files used to claim otherwise (`.claude/skills`, `.claude/agents`, `.codex/skills`,
`.qwen/skills`, `.cursor/rules/skills.mdc`), each committed as an ordinary file containing a relative
path instead of being a symlink. None of them linked anything on any OS, and the table that described
them was wrong for as long as it existed. They were removed in s138 (#880) rather than turned into
real symlinks: nothing had depended on them for half a year, and activating five agent documents
whose text nobody has reviewed since would add risk, not capability.

What an agent actually reads is in the entry points: `CLAUDE.md` (Claude Code), `AGENTS.md` (every
other agent), and `docs-internal/` for the operational detail.

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

## Support

- Documentation: <https://kalbac.github.io/woodev-plugin-framework/>
- Framework Source: [woodev/](../)

## Version Information

| Component | Version |
| --- | --- |
| Framework | 1.4.0 |
| Minimum PHP | 7.4 |
| Minimum WordPress | 6.3 |
| Minimum WooCommerce | 7.0 |

---

*This documentation is part of the Woodev Plugin Framework. For updates and additional resources, refer to the framework repository.*
