# Utilities Module

The Utilities module provides infrastructure for running tasks outside the HTTP request cycle. This includes background job processing and asynchronous requests.

## Overview

The Utilities module handles:

- Background job queue management
- Asynchronous HTTP requests
- Cron-based health checks
- Memory and time limit monitoring

## Key Classes

| Class | File | Purpose |
| --- | --- | --- |
| `Woodev_Async_Request` | `utilities/class-woodev-async-request.php` | Single async request |
| `Woodev_Background_Job_Handler` | `utilities/class-woodev-background-job-handler.php` | Job queue manager |

## Async Request

### Overview

`Woodev_Async_Request` fires a single background HTTP request to the site itself (loopback request). This is useful for:

- Deferring non-critical operations
- Sending emails after response
- Triggering external API calls

### Creating an Async Request

```php
class My_Async_Request extends Woodev_Async_Request {

    /**
     * Unique action name
     */
    protected $action = 'my_plugin_async';

    /**
     * Handle async request
     */
    protected function handle() {
        // Get data passed to request
        $data = $this->data;

        // Process async task
        if ( isset( $data['order_id'] ) ) {
            $this->process_order( absint( $data['order_id'] ) );
        }
    }

    /**
     * Process order
     */
    private function process_order( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Your async processing logic
        do_action( 'my_plugin_process_order', $order );
    }
}
```

### Dispatching Request

```php
$async = new My_Async_Request();

// Set data
$async->data( [
    'order_id' => $order->get_id(),
    'action'   => 'process',
] );

// Dispatch (fire-and-forget)
$async->dispatch();

// Continue with response immediately
```

### Data Flow

```php
// 1. Set data before dispatch
$async->data( [ 'key' => 'value' ] );

// 2. Dispatch sends POST to admin-ajax.php
$async->dispatch();

// 3. In handle(), access via $this->data
protected function handle() {
    $value = $this->data['key'] ?? '';
}
```

### Error Handling

```php
class My_Async_Request extends Woodev_Async_Request {

    protected function handle() {
        try {
            $data = $this->data;

            if ( empty( $data['item_id'] ) ) {
                throw new Exception( 'Missing item_id' );
            }

            $this->process_item( $data['item_id'] );

        } catch ( Exception $e ) {
            // Log error
            wc_get_logger()->error( $e->getMessage(), [
                'source' => 'my-plugin-async',
                'data'   => $this->data,
            ] );
        }
    }
}
```

## Background Job Handler

### Overview

`Woodev_Background_Job_Handler` provides a full-featured job queue:

- Jobs stored in `wp_options` as JSON
- Jobs processed one at a time
- Automatic retry on failure
- WP-Cron health check
- Memory and time limit monitoring

### Job Lifecycle

```text
1. Create Job → 2. Queue Job → 3. Dispatch → 4. Process → 5. Complete
                      ↑              ↓
                      └──── Retry ───┘
```

### Creating a Job Handler

```php
class My_Job_Handler extends Woodev_Background_Job_Handler {

    /**
     * Unique prefix for option keys
     */
    protected $prefix = 'my_plugin';

    /**
     * Action name
     */
    protected $action = 'process';

    /**
     * Process single item
     */
    protected function process_item( $item, $job ) {
        $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

        if ( ! $product_id ) {
            return null;  // Skip invalid items
        }

        // Import product
        $result = $this->import_product( $product_id );

        if ( is_wp_error( $result ) ) {
            throw new Exception( $result->get_error_message() );
        }

        return null;  // Item complete
    }

    /**
     * Import product
     */
    private function import_product( int $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return new WP_Error( 'not_found', 'Product not found' );
        }

        // Update from external source
        $external_data = $this->fetch_external_data( $product_id );

        $product->set_name( $external_data['name'] );
        $product->set_regular_price( $external_data['price'] );
        $product->save();

        return true;
    }

    /**
     * Called when all jobs complete
     */
    protected function complete() {
        parent::complete();

        // Send notification
        wp_mail(
            get_option( 'admin_email' ),
            'Import Complete',
            'All products imported successfully.'
        );

        // Log completion
        wc_get_logger()->info( 'Product import completed', [
            'source' => 'my-plugin-import',
        ] );
    }
}
```

### Creating and Queuing Jobs

