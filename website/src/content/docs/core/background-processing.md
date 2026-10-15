---
title: Background processing
description: Learn how to run long or deferred work outside the shopper's request with background jobs, and when to use Action Scheduler instead.
---

In this page you will learn which tool to pick for work that must not run inside the visitor's request, how to write a background job with progress, how to drive jobs from an admin screen, and how to tune the limits.

## Choose the right tool

Never call a slow remote service while a shopper waits for a page, and never rely on a request that a visitor may close. Move the work out of the request. The framework gives you three classes for that, and WooCommerce ships a fourth tool.

| You want to | Use |
|---|---|
| Run one task later, on a schedule, or again after a delay. Each task is independent: retry an export for one order, sync nightly. | **Action Scheduler** (part of WooCommerce) |
| Work through a long list of items as one job, remember how far it got, and resume it. | `Woodev_Background_Job_Handler` |
| Do the same, but let an administrator drive it from a screen with a progress bar, or run on a host that blocks background requests. | `Woodev_Job_Batch_Handler` on top of a job handler |
| Run a method in a separate request right now, without waiting for it. | `Woodev_Async_Request` |

When in doubt, prefer Action Scheduler for single deferred tasks, retries and recurring work. It is already installed with WooCommerce, shows pending and failed actions under **WooCommerce → Status → Scheduled Actions**, and needs no handler class. Use a job handler when the unit of work is *one job over many items* and you want progress and a stored result.

## Action Scheduler

Schedule an action and handle it with an ordinary hook. Guard the call so the plugin does not break where Action Scheduler is missing:

```php
<?php
if ( function_exists( 'as_schedule_single_action' ) ) {
	as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'my_carrier_retry_export', [ $order_id ], 'my-carrier' );
}

add_action(
	'my_carrier_retry_export',
	static function ( int $order_id ): void {
		\My_Carrier\Plugin::instance()->export_order( $order_id );
	}
);
```

The fourth argument is the *group*; use your plugin ID so your actions are easy to find and cancel. Use `as_unschedule_all_actions( $hook, $args, $group )` to cancel them.

## Async requests

`Woodev_Async_Request` runs a method in a separate request. `dispatch()` sends a request to WordPress's `admin-ajax.php` that returns at once, and the work happens in that second request.

Your subclass sets two properties, which together form the request's *identifier* (`{prefix}_{action}`, here `my_carrier_refresh`), and implements `handle()`:

```php
<?php
namespace My_Carrier;

defined( 'ABSPATH' ) || exit;

class Refresh_Request extends \Woodev_Async_Request {

	protected $prefix = 'my_carrier';

	protected $action = 'refresh';

	protected function handle() {
		Plugin::instance()->refresh_reference_data();
	}
}
```

Start it with `dispatch()`:

```php
<?php
( new \My_Carrier\Refresh_Request() )->dispatch();
```

:::caution
The constructor registers the AJAX hooks that receive the second request. Create the object on **every** request, from your plugin's constructor, and keep it in a property. If it exists only in the code that calls `dispatch()`, the second request finds no handler and nothing happens.
:::

`set_data( array $data )` returns the request and stores `$data` for the dispatch. The dispatch is a `GET` request, and PHP does not fill `$_POST` for a `GET`, so do not count on reading the data back in `handle()`. Keep the real data in storage (an option, a transient or a job) and treat the request as a trigger. That is exactly what the job handler below does.

## Background jobs

`Woodev_Background_Job_Handler` extends `Woodev_Async_Request`. It stores *jobs*, hands their items to your `process_item()` one at a time, and keeps going across requests until the queue is empty.

### Define a handler

Set `$prefix` and `$action` (they form the identifier, here `my_carrier_export_jobs`) and implement `process_item()`:

