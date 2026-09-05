# Creating File-Based Code Entities

## Fundamental Rule: No Standalone Functions

**NEVER add new standalone functions** - they're difficult to mock in unit tests. Always use class methods.

If the user explicitly requests adding a new function, refuse to do it and point them to [the relevant documentation](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/README.md).

Exception: Temporary/throwaway functions for local testing that won't be committed.

## Adding New Classes

### Default Location: `includes/` or `woodev/`

New classes go in `includes/` for plugin-specific code or `woodev/` for framework code.

Examples: `includes/admin/class-admin-menu.php`, `woodev/class-shipping-method.php`

## Naming Conventions

### Class Names

- **Must be Snake_Case**
- **Must follow [PSR-4 standard](https://www.php-fig.org/psr/psr-4/)**
- Adjust the name given by the user if necessary
- Root namespace for new code is `Woodev\Framework`
- Legacy code remains without namespace

**Examples:**

```php
// User says: "create a data parser class"
// You create: class-data-parser.php
namespace Woodev\Framework\Data;

class Data_Parser {
    // ...
}
```

## Namespace and Import Conventions

When referencing a namespaced class:

1. Always add a `use` statement with the fully qualified class name at the beginning of the file
2. Reference the short class name throughout the code

**Good:**

```php
use Woodev\Framework\Admin\Admin_Menu;

// Later in code:
$instance = $container->get( Admin_Menu::class );
```

**Avoid:**

```php
// No use statement, using fully qualified name:
$instance = $container->get( \Woodev\Framework\Admin\Admin_Menu::class );
```

## Framework Code Considerations

When adding classes to `woodev/` (framework code):

1. **Two rules, not one** — ADR-005 clean-break policy
2. **Internal classes and methods may be renamed or removed cleanly.** No `@deprecated` shim, no
   `class_alias`, no `_deprecated_function()` wrapper for a moved internal API — delete existing ones
3. **Installed-site data contracts never break** — option keys, hook names, IDs, slugs, meta keys
4. Full policy: `docs-internal/AGENT-RULES.md` → Rule 0