```php
// Create handler
$handler = new My_Job_Handler();

// Create job
$job = $handler->create_job( [
    'source' => 'csv_upload',
    'file_id' => $upload_id,
    'data' => [
        [ 'product_id' => 101 ],
        [ 'product_id' => 102 ],
        [ 'product_id' => 103 ],
    ],
] );

// Dispatch to start processing
$handler->dispatch();
```

### Job Data Structure

```php
$job = (object) [
    'id'           => 123,
    'status'       => 'queued',  // queued | processing | completed | failed
    'source'       => 'csv_upload',
    'data'         => [ /* items to process */ ],
    'date_created' => '2024-01-01 12:00:00',
    'date_modified'=> '2024-01-01 12:05:00',
];
```

### Checking Job Status

```php
$handler = new My_Job_Handler();

// Get job by ID
$job = $handler->get_job( $job_id );

if ( ! $job ) {
    echo 'Job not found';
    return;
}

echo 'Status: ' . $job->status;
echo 'Created: ' . $job->date_created;
echo 'Items remaining: ' . count( $job->data );

// Check if complete
if ( 'completed' === $job->status ) {
    echo 'Import finished!';
}

// Check if failed
if ( 'failed' === $job->status ) {
    echo 'Import failed. Check logs.';
}
```

### Listing Jobs

```php
$handler = new My_Job_Handler();

// Get all jobs
$all_jobs = $handler->get_jobs();

// Get jobs by status
$queued_jobs = $handler->get_jobs( [ 'status' => 'queued' ] );
$processing_jobs = $handler->get_jobs( [ 'status' => 'processing' ] );
$completed_jobs = $handler->get_jobs( [ 'status' => 'completed' ] );
$failed_jobs = $handler->get_jobs( [ 'status' => 'failed' ] );

// Get recent jobs
$recent = $handler->get_jobs( [
    'order'   => 'DESC',
    'orderby' => 'date_created',
    'limit'   => 10,
] );

// Count queue
$queue_count = count( $handler->get_jobs( [ 'status' => 'queued' ] ) );
echo "Jobs in queue: {$queue_count}";
```

### Managing Jobs

```php
$handler = new My_Job_Handler();

// Complete job early
$handler->complete_job( $job );

// Fail job with reason
$handler->fail_job( $job, 'External API unavailable' );

// Delete job
$handler->delete_job( $job );

// Update job data
$job->data[] = [ 'product_id' => 104 ];
$handler->update_job( $job );
```

### Custom Job ID

```php
$handler = new My_Job_Handler();

// Create job with specific ID
$job = $handler->create_job( [
    'source' => 'api_sync',
    'data'   => $items,
], 'daily_sync_' . date( 'Y-m-d' ) );

// Retrieve by known ID
$job = $handler->get_job( 'daily_sync_2024-01-01' );
```

## Advanced Features

### Memory and Time Limits

The handler automatically monitors resources:

```php
class My_Job_Handler extends Woodev_Background_Job_Handler {

    /**
     * Check memory limit
     */
    protected function memory_exceeded( $memory_limit ) {
        wc_get_logger()->warning( "Memory limit exceeded: {$memory_limit}", [
            'source' => 'my-plugin-jobs',
        ] );

        return true;  // Stop processing
    }

    /**
     * Check time limit
     */
    protected function time_exceeded() {
        $time_limit = 30;  // seconds
        $elapsed = time() - $this->start_time;

        return $elapsed >= $time_limit;
    }
}
```

### Custom Cron Intervals

```php
class My_Job_Handler extends Woodev_Background_Job_Handler {

    /**
     * Schedule cron healthcheck
     */
    public function schedule_cron_healthcheck() {
        return [
            'interval' => 60,  // 1 minute instead of 5
            'display'  => 'Every 1 minute',
        ];
    }
}
```

### Debug Tools

```php
class My_Job_Handler extends Woodev_Background_Job_Handler {

    /**
     * Add debug tool
     */
    protected function add_debug_tool( $tools ) {
        $tools['my_plugin_jobs'] = [
            'name'     => __( 'My Plugin Jobs', 'my-plugin' ),
            'button'   => __( 'View Jobs', 'my-plugin' ),
            'callback' => [ $this, 'run_debug_tool' ],
        ];

        return $tools;
    }

    /**
     * Run debug tool
     */
    protected function run_debug_tool() {
        $jobs = $this->get_jobs();

        echo '<h2>Job Queue</h2>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>ID</th><th>Status</th><th>Items</th><th>Created</th></tr></thead>';
        echo '<tbody>';

        foreach ( $jobs as $job ) {
            echo '<tr>';
            echo '<td>' . esc_html( $job->id ) . '</td>';
            echo '<td>' . esc_html( $job->status ) . '</td>';
            echo '<td>' . count( $job->data ) . '</td>';
            echo '<td>' . esc_html( $job->date_created ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
```

