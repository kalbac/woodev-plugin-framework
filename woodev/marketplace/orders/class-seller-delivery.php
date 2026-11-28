<?php
/**
 * What a marketplace says about an order the seller delivers.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Price\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An order the seller delivers (a courier of the shop's, or a carrier) carries this on its {@see Order_DTO}: who receives it, where,
 * when, and what the buyer paid for the delivery. An adapter puts it on **every** DTO of such an order, with whatever it knows so far;
 * an empty value means «not told yet», never «cleared».
 *
 * The framework writes it into the WooCommerce order once ({@see \Woodev\Framework\Marketplace\Flows\Seller_Delivery_Writer}): the
 * merchant may correct the recipient, the phone and the address, and a later read never writes over such a correction.
 *
 * Phones are written to the order as given, so the adapter brings them to the form its carriers take.
 *
 * The object is immutable: every `with_*` method returns a copy.
 *
 * @since 2.1.29
 */
final class Seller_Delivery {
	/**
	 * @var string
	 */
	private $payment_title;

	/**
	 * @var string
	 */
	private $line_title;

	/**
	 * @var string
	 */
	private $recipient = '';

	/**
	 * @var string
	 */
	private $phone = '';

	/**
	 * @var string
	 */
	private $phone_extension = '';

	/**
	 * @var array<int,array{phone:string,extension:string}>
	 */
	private $other_phones = [];

	/**
	 * @var string
	 */
	private $address = '';

	/**
	 * @var string
	 */
	private $latitude = '';

	/**
	 * @var string
	 */
	private $longitude = '';

	/**
	 * @var bool
	 */
	private $pickup_point = false;

	/**
	 * @var string
	 */
	private $address_note = '';

	/**
	 * @var string
	 */
	private $comment = '';

	/**
	 * @var string
	 */
	private $date = '';

	/**
	 * @var string
	 */
	private $time_from = '';

	/**
	 * @var string
	 */
	private $time_to = '';

	/**
	 * @var Money|null
	 */
	private $price;

	/**
	 * @var array<string,mixed>
	 */
	private $payload = [];

	/**
	 * Creates the description of a delivery.
	 *
	 * @since 2.1.29
	 *
	 * @param string $payment_title How the order was paid, in the merchant's words (the marketplace's name).
	 * @param string $line_title    The name of the shipping line that holds what the buyer paid for the delivery.
	 * @throws \InvalidArgumentException When one of the two is empty.
	 */
	public function __construct( string $payment_title, string $line_title ) {
		if ( '' === $payment_title || '' === $line_title ) {
			throw new \InvalidArgumentException( 'A seller delivery needs a payment title and a shipping line title.' );
		}

		$this->payment_title = $payment_title;
		$this->line_title    = $line_title;
	}

	/**
	 * Returns a copy with the recipient's name.
	 *
	 * @since 2.1.29
	 *
	 * @param string $name Name.
	 * @return self
	 */
	public function with_recipient( string $name ): self {
		$copy            = clone $this;
		$copy->recipient = $name;

		return $copy;
	}

	/**
	 * Returns a copy with the phone the order is reached by.
	 *
	 * @since 2.1.29
	 *
	 * @param string $phone     Phone; empty when the marketplace has not assigned one yet.
	 * @param string $extension Extension the phone needs to reach the recipient; empty when it needs none.
	 * @return self
	 */
	public function with_phone( string $phone, string $extension = '' ): self {
		$copy                  = clone $this;
		$copy->phone           = $phone;
		$copy->phone_extension = $extension;

		return $copy;
	}

	/**
	 * Returns a copy with one more phone the merchant may call, shown beside the order.
	 *
	 * @since 2.1.29
	 *
	 * @param string $phone     Phone; an empty one is ignored.
	 * @param string $extension Extension; empty when it needs none.
	 * @return self
	 */
	public function with_other_phone( string $phone, string $extension = '' ): self {
		if ( '' === $phone ) {
			return $this;
		}

		$copy                 = clone $this;
		$copy->other_phones[] = [
			'phone'     => $phone,
			'extension' => $extension,
		];

		return $copy;
	}

