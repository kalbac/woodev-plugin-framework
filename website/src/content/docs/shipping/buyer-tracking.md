---
title: Buyer tracking
description: Show the buyer a tracking number and the current delivery status, and put the same number in the status emails.
---

In this page you will learn what the framework shows a buyer for a delivery, how a carrier plugin reports its tracking numbers (one, several or only a link), how a shop overrides the markup, and how to adjust the list with a filter.

Covers framework release `%%FRAMEWORK_VERSION%%`. It assumes a carrier registered with an `Orders_Provider`, as in [Orders, export, documents & tracking](../orders-export-documents-and-tracking/).

## What the buyer sees

When an order of one of your carriers has a tracking number, the framework prints a block under the order details. It appears in two places: the **My Account** page of the order, and the page shown right after checkout. You write nothing for it.

| The order has | The block shows |
|---|---|
| One tracking number | A row **Tracking number** with the number as a link, and, once the carrier has reported something, a line **Current status: In transit, 14 November 2023**. |
| Several tracking numbers | One row per parcel, **Shipment 1 of 2**, **Shipment 2 of 2**, each with its own link and its own status. |
| A link and no number | One row with the link text **Track shipment**. |
| No tracking number | Nothing. Not even the heading. |

There is no setting to switch the block off: a delivery that has a tracking number always shows it.

The status line comes from what the framework already stores while it follows the delivery. For one parcel it is the newest recorded state, worded by your carrier's `status_labels` when it has a label for the carrier's own status code, and by the framework's wording of the canonical state otherwise. It carries a date, in the shop's date format. Until the carrier has reported anything, the row shows the number alone. The page never calls the carrier.

## Report the tracking numbers

By default the framework builds **one** parcel from what the provider already declares: the number is the order meta at `tracking_meta_key`, and the link is the meta at `tracking_url_meta_key` or the `tracking_url_template`. A carrier whose tracking meta holds the number a buyer quotes needs nothing more:

```php
$provider = Orders_Provider::create(
	'my_carrier',
	'My Carrier',
	'_my_carrier_order_id',
	[ 'my_carrier_courier', 'my_carrier_pickup' ],
	[
		'tracking_meta_key'     => '_my_carrier_tracking_number',
		'tracking_url_template' => 'https://my-carrier.example/track/{tracking}',
	]
);
```

Declare a `shipments_resolver` when the default does not fit:

- the tracking meta is **not** a number the buyer may see (an internal request id),
- an order can have **several** parcels,
- the link lives somewhere other than the meta key and template above.

The resolver receives the order and returns a list of `Tracking_Shipment`. It replaces the default entirely.

```php
use Woodev\Framework\Shipping\Order\Tracking_Shipment;

$provider = Orders_Provider::create(
	'my_carrier',
	'My Carrier',
	'_my_carrier_order_id',
	[ 'my_carrier_courier', 'my_carrier_pickup' ],
	[
		'shipments_resolver' => static function ( \WC_Order $order ): array {
			$parcels = (array) $order->get_meta( '_my_carrier_parcels' );
			$list    = [];

			foreach ( $parcels as $parcel ) {
				$list[] = Tracking_Shipment::create(
					(string) ( $parcel['number'] ?? '' ),
					'https://my-carrier.example/track/' . rawurlencode( (string) ( $parcel['number'] ?? '' ) ),
					$parcel['status_label'] ?? null,
					isset( $parcel['status_time'] ) ? (int) $parcel['status_time'] : null
				);
			}

			return $list;
		},
	]
);
```

`Tracking_Shipment::create( $number, $url, $status_label, $status_time )` checks what you pass:

| Argument | Rule |
|---|---|
| `$number` | Trimmed. May be empty **only** when `$url` is given. |
| `$url` | An absolute `http` or `https` link. Anything else becomes "no link". |
| `$status_label`, `$status_time` | Optional. Used **only** when the order has several parcels, so each row shows its own status. A single parcel shows the order's status instead. `$status_time` is a Unix time. |

A shipment with neither a number nor a usable link is dropped. A resolver that throws or does not return an array means "nothing to show": the buyer's page never fails over carrier data, and the failure goes to the plugin's log.

A link-only resolver, for a carrier whose buyer-facing handle is a sharing link:

```php
'shipments_resolver' => static function ( \WC_Order $order ): array {
	$link = Tracking_Shipment::create( '', (string) $order->get_meta( '_my_carrier_sharing_url' ) );

	return $link->is_empty() ? [] : [ $link ];
},
```

## The status emails use the same numbers

The delivery status emails read the same list, so the number on the page and in the mail always agree.

| Parcels | `{tracking_number}` | `{tracking_url}` |
|---|---|---|
| None | Empty. | Empty. |
| One | The number. | Its link. |
| Several | One line per parcel, **Shipment 1 of 2: number**, each number a link in the HTML mail. | Empty, because every link is inside its own line. |

In a subject or a heading, several parcels appear as their numbers only, separated by commas.

## Change the markup

The block is printed by a template. A shop or a theme overrides it by copying the file to:

```text
{theme}/woocommerce/woodev/shipping/order/tracking.php
```

The template receives `$order`, the carrier's `$provider`, and `$lines`: one array per parcel with the keys `caption`, `text`, `url` (or `null`) and `status` (a finished sentence, or an empty string). Print the strings with `esc_html()` and the link with `esc_url()`.

## Adjust the list with a filter

`woodev_shipping_order_tracking_shipments` runs after the resolver or the default, for the page and the emails alike. Use it to add a parcel, drop one or reword a status.

```php
add_filter(
	'woodev_shipping_order_tracking_shipments',
	static function ( array $shipments, \WC_Order $order, $provider ): array {
		if ( 'my_carrier' !== $provider->get_id() ) {
			return $shipments;
		}

		$shipments[] = Tracking_Shipment::create( 'RETURN-' . $order->get_id() );

		return $shipments;
	},
	10,
	3
);
```

Return a list of `Tracking_Shipment`. Anything else in the list is removed.

## Related

- [Orders, export, documents & tracking](../orders-export-documents-and-tracking/)
- [Hooks & filters](../../reference/hooks-and-filters/)