```php
<?php
namespace My_Carrier;

defined( 'ABSPATH' ) || exit;

class Export_Job_Handler extends \Woodev_Background_Job_Handler {

	protected $prefix = 'my_carrier';

	protected $action = 'export_jobs';

	/**
	 * Called once for every entry of the job's `data` list.
	 *
	 * @param mixed           $item One entry, here an order ID.
	 * @param \stdClass|object $job  The job. Changes made to it are saved after the item.
	 */
	protected function process_item( $item, $job ) {
		try {
			Plugin::instance()->get_client()->create_shipment( (int) $item );
		} catch ( \Woodev_API_Exception $e ) {
			// Remember the failure on the job and carry on with the next item.
			$job->failed_orders   = $job->failed_orders ?? [];
			$job->failed_orders[] = (int) $item;
		}
	}
}
```

Catch exceptions inside `process_item()`. The handler does not catch them for you, and one bad item should not stop the rest.

Create the handler from your plugin's constructor, on every request, for the same reason as above:

```php
<?php
namespace My_Carrier;

defined( 'ABSPATH' ) || exit;

class Plugin extends \Woodev\Framework\Woocommerce_Plugin {

	private Export_Job_Handler $export_jobs;

	public function __construct() {
		parent::__construct( 'my-carrier', MY_CARRIER_VERSION, [ 'text_domain' => 'my-carrier' ] );

		$this->export_jobs = new Export_Job_Handler();
	}

	// instance(), get_file(), get_plugin_name() and the rest as in "Your first plugin".
}
```

### Create and start a job

`create_job( $attrs )` stores a job and returns it, or returns `null` for empty attributes. The default loop reads the list in the `data` attribute, so put your items there. Everything else you add is available in `process_item()`.

```php
<?php
$job = $this->export_jobs->create_job(
	[
		'data'    => $order_ids,
		'carrier' => 'my-carrier',
	]
);

if ( $job ) {
	$this->export_jobs->dispatch();
}
```

`dispatch()` schedules a WP-Cron health check and fires the background request. To loop over a different attribute, set `protected $data_key = 'order_ids';` in your handler.

A job object carries your attributes plus these:

| Property | Meaning |
|---|---|
| `id` | A 32-character identifier. |
| `status` | `queued`, `processing`, `completed` or `failed`. |
| `total`, `progress` | Number of items, and how many are done. Set once processing starts. |
| `created_at`, `created_by`, `started_processing_at`, `updated_at`, `completed_at`, `failed_at` | Timestamps and the creating user. |
| `failure_reason` | The reason passed to `fail_job()`, when there is one. |

### Read and manage jobs

| Method | Purpose |
|---|---|
| `get_job( $id = null )` | One job by ID. Without an ID, the first queued or processing job. Returns `null` when none matches. |
| `get_jobs( array $args = [] )` | Jobs as an array, or `null` when there are none. Arguments: `status` (a string or a list), `order` (`ASC` or `DESC`, default `DESC`), `orderby` (default `option_id`). |
| `update_job( $job )` | Save the job and refresh `updated_at`. |
| `complete_job( $job )` | Mark it `completed`. |
| `fail_job( $job, $reason = '' )` | Mark it `failed` with a reason. A job that already finished is left alone. |
| `delete_job( $job )` | Remove it. |

Jobs live in the `wp_options` table without autoload. A finished job stays there until you delete it, so remove the ones you no longer need, for example from the completion hook below. An uncaught PHP fatal error marks the job being processed as `failed`.

### How a run works