	/**
	 * Returns a copy with the address, as one line.
	 *
	 * @since 2.1.29
	 *
	 * @param string $address      Address text; empty when the marketplace has not told it.
	 * @param string $latitude     Latitude, as a decimal number in text; empty when unknown.
	 * @param string $longitude    Longitude, the same way.
	 * @param bool   $pickup_point Whether the address is a pickup point's, not the buyer's.
	 * @return self
	 */
	public function with_address( string $address, string $latitude = '', string $longitude = '', bool $pickup_point = false ): self {
		$copy               = clone $this;
		$copy->address      = $address;
		$copy->latitude     = $latitude;
		$copy->longitude    = $longitude;
		$copy->pickup_point = $pickup_point;

		return $copy;
	}

	/**
	 * Returns a copy with the sentence that explains why the address is missing, shown while the order has none.
	 *
	 * @since 2.1.29
	 *
	 * @param string $note Merchant sentence.
	 * @return self
	 */
	public function with_address_note( string $note ): self {
		$copy               = clone $this;
		$copy->address_note = $note;

		return $copy;
	}

	/**
	 * Returns a copy with the buyer's comment.
	 *
	 * @since 2.1.29
	 *
	 * @param string $comment Comment.
	 * @return self
	 */
	public function with_comment( string $comment ): self {
		$copy          = clone $this;
		$copy->comment = $comment;

		return $copy;
	}

	/**
	 * Returns a copy with the day and the time the buyer chose.
	 *
	 * @since 2.1.29
	 *
	 * @param string $date      Day, `Y-m-d`; empty when unknown.
	 * @param string $time_from Start of the interval, `H:i`; empty when unknown.
	 * @param string $time_to   End of the interval, `H:i`; empty when unknown.
	 * @return self
	 */
	public function with_schedule( string $date, string $time_from = '', string $time_to = '' ): self {
		$copy            = clone $this;
		$copy->date      = $date;
		$copy->time_from = $time_from;
		$copy->time_to   = $time_to;

		return $copy;
	}

	/**
	 * Returns a copy with what the buyer paid for the delivery, in the order's currency. Zero is a free delivery.
	 *
	 * @since 2.1.29
	 *
	 * @param Money $price Amount.
	 * @return self
	 */
	public function with_price( Money $price ): self {
		$copy        = clone $this;
		$copy->price = $price;

		return $copy;
	}

	/**
	 * Returns a copy with the marketplace's own data of the order, which the order keeps whole.
	 *
	 * @since 2.1.29
	 *
	 * @param array<string,mixed> $payload Data as the marketplace sent it.
	 * @return self
	 */
	public function with_payload( array $payload ): self {
		$copy          = clone $this;
		$copy->payload = $payload;

		return $copy;
	}

	/**
	 * Returns what is known after an earlier read and this one. Each group of values is replaced as a whole when this read tells it,
	 * and kept from the earlier one otherwise: the recipient, the phones with their extensions, the address with its coordinates and
	 * pickup point flag, the comment, the day with its time, the price, and the note about a missing address.
	 *
	 * @since 2.1.29
	 *
	 * @param self|null $earlier What was known before; null for nothing.
	 * @return self
	 */
	public function merged_over( ?self $earlier ): self {
		if ( null === $earlier ) {
			return $this;
		}

		$merged = clone $this;

		if ( '' === $this->recipient ) {
			$merged->recipient = $earlier->recipient;
		}

		if ( '' === $this->phone && [] === $this->other_phones ) {
			$merged->phone           = $earlier->phone;
			$merged->phone_extension = $earlier->phone_extension;
			$merged->other_phones    = $earlier->other_phones;
		}

		if ( '' === $this->address && '' !== $earlier->address ) {
			$merged->address      = $earlier->address;
			$merged->latitude     = $earlier->latitude;
			$merged->longitude    = $earlier->longitude;
			$merged->pickup_point = $earlier->pickup_point;
		}

		if ( '' === $this->address_note ) {
			$merged->address_note = $earlier->address_note;
		}

		if ( '' === $this->comment ) {
			$merged->comment = $earlier->comment;
		}

		if ( '' === $this->date ) {
			$merged->date      = $earlier->date;
			$merged->time_from = $earlier->time_from;
			$merged->time_to   = $earlier->time_to;
		}

		if ( null === $this->price ) {
			$merged->price = $earlier->price;
		}

		return $merged;
	}

