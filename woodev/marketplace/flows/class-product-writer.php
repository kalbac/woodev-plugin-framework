<?php
/**
 * A marketplace card written into a WooCommerce product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Catalog\Gtin;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Framework\Marketplace\Sync\Shop_Price_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Steps 1–4 of creating a product from a card (the importer's «row 5»); linking the product is the caller's step 5.
 *
 * A card that lists barcodes gives a product with no GTIN the first of them that is one ({@see Gtin}); any other barcode stays
 * with the link only, because WooCommerce's setter would silently cut it down to its digits.
 *
 * 1. {@see self::start()}: the **first save** — a `draft` with the name, the SKU, the import marker and the categories, all in one
 *    save. The SKU and the marker go together because a run that was cut short is found again **by its SKU**; the categories go
 *    in so they are stored before the price is worked out.
 * 2. {@see self::complete()}: the fields (description, weight, dimensions) and then the price, whose rule is resolved from the
 *    product's *stored* categories, so a profile-level markup is seen.
 * 3. The images, one at a time, each reused by its source URL. The run's `$renew` is called before each image and a throw from
 *    it stops the writer; one image that cannot be loaded does not fail the product. The optional `$stored` is called right after a
 *    **new** image was stored — a cached or failed one never calls it — so the caller can count real progress.
 * 4. The **final save**: the status and the marker removed.
 *
 * Each field goes where the profile reads it on export, so an imported product round-trips. One profile governs the price and the
 * fields: the one the saved product's categories (and their ancestors) point at ({@see Import_Category::profile_id()}); with
 * none, the built-in defaults apply (full description, featured image, gallery, the shop's units).
 *
 * @since 2.1.6
 */
final class Product_Writer {
	/**
	 * Seconds one image download may take. WordPress's default is 300, longer than the account claim lives.
	 */
	public const IMAGE_TIMEOUT = 30;

	/**
	 * WooCommerce's own record of where an attachment came from.
	 */
	private const SOURCE_META = '_wc_attachment_source';

	/**
	 * Category resolution.
	 *
	 * @var Import_Category
	 */
	private $categories;

	/**
	 * Profiles.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * The price of a new product, worked out from the marketplace's.
	 *
	 * @var Shop_Price_Writer
	 */
	private $prices;

	/**
	 * Creates the writer.
	 *
	 * @since 2.1.6
	 *
	 * @param Import_Category    $categories Category resolution.
	 * @param Wpdb_Profile_Store $profiles   Profiles.
	 * @param Shop_Price_Writer  $prices     The shop side of the price.
	 */
	public function __construct( Import_Category $categories, Wpdb_Profile_Store $profiles, Shop_Price_Writer $prices ) {
		$this->categories = $categories;
		$this->profiles   = $profiles;
		$this->prices     = $prices;
	}

	/**
	 * Returns the meta key that marks a product whose import has not finished.
	 *
	 * @since 2.1.6
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function marker_key( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_importing';
	}

	/**
	 * Tells whether a product is an unfinished import of this marketplace.
	 *
	 * @since 2.1.6
	 *
	 * @param \WC_Product $product        Product.
	 * @param string      $marketplace_id The adapter's id.
	 * @return bool
	 */
	public static function is_marked( \WC_Product $product, string $marketplace_id ): bool {
		return '' !== (string) $product->get_meta( self::marker_key( $marketplace_id ) );
	}

	/**
	 * Creates the product and its first save (step 1).
	 *
	 * @since 2.1.6
	 *
	 * @param Account       $account           Account.
	 * @param Card_Content  $content           The card.
	 * @param bool          $create_categories Whether a card with no mapping gets its category path created.
	 * @param callable|null $prepare           `function ( \WC_Product $product ): void`; called on the unsaved product, so that what it sets is stored by the **same** first save as the SKU and the marker (the group of the card: {@see Group_Assembler::mark()}).
	 * @param string|null   $sku               The SKU to write instead of the article, '' for none; null writes the article (2.1.23).
	 * @return \WC_Product The saved draft: found by its SKU, carrying the marker and the categories.
	 * @throws \WC_Data_Exception When another product already has the SKU.
	 */
	public function start( Account $account, Card_Content $content, bool $create_categories, ?callable $prepare = null, ?string $sku = null ): \WC_Product {
		$article = (string) ( $content->card()['offer_id'] ?? '' );
		$product = new \WC_Product_Simple();

		$product->set_name( '' !== $content->name() ? $content->name() : $article );
		$product->set_status( 'draft' );
		$product->set_sku( $sku ?? $article );
		$product->update_meta_data( self::marker_key( $account->adapter_id() ), '1' );
		$product->set_category_ids( $this->categories->term_ids( $content, $create_categories ) );

		if ( null !== $prepare ) {
			call_user_func( $prepare, $product );
		}

		$product->save();

		return $product;
	}

