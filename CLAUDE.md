# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## MCP Tools

This project has the following MCP servers configured globally:

### Serena MCP

Serena provides symbolic code navigation, cross-referencing, and safe code editing capabilities across 30+ languages including PHP.

**Configuration:** Global (`~/.qwen/mcp.json`)

**Available Tools:**
- `search_symbols` — Find classes, functions, methods, variables by name
- `get_symbol_info` — Get detailed information about a symbol
- `find_references` — Find all references to a symbol
- `edit_file` — Safe code editing with symbolic awareness
- `list_files` — List project files with filtering

**Dashboard:** http://localhost:24282/dashboard (opens automatically when Serena starts)

**Documentation:** https://oraios.github.io/serena/

### Context7 MCP

Context7 provides up-to-date documentation and context for libraries and frameworks directly in your prompts.

**Configuration:** Global (`~/.qwen/mcp.json`)

**Purpose:** Automatically fetches the latest documentation for any library or framework mentioned in your conversation, ensuring AI responses are based on current docs rather than training data.

**Package:** `@upstash/context7-mcp`

**Documentation:** https://upstash.com/blog/context7-mcp

## Project Overview

**Woodev Plugin Framework** (`woodev/plugin-framework`) is a PHP library providing a scaffold for developing WooCommerce plugins. It ships as a vendored dependency bundled inside each WooCommerce plugin that uses it. Multiple plugins can run simultaneously; the bootstrap selects the highest framework version to load.

- PHP 7.4–8.x, platform target: PHP 8.1
- Text domain: `woodev-plugin-framework`
- All globals/classes must be prefixed `woodev` or `Woodev`
- i18n strings for UI strings use Russian where applicable (this is intentional)

## Commands

```bash
composer install              # install all dev dependencies

composer phpcs                # check code style (WordPress Coding Standards + PHPCompatibility)
composer phpcbf               # auto-fix code style
composer phpstan              # static analysis (level 3, PHP 7.4+)
composer test                 # run unit tests
composer test:unit            # run unit tests (Brain Monkey, no WP needed)
composer test:integration     # run integration tests (requires WP_TESTS_DIR or wp-env)
composer check                # run phpcs + phpstan + unit tests together
```

Run a single test file:
```bash
./vendor/bin/phpunit tests/unit/BootstrapTest.php
```

Integration tests require a WordPress test library. Set `WP_TESTS_DIR` env var or use `npx wp-env start`.

## Architecture

### Bootstrap & Multi-version Loading (`woodev/bootstrap.php`)

`Woodev_Plugin_Bootstrap` (singleton) is the entry point. Each plugin calls `register_plugin()` on the shared bootstrap instance. On `plugins_loaded`, the bootstrap sorts registered plugins by framework version (highest first), loads the highest version's `class-plugin.php` once, then initializes all compatible plugins. Plugins with incompatible framework, WC, or WP versions are deactivated with admin notices.

### Base Plugin Class (`woodev/class-plugin.php`)

`Woodev_Plugin` is the abstract base every plugin extends. Concrete plugins must implement:
- `get_file()` — return `__FILE__`
- `get_plugin_name()` — return localized plugin name
- `get_download_id()` — return EDD/store download ID

The constructor auto-initializes all framework subsystems and registers WP hooks. Plugins override the `init_*` methods to provide their own subsystem implementations.

### Subsystems (all initialized inside `Woodev_Plugin::__construct`)

| Class | Purpose |
|---|---|
| `Woodev_Plugin_Dependencies` | PHP extension/function/setting dependency checking |
| `Woodev_Admin_Message_Handler` | Flash messages persisted across requests |
| `Woodev_Admin_Notice_Handler` | Dismissible WP admin notices |
| `Woodev_Plugins_License` | License key storage and validation |
| `Woodev_Plugin_Updater` | Pulls plugin updates from Woodev store |
| `Woodev_Hook_Deprecator` | Fires `_doing_it_wrong` for deprecated hooks |
| `Woodev_Lifecycle` | Install/upgrade routines and milestone notices |
| `Woodev_REST_API` | Registers plugin REST API routes |
| `Woodev_Blocks_Handler` | Declares WC Cart/Checkout block compatibility |
| `Woodev_Plugin_Setup_Wizard` | Admin onboarding wizard (opt-in) |

### Plugin Variants

- **`Woodev_Payment_Gateway_Plugin`** (`woodev/payment-gateway/class-payment-gateway-plugin.php`) — extends `Woodev_Plugin`; loaded only when a plugin sets `is_payment_gateway` in bootstrap args. Manages one or more `Woodev_Payment_Gateway` instances.
- **`Woodev\Framework\Shipping\Shipping_Plugin`** (`woodev/shipping-method/class-shipping-plugin.php`) — loaded when `load_shipping_method` is set in bootstrap args.

### API Layer (`woodev/api/`)

`Woodev_API_Base` handles HTTP communication. Extend one of:
- `Woodev_Abstract_API_JSON_Request` / `Woodev_Abstract_API_JSON_Response`
- `Woodev_Abstract_API_XML_Request` / `Woodev_Abstract_API_XML_Response`
- `Woodev_Abstract_Cacheable_API_Base` — adds transient-based request caching via `Cacheable_Request_Trait`

Requests/responses must implement `Woodev_API_Request` / `Woodev_API_Response` interfaces. API requests are automatically logged via the `woodev_{plugin_id}_api_request_performed` action.

### Settings API (`woodev/settings-api/`)

`Woodev_Abstract_Settings` provides a WooCommerce-style settings page. Settings are defined as `Woodev_Setting` objects registered through `Woodev_Register_Settings`.

### Lifecycle & Upgrades (`woodev/class-lifecycle.php`)

Override `Woodev_Lifecycle` per plugin. Define `$upgrade_versions` array and add methods named `upgrade_to_X_Y_Z()`. Install/upgrade events are stored in the DB (last 30 events). Milestone notices prompt users for reviews after key actions.

### Box Packer (`woodev/box-packer/`)

Self-contained shipping box-packing algorithm. Implement `Woodev_Packer_Item_Interface` and `Woodev_Packer_Box_Interface`; use `Woodev_Abstract_Packer` subclasses (`Woodev_Packer_Single_Box`, `Woodev_Packer_Separately`, `Woodev_Packer_Virtual_Box`).

### Utilities (`woodev/utilities/`)

- `Woodev_Async_Request` — WP async (non-blocking) HTTP requests
- `Woodev_Background_Job_Handler` — WP background processing queue
- `Woodev_Job_Batch_Handler` — batch job processing with admin UI

## Testing

- **Unit tests** (`tests/unit/`) use Brain Monkey + Mockery; no WordPress required.
- **Integration tests** (`tests/integration/`) run inside a real WordPress environment.
- Test fixtures (`tests/_fixtures/`) contain three minimal plugins: `woodev-test-plugin`, `woodev-test-payment-gateway`, `woodev-test-shipping-method`.
- Test base classes `tests/unit/TestCase.php` and `tests/integration/TestCase.php` set up Brain Monkey and WP test scaffolding respectively.

## Code Style

- WordPress Coding Standards (`WordPress-Core`, `WordPress-Extra`, `WordPress-Docs`)
- Short array syntax `[]` is allowed (override of WPCS default)
- Line length limit: 120 characters
- PHPCompatibility checked for PHP 7.4+, minimum WP version 5.9
- PHPStan level 3; `checkDynamicProperties: false` (legacy code uses dynamic properties)
