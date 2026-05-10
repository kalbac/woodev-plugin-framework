# Gotcha: [php/namespace-migration-legacy-psr4] — Legacy Woodev_* vs PSR-4 Woodev\Framework\*
> Tags: php, namespace, psr4, migration, naming | Session: s2

## What happens
Mixing legacy global-namespace `Woodev_*` classes with new PSR-4 namespaced `Woodev\Framework\*` classes causes confusion about which convention to use for new code. Shipping module already uses PSR-4; the rest of the codebase uses legacy `Woodev_*`.

## Root cause
The framework is mid-migration:
- **Legacy code:** `Snake_Case` classes in global namespace (e.g., `Woodev_Plugin`, `Woodev_Payment_Gateway_Plugin`)
- **New code:** `Snake_Case` classes under `Woodev\Framework\*` PSR-4 namespace (e.g., `\Woodev\Framework\Shipping\Shipping_Plugin`)

Both conventions coexist. New code added to existing legacy modules should follow the module's convention. Brand-new modules should use PSR-4.

## Fix

❌ **Wrong — don't mix conventions in the same module:**
```php
// In a legacy module (no namespace)
class Woodev_My_New_Class { }  // ✅ correct for legacy

// DON'T do this in the same file:
namespace Woodev\Framework\SomeModule;
class My_New_Class { }  // ❌ inconsistent with rest of module
```

✅ **Correct — follow the module's convention:**

**For legacy modules** (payment-gateway, licensing, compatibility, admin, bootstrap):
```php
// No namespace declaration
class Woodev_My_New_Class extends Woodev_Plugin { }
```

**For new modules** (shipping-method already uses this, future modules):
```php
namespace Woodev\Framework\MyModule;

abstract class My_Plugin extends \Woodev_Plugin { }
```

### Current module conventions
| Module | Convention | Example |
|--------|-----------|---------|
| `woodev/` root (`bootstrap.php`, `class-plugin.php`, `class-helper.php`) | Legacy `Woodev_*` | `Woodev_Plugin_Bootstrap` |
| `payment-gateway/` | Legacy `Woodev_*` | `Woodev_Payment_Gateway_Plugin` |
| `licensing/` | Legacy `Woodev_*` | `Woodev_Plugins_License` |
| `compatibility/` | Legacy `Woodev_*` | `Woodev_Order_Compatibility` |
| `shipping-method/` | **PSR-4** `Woodev\Framework\Shipping\*` | `Woodev\Framework\Shipping\Shipping_Plugin` |

## Related
- [class-shipping-plugin.php](../../woodev/shipping-method/class-shipping-plugin.php) — PSR-4 example (line 12: `namespace Woodev\Framework\Shipping;`)
- [class-payment-gateway-plugin.php](../../woodev/payment-gateway/class-payment-gateway-plugin.php) — Legacy example (no namespace)
- [AGENT-RULES.md](../AGENT-RULES.md) — Rule 1: OOP Only
