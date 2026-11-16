<?php
/**
 * «Сбросить свою цену» and «Загрузить цены» of the «Товары сайта» table (#1797): the selection-mode half. The matching mode runs the
 * same {@see Site_Products_Bulk::run_part()} code; the pull job itself is tested in `PricePullTest`.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Line;
use Woodev\Framework\Marketplace\Data\Link_Record;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk
 */
final class SiteProductsPriceActionsTest extends Products_Test_Case {
	private const OVERRIDE = [
		'price' => [
			'kind'  => 'percent',
			'value' => 20,
		],
	];

	/**
	 * @param string $action Action id.
	 * @param int[]  $offers Offers.
	 * @return array<string,mixed>
	 */
	private function apply( string $action, array $offers ): array {
		return $this->bulk()->apply( $this->account_now(), $action, $offers, array_combine( $offers, $offers ) ?: [] );
	}

	/**
	 * A created, price-synced card whose link may carry an own price.
	 *
	 * @param bool   $override Whether the link has an own price.
	 * @param string $state    Card state.
	 * @return int Product id.
	 */
	private function linked( bool $override, string $state = Link_Record::STATE_CREATED ): int {
		$product = $this->add_product();
		$link    = $this->add_link( $product, $state );

		if ( $override ) {
			$this->links()->set_price_override( (int) $link->link_id(), self::OVERRIDE );
		}

		return (int) $product->get_id();
	}

