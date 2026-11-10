<?php
/**
 * What a profile form offers to choose from.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Mappings;

defined( 'ABSPATH' ) || exit;

/**
 * The choice lists of the profile form: where each card value comes from, and which VAT rates exist. They are a value handed to
 * {@see Profile_Input} and to the view, so a marketplace with other choices passes its own (`woodev_{marketplace id}_profile_choices`
 * filter in {@see Mappings_Module}) and nothing else in the editor changes.
 *
 * A source value is a `kind` or, for the custom-field kind, `meta:{key}`.
 *
 * @since 2.0.30
 */
final class Profile_Choices {
	/**
	 * Kind that names a product meta key.
	 */
	public const KIND_META = 'meta';

	/**
	 * Labels of the source kinds by setting: `setting => kind => label`.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $sources;

	/**
	 * Kind in force per setting when the form does not say.
	 *
	 * @var array<string,string>
	 */
	private $defaults;

	/**
	 * VAT rates: `value => label`; the empty value means «do not send».
	 *
	 * @var array<string,string>
	 */
	private $vat_rates;

	/**
	 * Titles of the source settings: `setting => title`.
	 *
	 * @var array<string,string>
	 */
	private $titles;

	/**
	 * Creates the choices.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,array<string,string>> $sources   Labels of the source kinds by setting.
	 * @param array<string,string>               $defaults  Default kind per setting; every key of `$sources` needs one.
	 * @param array<string,string>               $vat_rates VAT rates by value.
	 * @param array<string,string>               $titles    Title of each source setting.
	 */
	public function __construct( array $sources, array $defaults, array $vat_rates, array $titles ) {
		$this->sources   = $sources;
		$this->defaults  = $defaults;
		$this->vat_rates = $vat_rates;
		$this->titles    = $titles;
	}

	/**
	 * Returns the titles of the source settings.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,string>
	 */
	public function titles(): array {
		return $this->titles;
	}

	/**
	 * The choices every WooCommerce-fed card uses.
	 *
	 * @since 2.0.30
	 *
	 * @return self
	 */
	public static function standard(): self {
		$custom_field = __( 'Произвольное поле товара', 'woodev-plugin-framework' );
		$sku          = __( 'Артикул (SKU) товара', 'woodev-plugin-framework' );

		return new self(
			[
				'offer_id_source'      => [
					'sku'           => $sku,
					self::KIND_META => $custom_field,
				],
				'barcode_source'       => [
					'none'             => __( 'Не передавать штрихкод', 'woodev-plugin-framework' ),
					'global_unique_id' => __( 'Поле GTIN / EAN товара', 'woodev-plugin-framework' ),
					'sku'              => $sku,
					self::KIND_META    => $custom_field,
				],
				'description_source'   => [
					'description'       => __( 'Полное описание', 'woodev-plugin-framework' ),
					'short_description' => __( 'Краткое описание', 'woodev-plugin-framework' ),
					self::KIND_META     => $custom_field,
				],
				'primary_image_source' => [
					'featured'      => __( 'Главное фото товара', 'woodev-plugin-framework' ),
					self::KIND_META => __( 'Произвольное поле товара (ссылка на фото)', 'woodev-plugin-framework' ),
				],
				'images_source'        => [
					'gallery'       => __( 'Галерея товара', 'woodev-plugin-framework' ),
					'none'          => __( 'Не передавать дополнительные фото', 'woodev-plugin-framework' ),
					self::KIND_META => __( 'Произвольное поле товара (ссылки на фото)', 'woodev-plugin-framework' ),
				],
			],
			[
				'offer_id_source'      => 'sku',
				'barcode_source'       => 'none',
				'description_source'   => 'description',
				'primary_image_source' => 'featured',
				'images_source'        => 'gallery',
			],
			[
				''     => __( 'Не указывать', 'woodev-plugin-framework' ),
				'0'    => __( 'Без НДС', 'woodev-plugin-framework' ),
				'0.05' => '5%',
				'0.07' => '7%',
				'0.1'  => '10%',
				'0.2'  => '20%',
			],
			[
				'offer_id_source'      => __( 'Откуда брать артикул', 'woodev-plugin-framework' ),
				'barcode_source'       => __( 'Откуда брать штрихкод', 'woodev-plugin-framework' ),
				'description_source'   => __( 'Откуда брать описание', 'woodev-plugin-framework' ),
				'primary_image_source' => __( 'Откуда брать главное фото', 'woodev-plugin-framework' ),
				'images_source'        => __( 'Откуда брать дополнительные фото', 'woodev-plugin-framework' ),
			]
		);
	}

	/**
	 * Returns the source settings of the profile, in form order.
	 *
	 * @since 2.0.30
	 *
	 * @return string[]
	 */
	public function settings(): array {
		return array_keys( $this->sources );
	}

	/**
	 * Returns the labels of the source kinds by setting.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,array<string,string>>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Returns the default value of a source setting.
	 *
	 * @since 2.0.30
	 *
	 * @param string $setting Setting key.
	 * @return string
	 */
	public function default_for( string $setting ): string {
		return $this->defaults[ $setting ] ?? '';
	}

	/**
	 * Returns the VAT rates.
	 *
	 * @since 2.0.30
	 *
	 * @return array<string,string>
	 */
	public function vat_rates(): array {
		return $this->vat_rates;
	}

	/**
	 * Splits a stored source value into its kind and key.
	 *
	 * @since 2.0.30
	 *
	 * @param string $value `kind` or `meta:{key}`.
	 * @return array{0:string,1:string}
	 */
	public static function split( string $value ): array {
		$parts = explode( ':', $value, 2 );

		return [ $parts[0], $parts[1] ?? '' ];
	}

	/**
	 * Tells whether a value is one the form builds: a listed kind, and a key exactly when the kind is the custom field.
	 *
	 * @since 2.0.30
	 *
	 * @param string $setting Setting key.
	 * @param string $value   `kind` or `meta:{key}`.
	 * @return bool
	 */
	public function is_known( string $setting, string $value ): bool {
		list( $kind, $key ) = self::split( $value );

		if ( ! isset( $this->sources[ $setting ][ $kind ] ) ) {
			return false;
		}

		return self::KIND_META === $kind ? '' !== $key : '' === $key;
	}
}
