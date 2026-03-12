# Testing Guide

Brain Monkey, Mockery, and testing patterns for Woodev Framework.

---

## Base Test Classes

### Unit Tests: `Woodev\Tests\Unit\TestCase`

Located at `tests/unit/TestCase.php`. Automatically:

- Initializes Brain Monkey in `setUp()`
- Tears down Brain Monkey in `tearDown()`
- Stubs WordPress translation functions (`__()`, `_e()`, `esc_html__()`, etc.)
- Stubs WordPress escape functions (`esc_html()`, `esc_attr()`, etc.)
- Integrates Mockery with PHPUnit (auto-verifies expectations)

All unit tests extend this class. You never need to call `Monkey\setUp()` or `Monkey\tearDown()` manually.

### Integration Tests: `Woodev\Tests\Integration\TestCase`

Located at `tests/integration/TestCase.php`. Extends `WP_UnitTestCase` and:

- Verifies `Woodev_Plugin_Bootstrap` is loaded (fails fast if wp-env is not running)
- Provides `get_test_plugin()` helper to retrieve the fixture plugin instance
- Has access to real WordPress database, hooks, and WooCommerce

---

## Brain Monkey Patterns

### Stubbing Functions

```php
use Brain\Monkey\Functions;

// Return a fixed value
Functions\when( 'get_option' )->justReturn( 'default_value' );

// Return the first argument (passthrough)
Functions\when( 'sanitize_text_field' )->returnArg();

// Custom callback
Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
    return array_merge( $defaults, $args );
} );
```

### Stubbing Multiple Functions at Once

```php
use Brain\Monkey\Functions;

Functions\stubs( [
    'get_option'       => 'default',
    'get_site_option'  => false,
    'wp_create_nonce'  => 'nonce_value',
    'is_admin'         => true,
] );
```

### Expecting Function Calls

```php
use Brain\Monkey\Functions;

// Expect a function to be called once with specific args
Functions\expect( 'update_option' )
    ->once()
    ->with( 'my_option', 'new_value' )
    ->andReturn( true );

// Expect called with any args
Functions\expect( 'do_action' )
    ->once()
    ->with( 'woodev_my_hook', \Mockery::any() );

// Expect never called
Functions\expect( 'wp_die' )->never();
```

### Note on Pre-stubbed Functions

Since `TestCase` calls `stubTranslationFunctions()` and `stubEscapeFunctions()`, you do not need to stub these in individual tests:

- `__()`, `_e()`, `_x()`, `_n()`, `esc_html__()`, `esc_attr__()`, etc.
- `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `esc_textarea()`, etc.

---

## Mockery Patterns

### Mocking WordPress/WooCommerce Classes

```php
$order = \Mockery::mock( 'WC_Order' );
$order->shouldReceive( 'get_id' )->andReturn( 123 );
$order->shouldReceive( 'get_total' )->andReturn( '99.99' );
$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
```

### Mocking Abstract Classes

```php
// Create a partial mock of an abstract class
$gateway = \Mockery::mock( \Woodev_Payment_Gateway::class )
    ->makePartial()
    ->shouldAllowMockingProtectedMethods();

$gateway->shouldReceive( 'get_id' )->andReturn( 'test_gateway' );
```

### Mocking with Constructor Args

```php
$mock = \Mockery::mock( 'SomeClass', [ 'arg1', 'arg2' ] )
    ->makePartial();
```

### Spy Pattern

```php
$spy = \Mockery::spy( 'SomeClass' );

// Call code that uses the spy...
$spy->some_method( 'arg' );

// Assert after the fact
$spy->shouldHaveReceived( 'some_method' )->with( 'arg' )->once();
```

---

## Testing Singletons

Many framework classes (e.g., `Woodev_Plugin_Bootstrap`) use the singleton pattern. To test them in isolation, reset the static instance between tests using reflection:

```php
protected function reset_singleton( string $class_name ): void {
    $reflection = new \ReflectionClass( $class_name );
    $instance   = $reflection->getProperty( 'instance' );
    $instance->setAccessible( true );
    $instance->setValue( null, null );
}

