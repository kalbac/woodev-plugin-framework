<?php
/**
 * The posted settings of the product tab, checked against their declared shape.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Price\Price_Override;

defined( 'ABSPATH' ) || exit;

/**
 * A request body is validated against a declared shape before use. This is the shape of one account's block of the product tab and
 * of the variation panel:
 *
 * | key           | shape                                                                  | result                          |
 * |---------------|------------------------------------------------------------------------|---------------------------------|
 * | `offer_id`    | text the marketplace's article policy accepts; absent when not editable | `offer_id`: string or null      |
 * | `sync_stock`  | `'0'` or `'1'`                                                         | `sync_stock`: bool              |
 * | `sync_price`  | `'0'` or `'1'`                                                         | `sync_price`: bool              |
 * | `is_oversize` | `'0'` or `'1'`                                                         | `is_oversize`: bool             |
 * | `warehouse_id`| `''` («как в настройках аккаунта») or a positive integer              | `warehouse_id`: int or null     |
 * | `mapping_id`  | `''` («по категории») or a positive integer                            | `mapping_id`: int or null       |
 * | `price`       | `{ price|old_price|min_price: { kind: ''|fixed|percent|none, value } }`| `price_override`: array or null |
 *
 * A key that is absent is absent from the result: it stays as it is. A key of the wrong shape is an error, never a guess. The
 * existence of a warehouse or a mapping is the writer's check (it knows the account); this class knows only the shapes.
 *
 * @since 2.1.2
 */
final class Link_Form_Input {
	public const KEY_OFFER_ID = 'offer_id';

	private const FLAGS  = [ 'sync_stock', 'sync_price', 'is_oversize' ];
	private const IDS    = [ 'warehouse_id', 'mapping_id' ];
	private const FIELDS = [ Price_Override::FIELD_PRICE, Price_Override::FIELD_OLD_PRICE, Price_Override::FIELD_MIN_PRICE ];

	/**
	 * The chosen articles (checks a typed one).
	 *
	 * @var Pinned_Offer_Id
	 */
	private $pinned;

	/**
	 * Creates the input reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Pinned_Offer_Id $pinned The chosen articles.
	 */
	public function __construct( Pinned_Offer_Id $pinned ) {
		$this->pinned = $pinned;
	}

	/**
	 * Checks one block.
	 *
	 * @since 2.1.2
	 *
	 * @param mixed $raw The posted array of one account (or one variation and account).
	 * @return array{values:array<string,mixed>,errors:string[]} `values` hold the keys of the table above; `errors` are sentences for the merchant.
	 */
	public function parse( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [
				'values' => [],
				'errors' => [ self::bad_request() ],
			];
		}

		$values = [];
		$errors = [];

		foreach ( self::FLAGS as $flag ) {
			if ( ! array_key_exists( $flag, $raw ) ) {
				continue;
			}

			if ( '0' === $raw[ $flag ] || '1' === $raw[ $flag ] ) {
				$values[ $flag ] = '1' === $raw[ $flag ];
			} else {
				$errors[] = self::bad_request();
			}
		}

		foreach ( self::IDS as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$id = self::positive_id( $raw[ $key ] );

			if ( false === $id ) {
				$errors[] = self::bad_request();
			} else {
				$values[ $key ] = $id;
			}
		}

		if ( array_key_exists( self::KEY_OFFER_ID, $raw ) ) {
			$article = is_string( $raw[ self::KEY_OFFER_ID ] ) ? trim( $raw[ self::KEY_OFFER_ID ] ) : null;
			$problem = null === $article ? self::bad_request() : $this->pinned->problem( $article );

			if ( null !== $problem ) {
				$errors[] = $problem;
			} else {
				$values[ self::KEY_OFFER_ID ] = '' === $article ? null : $article;
			}
		}

		if ( array_key_exists( 'price', $raw ) ) {
			$parsed = self::price_override( $raw['price'] );

			if ( [] !== $parsed['errors'] ) {
				$errors = array_merge( $errors, $parsed['errors'] );
			} else {
				$values['price_override'] = $parsed['override'];
			}
		}

		return [
			'values' => [] === $errors ? $values : [],
			'errors' => array_values( array_unique( $errors ) ),
		];
	}

	/**
	 * Reads the three price fields.
	 *
	 * @param mixed $raw `{ field: { kind, value } }`.
	 * @return array{override:array<string,array{kind:string,value:string}>|null,errors:string[]}
	 */
	private static function price_override( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [
				'override' => null,
				'errors'   => [ self::bad_request() ],
			];
		}

		$override = [];
		$errors   = [];

		foreach ( self::FIELDS as $name ) {
			$field = $raw[ $name ] ?? [];
			$kind  = is_array( $field ) && is_string( $field['kind'] ?? '' ) ? (string) ( $field['kind'] ?? '' ) : null;
			$value = is_array( $field ) && is_string( $field['value'] ?? '' ) ? trim( (string) ( $field['value'] ?? '' ) ) : null;

			if ( null === $kind || null === $value ) {
				$errors[] = self::bad_request();
				continue;
			}

			if ( '' === $kind ) {
				continue; // The rule decides.
			}

			try {
				$checked = Price_Override::from_array(
					[
						$name => [
							'kind'  => $kind,
							'value' => $value,
						],
					]
				)->field_of( $name );
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = self::price_problem( $name );
				continue;
			}

			if ( null !== $checked ) {
				$override[ $name ] = $checked;
			}
		}

		return [
			'override' => [] === $override ? null : $override,
			'errors'   => $errors,
		];
	}

	/**
	 * Reads an optional positive integer.
	 *
	 * @param mixed $value Posted value.
	 * @return int|null|false Null for «not chosen», false for a value of the wrong shape.
	 */
	private static function positive_id( $value ) {
		if ( '' === $value ) {
			return null;
		}

		return is_string( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $value ) ? (int) $value : false;
	}

	/**
	 * Names what is wrong with a price field.
	 *
	 * @param string $name `Price_Override::FIELD_*`.
	 * @return string
	 */
	private static function price_problem( string $name ): string {
		$labels = [
			Price_Override::FIELD_PRICE     => __( 'Цена', 'woodev-plugin-framework' ),
			Price_Override::FIELD_OLD_PRICE => __( 'Старая цена', 'woodev-plugin-framework' ),
			Price_Override::FIELD_MIN_PRICE => __( 'Минимальная цена', 'woodev-plugin-framework' ),
		];

		return sprintf(
			/* translators: %s: name of the price field. */
			__( '«%s»: введите число больше нуля (сумма — не больше двух знаков после запятой; наценка в процентах — не меньше −99, минимальная цена — до 100) или оставьте «По правилу».', 'woodev-plugin-framework' ),
			$labels[ $name ]
		);
	}

	/**
	 * The sentence for a body that is not of the declared shape.
	 *
	 * @return string
	 */
	private static function bad_request(): string {
		return __( 'Запрос не удалось разобрать. Обновите страницу и повторите.', 'woodev-plugin-framework' );
	}
}
