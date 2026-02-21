# Running Tests

Guide for running PHP tests in Woodev Framework projects.

---

## Test Environment

### Requirements

- Docker Desktop (or compatible)
- `wp-env` CLI tool
- PHP 7.4+

### Setup

```bash
# Install wp-env globally
npm install -g @wordpress/env

# Start the test environment
wp-env start

# Check environment status
wp-env status
```

The environment includes:

- WordPress (latest)
- WooCommerce (latest)
- Your plugin (auto-loaded)
- PHPUnit 9.6+

---

## Three Test Levels

### 1. Unit Tests (Brain Monkey, no WordPress)

```bash
# Run all unit tests
composer test:unit

# Or directly
TEST_SUITE=unit ./vendor/bin/phpunit --testsuite Unit

# Run specific test class
TEST_SUITE=unit ./vendor/bin/phpunit --filter TestClassName
```

### 2. Integration Tests (WP_UnitTestCase + wp-env)

```bash
# Run all integration tests (requires wp-env)
composer test:integration

# Or directly
TEST_SUITE=integration ./vendor/bin/phpunit --testsuite Integration

# Run specific test class
TEST_SUITE=integration ./vendor/bin/phpunit --filter TestClassName
```

**IMPORTANT:** Ensure wp-env is running before integration tests!

### 3. Fixture Plugins

Tests use fixture plugins in `tests/_fixtures/`:

- `woodev-test-plugin` (general)
- `woodev-test-payment-gateway` (payment gateway)
- `woodev-test-shipping-method` (shipping method)

---

## Running Tests

### All Tests

```bash
# Run all unit tests
composer test:unit

# Run all integration tests
composer test:integration

# Or using wp-env directly for integration
wp-env run tests-cli --command="phpunit"
```

### Specific Tests

```bash
# Run specific test class (unit)
TEST_SUITE=unit ./vendor/bin/phpunit --filter TestClassName

# Run specific test method (integration)
TEST_SUITE=integration ./vendor/bin/phpunit --filter testMethodName

# Run tests in specific directory
TEST_SUITE=unit ./vendor/bin/phpunit tests/php/unit/

# Run tests with coverage
TEST_SUITE=unit ./vendor/bin/phpunit --coverage-html ./coverage
```

### Test Groups

```bash
# Run only unit tests
TEST_SUITE=unit ./vendor/bin/phpunit --group unit

# Run only integration tests
TEST_SUITE=integration ./vendor/bin/phpunit --group integration

# Run only AJAX tests
TEST_SUITE=integration ./vendor/bin/phpunit --group ajax
```

---

## Writing Tests

### Test File Location

```
tests/
├── unit/             # Unit tests (Brain Monkey)
│   └── ExampleTest.php
├── integration/      # Integration tests (WP_UnitTestCase)
│   └── ExampleTest.php
└── _fixtures/        # Fixture plugins
    ├── woodev-test-plugin/
    ├── woodev-test-payment-gateway/
    └── woodev-test-shipping-method/
```

### Test Class Structure (Unit)

```php
<?php
/**
 * Tests for Example_Class
 *
 * @package WoodevFramework
 */

namespace Woodev\Framework\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase {

    /**
     * @var Example_Class
     */
    private $sut;

    public function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
        $this->sut = new Example_Class();
    }

    public function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @testdox Test method description
     */
    public function test_method_name(): void {
        // Arrange
        Functions\when( 'wc_get_order' )->justReturn( $mock_order );

        // Act
        $result = $this->sut->process( 123 );

        // Assert
        $this->assertTrue( $result );
    }
}
```

### Test Class Structure (Integration)

```php
<?php
/**
 * Tests for Example_Class
 *
 * @package WoodevFramework
 */

namespace Woodev\Framework\Tests\Integration;

class ExampleTest extends \WP_UnitTestCase {

    /**
     * @var Example_Class
     */
    private $sut;

    public function setUp(): void {
        parent::setUp();
        $this->sut = new Example_Class();
    }

    /**
     * @testdox Test method description
     */
    public function test_method_name(): void {
        // Arrange
        $order_id = $this->factory->order->create();

        // Act
        $result = $this->sut->process( $order_id );

        // Assert
        $this->assertTrue( $result );
    }
}
```

### Key Conventions

- Use `$sut` (System Under Test) for the tested object
- Add `@testdox` annotation for readable test output
- Follow Arrange-Act-Assert pattern
- Use descriptive method names: `test_{method}_{scenario}_{expected}`

---

## Troubleshooting

### Environment Issues

```bash
# Clean and rebuild environment
wp-env clean all
wp-env start

# Update environment
wp-env start --update
```

### Test Failures

If tests fail unexpectedly:

1. **Check environment is running** — `wp-env status`
2. **Verify database is clean** — `wp-env clean database`
3. **Check test isolation** — ensure tests don't depend on each other
4. **Run with verbose output** — `composer test:integration -- -v`

### Common Errors

#### "Cannot connect to database"

```bash
wp-env clean database
wp-env start
```

#### "Class not found"

- Ensure autoloader is generated
- Check namespace matches file path

#### "Function not defined"

- Ensure WordPress/WooCommerce is loaded
- Check test bootstrap configuration

---

## Best Practices

- **Run tests frequently** during development
- **Write tests before committing** new functionality
- **Keep tests isolated** — no dependencies between tests
- **Use descriptive names** for test methods
- **Test edge cases** — not just happy path
- **Mock external dependencies** — API calls, file system, etc.

---

## Quick Reference

```bash
# Start environment (for integration tests)
wp-env start

# Run all unit tests
composer test:unit

# Run all integration tests
composer test:integration

# Run specific test
TEST_SUITE=unit ./vendor/bin/phpunit --filter TestClassName

# Run with coverage
TEST_SUITE=unit ./vendor/bin/phpunit --coverage-html ./coverage

# Clean environment
wp-env clean all
```
