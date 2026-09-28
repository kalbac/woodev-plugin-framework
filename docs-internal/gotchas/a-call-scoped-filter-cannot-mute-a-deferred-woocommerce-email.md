# Gotcha: [woocommerce/email] — a filter added and removed around a status change cannot mute a DEFERRED WooCommerce e-mail; and WC ≤ 10.7 dispatches a batch from stale order copies

> Tags: woocommerce, email, deferred, action-scheduler | Session: s143 (#981)

## What happens

To stop «New order» on an admin edit, `Order_Editor` added `woocommerce_email_enabled_new_order` → false
around the save and removed it in `finally`. Green in every synchronous test — and wrong on any store that
enables `woocommerce_defer_transactional_emails` (or WC 11's `deferred_transactional_emails` feature):
the transition only QUEUES the notification, it is sent in a later request, and the filter is gone by then.

## Root cause

With deferral on, `WC_Emails::queue_transactional_email` collects the action; on `shutdown` WC ≤ 10.7 saves
it to an option and a loopback runs `WC_Background_Emailer::handle()`, WC 10.8+ schedules one Action Scheduler
action per notification. Both end in `do_action_ref_array( $filter . '_notification', $args )` →
`WC_Email_New_Order::trigger()` → `is_enabled()` with the order **re-read from the datastore**. Only what the
order itself carries survives into that request.

Second trap, WC ≤ 10.7 only: `WC_Background_Emailer::handle()` → `get_batch()` unserialises the WHOLE batch
first, so two queued notifications of one order are both woken (and re-read) before either runs — the
second sees meta the first has already changed, and writes its stale copy back.

## Fix

❌ `add_filter( … ); $order->save(); … finally { remove_filter( … ); }`
✅ Persist the intent on the order (one entry per edit-triggered transition, with a TTL so a lost queue
cannot mute a later legitimate send), keep a process-wide filter that consumes a matching entry only under
`doing_action( $transition . '_notification' )`, and re-read the order's meta (`read_meta_data( true )`)
before consuming. Test with deferral ON and drain the queue the way WC does (AS `process_action` on 10.8+,
`WC_Background_Emailer::save()` + `handle()` below). Reference: `Order_Editor::mute_new_order_email()`.

## Related

- [README.md](../research/2026-09-28-981-new-order-email-trace/README.md) — rounds 2 and 3 of the trace
- [woocommerce-email-recipient-filter-is-not-a-sent-signal](woocommerce-email-recipient-filter-is-not-a-sent-signal.md)