	/**
	 * Fills a started product, loads its images and saves it for good (steps 2–4). Also the resume of an earlier run: the images
	 * that run fetched are found by their source URL and nothing is downloaded twice.
	 *
	 * @since 2.1.6
	 *
	 * @param Account       $account Account.
	 * @param Card_Content  $content The card.
	 * @param \WC_Product   $product The product {@see self::start()} saved.
	 * @param bool          $publish Whether the finished product is published; it stays a draft otherwise, and a variation is switched off (`private`).
	 * @param callable      $renew   `function (): void`; extends the run's claim, throws when it was lost.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored (a cached or failed one is no progress).
	 * @return string[] Warnings for the merchant: what could not be set (the product exists).
	 */
	public function complete( Account $account, Card_Content $content, \WC_Product $product, bool $publish, callable $renew, ?callable $stored = null ): array {
		$profile  = $this->profile( $this->categories->profile_id( $product ) );
		$warnings = [];

		$this->fill( $content, $product, $profile );
		$this->gtin( $content, $product );
		$product->save();

		$warning = $this->price( $account, $content, $product );

		if ( '' !== $warning ) {
			$warnings[] = $warning;
		}

		$warning = $this->images( $content, $product, $profile, $renew, $stored );

		if ( '' !== $warning ) {
			$warnings[] = $warning;
		}

		$product->set_status( self::final_status( $product, $publish ) );
		$product->delete_meta_data( self::marker_key( $account->adapter_id() ) );
		$product->save();

		return $warnings;
	}

	/**
	 * Returns the status the final save gives a product. A product that is not published is a draft. A variation cannot be
	 * one — WooCommerce lists only the variations that are `publish` (switched on) or `private` (switched off) — so a variation
	 * that is not published is switched off. The status goes in with the final save, never after it.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param bool        $publish Whether it is published.
	 * @return string
	 */
	private static function final_status( \WC_Product $product, bool $publish ): string {
		if ( $publish ) {
			return 'publish';
		}

		return $product->is_type( 'variation' ) ? 'private' : 'draft';
	}

	/**
	 * Creates a product from a card, start to finish.
	 *
	 * @since 2.1.6
	 *
	 * @param Account       $account           Account.
	 * @param Card_Content  $content           The card.
	 * @param bool          $publish           Whether the finished product is published.
	 * @param bool          $create_categories Whether a card with no mapping gets its category path created.
	 * @param callable      $renew             `function (): void`; extends the run's claim, throws when it was lost.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored (a cached or failed one is no progress).
	 * @return array{product:\WC_Product,warnings:string[]}
	 * @throws \WC_Data_Exception When another product already has the SKU.
	 */
	public function write( Account $account, Card_Content $content, bool $publish, bool $create_categories, callable $renew, ?callable $stored = null ): array {
		$product = $this->start( $account, $content, $create_categories );

		return [
			'product'  => $product,
			'warnings' => $this->complete( $account, $content, $product, $publish, $renew, $stored ),
		];
	}

	/**
	 * Loads the profile of a card's mapping, an empty one when it has none.
	 *
	 * @param int|null $profile_id Profile id.
	 * @return Profile
	 */
	private function profile( ?int $profile_id ): Profile {
		$profile = null !== $profile_id ? $this->profiles->get( $profile_id ) : null;

		return $profile ?? new Profile( null, '' );
	}

	/**
	 * Sets description, attributes, weight and dimensions (step 2, before the price). The attributes are the card's characteristics
	 * ({@see Card_Attributes}); the variation of a group has none of its own.
	 *
	 * @param Card_Content $content Card.
	 * @param \WC_Product  $product Product.
	 * @param Profile      $profile Profile.
	 * @return void
	 */
	private function fill( Card_Content $content, \WC_Product $product, Profile $profile ): void {
		$this->describe( $content->description(), $product, (string) $profile->setting( 'description_source', 'description' ) );

		if ( ! $product->is_type( 'variation' ) ) { // A variation's attributes are its chosen values; the card's characteristics are the product's.
			Card_Attributes::write( $product, $content );
		}

		$units = (array) $profile->setting( 'units', [] );

		if ( null !== $content->weight_g() && $content->weight_g() > 0 ) {
			$product->set_weight( wc_format_decimal( wc_get_weight( $content->weight_g(), $this->unit( $units, 'weight', 'woocommerce_weight_unit' ), 'g' ) ) );
		}

		$dimensions = [
			'length' => $content->length_mm(),
			'width'  => $content->width_mm(),
			'height' => $content->height_mm(),
		];

		foreach ( $dimensions as $name => $millimetres ) {
			if ( null !== $millimetres && $millimetres > 0 ) {
				$product->{'set_' . $name}( wc_format_decimal( wc_get_dimension( $millimetres, $this->unit( $units, 'dimension', 'woocommerce_dimension_unit' ), 'mm' ) ) );
			}
		}
	}

