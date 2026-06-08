---
id: virtual-box-null-best-inf-overflow
namespace: box-packer
tags: [box-packer, php, algorithm, null-safety]
session: 2026-06-09
---

# virtual-box-null-best-inf-overflow

Initializing `$best = null; $best_volume = PHP_FLOAT_MAX` in `calculate_virtual_box_dimensions()` causes a null dereference when all candidate volumes overflow to `INF`.

## The bug

```php
$best        = null;
$best_volume = PHP_FLOAT_MAX;

foreach ( $candidates as $dims ) {
    $volume = $dims[0] * $dims[1] * $dims[2];
    if ( $volume < $best_volume ) {  // INF < PHP_FLOAT_MAX = FALSE
        $best        = $dims;
    }
}

return ['length' => $best[0], ...];  // null dereference
```

If any item dimension approaches `PHP_FLOAT_MAX`, the product overflows to `INF`. `INF < PHP_FLOAT_MAX` evaluates to `false` in PHP, so `$best` remains `null`. Dereferencing `$best[0]` produces a fatal error.

## The fix

Initialize `$best = $candidates[0]` instead of `null`. Even if all volumes are INF (all candidates are equally "worst"), `$best` holds a valid candidate and the return is safe. This is semantically correct: when all options are equally unconstrained, the first option is a valid choice.

```php
$best        = $candidates[0];
$best_volume = PHP_FLOAT_MAX;
```

## Related

- [[virtual-box-rsort-axis-alignment]] — the other S2-P2 critic catch, same function