protected function setUp(): void {
    parent::setUp();
    $this->reset_singleton( \Woodev_Plugin_Bootstrap::class );
}
```

---

## Testing `class_exists()` Guards

Some framework code uses `class_exists()` checks. In unit tests, the checked class may not be loaded. Stub it:

```php
Functions\when( 'class_exists' )->alias( function ( $class ) {
    if ( 'WooCommerce' === $class ) {
        return true;
    }
    return \class_exists( $class );
} );
```

Or ensure the relevant file is `require_once`'d in the test bootstrap.

---

## Testing Hooks

### Asserting Actions and Filters Were Added

```php
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

// Expect an action to be added
Actions\expectAdded( 'init' )
    ->once()
    ->with( [ \Mockery::any(), 'init_handler' ] );

// Expect a filter to be added
Filters\expectAdded( 'woocommerce_payment_gateways' )
    ->once();
```

### Asserting Actions Were Fired

```php
use Brain\Monkey\Actions;

Actions\expectDone( 'woodev_plugin_loaded' )
    ->once()
    ->with( \Mockery::type( \Woodev_Plugin::class ) );

// Then call the code that fires the action
```

### Checking Hook Registration After the Fact

```php
// After calling code that registers hooks:
$this->assertTrue( has_action( 'init', 'my_callback' ) !== false );
$this->assertTrue( has_filter( 'the_content', 'my_filter' ) !== false );
```

Note: `has_action()` and `has_filter()` work in Brain Monkey when hooks are registered via `add_action()`/`add_filter()` during the test.

---

## Data Providers

Use `@dataProvider` for parameterized tests:

```php
/**
 * @dataProvider version_format_provider
 */
public function test_version_format( string $version, bool $expected ): void {
    $result = $this->is_valid_version( $version );
    $this->assertSame( $expected, $result );
}

public function version_format_provider(): array {
    return [
        'valid semver'       => [ '1.2.3', true ],
        'valid with v'       => [ 'v1.0.0', false ],
        'missing patch'      => [ '1.2', false ],
        'empty string'       => [ '', false ],
        'with pre-release'   => [ '1.2.3-beta', true ],
    ];
}
```

---

## Fixture Plugins

Three fixture plugins live in `tests/_fixtures/`:

| Plugin | Purpose |
|--------|---------|
| `woodev-test-plugin` | General framework feature testing |
| `woodev-test-payment-gateway` | Payment gateway subsystem testing |
| `woodev-test-shipping-method` | Shipping method subsystem testing |

Each fixture plugin:

- Is a minimal WordPress plugin that extends the framework
- Gets mapped into wp-env via `.wp-env.json` mappings
- Shares the `woodev/` directory (mapped separately)
- Is used primarily in integration tests

Use fixture plugins when you need a real plugin instance running inside WordPress. For unit tests, prefer mocking.

---

## Example Test Following Project Conventions

```php
<?php
/**
 * Tests for Woodev_Plugin_Bootstrap
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

class BootstrapRegistrationTest extends TestCase {

    /**
     * Reset singleton between tests.
     */
    protected function setUp(): void {
        parent::setUp();

        $reflection = new \ReflectionClass( \Woodev_Plugin_Bootstrap::class );
        $instance   = $reflection->getProperty( 'instance' );
        $instance->setAccessible( true );
        $instance->setValue( null, null );
    }

    public function test_register_plugin_stores_plugin_data(): void {
        Functions\when( 'plugin_basename' )->returnArg();

        $bootstrap = \Woodev_Plugin_Bootstrap::instance();
        $bootstrap->register_plugin( [
            'plugin_id'         => 'test',
            'plugin_name'       => 'Test Plugin',
            'framework_version' => '1.0.0',
            'plugin_file'       => '/path/to/plugin.php',
        ] );

        $registered = $bootstrap->get_registered_plugins();
        $this->assertArrayHasKey( 'test', $registered );
    }

    /**
     * @dataProvider invalid_registration_provider
     */
    public function test_register_plugin_rejects_invalid_data( array $args ): void {
        $bootstrap = \Woodev_Plugin_Bootstrap::instance();

        $this->expectException( \InvalidArgumentException::class );
        $bootstrap->register_plugin( $args );
    }

    public function invalid_registration_provider(): array {
        return [
            'missing plugin_id' => [ [ 'plugin_name' => 'Test' ] ],
            'empty args'        => [ [] ],
        ];
    }
}
```
