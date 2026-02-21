# Dependency Injection

## Table of Contents

- [Standard DI Pattern for Internal Classes](#standard-di-pattern-for-internal-classes)
- [Why Use Dependency Injection?](#why-use-dependency-injection)

## Standard DI Pattern for Internal Classes

Dependencies are injected via a `final` `init` method with `@internal` annotation (blank lines before/after).

**Example:**

```php
namespace Woodev\Framework\Admin;

use Woodev\Framework\Logging\Logger;
use Woodev\Framework\Data_Store\Order_Data_Store;

class Order_Processor {
    private Logger $logger;
    private Order_Data_Store $data_store;

    /**
     * Initialize the order processor with dependencies.
     *
     * @internal
     *
     * @param Logger           $logger     The logger instance.
     * @param Order_Data_Store $data_store The order data store.
     */
    final public function init( Logger $logger, Order_Data_Store $data_store ) {
        $this->logger     = $logger;
        $this->data_store = $data_store;
    }

    public function process( int $order_id ) {
        $this->logger->log( "Processing order {$order_id}" );
        // ...
    }
}
```

## Why Use Dependency Injection?

- Easy mocking in tests
- Swap dependencies without code changes
- Explicit dependencies in signature
