<?php
/**
 * The cards of one marketplace group assembled into one variable product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Sync\Price_Refresh_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * A marketplace may sell several cards as one group (the colours of one T-shirt). {@see Card_Content::group()} names the group
 * (`key`, '' when the card stands alone), its size and the card's own aspects (`Цвет => красный`). The importer makes **one**
 * shop product per group, whatever the order and the number of runs its cards arrive in:
 *
 * - the first card of a group is written as a simple product by {@see Product_Writer}, exactly like a card with no group, and
 *   {@see self::remember()} marks that product as the group's;
 * - when the group has several cards, or a second card of the group arrives later, {@see self::convert()} turns the product into
 *   a variable one **in place**: the product keeps its id, its content and its orders, and the card it was made from becomes its
 *   first variation;
 * - every further card becomes one more variation ({@see self::start_variation()}), filled by {@see Product_Writer::complete()}.
 *
 * So the red card today and the black card a week later give the same product as both at once. Only products the importer made
 * are ever assembled: a product the merchant linked by hand carries no group mark and is never converted.
 *
 * Two product meta keep the state, both written with the importer's own saves:
 *
 * - {@see self::group_key()}: `{account id}:{group key}`, on the product of the group for good — how a later card finds it;
 * - {@see self::variant_key()}: the aspects of the card a simple product was made from and, during a conversion, what the
 *   product gives up to its first variation. It is on the product from its first save until its conversion is complete, so a
 *   conversion that was cut short is finished by the next card of the group.
 *
 * The conversion runs in the order that never meets a duplicate SKU or a unique index of the links table: (1) the product
 * becomes `variable` and gives up its SKU; the SKU, the price, the stock and the measures are kept in the meta in that same
 * save, because WooCommerce reads a variable product's own price as empty and recomputes its stock status from its variations;
 * (2) the first variation takes them; (3) every offer row of the product moves to the variation
 * ({@see Wpdb_Link_Store::move_offer()}: the card moves, nothing new is linked); (4) the product gets a row of kind `parent` —
 * no article, so the variable product itself is never a card; then the product stops managing stock of its own. WooCommerce
 * clears the product's own price when it syncs the product with its variations, which every variation save asks for. Every
 * step tells from the stored state whether it has been done.
 *
 * The aspects are written as the product's own (custom) attributes, never as global attribute taxonomies. A card with no aspect
 * is told apart by its article under the attribute «Вариант».
 *
 * @since 2.1.6
 */
final class Group_Assembler {
	/**
	 * What a simple product holds that belongs to a variation once the product is variable.
	 *
	 * @var string[]
	 */
	private const VARIATION_PROPS = [ 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'manage_stock', 'stock_quantity', 'backorders', 'stock_status', 'weight', 'length', 'width', 'height', 'image_id' ];

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Creates the assembler.
	 *
	 * @since 2.1.6
	 *
	 * @param Wpdb_Link_Store $links Links.
	 */
	public function __construct( Wpdb_Link_Store $links ) {
		$this->links = $links;
	}

	/**
	 * Returns the meta key that names the group a product was imported for.
	 *
	 * @since 2.1.6
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function group_key( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_import_group';
	}

	/**
	 * Returns the meta key that keeps the card a simple product was made from, until the product is variable.
	 *
	 * @since 2.1.6
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function variant_key( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_import_variant';
	}

	/**
	 * Names the group of a card: the cards with one key become one product; '' means the card is a product of its own. The one
	 * group decision of the importer — the job and the preview of «Импортировать» both ask it.
	 *
	 * @since 2.1.6
	 *
	 * @param array<string,mixed> $group The `group` of a card: {@see Card_Content::group()}, or the same member of a snapshot card.
	 * @return string
	 */
	public static function key_of( array $group ): string {
		return trim( (string) ( $group['key'] ?? '' ) );
	}

	/**
	 * Returns the lookup of the product a group already has on the site. A read; nothing is written.
	 *
	 * @since 2.1.6
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return callable `function ( int $account_id, string $key ): int`, the product id, 0 when the group has no product yet.
	 */
	public static function lookup( string $marketplace_id ): callable {
		return static function ( int $account_id, string $key ) use ( $marketplace_id ): int {
			return self::find( $marketplace_id, $account_id, $key );
		};
	}

