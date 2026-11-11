<?php
/**
 * The export context builder: mapping layers, the validator context and the price answers the sync reads.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Sync\Price_Context;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Export_Context_Builder
 */
final class ExportContextBuilderTest extends Export_Test_Case {
	/**
	 * The builder is the export-side answer the price sync asks for.
	 *
	 * @return void
	 */
	public function test_the_builder_is_the_price_context(): void {
		$this->assertInstanceOf( Price_Context::class, $this->contexts() );
	}

	/**
	 * Without a rule the marketplace price is the shop price in the cabinet currency.
	 *
	 * @return void
	 */
	public function test_the_price_pair_follows_the_account_rule(): void {
		$product = $this->add_product( [ 'price' => '1000', 'regular_price' => '1000' ] );
		$link    = $this->add_link( $product );

		$plain = $this->contexts()->price_pair( $this->account_now(), $product, $link );
		$this->assertSame( '1000.00', $plain->price()->to_string() );

		$this->set_account( [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => '10' ] ] );

		$marked = $this->contexts()->price_pair( $this->account_now(), $product, $link );
		$this->assertSame( '1100.00', $marked->price()->to_string() );
	}

	/**
	 * A profile's rule wins over the account's, and the link's override wins over both.
	 *
	 * @return void
	 */
	public function test_the_profile_and_the_link_override_the_account_rule(): void {
		$profile_id = $this->profiles()->create( 'Наценка 20', [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => '20' ] ] );
		$this->mappings()->update( $this->mapping_id, 'Блокноты', [ 'category_ref' => 'c-1' ], $profile_id, [] );
		$this->set_account( [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => '10' ] ] );

		$product = $this->add_product();
		$link    = $this->add_link( $product );

		$this->assertSame( '1200.00', $this->contexts()->price_pair( $this->account_now(), $product, $link )->price()->to_string() );

		$overridden = $link->with( [ 'price_rule_override' => [ 'price' => [ 'kind' => 'fixed', 'value' => '1500' ] ] ] );
		$this->assertSame( '1500.00', $this->contexts()->price_pair( $this->account_now(), $product, $overridden )->price()->to_string() );
	}

	/**
	 * A product with no price has no pair; a rule that cannot be read throws.
	 *
	 * @return void
	 */
	public function test_no_price_gives_no_pair_and_a_broken_rule_throws(): void {
		$free = $this->add_product( [ 'price' => '' ] );
		$this->assertNull( $this->contexts()->price_pair( $this->account_now(), $free, $this->add_link( $free ) ) );

		$this->set_account( [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => 'abc' ] ] );
		$product = $this->add_product();

		$this->expectException( \InvalidArgumentException::class );
		$this->contexts()->price_pair( $this->account_now(), $product, $this->add_link( $product ) );
	}

	/**
	 * A cabinet in another currency with no currency rule cannot be priced.
	 *
	 * @return void
	 */
	public function test_an_unresolved_currency_throws(): void {
		$this->facts->currency = 'CNY';
		$product               = $this->add_product();

		$this->expectException( \InvalidArgumentException::class );
		$this->contexts()->price_pair( $this->account_now(), $product, $this->add_link( $product ) );
	}

	/**
	 * The terms and the inverse give back the shop price, and the payload has the stored shape.
	 *
	 * @return void
	 */
	public function test_terms_inverse_and_payload(): void {
		$this->set_account( [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => '25' ] ] );
		$product  = $this->add_product();
		$contexts = $this->contexts();

		$terms    = $contexts->price_terms( $this->account_now(), $product );
		$restored = $contexts->invert( Money::from_string( '1250', 'RUB' ), null, $terms['rule'], null, $terms['currency'] );

		$this->assertSame( '1000.00', $restored['regular']->to_string() );
		$this->assertNull( $restored['sale'] );

		$pair = $contexts->price_pair( $this->account_now(), $product, $this->add_link( $product ) );
		$this->assertSame(
			[
				'price'         => '1250.00',
				'old_price'     => '0',
				'min_price'     => null,
				'currency_code' => 'RUB',
			],
			$contexts->payload( $pair )
		);
	}

	/**
	 * The context carries the adapter's facts, the flow's own facts and the price it worked out; the flow's win.
	 *
	 * @return void
	 */
	public function test_the_context_merges_the_marketplace_facts_with_the_flows_own(): void {
		$this->facts->facts = [
			'marketplace_name' => 'Fake',
			'image_urls'       => [ 'https://example.test/a.jpg' ],
			'frozen_offer_id'  => 'WRONG',
		];
		$product = $this->add_product( [ 'sku' => 'NB-7' ] );
		$other   = $this->add_product( [ 'sku' => 'NB-8' ] );
		$this->add_link( $other );

		$context = $this->contexts()->build( $this->account_now(), $product, null );
		$raw     = $context->raw_context();

		$this->assertSame( 'NB-7', $raw['frozen_offer_id'], 'the flow decides the article' );
		$this->assertSame( [ 'https://example.test/a.jpg' ], $raw['image_urls'] );
		$this->assertSame( 'RUB', $raw['shop_currency'] );
		$this->assertSame( 'RUB', $raw['account_currency'] );
		$this->assertSame( '1000.00', $raw['price_pair']->price()->to_string() );
		$this->assertNull( $raw['offer_id_owner']( 'NB-7' ) );
		$this->assertSame( (int) $other->get_id(), $raw['offer_id_owner']( 'NB-8' ) );
		$this->assertSame( $this->mapping_id, $context->mapping()->id() );
	}

	/**
	 * An article already sent stays the link's article, even when the SKU changed since.
	 *
	 * @return void
	 */
	public function test_a_frozen_article_stays(): void {
		$product = $this->add_product( [ 'sku' => 'NEW-SKU' ] );
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, (int) $product->get_id(), 0, 'OLD-SKU', [ 'card_state' => Link_Record::STATE_CREATED ] ) );
		$link = $this->link_of( (int) $product->get_id() );

		$this->assertSame( 'OLD-SKU', $this->contexts()->offer_id( $this->account_now(), $product, $link ) );
	}

	/**
	 * A variation sees the other variations of its parent that become cards, with their mappings.
	 *
	 * @return void
	 */
	public function test_siblings_are_the_other_exportable_variations(): void {
		$one     = $this->add_product( [ 'parent_id' => 400 ] );
		$two     = $this->add_product( [ 'parent_id' => 400 ] );
		$digital = $this->add_product( [ 'parent_id' => 400, 'downloadable' => true ] );
		$this->add_product( [ 'id' => 400, 'type' => 'variable', 'children' => [ $one->get_id(), $two->get_id(), $digital->get_id() ] ] );

		$siblings = $this->contexts()->build( $this->account_now(), $one, null )->raw_context()['siblings'];

		$this->assertCount( 1, $siblings );
		$this->assertSame( $two, $siblings[0]['product'] );
		$this->assertSame( $this->mapping_id, $siblings[0]['mapping']->id() );
	}

	/**
	 * A link override is laid over the mapping as a fixed source and also travels as an override value.
	 *
	 * @return void
	 */
	public function test_link_overrides_become_fixed_sources(): void {
		$product = $this->add_product();
		$this->add_link(
			$product,
			Link_Record::STATE_UNLINKED,
			[ 'attribute_overrides' => [ '85' => [ [ 'dictionary_value_id' => 0, 'value' => 'Brand X' ] ] ] ]
		);

		$context = $this->contexts()->build( $this->account_now(), $product, $this->link_of( (int) $product->get_id() ) );

		$this->assertSame( Source::FIXED, $context->mapping()->source_for( '85' )->kind() );
		$this->assertSame( 'Brand X', $context->overrides()['85'][0]['value'] );
	}

	/**
	 * The builder loads a product through `wc_get_product()` and answers null for a missing one.
	 *
	 * @return void
	 */
	public function test_a_missing_product_is_null(): void {
		$this->assertNull( $this->contexts()->product( 99999 ) );
	}
}
