# Box Packer Module

The Box Packer module provides algorithms and abstractions for packing products into boxes. It is primarily used by shipping plugins to calculate optimal box selection and shipping rates.

## Overview

The Box Packer module handles:

- Item and box definitions
- Packing algorithms
- Volume and weight calculations
- Multi-package support
- Dimensional weight calculations

## Key Classes and Interfaces

### Interfaces

| Interface | File | Purpose |
| --- | --- | --- |
| `Woodev_Box_Packer_Item` | `interfaces/interface-packer-item.php` | Defines a packable item |
| `Woodev_Box_Packer_Box` | `interfaces/interface-packer-box.php` | Defines a box for packing |
| `Woodev_Packer_Interface` | `interfaces/interface-packer.php` | Defines a packing algorithm |

### Implementation Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Packer_Item_Implementation` | `class-item-implementation.php` | Standard item implementation |
| `Woodev_Packer_Box_Implementation` | `class-box-implementation.php` | Standard box implementation |
| `Woodev_Box_Packer_Packed_Box` | `class-packed-box.php` | Represents a packed box |
| `Woodev_Packer` | `abstract-class-packer.php` | Abstract base for packers |
| `Woodev_Packer_Single_Box` | `class-packer-single-box.php` | Single box strategy |
| `Woodev_Box_Packer_Packages_Weight` | `class-packages-weight.php` | Weight calculator |

## Item Interface

### Woodev_Box_Packer_Item

```php
interface Woodev_Box_Packer_Item {
    public function get_name(): string;
    public function get_volume(): float;
    public function get_height(): float;
    public function get_width(): float;
    public function get_length(): float;
    public function get_weight(): float;
    public function get_value(): float;
    public function get_internal_data();
}
```

### Using Item Implementation

```php
use Woodev\Framework\BoxPacker\Woodev_Packer_Item_Implementation;

$item = new Woodev_Packer_Item_Implementation(
    30.0,  // length (cm)
    20.0,  // width (cm)
    10.0,  // height (cm)
    1.5,   // weight (kg)
    500.0, // value (currency)
    [      // internal data
        'product_id' => 123,
        'name'       => 'T-Shirt',
    ]
);

// Access properties
echo $item->get_name();      // 'T-Shirt'
echo $item->get_volume();    // 6000 (cm³)
echo $item->get_weight();    // 1.5 (kg)
echo $item->get_length();    // 30 (largest dimension)
echo $item->get_width();     // 20
echo $item->get_height();    // 10 (smallest dimension)
```

**Note:** Dimensions are automatically sorted so length >= width >= height.

### From WooCommerce Product

```php
$product = wc_get_product( 123 );
$dimensions = $product->get_dimensions();

$item = new Woodev_Packer_Item_Implementation(
    (float) $dimensions['length'],
    (float) $dimensions['width'],
    (float) $dimensions['height'],
    (float) $product->get_weight(),
    (float) $product->get_price(),
    [
        'product_id' => $product->get_id(),
        'name'       => $product->get_name(),
    ]
);

$item->set_product( $product );
```

## Box Interface

### Woodev_Box_Packer_Box

```php
interface Woodev_Box_Packer_Box extends Woodev_Box_Packer_Item {
    public function get_max_weight(): ?float;
    public function get_name(): string;
    public function get_unique_id(): string;
}
```

### Using Box Implementation

```php
use Woodev\Framework\BoxPacker\Woodev_Packer_Box_Implementation;

$box = new Woodev_Packer_Box_Implementation(
    50.0,   // length (cm)
    40.0,   // width (cm)
    30.0,   // height (cm)
    0.5,    // box weight (kg)
    20.0,   // max weight (kg) - total including box
    'BOX-001', // unique ID
    'Large Box'  // display name
);

echo $box->get_unique_id();   // 'BOX-001'
echo $box->get_name();        // 'Large Box'
echo $box->get_max_weight();  // 20.0 (kg)
echo $box->get_weight();      // 0.5 (kg) - box weight only
echo $box->get_volume();      // 60000 (cm³)
```

## Packed Box

### Woodev_Box_Packer_Packed_Box

Represents a box with items packed into it:

```php
use Woodev\Framework\BoxPacker\Woodev_Box_Packer_Packed_Box;

$box = new Woodev_Packer_Box_Implementation( /* ... */ );
$items = [ $item1, $item2, $item3 ];

$packed_box = new Woodev_Box_Packer_Packed_Box( $box, $items );

// Get results
$packed_items = $packed_box->get_packed_items();
$nofit_items = $packed_box->get_nofit_items();
$weight = $packed_box->get_packed_weight();
$value = $packed_box->get_packed_value();
$success = $packed_box->get_success_percent();
```

### Packing Success Calculation

The success percentage is calculated based on:

1. **Weight ratio**: Packed weight / Total weight
2. **Volume ratio**: Packed volume / Total volume

Final percentage: `weight_ratio × volume_ratio × 100`

## Packing Algorithms

### Woodev_Packer (Abstract Base)

```php
abstract class Woodev_Packer implements Woodev_Packer_Interface {
    
    protected $boxes;   // Available boxes
    protected $items;   // Items to pack
    protected $packages; // Packed boxes
    
    public function add_item( Woodev_Box_Packer_Item $item ): void
    public function add_box( Woodev_Box_Packer_Box $box ): void
    public function get_packages(): array
    public function get_items_cannot_pack(): array
    abstract public function pack();
}
```

### Single Box Packer

Packs all items into one virtual box:

```php
use Woodev\Framework\BoxPacker\Woodev_Packer_Single_Box;

$packer = new Woodev_Packer_Single_Box( 'Single Package' );

// Add items
foreach ( $order_items as $order_item ) {
    $product = $order_item->get_product();
    $dimensions = $product->get_dimensions();
    
    for ( $i = 0; $i < $order_item->get_quantity(); $i++ ) {
        $packer->add_item( new Woodev_Packer_Item_Implementation(
            (float) $dimensions['length'],
            (float) $dimensions['width'],
            (float) $dimensions['height'],
            (float) $product->get_weight()
        ) );
    }
}

// Pack
$packer->pack();

// Get results
$packages = $packer->get_packages();
$unpacked = $packer->get_items_cannot_pack();

// First package (only one for single box)
$package = $packages[0];
echo "Dimensions: " . 
     $package->get_box()->get_length() . "x" .
     $package->get_box()->get_width() . "x" .
     $package->get_box()->get_height() . " cm";
echo "Weight: " . $package->get_packed_weight() . " kg";
```

### How Single Box Works

1. Finds the greatest dimension among all items
2. Sets that as the package's primary dimension
3. Sums the other two dimensions across all items

This creates a box that can contain all items laid out side by side.

## Package Weight Calculator

```php
use Woodev\Framework\BoxPacker\Woodev_Box_Packer_Packages_Weight;

$packages = [ $package1, $package2, $package3 ];
$weight_calc = new Woodev_Box_Packer_Packages_Weight( $packages );

$total_weight = $weight_calc->get_total_weight();
echo "Total shipping weight: {$total_weight} kg";
```

## Practical Examples

### Example 1: Order Packing

```php
function pack_order( WC_Order $order ): array {
    $packer = new Woodev_Packer_Single_Box();
    
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        
        if ( ! $product || $product->is_virtual() ) {
            continue;
        }
        
        $dimensions = $product->get_dimensions();
        
        for ( $i = 0; $i < $item->get_quantity(); $i++ ) {
            $packer->add_item( new Woodev_Packer_Item_Implementation(
                (float) $dimensions['length'],
                (float) $dimensions['width'],
                (float) $dimensions['height'],
                (float) $product->get_weight(),
                (float) $order->get_item_total( $item ),
                [ 'product_id' => $product->get_id() ]
            ) );
        }
    }
    
    $packer->pack();
    
    return $packer->get_packages();
}

// Usage
$packages = pack_order( $order );

foreach ( $packages as $i => $package ) {
    echo "Package " . ( $i + 1 ) . ":\n";
    echo "  Dimensions: " . 
         $package->get_box()->get_length() . "x" .
         $package->get_box()->get_width() . "x" .
         $package->get_box()->get_height() . " cm\n";
    echo "  Weight: " . $package->get_packed_weight() . " kg\n";
    echo "  Items: " . count( $package->get_packed_items() ) . "\n";
}
```