	/**
	 * Returns the product the card's group already has, null when the card stands alone or is the first of its group to arrive.
	 *
	 * @since 2.1.6
	 *
	 * @param Account      $account Account.
	 * @param Card_Content $content The card.
	 * @return \WC_Product|null
	 */
	public function product_of( Account $account, Card_Content $content ): ?\WC_Product {
		$key = self::key_of( $content->group() );

		if ( '' === $key ) {
			return null;
		}

		$id      = self::find( $account->adapter_id(), $account->id(), $key );
		$product = $id > 0 ? wc_get_product( $id ) : null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Marks a product the importer has just started as the product of its card's group, and keeps the card's aspects for the
	 * day the product becomes variable. Nothing happens for a card with no group, or a product that is marked already.
	 *
	 * @since 2.1.6
	 *
	 * @param Account      $account Account.
	 * @param \WC_Product  $product The product {@see Product_Writer::start()} saved.
	 * @param Card_Content $content The card.
	 * @return void
	 */
	public function remember( Account $account, \WC_Product $product, Card_Content $content ): void {
		$key  = self::key_of( $content->group() );
		$meta = self::group_key( $account->adapter_id() );

		if ( '' === $key || '' !== (string) $product->get_meta( $meta ) ) {
			return;
		}

		$product->update_meta_data( $meta, $account->id() . ':' . $key );
		$product->update_meta_data( self::variant_key( $account->adapter_id() ), [ 'aspects' => self::aspects( $content ) ] );
		$product->save();
	}

	/**
	 * Called once the card's own product is linked: the product of a group of several cards becomes variable at once, so a lone
	 * card of such a group is already the variation its siblings will join. A product that is not the importer's product of this
	 * group (the merchant's own, linked by its article) is left as it is.
	 *
	 * @since 2.1.6
	 *
	 * @param Account      $account Account.
	 * @param \WC_Product  $product The product the card was linked to.
	 * @param Card_Content $content The card.
	 * @param Claim_Fence  $fence   The run's account claim.
	 * @return void
	 * @throws Claim_Lost When another run took the account claim before the links were moved.
	 */
	public function settle( Account $account, \WC_Product $product, Card_Content $content, Claim_Fence $fence ): void {
		$group = $content->group();
		$key   = self::key_of( $group );

		if ( '' === $key || $group['size'] < 2 ) {
			return;
		}

		$owned = $account->id() . ':' . $key === (string) $product->get_meta( self::group_key( $account->adapter_id() ) );

		if ( $owned && is_array( $product->get_meta( self::variant_key( $account->adapter_id() ) ) ) ) {
			$this->convert( $account, $product, $fence );
		}
	}

	/**
	 * Turns the product of a group into a variable product whose first variation is the card the product was made from. In
	 * place: the product keeps its id. A product that is variable already, with nothing left to finish, is returned as it is.
	 *
	 * @since 2.1.6
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product The product of the group.
	 * @param Claim_Fence $fence   The run's account claim.
	 * @return \WC_Product The variable product.
	 * @throws Claim_Lost When another run took the account claim before the links were moved.
	 */
	public function convert( Account $account, \WC_Product $product, Claim_Fence $fence ): \WC_Product {
		$pending = $product->get_meta( self::variant_key( $account->adapter_id() ) );

		if ( ! is_array( $pending ) && $product->is_type( 'variable' ) ) {
			return $product;
		}

		// The price only moves from the product to its variation: nothing for the marketplace's price push to react to.
		return Price_Refresh_Guard::run(
			$account->adapter_id(),
			function () use ( $account, $product, $pending, $fence ): \WC_Product {
				$parent    = $this->as_variable( $account, $product, is_array( $pending ) ? $pending : [] );
				$variation = $this->first_variation( $parent, (array) $parent->get_meta( self::variant_key( $account->adapter_id() ) ) );

				$this->move_links( $account, (int) $parent->get_id(), (int) $variation->get_id(), $fence );

				return $this->finish_parent( $account, $parent );
			}
		);
	}

	/**
	 * Makes the first save of a card that joins a variable product: a switched-off variation with the article as its SKU and the
	 * import marker, both in one save, because a run that was cut short finds its variation again **by the SKU**.
	 *
	 * @since 2.1.6
	 *
	 * @param Account      $account Account.
	 * @param \WC_Product  $parent  The variable product of the group.
	 * @param Card_Content $content The card.
	 * @return \WC_Product The saved variation.
	 * @throws \WC_Data_Exception When another product already has the SKU.
	 */
	public function start_variation( Account $account, \WC_Product $parent, Card_Content $content ): \WC_Product {
		$variation = wc_get_product_object( 'variation' );

		$variation->set_parent_id( (int) $parent->get_id() );
		$variation->set_status( 'private' );
		$variation->set_sku( (string) ( $content->card()['offer_id'] ?? '' ) );
		$variation->set_attributes( self::variation_attributes( self::aspects( $content ) ) );
		$variation->update_meta_data( Product_Writer::marker_key( $account->adapter_id() ), '1' );
		$variation->save();

		return $variation;
	}

	/**
	 * Makes the variable product offer the aspects of a card, so that the card's variation can be chosen in the shop. Saves the
	 * product only when an aspect or a value is new to it.
	 *
	 * @since 2.1.6
	 *
	 * @param \WC_Product  $variation The card's variation.
	 * @param Card_Content $content   The card.
	 * @return void
	 */
	public function offer( \WC_Product $variation, Card_Content $content ): void {
		$parent = wc_get_product( (int) $variation->get_parent_id() );

		if ( $parent instanceof \WC_Product && $this->add_aspects( $parent, self::aspects( $content ) ) ) {
			$parent->save();
		}
	}

	/**
	 * Returns the card as a variation takes it: without the other images. A variation shows one image; the gallery is the
	 * product's, filled from the card the product was made from.
	 *
	 * @since 2.1.6
	 *
	 * @param Card_Content $content The card.
	 * @return Card_Content
	 */
	public static function variation_content( Card_Content $content ): Card_Content {
		return new Card_Content(
			$content->card(),
			[
				'name'            => $content->name(),
				'description'     => $content->description(),
				'prices'          => $content->prices(),
				'weight_g'        => $content->weight_g(),
				'length_mm'       => $content->length_mm(),
				'width_mm'        => $content->width_mm(),
				'height_mm'       => $content->height_mm(),
				'primary_image'   => $content->primary_image(),
				'images'          => [],
				'remote_category' => $content->remote_category(),
				'category_path'   => $content->category_path(),
				'group'           => $content->group(),
			]
		);
	}

	/**
	 * Decides whether a finished variation may sell at once. A variation of a product that is not published sells with the
	 * product, when the merchant publishes it. A variation that joins a **published** product would be on sale the moment it is
	 * saved, so unless the shop publishes imported products at once it is switched off and the merchant is told.
	 *
	 * @since 2.1.6
	 *
	 * @param \WC_Product $variation The finished variation.
	 * @param bool        $publish   Whether the shop publishes imported products at once.
	 * @return string[] Warnings for the merchant.
	 */
	public function hold_back( \WC_Product $variation, bool $publish ): array {
		$parent = $publish ? null : wc_get_product( (int) $variation->get_parent_id() );

		if ( ! $parent instanceof \WC_Product || 'publish' !== $parent->get_status() ) {
			return [];
		}

		$variation->set_status( 'private' );
		$variation->save();

		return [ __( 'Вариант добавлен в опубликованный товар выключенным. Проверьте его и включите в карточке товара.', 'woodev-plugin-framework' ) ];
	}

	/**
	 * Finds the product of a group: the oldest product that carries the group's mark, whatever its status short of the bin.
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @param int    $account_id     Account id.
	 * @param string $key            Group key.
	 * @return int Product id, 0 when there is none.
	 */
	private static function find( string $marketplace_id, int $account_id, string $key ): int {
		$found = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::group_key( $marketplace_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The one lookup of a group's product.
				'meta_value'     => $account_id . ':' . $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The one lookup of a group's product.
			]
		);

		return is_array( $found ) && [] !== $found ? (int) $found[0] : 0;
	}

