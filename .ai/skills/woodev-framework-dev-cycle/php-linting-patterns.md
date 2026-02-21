# PHP Linting Patterns and Common Issues

## Table of Contents

- [Critical Rule: Lint Only Specific Files](#critical-rule-lint-only-specific-files)
- [Common PHP Linting Issues & Fixes](#common-php-linting-issues--fixes)
- [Translators Comment Placement](#translators-comment-placement)
- [PSR-12 File Header Order](#psr-12-file-header-order)
- [Unused Closure Parameters](#unused-closure-parameters)
- [Array and Operator Alignment](#array-and-operator-alignment)
- [Indentation Rules](#indentation-rules)
- [Workflow for Fixing PHP Linting Issues](#workflow-for-fixing-php-linting-issues)
- [Quick Command Reference](#quick-command-reference)

---

## Critical Rule: Lint Only Specific Files

**NEVER run linting on the entire codebase.** Always lint specific files, changed files or staged files only.

```bash
# ✅ CORRECT: Check only changed files
composer phpcs

# ✅ CORRECT: Check specific file
composer phpcs -- woodev/path/to/File.php
composer phpcbf -- woodev/path/to/File.php

# ❌ WRONG: Lints entire codebase (slow and unnecessary)
vendor/bin/phpcs .
```

---

## Common PHP Linting Issues & Fixes

### Quick Reference Table

| Issue | Wrong | Correct |
|-------|-------|---------|
| **Translators comment** | Before return | Before function call |
| **File docblock (PSR-12)** | After `declare()` | Before `declare()` |
| **Indentation** | Spaces | Tabs only |
| **Array alignment** | Inconsistent | Align `=>` with context |
| **Equals alignment** | Inconsistent | Match surrounding style |

---

## Translators Comment Placement

Translators comments must be placed **immediately before the translation function call**, not before the return statement.

### Wrong - Comment Before Return

```php
/* translators: %s: Gateway name. */
return sprintf(
    esc_html__( '%s is not supported.', 'woocommerce' ),
    'Gateway'
);
```

### Correct - Comment Before Translation Function

```php
return sprintf(
    /* translators: %s: Gateway name. */
    esc_html__( '%s is not supported.', 'woocommerce' ),
    'Gateway'
);
```

### Multiple Parameters

```php
return sprintf(
    /* translators: 1: Gateway name, 2: Country code. */
    esc_html__( '%1$s is not available in %2$s.', 'woocommerce' ),
    $gateway_name,
    $country_code
);
```

---

## PSR-12 File Header Order

File docblocks must come **before** the `declare()` statement, not after.

### Wrong - Docblock After declare()

```php
<?php
declare( strict_types=1 );

/**
 * File docblock should come BEFORE declare()
 */
```

### Correct - Docblock Before declare()

```php
<?php
/**
 * File docblock should come BEFORE declare()
 */

declare( strict_types=1 );
```

---

## Unused Closure Parameters

### The Solution

```php
// ✅ CORRECT - unset unused parameters
'callback' => function ( string $return_url ) {
    unset( $return_url ); // Avoid parameter not used PHPCS errors.
    return array( 'success' => true );
},
```

### Multiple Unused Parameters

```php
'callback' => function ( $arg1, $arg2, $arg3 ) {
    unset( $arg1, $arg2 ); // Avoid parameter not used PHPCS errors.
    return $arg3;
},
```

### Common Scenarios

- Mock method callbacks in PHPUnit tests
- Array/filter callbacks where signature is fixed
- Interface implementations with unused parameters

---

## Array and Operator Alignment

### Array Arrow Alignment

Align `=>` arrows consistently within each array context:

```php
// Correct - aligned arrows
$options = array(
    'gateway_id'   => 'stripe',
    'enabled'      => true,
    'country_code' => 'US',
);

// Also correct - no alignment for short arrays
$small = array(
    'id' => 123,
    'name' => 'Test',
);
```

### Assignment Operator Alignment

Match the surrounding code style:

```php
// When surrounding code aligns, align:
$gateway_id   = 'stripe';
$enabled      = true;
$country_code = 'US';

// When surrounding code doesn't align, don't align:
$gateway_id = 'stripe';
$enabled = true;
$country_code = 'US';
```

---

## Indentation Rules

**Always use tabs, never spaces, for indentation.**

```php
// ✅ Correct - tabs for indentation
public function process_payment( $order_id ) {
→   $order = wc_get_order( $order_id );
→   →   if ( ! $order ) {
→   →   →   return false;
→   →   }
→   →   return true;
}

// ❌ Wrong - spaces for indentation
public function process_payment( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return false;
    }
    return true;
}
```

---

## Workflow for Fixing PHP Linting Issues

1. **Run linting on changed files:**

   ```bash
   composer phpcs
   ```

2. **Auto-fix what you can:**

   ```bash
   composer phpcbf -- woodev/path/to/file.php
   ```

3. **Review remaining errors**
   - Common issues that require manual fixing:
     - Translators comment placement
     - File docblock order (PSR-12)
     - Unused closure parameters (add `unset()`)

4. **Address remaining issues manually**

5. **Verify the output is clean:**

   ```bash
   composer phpcs -- woodev/path/to/file.php
   ```

6. **Commit with Conventional Commits format**

---

## Quick Command Reference

```bash
# Check all
composer phpcs

# Check specific file
composer phpcs -- woodev/path/to/file.php

# Fix specific file
composer phpcbf -- woodev/path/to/file.php

# Check with error details
vendor/bin/phpcs -s woodev/path/to/file.php
```