### Example 2: Dimensional Weight

```php
function calculate_dimensional_weight( array $packages ): float {
    $dimensional_factor = 5000;  // Standard divisor for cm/kg
    $total_dim_weight = 0;
    
    foreach ( $packages as $package ) {
        $box = $package->get_box();
        
        $dim_weight = (
            $box->get_length() * 
            $box->get_width() * 
            $box->get_height()
        ) / $dimensional_factor;
        
        $actual_weight = $package->get_packed_weight();
        
        // Use the greater of dimensional or actual weight
        $total_dim_weight += max( $dim_weight, $actual_weight );
    }
    
    return $total_dim_weight;
}

// Usage
$packages = pack_order( $order );
$billable_weight = calculate_dimensional_weight( $packages );
echo "Billable weight: {$billable_weight} kg";
```

### Example 3: Multiple Box Types

```php
class Multi_Box_Packer extends Woodev_Packer {
    
    public function pack() {
        $this->packages = [];
        $unpacked_items = $this->items;
        
        // Sort boxes by volume (largest first)
        usort( $this->boxes, function( $a, $b ) {
            return $b->get_volume() <=> $a->get_volume();
        } );
        
        while ( ! empty( $unpacked_items ) ) {
            $best_box = null;
            $best_fit = 0;
            
            foreach ( $this->boxes as $box ) {
                $test_box = new Woodev_Box_Packer_Packed_Box( $box, $unpacked_items );
                $fit = $test_box->get_success_percent();
                
                if ( $fit > $best_fit && empty( $test_box->get_nofit_items() ) ) {
                    $best_box = $box;
                    $best_fit = $fit;
                }
            }
            
            if ( ! $best_box ) {
                break;  // No box fits remaining items
            }
            
            // Create packed box
            $packed = new Woodev_Box_Packer_Packed_Box( $best_box, $unpacked_items );
            $this->packages[] = $packed;
            
            // Remove packed items
            $packed_item_ids = array_map(
                'spl_object_id',
                $packed->get_packed_items()
            );
            
            $unpacked_items = array_filter( $unpacked_items, function( $item ) use ( $packed_item_ids ) {
                return ! in_array( spl_object_id( $item ), $packed_item_ids );
            } );
        }
        
        $this->items_cannot_pack = $unpacked_items;
    }
}

// Usage
$packer = new Multi_Box_Packer();

// Add boxes
$packer->add_box( new Woodev_Packer_Box_Implementation(
    30, 20, 15, 0.3, 10, 'small', 'Small Box'
) );
$packer->add_box( new Woodev_Packer_Box_Implementation(
    50, 40, 30, 0.8, 20, 'medium', 'Medium Box'
) );
$packer->add_box( new Woodev_Packer_Box_Implementation(
    80, 60, 50, 1.5, 30, 'large', 'Large Box'
) );

// Add items
foreach ( $order_items as $item ) {
    $packer->add_item( $item );
}

$packer->pack();

$packages = $packer->get_packages();
$unpacked = $packer->get_items_cannot_pack();
```

### Example 4: Shipping Rate Calculation

```php
function calculate_shipping_rate( WC_Order $order ): array {
    $packages = pack_order( $order );
    $rates = [];
    
    foreach ( $packages as $i => $package ) {
        $box = $package->get_box();
        $weight = $package->get_packed_weight();
        
        // Calculate rate for this package
        $rate = get_carrier_rate(
            $box->get_length(),
            $box->get_width(),
            $box->get_height(),
            $weight,
            $order->get_shipping_postcode()
        );
        
        $rates[] = [
            'package' => $i + 1,
            'dimensions' => sprintf(
                '%dx%dx%d cm',
                $box->get_length(),
                $box->get_width(),
                $box->get_height()
            ),
            'weight' => sprintf( '%.2f kg', $weight ),
            'rate' => $rate,
        ];
    }
    
    return [
        'total_rate' => array_sum( array_column( $rates, 'rate' ) ),
        'packages' => $rates,
    ];
}
```

## Custom Packers

### Volume-Optimized Packer