	/**
	 * Step 1: the product becomes variable and gives up its SKU. What its first variation will take is read from the simple
	 * product and kept beside the aspects in the same save: a variable product no longer answers for its own price.
	 *
	 * @param Account             $account Account.
	 * @param \WC_Product         $product The product of the group.
	 * @param array<string,mixed> $pending What is kept of the card the product was made from.
	 * @return \WC_Product The variable product.
	 */
	private function as_variable( Account $account, \WC_Product $product, array $pending ): \WC_Product {
		if ( $product->is_type( 'variable' ) ) {
			return $product;
		}

		$sku     = (string) $product->get_sku( 'edit' );
		$aspects = self::clean( (array) ( $pending['aspects'] ?? [] ), $sku );
		$parent  = wc_get_product_object( 'variable', (int) $product->get_id() );

		$parent->update_meta_data(
			self::variant_key( $account->adapter_id() ),
			[
				'aspects' => $aspects,
				'sku'     => $sku,
				'props'   => self::variation_props( $product ),
			]
		);
		$parent->set_sku( '' );
		$this->add_aspects( $parent, $aspects );
		$parent->save();

		return $parent;
	}

	/**
	 * Reads what a simple product holds that its first variation will hold; a date becomes a timestamp, so the meta stores it.
	 *
	 * @param \WC_Product $product The simple product.
	 * @return array<string,mixed>
	 */
	private static function variation_props( \WC_Product $product ): array {
		$props = [];

		foreach ( self::VARIATION_PROPS as $prop ) {
			$value          = $product->{'get_' . $prop}( 'edit' );
			$props[ $prop ] = $value instanceof \DateTimeInterface ? $value->getTimestamp() : $value;
		}

		return $props;
	}

