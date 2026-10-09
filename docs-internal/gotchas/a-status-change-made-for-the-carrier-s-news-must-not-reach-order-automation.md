# Gotcha: [shipping/orders] — An order status set FROM the carrier's news must not reach Order_Automation
> Tags: shipping/orders, order-automation, cancel-loop | Session: s166

## What happens

`Order_Automation::handle_status_change()` listens to `woocommerce_order_status_changed` and, on
`cancelled`/`refunded`, queues a cancellation of the shipment at the carrier. The «Статус отменённого
заказа» (#1203) moves an order to `wc-cancelled` BECAUSE the carrier reported the shipment cancelled — so
without a guard the same status change tells the carrier to cancel what it has already cancelled (a
needless request, and a «cannot cancel (status Отменено)» note on the order).

Second trap, same feature: the canonical state `cancelled` has TWO sources — the carrier's raw status
through its `status_map`, and the framework's own `Shipment_Cancellation` marker (the «Отменить» button, a
cancelled order). Both publish `woodev_shipping_delivery_status_changed`. A listener that moves the
order on «cancelled» alone would cancel the whole order when the merchant merely cancelled the shipment.

## Root cause

`WC_Order::update_status()` fires `woocommerce_order_status_changed` synchronously, and nothing on the
event says who asked for the change.

## Fix

❌ Set the status and hope the cancel gate (`Order_Actions::can_cancel()`) refuses it later.

✅ `Cancelled_Order_Status::apply()` raises a request-local flag around `update_status()` (try/finally);
`Order_Automation::handle_status_change()` returns early while `is_applying()`. And `apply()` returns
when `Shipment_Cancellation::is_cancelled( $order )` — that cancellation is the merchant's, not news.

## Related

- [a-negative-meta-clause-or-ed-across-providers-matches-every-order.md](a-negative-meta-clause-or-ed-across-providers-matches-every-order.md) — another shipping/orders trap.