	/**
	 * Gives a product that has no GTIN the first barcode of the card that is one. Only that one is tried: when another product
	 * holds it WooCommerce refuses it and the field stays empty.
	 *
	 * @param Card_Content $content Card.
	 * @param \WC_Product  $product Product.
	 * @return void
	 */
	private function gtin( Card_Content $content, \WC_Product $product ): void {
		$gtin = Gtin::first( (array) ( $content->card()['barcodes'] ?? [] ) );

		// WooCommerce has the field since 9.2.
		if ( '' === $gtin || ! is_callable( [ $product, 'set_global_unique_id' ] ) || '' !== (string) $product->get_global_unique_id( 'edit' ) ) {
			return;
		}

		try {
			$product->set_global_unique_id( $gtin );
		} catch ( \WC_Data_Exception $e ) {
			return; // Another product holds this GTIN. The barcode stays with the link.
		}
	}

	/**
	 * Returns the unit a measure is stored in: the profile's own (the export reads the product's numbers in it), else the shop's.
	 *
	 * @param array<mixed> $units  The profile's `units` setting: `weight` and `dimension`.
	 * @param string       $key    `weight` or `dimension`.
	 * @param string       $option The shop's unit option.
	 * @return string
	 */
	private function unit( array $units, string $key, string $option ): string {
		$unit = isset( $units[ $key ] ) ? (string) $units[ $key ] : '';

		return '' !== $unit ? $unit : (string) get_option( $option );
	}

	/**
	 * Writes the description where the profile reads it.
	 *
	 * @param string      $text    Raw description.
	 * @param \WC_Product $product Product.
	 * @param string      $source  `description`, `short_description` or `meta:{key}`.
	 * @return void
	 */
	private function describe( string $text, \WC_Product $product, string $source ): void {
		$text = wp_kses_post( $text );
		$key  = self::meta_key( $source );

		if ( '' !== $key ) {
			$product->update_meta_data( $key, $text );
		} elseif ( 'short_description' === $source ) {
			$product->set_short_description( $text );
		} else {
			$product->set_description( $text );
		}
	}

	/**
	 * Works the price out and saves it (step 2, after the first save).
	 *
	 * @param Account      $account Account.
	 * @param Card_Content $content Card.
	 * @param \WC_Product  $product Product, saved with its categories.
	 * @return string The warning, '' when the price was set or the product is left as it is.
	 */
	private function price( Account $account, Card_Content $content, \WC_Product $product ): string {
		$remote = $content->prices();

		if ( null === $remote ) {
			return __( 'На маркетплейсе у карточки нет цены — цена товара не задана.', 'woodev-plugin-framework' );
		}

		$refusal  = null;
		$restored = $this->prices->restore( $account, $product, $remote, null, $refusal );

		if ( null === $restored ) {
			/* translators: %s: why the price could not be worked out */
			return sprintf( __( 'Цена товара не задана: %s', 'woodev-plugin-framework' ), (string) $refusal );
		}

		$this->prices->write( $account->adapter_id(), $product, $restored );

		return '';
	}

	/**
	 * Loads the images where the profile reads them (step 3).
	 *
	 * @param Card_Content  $content Card.
	 * @param \WC_Product   $product Product.
	 * @param Profile       $profile Profile.
	 * @param callable      $renew   Extends the claim or throws.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored (a cached or failed one is no progress).
	 * @return string The warning, '' when every image is in.
	 */
	private function images( Card_Content $content, \WC_Product $product, Profile $profile, callable $renew, ?callable $stored = null ): string {
		$failed  = 0;
		$primary = (string) $profile->setting( 'primary_image_source', 'featured' );
		$others  = (string) $profile->setting( 'images_source', 'gallery' );

		if ( '' !== $content->primary_image() ) {
			$failed += $this->primary( $content->primary_image(), $product, $primary, $renew, $stored );
		}

		$failed += $this->gallery( $content->images(), $product, $others, $renew, $stored );

		if ( 0 === $failed ) {
			return '';
		}

		/* translators: %d: number of images */
		return sprintf( _n( 'Не удалось загрузить %d фото — товар создан без него.', 'Не удалось загрузить %d фото — товар создан без них.', $failed, 'woodev-plugin-framework' ), $failed );
	}