	/**
	 * Step 2: the first variation takes the former SKU and what a variable product cannot hold itself. While a conversion is
	 * open the product has no other variation, so a variation that is there already is the one an earlier run made.
	 *
	 * @param \WC_Product         $parent  The variable product.
	 * @param array<string,mixed> $variant The aspects of the product's own card, its former SKU and the values it gave up.
	 * @return \WC_Product The saved variation.
	 */
	private function first_variation( \WC_Product $parent, array $variant ): \WC_Product {
		$children = $parent->get_children();
		$existing = [] === $children ? null : wc_get_product( (int) reset( $children ) );

		if ( $existing instanceof \WC_Product ) {
			return $existing;
		}

		$variation = wc_get_product_object( 'variation' );

		$variation->set_parent_id( (int) $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->set_attributes( self::variation_attributes( (array) ( $variant['aspects'] ?? [] ) ) );

		foreach ( array_intersect_key( (array) ( $variant['props'] ?? [] ), array_flip( self::VARIATION_PROPS ) ) as $prop => $value ) {
			$variation->{'set_' . $prop}( $value );
		}

		$variation->set_sku( (string) ( $variant['sku'] ?? '' ) );
		$variation->save();

		return $variation;
	}

	/**
	 * Steps 3 and 4: the offer rows of the product, of every account, move to the variation, and each of those accounts gets the
	 * product's row of kind `parent` with the mapping the offer row named. Read from the table both times, so a run that was cut
	 * between the two finds the moved rows and still adds the parent rows.
	 *
	 * @param Account     $account      The account of the run.
	 * @param int         $parent_id    The variable product.
	 * @param int         $variation_id Its first variation.
	 * @param Claim_Fence $fence        The run's claim; it guards the rows of the run's own account.
	 * @return void
	 * @throws Claim_Lost When another run took the account claim.
	 */
	private function move_links( Account $account, int $parent_id, int $variation_id, Claim_Fence $fence ): void {
		foreach ( $this->links->offers_of_product( $parent_id ) as $row ) {
			$guard = $row->account_id() === $account->id() ? $fence : null;

			if ( $row->wc_product_id() === $parent_id && ! $this->links->move_offer( (int) $row->link_id(), $variation_id, $parent_id, $guard ) ) {
				$this->require_claim( $account, $guard );
			}
		}

		foreach ( $this->links->offers_of_product( $parent_id ) as $row ) {
			if ( $row->wc_product_id() !== $variation_id ) {
				continue;
			}

			$guard  = $row->account_id() === $account->id() ? $fence : null;
			$parent = Link_Record::parent_row( $row->account_id(), $parent_id, [ 'mapping_id' => $row->mapping_id() ] );

			if ( Wpdb_Link_Store::FENCED_OUT === $this->links->insert_card( $parent, '', $guard ) ) {
				$this->require_claim( $account, $guard );
			}
		}
	}

	/**
	 * Stops the run when a write of its own account was refused because the claim is gone.
	 *
	 * @param Account          $account Account.
	 * @param Claim_Fence|null $fence   The claim the write was made under, null for a row of another account.
	 * @return void
	 * @throws Claim_Lost When the claim is no longer held.
	 */
	private function require_claim( Account $account, ?Claim_Fence $fence ): void {
		if ( null !== $fence && ! $this->links->claim_held( $account->id(), $fence ) ) {
			throw new Claim_Lost( 'The account claim was taken over while a product was being converted to a variable one.' );
		}
	}

	/**
	 * Closes the conversion: the variable product manages no stock of its own, and nothing is left to finish. The product is
	 * read again first, so that it knows the variation it has now.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $parent  The variable product.
	 * @return \WC_Product The variable product, as stored.
	 */
	private function finish_parent( Account $account, \WC_Product $parent ): \WC_Product {
		$stored = wc_get_product( (int) $parent->get_id() );
		$parent = $stored instanceof \WC_Product ? $stored : $parent;

		$parent->set_manage_stock( false );
		$parent->delete_meta_data( self::variant_key( $account->adapter_id() ) );
		$parent->save();

		return $parent;
	}

	/**
	 * Adds aspects and their values to the product's own attributes, each one usable for variations.
	 *
	 * @param \WC_Product          $parent  The product.
	 * @param array<string,string> $aspects Aspect name => value.
	 * @return bool Whether the product's attributes changed (the caller saves).
	 */
	private function add_aspects( \WC_Product $parent, array $aspects ): bool {
		$attributes = $parent->get_attributes( 'edit' );
		$changed    = false;

		foreach ( $aspects as $name => $value ) {
			$slug    = sanitize_title( (string) $name );
			$known   = $attributes[ $slug ] ?? null;
			$options = $known instanceof \WC_Product_Attribute ? array_map( 'strval', $known->get_options() ) : [];

			if ( $known instanceof \WC_Product_Attribute && $known->get_variation() && in_array( $value, $options, true ) ) {
				continue;
			}

			$attributes[ $slug ] = $this->attribute( (string) $name, array_values( array_unique( array_merge( $options, [ $value ] ) ) ), $known, count( $attributes ) );
			$changed             = true;
		}

		if ( $changed ) {
			$parent->set_attributes( array_values( $attributes ) );
		}

		return $changed;
	}

	/**
	 * Builds a custom attribute of the product that varies. Always a new object: WooCommerce compares the attribute list by
	 * identity and would not save a list whose objects were only changed in place.
	 *
	 * @param string                     $name     Attribute name.
	 * @param string[]                   $options  Its values.
	 * @param \WC_Product_Attribute|null $known    The attribute the product has under that name, null for a new one.
	 * @param int                        $position Position of a new attribute.
	 * @return \WC_Product_Attribute
	 */
	private function attribute( string $name, array $options, ?\WC_Product_Attribute $known, int $position ): \WC_Product_Attribute {
		$attribute = null === $known ? new \WC_Product_Attribute() : clone $known;

		if ( null === $known ) {
			$attribute->set_name( $name );
			$attribute->set_visible( true );
			$attribute->set_position( $position );
		}

		$attribute->set_options( $options );
		$attribute->set_variation( true );

		return $attribute;
	}

	/**
	 * Returns the attributes of a variation: the value of each aspect, under the key WooCommerce gives a custom attribute.
	 *
	 * @param array<string,string> $aspects Aspect name => value.
	 * @return array<string,string>
	 */
	private static function variation_attributes( array $aspects ): array {
		$attributes = [];

		foreach ( $aspects as $name => $value ) {
			$attributes[ sanitize_title( (string) $name ) ] = (string) $value;
		}

		return $attributes;
	}

	/**
	 * Returns what tells a card apart inside its group.
	 *
	 * @param Card_Content $content The card.
	 * @return array<string,string>
	 */
	private static function aspects( Card_Content $content ): array {
		return self::clean( $content->group()['aspects'], (string) ( $content->card()['offer_id'] ?? '' ) );
	}

	/**
	 * Keeps the aspects that have a name and a value; a card with none is told apart by its article.
	 *
	 * @param array<mixed> $aspects Aspect name => value, as the adapter gave them.
	 * @param string       $article The card's article.
	 * @return array<string,string>
	 */
	private static function clean( array $aspects, string $article ): array {
		$clean = [];

		foreach ( $aspects as $name => $value ) {
			$name  = trim( (string) $name );
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';

			if ( '' !== $name && '' !== $value ) {
				$clean[ $name ] = $value;
			}
		}

		if ( [] === $clean && '' !== $article ) {
			$clean[ __( 'Вариант', 'woodev-plugin-framework' ) ] = $article;
		}

		return $clean;
	}
}