	/**
	 * D2: the override is removed and the price the rule gives is recorded as the intent; a row with no own price is skipped with the
	 * reason, and nothing is written by the preview.
	 *
	 * @return void
	 */
	public function test_reset_price_writes_null_records_the_price_intent_and_skips_rows_without_an_override(): void {
		$with    = $this->linked( true );
		$without = $this->linked( false );

		$plan = $this->bulk()->preview( $this->account_now(), Site_Products_Bulk::RESET_PRICE, [ $with, $without ], [ $with => $with, $without => $without ] );

		$this->assertSame( [ 2, 1, 1 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertSame( 'своей цены нет', $plan['groups'][0]['reason'] );
		$this->assertNotNull( $this->link_of( $with )->price_rule_override(), 'A preview writes nothing.' );

		$result = $this->apply( Site_Products_Bulk::RESET_PRICE, [ $with, $without ] );

		$this->assertSame( [ 1, 1, 0 ], [ $result['accepted'], $result['skipped'], $result['failed'] ] );
		$this->assertNull( $this->link_of( $with )->price_rule_override() );
		$this->assertNull( $this->link_of( $without )->price_rule_override() );
		$this->assertNotNull( $this->intents()->pending_of( $this->account_id, (int) $this->link_of( $with )->link_id(), 0, 'price' ), 'The price the rule gives now is wanted on the marketplace.' );
		$this->assertNull( $this->intents()->pending_of( $this->account_id, (int) $this->link_of( $without )->link_id(), 0, 'price' ) );
		$this->assertFalse( $result['queued'], 'The reset finishes inside the request.' );
	}

	/**
	 * A link that is not created has an own price too; clearing it records no intent (nothing to send yet) but is still a reset.
	 *
	 * @return void
	 */
	public function test_reset_price_also_clears_the_override_of_a_card_that_is_not_created_yet(): void {
		$waiting = $this->linked( true, Link_Record::STATE_UNLINKED );

		$result = $this->apply( Site_Products_Bulk::RESET_PRICE, [ $waiting ] );

		$this->assertSame( 1, $result['accepted'] );
		$this->assertNull( $this->link_of( $waiting )->price_rule_override() );
		$this->assertNull( $this->intents()->pending_of( $this->account_id, (int) $this->link_of( $waiting )->link_id(), 0, 'price' ) );
	}

	/**
	 * D3: the created cards of the selection are queued for the pull by article; a card that is not created, and a product never sent,
	 * are left out with the reason; nothing is queued by the preview.
	 *
	 * @return void
	 */
	public function test_pull_prices_queues_the_created_cards_by_article_and_names_the_rest(): void {
		$created = $this->linked( false );
		$silent  = $this->linked( false, Link_Record::STATE_UNLINKED );
		$never   = (int) $this->add_product()->get_id();
		$ids     = [ $created, $silent, $never ];

		$plan = $this->bulk()->preview( $this->account_now(), Site_Products_Bulk::PULL_PRICES, $ids, array_combine( $ids, $ids ) ?: [] );

		$this->assertSame( [ 3, 1, 2 ], [ $plan['requested'], $plan['eligible'], $plan['skipped'] ] );
		$this->assertSame( [], $this->pulled, 'A preview queues nothing.' );

		$result  = $this->apply( Site_Products_Bulk::PULL_PRICES, $ids );
		$reasons = array_column( $result['groups'], 'reason' );

		$this->assertSame( [ 1, 2, 0 ], [ $result['accepted'], $result['skipped'], $result['failed'] ] );
		$this->assertTrue( $result['queued'] );
		$this->assertSame( [ [ $this->link_of( $created )->offer_id() ] ], $this->pulled );
		$this->assertContains( 'карточка ещё не создана на маркетплейсе', $reasons );
		$this->assertContains( 'не отправлялся на маркетплейс — сначала экспортируйте товар', $reasons );
		$this->assertStringContainsString( 'Загрузка цен поставлена в очередь', $result['messages']['action_status_queued'] );
	}

	/**
	 * A link whose price switch is off, and an account that pushes prices, are pulled all the same: the merchant asked explicitly.
	 *
	 * @return void
	 */
	public function test_pull_prices_does_not_look_at_the_price_switch_or_the_direction(): void {
		$product = $this->add_product();
		$this->add_link( $product, Link_Record::STATE_CREATED, [ 'sync_price' => 0 ] );
		$id = (int) $product->get_id();

		$this->set_account( [ 'price_direction' => 'to_marketplace' ] );

		$plan = $this->bulk()->preview( $this->account_now(), Site_Products_Bulk::PULL_PRICES, [ $id ], [ $id => $id ] );

		$this->assertSame( 1, $plan['eligible'] );
	}

	/**
	 * A scheduler that refuses the batches is an error the merchant reads, not a silent «done».
	 *
	 * @return void
	 */
	public function test_pull_prices_says_so_when_the_queue_refuses(): void {
		$id                 = $this->linked( false );
		$this->pull_refused = true;

		$this->expectException( Action_State_Exception::class );
		$this->expectExceptionMessageMatches( '/Action Scheduler/' );

		$this->apply( Site_Products_Bulk::PULL_PRICES, [ $id ] );
	}

	/**
	 * The two actions are declared with the others, labelled as the account line labels them, and a lapsed licence stops the pull (it queues a
	 * read of the marketplace) but not the reset (it writes only on the site).
	 *
	 * @return void
	 */
	public function test_the_two_actions_are_declared_labelled_and_the_pull_is_licence_gated(): void {
		$actions = array_column( Site_Products_Definition::build( [], 'Ozon', [] )['bulk_actions'], null, 'id' );

		$this->assertSame( 'Сбросить свою цену', $actions['reset_price']['label'] );
		$this->assertSame( 'Загрузить цены', $actions['pull_prices']['label'] );
		$this->assertStringContainsString( 'с Ozon', $actions['pull_prices']['scope_sentence'], 'The sentence names the marketplace.' );
		$this->assertTrue( $actions['pull_prices']['all_matching'], 'Both run over every product under a filter too.' );
		$this->assertTrue( $actions['reset_price']['all_matching'] );
		$this->assertNull( Site_Products_Bulk::license_phrase( Site_Products_Bulk::RESET_PRICE ) );
		$this->assertNotNull( Site_Products_Bulk::license_phrase( Site_Products_Bulk::PULL_PRICES ) );
	}

	/**
	 * The preview sentence of the matching mode and the wording of the finished run name the two actions.
	 *
	 * @return void
	 */
	public function test_the_matching_mode_has_words_for_both_actions(): void {
		$this->assertStringContainsString( 'своя цена будет удалена', Site_Products_Run_Line::effect( Site_Products_Bulk::RESET_PRICE, 'Ozon' ) );
		$this->assertStringContainsString( 'будут прочитаны с Ozon', Site_Products_Run_Line::effect( Site_Products_Bulk::PULL_PRICES, 'Ozon' ) );
	}
}
