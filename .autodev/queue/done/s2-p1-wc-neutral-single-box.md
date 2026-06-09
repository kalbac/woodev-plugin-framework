---
id: s2-p1-wc-neutral-single-box
title: Remove wc_list_pluck() from Packer_Single_Box — WC-neutral core
phase: S2 Box-Packer
type: fix
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/box-packer/class-packer-single-box.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green
  - no wc_list_pluck call in class-packer-single-box.php
  - PHPStan 0 errors
---

# Task

Spec §P1 — `Woodev_Packer_Single_Box::get_items_dimensions()` calls `wc_list_pluck()` (a
WooCommerce function). If WooCommerce is not active, `pack()` fatals. The fix is a pure
drop-in replacement using `array_map()` with an arrow function — no behaviour change,
no contract zones touched.

## What to change

In `woodev/box-packer/class-packer-single-box.php`, method `get_items_dimensions()`:

Replace:
```php
return array(
    'height' => wc_list_pluck( $this->items, 'get_height' ),
    'length' => wc_list_pluck( $this->items, 'get_length' ),
    'width'  => wc_list_pluck( $this->items, 'get_width' ),
);
```

With:
```php
return array(
    'height' => array_map( fn( Woodev_Box_Packer_Item $item ) => $item->get_height(), $this->items ),
    'length' => array_map( fn( Woodev_Box_Packer_Item $item ) => $item->get_length(), $this->items ),
    'width'  => array_map( fn( Woodev_Box_Packer_Item $item ) => $item->get_width(), $this->items ),
);
```

Arrow function syntax is PHP 7.4+ — within the project minimum.

## Spec reference

`docs-internal/platform-v2-s2-boxpacker-spec.md §P1`

<!-- committed: 031e9e9 -->
