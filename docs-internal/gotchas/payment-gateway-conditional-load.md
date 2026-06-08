# Gotcha: [bootstrap/payment-gateway-conditional-load] — Payment gateway base class loads conditionally
> Tags: bootstrap, payment-gateway, class-loading | Session: s2

## What happens
If a payment gateway plugin calls `register_plugin(...)` without setting `'is_payment_gateway' => true` in `$args`, the `Woodev_Payment_Gateway_Plugin` class is never loaded. When the plugin tries to extend it, PHP throws a **fatal error: Class not found**.

The same applies to shipping methods: without `'load_shipping_method' => true`, `Woodev\Framework\Shipping\Shipping_Plugin` is not loaded.

## Root cause
The bootstrap conditionally loads module-specific base classes. In `load_plugins()` (line 114–120 of `bootstrap.php`), `class-payment-gateway-plugin.php` is only `require_once`'d if `$args['is_payment_gateway']` is set. Similarly, `class-shipping-plugin.php` is only loaded if `$args['load_shipping_method']` is set. This avoids loading heavy payment gateway code for simple plugins that don't need it.

## Fix

❌ **Wrong:**
```php
// PHP Fatal error: Class 'Woodev_Payment_Gateway_Plugin' not found
Woodev_Plugin_Bootstrap::instance()->register_plugin(
    '1.4.1', 'My Gateway', __FILE__, $callback,
    [/* missing is_payment_gateway */]
);

class My_Gateway extends Woodev_Payment_Gateway_Plugin { ... }
```

✅ **Correct:**
```php
Woodev_Plugin_Bootstrap::instance()->register_plugin(
    '1.4.1', 'My Gateway', __FILE__, $callback,
    [
        'is_payment_gateway' => true,  // ✅ Required!
        'minimum_wc_version' => '7.0',
    ]
);

// Also valid for shipping plugins:
// 'load_shipping_method' => true,
```

## Related
- [bootstrap.php](../../woodev/bootstrap.php) — Lines 114–120: conditional class loading
- [class-payment-gateway-plugin.php](../../woodev/payment-gateway/class-payment-gateway-plugin.php) — Base gateway plugin class
