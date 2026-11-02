# Running Tests

Guide for running PHP tests in Woodev Framework projects.

---

## Test Environment

### Requirements

- Docker Desktop (or compatible)
- `@wordpress/env` CLI tool
- PHP 8.1 (platform target)

### Setup

```bash
# Install wp-env globally
npm install -g @wordpress/env

# Start the test environment
npx wp-env start

# Check environment status
npx wp-env status
```

The environment includes:

- WordPress (latest)
- WooCommerce (latest stable)
- Fixture plugins (auto-loaded via mappings)
- PHPUnit 9.6+

---

## Two Test Levels

### 1. Unit Tests (Brain Monkey, no WordPress)

```bash
# Run all unit tests
composer test:unit

# Or directly
TEST_SUITE=unit ./vendor/bin/phpunit --testsuite Unit

# Run specific test file
./vendor/bin/phpunit tests/unit/BootstrapTest.php

# Filter by class or method name
TEST_SUITE=unit ./vendor/bin/phpunit --filter BootstrapTest
```

### 2. Integration Tests (WP_UnitTestCase + wp-env)

Integration runs **only inside the wp-env `tests-cli` container**, on the branch checked out in the main tree, and is the coordinator's job (a worktree cannot run it — `WOODEV_FRAMEWORK_DIR` points at the main checkout). On the host, `composer test:integration` dies with `Class "WP_UnitTestCase" not found`, which reads like a bootstrap regression but is not one.

```bash
npx wp-env start
npx wp-env run tests-cli bash -c "cd /var/www/html/woodev-framework && TEST_SUITE=integration WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --testsuite=Integration"
```

On Windows Git Bash the container paths are mangled; the exact command and the traps are in the gotcha `wpenv-windows-gitbash-path-mangling`. The integration bootstrap branches on `TEST_SUITE=integration` (`tests/bootstrap.php`).

### Result cache

`phpunit.xml` sets `executionOrder="depends,defects"`, so a stale `.phpunit.result.cache` reorders tests and two runs of the same tree disagree. Run `rm -f .phpunit.result.cache` before every measurement.

### Other test and doc gates

```bash
npm run test:js      # jest (run from bash; jest-unit.config.js scopes roots)
npm run lint:docs    # docs-internal structure, links, gotcha index
```

### Fixture Plugins

Tests use fixture plugins in `tests/_fixtures/`:

- `woodev-test-plugin` (general framework testing)
- `woodev-test-payment-gateway` (payment gateway testing)
- `woodev-test-shipping-method` (shipping method testing)
- `woodev-realistic-shipping-plugin`, `woodev-realistic-payment-plugin` and others — the full list is `ls tests/_fixtures`

They are mapped into wp-env via the `mappings` blocks of `.wp-env.json` (the file is the source of truth; it also sets `WOODEV_FRAMEWORK_DIR` and `WOODEV_UI_KIT_GALLERY`).

---

## Writing Tests

### Test File Location

```text
tests/
  unit/             # Unit tests (Brain Monkey)
    TestCase.php    # Base class -- extend this
    BootstrapTest.php
  integration/      # Integration tests (WP_UnitTestCase)
    TestCase.php    # Base class -- extend this
  _fixtures/        # Fixture plugins
    woodev-test-plugin/
    woodev-test-payment-gateway/
    woodev-test-shipping-method/
```

### Test Class Structure (Unit)

```php
<?php
/**
 * Tests for Example_Class
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

class ExampleTest extends TestCase {

    public function test_method_returns_expected_value(): void {
        // Arrange
        Functions\when( 'get_option' )->justReturn( 'value' );

        // Act
        $result = some_function();

        // Assert
        $this->assertEquals( 'expected', $result );
    }
}
```

**Key points:**

- Namespace: `Woodev\Tests\Unit`
- Extend `TestCase` (not `PHPUnit\Framework\TestCase` directly)
- `TestCase` auto-initializes Brain Monkey and stubs translation/escape functions
- Follow Arrange-Act-Assert pattern
- Use descriptive method names: `test_{method}_{scenario}_{expected}`

### Test Class Structure (Integration)

```php
<?php
/**
 * Tests for Example_Class
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

class ExampleTest extends TestCase {

    public function test_plugin_is_loaded(): void {
        $plugin = $this->get_test_plugin();

        $this->assertInstanceOf( \Woodev_Plugin::class, $plugin );
    }
}
```

**Key points:**

- Namespace: `Woodev\Tests\Integration`
- Extend `TestCase` (wraps `WP_UnitTestCase`)
- `get_test_plugin()` helper returns the fixture plugin instance
- Has access to real WordPress and WooCommerce

For detailed Brain Monkey/Mockery patterns and advanced testing techniques, see [testing-guide.md](testing-guide.md).

---

## Troubleshooting

### Environment Issues

```bash
# Clean and rebuild environment
npx wp-env clean all
npx wp-env start

# Update environment
npx wp-env start --update
```

### Test Failures

If tests fail unexpectedly:

1. **Check environment is running** -- `npx wp-env status`
2. **Verify database is clean** -- `npx wp-env clean database`
3. **Check test isolation** -- ensure tests do not depend on each other
4. **Run with verbose output** -- `composer test:unit -- -v`

### Common Errors

#### "Cannot connect to database"

```bash
npx wp-env clean database
npx wp-env start
```

#### "Class not found"

- Ensure autoloader is generated (`composer dump-autoload`)
- Check namespace matches file path

#### "Function not defined"

- For unit tests: stub the function with Brain Monkey
- For integration tests: ensure wp-env is running

---

## Quick Reference

```bash
# Start environment (for integration tests)
npx wp-env start

# Run all unit tests
composer test:unit

# Integration: inside the tests-cli container only (see "Integration Tests" above)

# Run specific test
./vendor/bin/phpunit tests/unit/BootstrapTest.php

# Run with coverage
TEST_SUITE=unit ./vendor/bin/phpunit --coverage-html ./coverage

# Clean environment
npx wp-env clean all
```
