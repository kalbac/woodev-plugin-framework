# Gotcha: [php/dependency-function-check-bug] — get_missing_php_functions() uses extension_loaded instead of function_exists
> Tags: php, dependencies, bug, functions | Session: s2

## What happens
`Woodev_Plugin_Dependencies::get_missing_php_functions()` incorrectly uses `extension_loaded()` to check whether a PHP **function** exists. This means it checks for a PHP extension with the same name as the function, not the function itself. The method will never correctly detect missing functions (unless the function name happens to match an extension name, which is rare).

## Root cause
In `class-woodev-plugin-dependencies.php` line 374, the loop over `$this->get_php_functions()` uses `extension_loaded( $function )` instead of `function_exists( $function )`. This is a copy-paste error from `get_missing_php_extensions()` (line 344), which correctly uses `extension_loaded()` to check for PHP extensions.

## Fix

❌ **Wrong (current code):**
```php
public function get_missing_php_functions() {
    $missing_functions = [];
    foreach ( $this->get_php_functions() as $function ) {
        if ( ! extension_loaded( $function ) ) {  // ❌ Checks for extension, not function!
            $missing_functions[] = $function;
        }
    }
    return $missing_functions;
}
```

✅ **Correct:**
```php
public function get_missing_php_functions() {
    $missing_functions = [];
    foreach ( $this->get_php_functions() as $function ) {
        if ( ! function_exists( $function ) ) {  // ✅ Checks if function exists
            $missing_functions[] = $function;
        }
    }
    return $missing_functions;
}
```

## Related
- [class-woodev-plugin-dependencies.php](../../woodev/class-woodev-plugin-dependencies.php) — Lines 368–380: the buggy method, compare with lines 338–349 for correct `extension_loaded` usage
