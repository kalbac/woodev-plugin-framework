# Woodev Plugin Framework — QWEN.md

## Project Overview

**Woodev Plugin Framework** is a PHP library providing a scaffold for developing WooCommerce plugins. It ships as a vendored dependency bundled inside each WooCommerce plugin that uses it. Multiple plugins can run simultaneously; the bootstrap selects the highest framework version to load.

- **Type:** PHP Library / Framework
- **License:** GPL-3.0-or-later
- **PHP:** 7.4–8.x (platform target: 8.1)
- **Minimum WordPress:** 5.9
- **Minimum WooCommerce:** 5.6
- **Text Domain:** `woodev-plugin-framework`
- **Namespace:** `Woodev\Framework\*` (PSR-4), legacy code without namespace

## Directory Structure

```
woodev_framework/
├── woodev/                      # Framework source code
│   ├── bootstrap.php            # Singleton bootstrap loader
│   ├── class-plugin.php         # Woodev_Plugin abstract base class (VERSION constant here)
│   ├── class-lifecycle.php      # Install/upgrade lifecycle handler
│   ├── class-helper.php         # Static utility helpers
│   ├── class-admin-notice-handler.php
│   ├── class-admin-message-handler.php
│   ├── admin/                   # Admin pages
│   ├── api/                     # HTTP API base classes
│   ├── box-packer/              # Box packing algorithm abstractions
│   ├── compatibility/           # HPOS and WooCommerce compatibility
│   ├── handlers/                # Gutenberg blocks handler
│   ├── rest-api/                # Settings REST API integration
│   ├── settings-api/            # Typed settings framework
│   ├── shipping-method/         # Shipping plugin and method base classes
│   ├── payment-gateway/         # Payment gateway base classes
│   └── utilities/               # Background job queue
├── tests/
│   ├── unit/                    # Unit tests (Brain Monkey, no WordPress)
│   ├── integration/             # Integration tests (WP_UnitTestCase + wp-env)
│   └── _fixtures/               # Fixture plugins for testing
├── docs/                        # Developer documentation
├── .github/
│   ├── workflows/
│   │   └── ci.yml               # CI/CD (tests → tag → CHANGELOG → release)
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── CONTRIBUTING.md
├── .claude/                     # AI agents and skills
│   ├── agents/                  # Sub-agents for specialized tasks
│   ├── skills/                  # Detailed skill documentation
│   └── QUICK-REFERENCE.md
├── composer.json                # PHP dependencies and scripts
├── package.json                 # Node.js dependencies (markdown linting)
├── phpunit.xml                  # PHPUnit configuration
├── .wp-env.json                 # wp-env Docker configuration
└── cliff.toml                   # git-cliff CHANGELOG configuration
```

## Building and Running

### Install Dependencies

```bash
composer install              # Install all PHP dev dependencies
npm install                   # Install Node.js dependencies (for markdown linting)
```

### Code Quality Commands

```bash
composer check                # Run all checks: phpcs + phpstan + unit tests
composer phpcs                # Check code style (WordPress Coding Standards)
composer phpcbf               # Auto-fix code style
composer phpstan              # Static analysis (level 3, PHP 7.4+)
```

### Testing

```bash
# Unit tests (Brain Monkey, no WordPress required)
composer test:unit
./vendor/bin/phpunit --testsuite=Unit

# Integration tests (requires wp-env)
composer test:integration
./vendor/bin/phpunit --testsuite=Integration

# Run specific test file
./vendor/bin/phpunit tests/unit/BootstrapTest.php
```

### Environment Management

```bash
# Start wp-env (required for integration tests)
npx wp-env start

# Check environment status
npx wp-env status

# Stop environment
npx wp-env stop

# Clean and rebuild
npx wp-env clean all && npx wp-env start
```

### Access Environment

After starting wp-env:

- **Development URL:** http://localhost:8888
- **Test URL:** http://localhost:8889
- **WordPress Admin:** http://localhost:8888/wp-admin

## Development Conventions

### Coding Standards

- **WordPress Coding Standards:** `WordPress-Core`, `WordPress-Extra`, `WordPress-Docs`
- **PHPCompatibility:** Checked for PHP 7.4+
- **PHPStan:** Level 3 (with `checkDynamicProperties: false` for legacy code)
- **Line Length:** 120 characters
- **Indentation:** Tabs, not spaces
- **Array Syntax:** Short syntax `[]` allowed
- **File Encoding:** UTF-8, Unix line endings (LF)

### Naming Conventions

- **Classes:** `Snake_Case` (e.g., `Woodev_Plugin_Class`)
- **Methods/Variables/Hooks:** `snake_case` (e.g., `process_payment()`)
- **Namespace:** `Woodev\Framework\*` for new code (PSR-4)
- **Legacy Code:** No namespace (`Woodev_Plugin`, `Woodev_Plugin_Bootstrap`)

### Documentation

- **Docblocks:** Required for all classes, methods, and hooks
- **Type Declarations:** Required for all parameters and return types (PHP 7.4+)
- **@since Annotation:** Use version from `VERSION` constant in `woodev/class-plugin.php`
- **Developer Docs:** English
- **User Docs:** Russian

### Backward Compatibility (CRITICAL)

This framework is used by 10+ dependent plugins. Breaking changes affect all of them.

**Rules:**