	/**
	 * Returns the values as an array an order can store. The payload is not part of it.
	 *
	 * @since 2.1.29
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'payment_title'   => $this->payment_title,
			'line_title'      => $this->line_title,
			'recipient'       => $this->recipient,
			'phone'           => $this->phone,
			'phone_extension' => $this->phone_extension,
			'other_phones'    => $this->other_phones,
			'address'         => $this->address,
			'latitude'        => $this->latitude,
			'longitude'       => $this->longitude,
			'pickup_point'    => $this->pickup_point,
			'address_note'    => $this->address_note,
			'comment'         => $this->comment,
			'date'            => $this->date,
			'time_from'       => $this->time_from,
			'time_to'         => $this->time_to,
			'price_minor'     => null === $this->price ? null : $this->price->minor(),
			'currency'        => null === $this->price ? '' : $this->price->currency(),
		];
	}

	/**
	 * Reads values stored by {@see self::to_array()}.
	 *
	 * @since 2.1.29
	 *
	 * @param mixed $stored Stored value.
	 * @return self|null Null when it is not such a value.
	 */
	public static function from_array( $stored ): ?self {
		if ( ! is_array( $stored ) || '' === (string) ( $stored['payment_title'] ?? '' ) || '' === (string) ( $stored['line_title'] ?? '' ) ) {
			return null;
		}

		$delivery = ( new self( (string) $stored['payment_title'], (string) $stored['line_title'] ) )
			->with_recipient( (string) ( $stored['recipient'] ?? '' ) )
			->with_phone( (string) ( $stored['phone'] ?? '' ), (string) ( $stored['phone_extension'] ?? '' ) )
			->with_address(
				(string) ( $stored['address'] ?? '' ),
				(string) ( $stored['latitude'] ?? '' ),
				(string) ( $stored['longitude'] ?? '' ),
				! empty( $stored['pickup_point'] )
			)
			->with_address_note( (string) ( $stored['address_note'] ?? '' ) )
			->with_comment( (string) ( $stored['comment'] ?? '' ) )
			->with_schedule( (string) ( $stored['date'] ?? '' ), (string) ( $stored['time_from'] ?? '' ), (string) ( $stored['time_to'] ?? '' ) );

		foreach ( (array) ( $stored['other_phones'] ?? [] ) as $other ) {
			$delivery = $delivery->with_other_phone( (string) ( $other['phone'] ?? '' ), (string) ( $other['extension'] ?? '' ) );
		}

		if ( isset( $stored['price_minor'] ) && '' !== (string) ( $stored['currency'] ?? '' ) ) {
			$delivery = $delivery->with_price( Money::of( (int) $stored['price_minor'], (string) $stored['currency'] ) );
		}

		return $delivery;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string How the order was paid.
	 */
	public function payment_title(): string {
		return $this->payment_title;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The name of the shipping line.
	 */
	public function line_title(): string {
		return $this->line_title;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The recipient's name; empty when not told.
	 */
	public function recipient(): string {
		return $this->recipient;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The phone; empty when not assigned.
	 */
	public function phone(): string {
		return $this->phone;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The extension the phone needs; empty when it needs none.
	 */
	public function phone_extension(): string {
		return $this->phone_extension;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return array<int,array{phone:string,extension:string}> Other phones.
	 */
	public function other_phones(): array {
		return $this->other_phones;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The address as one line; empty when not told.
	 */
	public function address(): string {
		return $this->address;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string Latitude; empty when unknown.
	 */
	public function latitude(): string {
		return $this->latitude;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string Longitude; empty when unknown.
	 */
	public function longitude(): string {
		return $this->longitude;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return bool Whether the address is a pickup point's.
	 */
	public function is_pickup_point(): bool {
		return $this->pickup_point;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string Why the address is missing; empty when the adapter gave no reason.
	 */
	public function address_note(): string {
		return $this->address_note;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The buyer's comment.
	 */
	public function comment(): string {
		return $this->comment;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string The day the buyer chose, `Y-m-d`; empty when unknown.
	 */
	public function date(): string {
		return $this->date;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string Start of the interval, `H:i`; empty when unknown.
	 */
	public function time_from(): string {
		return $this->time_from;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return string End of the interval, `H:i`; empty when unknown.
	 */
	public function time_to(): string {
		return $this->time_to;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return Money|null What the buyer paid for the delivery; null when unknown.
	 */
	public function price(): ?Money {
		return $this->price;
	}

	/**
	 * @since 2.1.29
	 *
	 * @return array<string,mixed> The marketplace's own data of the order.
	 */
	public function payload(): array {
		return $this->payload;
	}
}