### Test Connection

```php
$handler = new My_Job_Handler();

// Test background processing
$result = $handler->test_connection();

if ( is_wp_error( $result ) ) {
    wc_get_logger()->error( 'Background processing not working: ' . $result->get_error_message() );
} else {
    wc_get_logger()->info( 'Background processing is working' );
}
```

## Practical Examples

### Example 1: Bulk Order Export

```php
class Order_Export_Handler extends Woodev_Background_Job_Handler {

    protected $prefix = 'my_plugin';
    protected $action = 'export_orders';

    protected function process_item( $item, $job ) {
        $order_id = absint( $item['order_id'] );
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return null;
        }

        // Export to CSV
        $this->export_order_to_csv( $order, $job->export_file );

        return null;
    }

    private function export_order_to_csv( WC_Order $order, string $file ) {
        $line = implode( ',', [
            $order->get_id(),
            $order->get_date_created()->date( 'Y-m-d H:i:s' ),
            $order->get_billing_email(),
            $order->get_total(),
        ] );

        file_put_contents( $file, $line . PHP_EOL, FILE_APPEND );
    }

    protected function complete() {
        parent::complete();

        // Email exported file
        $file = $this->current_job->export_file;

        wp_mail(
            get_option( 'admin_email' ),
            'Order Export Complete',
            'Your export is attached.',
            '',
            [ $file ]
        );
    }
}

// Usage
$export_handler = new Order_Export_Handler();

$orders = wc_get_orders( [ 'limit' => 1000 ] );

$items = array_map( function( $order ) {
    return [ 'order_id' => $order->get_id() ];
}, $orders );

$job = $export_handler->create_job( [
    'export_file' => wp_upload_dir()['basedir'] . '/exports/orders.csv',
    'data' => $items,
] );

$export_handler->dispatch();
```

### Example 2: Product Sync

```php
class Product_Sync_Handler extends Woodev_Background_Job_Handler {

    protected $prefix = 'my_plugin';
    protected $action = 'sync_products';

    protected function process_item( $item, $job ) {
        $product_id = absint( $item['product_id'] );
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return null;
        }

        // Fetch from API
        $api = new External_API();
        $external_data = $api->get_product( $product_id );

        if ( is_wp_error( $external_data ) ) {
            throw new Exception( $external_data->get_error_message() );
        }

        // Update product
        $product->set_name( $external_data['name'] );
        $product->set_regular_price( $external_data['price'] );
        $product->set_stock_quantity( $external_data['stock'] );
        $product->save();

        // Log sync
        $product->update_meta_data( '_last_synced', current_time( 'mysql' ) );

        return null;
    }

    protected function complete() {
        parent::complete();
        update_option( 'my_plugin_last_product_sync', current_time( 'mysql' ) );
    }
}

// Schedule daily sync
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'my_plugin_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'my_plugin_daily_sync' );
    }
} );

add_action( 'my_plugin_daily_sync', function() {
    $sync_handler = new Product_Sync_Handler();

    $products = wc_get_products( [ 'limit' => -1 ] );

    $items = array_map( function( $product ) {
        return [ 'product_id' => $product->get_id() ];
    }, $products );

    $job = $sync_handler->create_job( [ 'data' => $items ] );
    $sync_handler->dispatch();
} );
```

### Example 3: Image Processing

```php
class Image_Processor extends Woodev_Background_Job_Handler {

    protected $prefix = 'my_plugin';
    protected $action = 'process_images';

    protected function process_item( $item, $job ) {
        $attachment_id = absint( $item['attachment_id'] );
        $file_path = get_attached_file( $attachment_id );

        if ( ! file_exists( $file_path ) ) {
            return null;
        }

        // Generate additional sizes
        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // Optimize image
        $this->optimize_image( $file_path );

        return null;
    }

    private function optimize_image( string $file_path ) {
        if ( function_exists( 'wp_optimize_image' ) ) {
            wp_optimize_image( $file_path );
        }
    }
}

// Queue after upload
add_action( 'add_attachment', function( $attachment_id ) {
    $processor = new Image_Processor();

    $job = $processor->create_job( [
        'data' => [ [ 'attachment_id' => $attachment_id ] ],
    ] );

    $processor->dispatch();
} );
```

