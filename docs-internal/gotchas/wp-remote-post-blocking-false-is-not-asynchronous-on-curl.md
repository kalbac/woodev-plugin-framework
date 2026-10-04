# gotcha: `wp_remote_post( …, [ 'blocking' => false ] )` is NOT asynchronous on the cURL transport

**Namespace:** `[php/*]`
**Discovered:** s150 (2026-10-04), card #130 (Codex critic, measured)

## What happened

The first error-reporter draft sent events from the request (and from the shutdown handler) with `blocking => false`,
`timeout => 3`, assuming fire-and-forget. The critic ran the equivalent WordPress Requests cURL options against a
slow localhost receiver: the call took **3.00 s, errno 28**. Requests' cURL transport still runs `curl_exec` and waits
for the receiver up to the timeout; `blocking => false` only skips reading the response body. A page that reports an
error would be delayed by the receiver's latency, and a shutdown handler would hold the PHP worker.

## ❌ Wrong

```php
wp_remote_post( $url, [ 'blocking' => false, 'timeout' => 3, 'body' => $payload ] ); // in a request / shutdown path
```

## ✅ Correct

Keep the network out of the request entirely: enqueue cheaply (a bounded, autoload=no option) and send from a
WP-Cron single event, where a blocking call with a short timeout is legal.

```php
Event_Queue::push( $event );                       // request / shutdown: no HTTP
wp_schedule_single_event( time() + 60, $hook );    // cron drains and posts with blocking => true, timeout 3
```

## Related

- `woodev/error-reporting/class-dispatcher.php`, `class-transport.php`; spec `../specs/2026-10-04-error-reporter-design.md` D6
- [WordPress/Requests cURL transport](https://github.com/WordPress/Requests/blob/v2.0.11/src/Transport/Curl.php)
- `../gotcha-index/php.md`