	/**
	 * Sets the main image: downloaded as the featured image, or its link written to the profile's custom field.
	 *
	 * @param string        $url     Image URL.
	 * @param \WC_Product   $product Product.
	 * @param string        $source  `featured` or `meta:{key}`.
	 * @param callable      $renew   Extends the claim or throws.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored (a cached or failed one is no progress).
	 * @return int Images that could not be loaded.
	 */
	private function primary( string $url, \WC_Product $product, string $source, callable $renew, ?callable $stored = null ): int {
		$key = self::meta_key( $source );

		if ( '' !== $key ) {
			$product->update_meta_data( $key, $url );

			return 0;
		}

		$attachment_id = $this->attachment( $url, $product, $renew, $stored );

		if ( 0 === $attachment_id ) {
			return 1;
		}

		$product->set_image_id( $attachment_id );

		return 0;
	}

	/**
	 * Sets the other images: downloaded into the gallery, their links written to the profile's custom field, or left out.
	 *
	 * @param string[]      $urls    Image URLs.
	 * @param \WC_Product   $product Product.
	 * @param string        $source  `gallery`, `none` or `meta:{key}`.
	 * @param callable      $renew   Extends the claim or throws.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored (a cached or failed one is no progress).
	 * @return int Images that could not be loaded.
	 */
	private function gallery( array $urls, \WC_Product $product, string $source, callable $renew, ?callable $stored = null ): int {
		$key = self::meta_key( $source );

		if ( 'none' === $source || [] === $urls ) {
			return 0;
		}

		if ( '' !== $key ) {
			$product->update_meta_data( $key, implode( "\n", $urls ) );

			return 0;
		}

		$ids    = [];
		$failed = 0;

		foreach ( $urls as $url ) {
			$attachment_id = $this->attachment( $url, $product, $renew, $stored );

			if ( $attachment_id > 0 ) {
				$ids[] = $attachment_id;
			} else {
				++$failed;
			}
		}

		$product->set_gallery_image_ids( $ids );

		return $failed;
	}

	/**
	 * Returns the attachment of an image: the one an earlier run (or WooCommerce's own importer) made from the same URL, else a
	 * new download. The claim is renewed first, so a lost claim stops the run before it spends 30 seconds on a download.
	 *
	 * @param string        $url      Image URL.
	 * @param \WC_Product   $product  Product the attachment is made for.
	 * @param callable      $renew    Extends the claim or throws.
	 * @param callable|null $stored `function (): void`; called right after a new image was stored (a cached or failed one is no progress).
	 * @return int Attachment id, 0 when the image could not be loaded.
	 */
	private function attachment( string $url, \WC_Product $product, callable $renew, ?callable $stored = null ): int {
		call_user_func( $renew );

		$url   = esc_url_raw( $url );
		$known = $this->known_attachment( $url );

		if ( $known > 0 ) {
			return $known;
		}

		$upload = $this->download( $url );

		if ( is_wp_error( $upload ) ) {
			return 0;
		}

		$attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload, (int) $product->get_id() );

		if ( $attachment_id <= 0 ) {
			return 0; // `wp_insert_attachment()` answers 0 when the attachment could not be made.
		}

		update_post_meta( $attachment_id, self::SOURCE_META, $url );

		if ( null !== $stored ) {
			call_user_func( $stored );
		}

		return $attachment_id;
	}

	/**
	 * Downloads an image with WooCommerce's helper, the one download bounded at {@see self::IMAGE_TIMEOUT}. The helper's own
	 * `download_url()` waits 300 seconds by default; the filter lives for this call only.
	 *
	 * @param string $url Image URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function download( string $url ) {
		$bound = static function ( array $args ): array {
			$args['timeout'] = self::IMAGE_TIMEOUT;

			return $args;
		};

		add_filter( 'http_request_args', $bound, PHP_INT_MAX );

		try {
			return wc_rest_upload_image_from_url( $url );
		} finally {
			remove_filter( 'http_request_args', $bound, PHP_INT_MAX );
		}
	}

	/**
	 * Finds the attachment made from a URL earlier (WooCommerce's convention).
	 *
	 * @param string $url Image URL.
	 * @return int Attachment id, 0 when there is none.
	 */
	private function known_attachment( string $url ): int {
		$found = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce's own lookup of an imported image.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- WooCommerce's own lookup of an imported image.
			]
		);

		return is_array( $found ) && [] !== $found ? (int) $found[0] : 0;
	}

	/**
	 * Returns the custom field a profile source names, '' when the source is not a custom field.
	 *
	 * @param string $source A source kind or `meta:{key}`.
	 * @return string
	 */
	private static function meta_key( string $source ): string {
		return 0 === strpos( $source, 'meta:' ) ? substr( $source, 5 ) : '';
	}
}