```php
class Volume_Optimized_Packer extends Woodev_Packer {
    
    public function pack() {
        $this->packages = [];
        
        // Sort items by volume (largest first)
        usort( $this->items, function( $a, $b ) {
            return $b->get_volume() <=> $a->get_volume();
        } );
        
        foreach ( $this->boxes as $box ) {
            $packed_box = new Woodev_Box_Packer_Packed_Box( $box, $this->items );
            
            if ( empty( $packed_box->get_nofit_items() ) ) {
                $this->packages[] = $packed_box;
                $this->items = [];
                break;
            }
            
            // Use partially filled box if good fit
            if ( $packed_box->get_success_percent() > 80 ) {
                $this->packages[] = $packed_box;
                $this->items = $packed_box->get_nofit_items();
            }
        }
        
        $this->items_cannot_pack = $this->items;
    }
}
```

## Best Practices

### 1. Validate Dimensions

```php
function create_item( WC_Product $product ): ?Woodev_Packer_Item_Implementation {
    $dimensions = $product->get_dimensions();
    
    if ( empty( $dimensions['length'] ) || 
         empty( $dimensions['width'] ) || 
         empty( $dimensions['height'] ) ) {
        return null;
    }
    
    return new Woodev_Packer_Item_Implementation(
        (float) $dimensions['length'],
        (float) $dimensions['width'],
        (float) $dimensions['height'],
        (float) $product->get_weight()
    );
}
```

### 2. Handle Virtual Products

```php
foreach ( $order->get_items() as $item ) {
    $product = $item->get_product();
    
    // Skip virtual products
    if ( $product->is_virtual() || $product->is_downloadable() ) {
        continue;
    }
    
    $packer->add_item( create_item( $product ) );
}
```

### 3. Consider Box Weight

```php
$box = new Woodev_Packer_Box_Implementation(
    50, 40, 30,
    0.8,  // Box weight - important for accurate shipping
    20,   // Max total weight
    'medium',
    'Medium Box'
);
```

### 4. Cache Box Configurations

```php
function get_available_boxes(): array {
    $cache_key = 'shipping_boxes';
    $boxes = get_transient( $cache_key );
    
    if ( false === $boxes ) {
        $boxes = [
            new Woodev_Packer_Box_Implementation( 30, 20, 15, 0.3, 10, 'small', 'Small' ),
            new Woodev_Packer_Box_Implementation( 50, 40, 30, 0.8, 20, 'medium', 'Medium' ),
            new Woodev_Packer_Box_Implementation( 80, 60, 50, 1.5, 30, 'large', 'Large' ),
        ];
        set_transient( $cache_key, $boxes, WEEK_IN_SECONDS );
    }
    
    return $boxes;
}
```

### 5. Log Packing Results

```php
function log_packing_result( array $packages, WC_Order $order ) {
    $log_message = "Order #{$order->get_id()} packing:\n";
    
    foreach ( $packages as $i => $package ) {
        $box = $package->get_box();
        $log_message .= sprintf(
            "  Package %d: %dx%dx%d cm, %.2f kg, %d items\n",
            $i + 1,
            $box->get_length(),
            $box->get_width(),
            $box->get_height(),
            $package->get_packed_weight(),
            count( $package->get_packed_items() )
        );
    }
    
    wc_get_logger()->debug( $log_message, [ 'source' => 'shipping-packing' ] );
}
```

## Troubleshooting

### Items Not Packing

If items are in `get_items_cannot_pack()`:

1. Check item dimensions are smaller than box dimensions
2. Verify item weight doesn't exceed box max weight
3. Ensure dimensions use the same unit (cm, kg)

### Incorrect Volume

Dimensions are automatically sorted:

```php
$item = new Woodev_Packer_Item_Implementation(
    10, 30, 20,  // Input: 10x30x20
    1.0
);

echo $item->get_length();  // 30 (largest)
echo $item->get_width();   // 20 (middle)
echo $item->get_height();  // 10 (smallest)
```

## Related Documentation

- [Shipping Method](shipping-method.md) — Shipping plugins
- [API Module](api-module.md) — HTTP client for rate APIs

---

*For more information, see [README.md](README.md).*