The background request locks the queue, takes the first unfinished job and works through its items. It stops when the **time limit** (20 seconds) or the **memory limit** (90 % of PHP's `memory_limit`) is reached, unlocks, and dispatches a new request if the queue still holds work. The progress is saved after every item, so a stopped job continues where it left off.

If a request dies, a WP-Cron health check (every 5 minutes) restarts the queue. When the queue is empty, the check removes itself.

### Hooks and limits

`{id}` is the handler's identifier, for example `my_carrier_export_jobs`.

| Hook | Type | Arguments | Purpose |
|---|---|---|---|
| `{id}_job_created` | action | `$job` | A job was created. |
| `{id}_job_updated` | action | `$job` | A job was saved. |
| `{id}_job_complete` | action | `$job` | A job finished. |
| `{id}_job_failed` | action | `$job` | A job was marked failed. |
| `{id}_job_deleted` | action | `$job` | A job was deleted. |
| `{id}_new_job_attrs` | filter | `$attrs`, `$job_id` | Adjust the attributes of a new job. |
| `{id}_returned_job` | filter | `$job` | Adjust a job read from the database. A non-object return is ignored. |
| `{id}_default_time_limit` | filter | `$seconds` | The time limit per run, default `20`. A value that is not a positive number is ignored. |
| `{id}_time_exceeded` | filter | `$exceeded` | Override the time check. |
| `{id}_memory_exceeded` | filter | `$exceeded` | Override the memory check. |
| `{id}_queue_lock_time` | filter | `$seconds` | How long a run holds the lock, default `60`. Keep it above the time limit. |
| `{id}_cron_interval` | filter | `$minutes` | The health-check interval, default `5`. A value that is not a positive number is ignored. |

You can also set the lock time and the interval as handler properties: `protected $queue_lock_time = 90;` (seconds) and `protected $cron_interval = 10;` (minutes).

```php
<?php
add_action(
	'my_carrier_export_jobs_job_complete',
	static function ( $job ): void {
		if ( ! empty( $job->failed_orders ) ) {
			\My_Carrier\Plugin::instance()->log_error(
				sprintf( 'Export finished with %d failed orders.', count( $job->failed_orders ) )
			);
		}
	}
);
```

### Check that background requests work

A job handler relies on the site being able to send a request to itself. Some hosts block that. The framework adds a check to **WooCommerce → Status → Tools**, called «Тест фоновой обработки» (Background Processing Test), with the button «Запустить тест» (Run Test). When it fails, background jobs cannot start on that site: tell the merchant to ask the host to allow loopback connections, or drive the work with the batch handler below or with Action Scheduler.

## Running jobs from an admin screen

`Woodev_Job_Batch_Handler` processes a job in small batches that an administrator's browser requests one after another, so progress is visible and no background request is needed. It takes an existing job handler.

Extend it and name the capability that may run and cancel jobs. A nonce alone is not authorisation, so the method is mandatory:

```php
<?php
namespace My_Carrier;

defined( 'ABSPATH' ) || exit;

class Export_Batch_Handler extends \Woodev_Job_Batch_Handler {

	protected function get_required_capability(): string {
		return 'manage_woocommerce';
	}
}
```

Create it next to the job handler, again on every admin request. It does nothing outside the admin:

```php
<?php
// In the constructor of My_Carrier\Plugin, after the job handler exists:
$this->export_batch = new Export_Batch_Handler( $this->export_jobs, $this );
```

The constructor adds two AJAX actions, `{id}_process_batch` and `{id}_cancel_job`, loads the framework's batch script on admin screens, and creates a JavaScript object named `window.{id}_batch_handler`. Call it from your own admin script with the ID of a job you created:

```js
const handler = window.my_carrier_export_jobs_batch_handler;

// A progress event is triggered on `document` after every batch.
jQuery( document ).on( 'my_carrier_export_jobs_batch_progress_' + jobId, function ( event, progress ) {
	console.log( progress.percentage + '%', progress.progress, '/', progress.total );
} );

handler.process_job( jobId ).then( function ( response ) {
	console.log( 'Finished with status', response.data.status );
} );
```

`process_job()` returns a promise. It requests one batch at a time until the job is no longer `processing`. Set `handler.cancelled = jobId` to cancel: the next request deletes the job.

You can tune it with these members:

| Member | Effect |
|---|---|
| `{id}_batch_handler_items_per_batch` filter | Items per request, default `20`. |
| `{id}_batch_handler_js_args` filter | Arguments passed to the JavaScript object. |
| `process_job_status( $job )` method | Override to add properties to the job returned to the browser after each batch. |
| `get_js_class()` method | Return the name of your own JavaScript class that extends the framework's. |

## Related

- [Calling external APIs](../calling-external-apis/) — the clients that jobs usually call.
- [Lifecycle & upgrades](../lifecycle-and-upgrades/) — create handlers while the plugin boots.
- [Logging & debugging](../logging-and-debugging/) — record a job's failures.
