<?php
/**
 * Where a card field gets its value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The origin of one field's value in a mapping: a constant, a WooCommerce attribute, a product meta key or a built-in
 * product field.
 *
 * @since 2.0.29
 */
final class Source {
	public const FIXED        = 'fixed';
	public const WC_ATTRIBUTE = 'wc_attribute';
	public const META         = 'meta';
	public const FIELD        = 'field';

	/**
	 * One of the kind constants.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * The constant value, for `FIXED`.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Attribute taxonomy, meta key or field name, for the other kinds.
	 *
	 * @var string
	 */
	private $wc_key;

	/**
	 * Dictionary value id chosen by the merchant, if any.
	 *
	 * @var string|null
	 */
	private $dictionary_value_id;

	/**
	 * Creates a source.
	 *
	 * @since 2.0.29
	 *
	 * @param string      $kind                `FIXED`, `WC_ATTRIBUTE`, `META` or `FIELD`.
	 * @param string      $value               Constant value (`FIXED`).
	 * @param string      $wc_key              Attribute, meta key or field name (other kinds).
	 * @param string|null $dictionary_value_id Dictionary value id.
	 * @throws \InvalidArgumentException On an unknown kind.
	 */
	public function __construct( string $kind, string $value = '', string $wc_key = '', ?string $dictionary_value_id = null ) {
		if ( ! in_array( $kind, [ self::FIXED, self::WC_ATTRIBUTE, self::META, self::FIELD ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown source kind.' );
		}

		$this->kind                = $kind;
		$this->value               = $value;
		$this->wc_key              = $wc_key;
		$this->dictionary_value_id = $dictionary_value_id;
	}

	/**
	 * Returns the kind.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the constant value.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Returns the attribute, meta key or field name.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function wc_key(): string {
		return $this->wc_key;
	}

	/**
	 * Returns the dictionary value id, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function dictionary_value_id(): ?string {
		return $this->dictionary_value_id;
	}

	/**
	 * Tells whether the source can produce a value: a fixed source needs a value, every other kind needs a key.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return self::FIXED === $this->kind ? '' !== $this->value || null !== $this->dictionary_value_id : '' !== $this->wc_key;
	}
}