### Example 4: Webhook Delivery

```php
class Webhook_Delivery extends Woodev_Background_Job_Handler {

    protected $prefix = 'my_plugin';
    protected $action = 'deliver_webhook';

    protected function process_item( $item, $job ) {
        $webhook_url = $item['webhook_url'];
        $payload = $item['payload'];

        $response = wp_remote_post( $webhook_url, [
            'body' => json_encode( $payload ),
            'headers' => [ 'Content-Type' => 'application/json' ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( "Webhook returned {$code}" );
        }

        return null;
    }
}

// Usage
function deliver_webhook( string $url, array $data ) {
    $handler = new Webhook_Delivery();

    $job = $handler->create_job( [
        'data' => [ [ 'webhook_url' => $url, 'payload' => $data ] ],
    ] );

    $handler->dispatch();
}
```

## Best Practices

### 1. Validate Input

```php
protected function process_item( $item, $job ) {
    if ( empty( $item['id'] ) ) {
        return null;  // Skip invalid
    }

    $id = absint( $item['id'] );
    $text = sanitize_text_field( $item['text'] );

    // Process...
}
```

### 2. Use Logging

```php
protected function process_item( $item, $job ) {
    wc_get_logger()->debug( "Processing item: {$item['id']}", [
        'source' => 'my-plugin-jobs',
        'job_id' => $job->id,
    ] );

    try {
        $this->risky_operation( $item );
    } catch ( Exception $e ) {
        wc_get_logger()->error( "Item {$item['id']} failed: {$e->getMessage()}", [
            'source' => 'my-plugin-jobs',
        ] );
        throw $e;
    }
}
```

### 3. Implement Retry Logic

```php
protected function process_item( $item, $job ) {
    $retry_count = $item['retry_count'] ?? 0;

    try {
        $this->risky_operation( $item );
    } catch ( Exception $e ) {
        if ( $retry_count < 3 ) {
            $item['retry_count'] = $retry_count + 1;
            return $item;  // Re-queue
        }

        throw $e;  // Fail after 3 retries
    }

    return null;
}
```

### 4. Clean Up Old Jobs

```php
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'my_plugin_cleanup_jobs' ) ) {
        wp_schedule_event( time(), 'daily', 'my_plugin_cleanup_jobs' );
    }
} );

add_action( 'my_plugin_cleanup_jobs', function() {
    $handler = new My_Job_Handler();
    $jobs = $handler->get_jobs( [ 'status' => 'completed' ] );

    foreach ( $jobs as $job ) {
        $created = strtotime( $job->date_created );

        if ( time() - $created > WEEK_IN_SECONDS ) {
            $handler->delete_job( $job );
        }
    }
} );
```

### 5. Prevent Duplicate Jobs

```php
function create_unique_job( $handler, $job_type, $data ) {
    // Check for existing job
    $existing = $handler->get_jobs( [
        'status' => 'queued',
        'source' => $job_type,
    ] );

    if ( ! empty( $existing ) ) {
        wc_get_logger()->info( "Job already queued: {$job_type}", [
            'source' => 'my-plugin-jobs',
        ] );
        return null;
    }

    return $handler->create_job( [
        'source' => $job_type,
        'data' => $data,
    ] );
}
```

## Troubleshooting

### Jobs Not Processing

1. **Check cron:**

   ```php
   wp_schedule_event( time(), 'daily', 'test_cron' );
   add_action( 'test_cron', function() {
       error_log( 'Cron working' );
   } );
   ```

2. **Verify loopback:**

   ```php
   $response = wp_remote_post( admin_url( 'admin-ajax.php' ), [
       'timeout' => 1,
       'blocking' => false,
   ] );

   if ( is_wp_error( $response ) ) {
       error_log( 'Loopback failed: ' . $response->get_error_message() );
   }
   ```

3. **Check PHP error logs**

### Jobs Stuck in Processing

```php
// Manually unlock
$handler = new My_Job_Handler();
delete_transient( $handler->get_identifier() . '_process_lock' );

// Or use healthcheck
$handler->handle_cron_healthcheck();
```

### Memory Exhaustion

```php
add_filter( 'woodev_background_job_memory_limit', function() {
    return '512M';
} );
```

## Related Documentation

- [Core Framework](core-framework.md) — Plugin base class
- [API Module](api-module.md) — HTTP client
- [Helpers](helpers.md) — Utility functions

---

*For more information, see [README.md](README.md).*