- **NEVER** delete or rename public methods/classes without deprecation cycle
- **ALWAYS** use `@deprecated` annotation for deprecated code
- **ALWAYS** call `_deprecated_function()` in deprecated methods
- Deprecation cycle: minimum one full version before removal
- Breaking changes require major version bump (semver)

**Example:**

```php
/**
 * Old method name.
 *
 * @deprecated 2.0.0 Use new_method_name() instead.
 * @see self::new_method_name()
 */
public function old_method_name(): void {
    _deprecated_function( __METHOD__, '2.0.0', __CLASS__ . '::new_method_name()' );
    $this->new_method_name();
}
```

### Git Workflow

#### Branch Naming

```
{type}/{description}
```

Types: `feature/`, `fix/`, `hotfix/`, `docs/`, `refactor/`, `chore/`

#### Commit Messages (Conventional Commits)

All commits MUST follow Conventional Commits format for automatic CHANGELOG generation:

```
{type}: {description}

[optional body]

[optional footer]
```

| Type | Description | CHANGELOG Section |
|------|-------------|-------------------|
| `feat:` | New feature | ✨ Features |
| `fix:` | Bug fix | 🐛 Bug Fixes |
| `docs:` | Documentation | 📝 Documentation |
| `refactor:` | Code refactoring | 🔧 Refactoring |
| `test:` | Tests | ✅ Tests |
| `chore:` | Auxiliary tasks | ⚙️ Chores |
| `ci:` | CI/CD changes | 🚀 CI/CD |

**Breaking Changes:** Add `!` after type and `BREAKING CHANGE:` footer:

```
feat!: remove deprecated old_method()

BREAKING CHANGE: old_method() has been removed. Use new_method() instead.
```

### Release Workflow (Fully Automatic)

No manual release steps needed!

1. Update `VERSION` constant in `woodev/class-plugin.php`
2. Commit: `git commit -m "chore: release version 2.1.0"`
3. Push: `git push origin main`

**GitHub Actions automatically:**

- Runs all tests (PHPCS, PHPStan, Unit, Integration)
- Creates git tag `v2.1.0`
- Generates CHANGELOG via `git-cliff`
- Builds release ZIP
- Publishes GitHub Release

## Architecture

### Bootstrap & Multi-version Loading

`Woodev_Plugin_Bootstrap` (singleton) is the entry point in `woodev/bootstrap.php`. Each plugin calls `register_plugin()` on the shared bootstrap instance. On `plugins_loaded`, the bootstrap:

1. Sorts registered plugins by framework version (highest first)
2. Loads the highest version's `class-plugin.php` once
3. Initializes all compatible plugins

### Base Plugin Class

`Woodev_Plugin` in `woodev/class-plugin.php` is the abstract base every plugin extends. Concrete plugins must implement:

- `get_file()` — return `__FILE__`
- `get_plugin_name()` — return localized plugin name
- `get_download_id()` — return EDD/store download ID

### Subsystems

| Class | Purpose |
|-------|---------|
| `Woodev_Plugin_Dependencies` | PHP extension/function/setting dependency checking |
| `Woodev_Admin_Message_Handler` | Flash messages persisted across requests |
| `Woodev_Admin_Notice_Handler` | Dismissible WP admin notices |
| `Woodev_Plugins_License` | License key storage and validation |
| `Woodev_Plugin_Updater` | Pulls plugin updates from Woodev store |
| `Woodev_Hook_Deprecator` | Fires `_doing_it_wrong` for deprecated hooks |
| `Woodev_Lifecycle` | Install/upgrade routines and milestone notices |
| `Woodev_REST_API` | Registers plugin REST API routes |
| `Woodev_Blocks_Handler` | Declares WC Cart/Checkout block compatibility |

### Plugin Variants

- **Payment Gateway:** `Woodev_Payment_Gateway_Plugin` — extends `Woodev_Plugin`; loaded when `is_payment_gateway` is set
- **Shipping Method:** `Woodev\Framework\Shipping\Shipping_Plugin` — loaded when `load_shipping_method` is set

## Testing Practices

### Unit Tests

- Location: `tests/unit/`
- Use Brain Monkey + Mockery
- No WordPress required
- Fast execution

### Integration Tests

- Location: `tests/integration/`
- Run inside wp-env with real WordPress
- Require `npx wp-env start`
- Test actual WordPress/WooCommerce integration

### Test Fixtures

Three minimal plugins in `tests/_fixtures/`:

- `woodev-test-plugin` (general)
- `woodev-test-payment-gateway` (payment gateway)
- `woodev-test-shipping-method` (shipping method)

## AI Agents Integration

This project has specialized AI sub-agents in `.claude/agents/`:

| Agent | Purpose |
|-------|---------|
| `woodev-framework-backend-agent` | Backend PHP development |
| `woodev-framework-code-review-agent` | Code review and standards |
| `woodev-framework-dev-cycle-agent` | Testing, linting, Conventional Commits |
| `woodev-framework-docs-agent` | Documentation |
| `woodev-framework-env-agent` | Environment management |
| `woodev-framework-git-agent` | Git operations and releases |

See `.claude/QUICK-REFERENCE.md` for detailed usage.

## Key URLs and Resources

- **Documentation:** `docs/`
- **Framework Source:** `woodev/`
- **Contributing Guide:** `.github/CONTRIBUTING.md`
- **Pull Request Template:** `.github/PULL_REQUEST_TEMPLATE.md`
