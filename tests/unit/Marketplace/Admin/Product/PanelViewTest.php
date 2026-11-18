<?php
/**
 * Unit tests for the markup of the product tab.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Product;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Product\Card_State_Badge;
use Woodev\Framework\Marketplace\Admin\Product\Panel_View;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Tests\Unit\TestCase;

final class PanelViewTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
	}

	public function test_without_accounts_the_tab_links_to_the_settings(): void {
		$html = $this->render_tab( [], [] );

		$this->assertStringContainsString( 'Fake', $html );
		$this->assertStringContainsString( 'href="https://example.test/settings"', $html );
		$this->assertStringNotContainsString( 'woodev_fake_nonce', $html );
	}

	public function test_the_tab_carries_its_own_nonce_field_and_names_fields_by_account(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'detach' => 'tok-detach' ] );

		$this->assertStringContainsString( 'name="woodev_fake_nonce" value="save-nonce"', $html );
		$this->assertStringContainsString( 'name="woodev_fake[3][sync_stock]"', $html );
	}

	public function test_only_the_buttons_the_user_has_a_nonce_for_are_offered(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'detach' => 'tok-detach' ] );

		$this->assertStringContainsString( 'data-woodev-mp-op="detach"', $html );
		$this->assertStringContainsString( 'data-nonce="tok-detach"', $html );
		$this->assertStringContainsString( 'data-action="woodev_fake_product"', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-op="export"', $html );
		$this->assertStringNotContainsString( 'data-woodev-mp-op="archive"', $html );
	}

	public function test_the_oversize_switch_is_shown_only_when_the_marketplace_has_one(): void {
		$without = $this->render_tab( [ $this->block() ], [] );
		$with    = $this->render_tab( [ $this->block() ], [], true );

		$this->assertStringNotContainsString( '[is_oversize]', $without );
		$this->assertStringContainsString( '[is_oversize]', $with );
	}

	public function test_the_price_fields_are_hidden_for_a_marketplace_without_prices(): void {
		$with = $this->render_tab( [ $this->block() ], [] );

		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ), false, false ) )->render_tab(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'ops'          => [],
				'link_scope'   => '',
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$without = (string) ob_get_clean();

		$this->assertStringContainsString( '[price][price][kind]', $with );
		$this->assertStringNotContainsString( '[price][price][kind]', $without );
		$this->assertStringContainsString( '[sync_price]', $without );
	}

	public function test_a_variation_panel_uses_the_loop_in_its_field_names(): void {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ) ) )->render_variation(
			[
				'blocks'       => [ $this->block() ],
				'nonce'        => 'save-nonce',
				'loop'         => 4,
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="woodev_fake_variation[4][3][sync_price]"', $html );
	}

	public function test_the_badge_collapses_the_lifecycle_into_the_seven_names(): void {
		$this->assertSame( 'Не связан', Card_State_Badge::of( Link_Record::STATE_UNLINKED )['label'] );
		$this->assertSame( 'Создаётся', Card_State_Badge::of( Link_Record::STATE_SKU_CREATED )['label'] );
		$this->assertSame( Card_State_Badge::TONE_OK, Card_State_Badge::of( Link_Record::STATE_CREATED )['tone'] );
		$this->assertSame( Card_State_Badge::TONE_ERROR, Card_State_Badge::of( Link_Record::STATE_IMPORTED_WITH_ERRORS )['tone'] );
		$this->assertTrue( Card_State_Badge::is_unsent( Link_Record::STATE_FAILED ) );
		$this->assertFalse( Card_State_Badge::is_unsent( Link_Record::STATE_CREATED ) );
	}

	public function test_the_mapping_option_is_short_and_its_link_goes_under_the_select(): void {
		$block                       = $this->block();
		$block['mapping']['resolved'] = 'Платья';
		$html                        = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '>По категории: Платья</option>', $html );
		$this->assertMatchesRegularExpression( '#</select><span class="woodev-mp-row-link"><a href="https://example.test/mappings">Открыть «Сопоставление»</a></span>#u', $html );
	}

	public function test_the_actions_on_this_product_are_one_group_of_icon_buttons_with_a_short_name(): void {
		$ops  = [
			'export'  => 't1',
			'link'    => 't2',
			'archive' => 't3',
			'detach'  => 't4',
		];
		$html = $this->render_tab( [ $this->block() ], $ops );

		$this->assertSame( 1, substr_count( $html, 'class="woodev-mp-buttons__row" role="group"' ) );
		preg_match( '#<span class="woodev-mp-buttons__row".*?</span><span class="woodev-mp-result#us', $html, $row );
		$this->assertSame( 3, substr_count( $row[0], '<button' ), 'export, archive, detach: the category-wide link is not an icon of this product' );
		$this->assertSame( 3, substr_count( $row[0], '<svg class="woodev-mp-btn__icon"' ) );
		$this->assertStringContainsString( 'aria-label="Архивировать на Fake"', $row[0] );
		$this->assertStringContainsString( 'title="Архивировать на Fake"', $row[0] );
		$this->assertStringContainsString( 'title="Снять связь" aria-label="Снять связь"', $row[0], 'the tooltip is the verb only, not the explanation' );
		$this->assertStringNotContainsString( 'Связать', $row[0] );
		$this->assertStringNotContainsString( 'Сайт забудет связь', $row[0] );
		$this->assertStringNotContainsString( 'Архивировать:', $html );
		$this->assertStringNotContainsString( '<label></label>', $html );
	}

	public function test_the_help_under_the_buttons_is_one_collapsed_block_with_the_category_link_in_it(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'export' => 't1', 'link' => 't2', 'detach' => 't4' ], false, [ 'link_scope' => 'Платья' ] );

		$this->assertMatchesRegularExpression( '#<fieldset class="form-field woodev-mp-hints"><details><summary>Подсказки</summary>#u', $html );
		$this->assertStringNotContainsString( '<details open', $html );
		$this->assertStringContainsString( 'сначала сохраните товар («Обновить» справа), затем нажмите «Обновить карточку».', $html );
		$this->assertStringContainsString( '«Снять связь»: Сайт забудет связь с карточкой маркетплейса.', $html );
		$this->assertStringNotContainsString( '«Архивировать на Fake»:', $html, 'a button the user has no nonce for is not explained' );
		$this->assertStringContainsString( 'для всех товаров категории «Платья», а не только для этого товара.', $html );
		$this->assertMatchesRegularExpression( '#<button type="button" class="button-link woodev-mp-link-op" data-woodev-mp-op="link"[^>]*>Связать категорию по артикулу</button>#u', $html );
		$this->assertSame( 1, substr_count( $html, '<details>' ), 'The help is one block, not one per paragraph.' );
	}

	public function test_the_category_link_is_not_offered_without_its_nonce(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'export' => 't1' ], false, [ 'link_scope' => 'Платья' ] );

		$this->assertStringNotContainsString( 'woodev-mp-link-op', $html );
		$this->assertStringNotContainsString( 'Платья', $html );
	}

	public function test_a_product_without_a_category_links_the_whole_shop(): void {
		$html = $this->render_tab( [ $this->block() ], [ 'link' => 't2' ] );

		$this->assertStringContainsString( '>Связать все товары по артикулу</button>', $html );
		$this->assertStringContainsString( 'идёт по всем товарам магазина', $html );
	}

	public function test_the_logging_hint_is_never_shown_to_a_merchant_who_has_not_turned_logging_on(): void {
		$block            = $this->block();
		$block['link_id'] = 7;
		$html             = $this->render_tab( [ $block ], [] );

		$this->assertStringNotContainsString( 'Включите «Логирование»', $html );
		$this->assertStringNotContainsString( 'Почему не синхронизируется', $html );
	}

	public function test_the_check_is_collapsed_under_a_counted_heading_with_a_short_fix_link(): void {
		$block                           = $this->block();
		$block['validation']['errors']   = [
			[
				'level'   => 'error',
				'message' => 'Не указан вес',
				'fix'     => 'mapping',
			],
			[
				'level'   => 'error',
				'message' => 'Нет главного фото',
				'fix'     => 'product',
			],
		];
		$html                            = $this->render_tab( [ $block ], [] );

		$this->assertMatchesRegularExpression( '#<fieldset class="form-field"><legend>Проверка<span class="woocommerce-help-tip"[^>]*></span></legend><details class="woodev-mp-issues">#u', $html );
		$this->assertStringNotContainsString( '<details class="woodev-mp-issues" open', $html );
		$this->assertStringContainsString( '>Исправить перед выгрузкой (2)</summary>', $html );
		$this->assertStringContainsString( 'Не указан вес <a href="https://example.test/mappings">Исправить</a>', $html );
		$this->assertStringContainsString( 'Нет главного фото</span>', $html );
	}

	public function test_a_variable_product_has_an_intro_across_the_tab_and_variants_whose_notes_stay_on_their_line(): void {
		$block         = $this->block();
		$block['kind'] = 'parent';
		$block['counts']      = [ 'Не связан' => 1 ];
		$block['any_created'] = false;
		$block['any_linked']  = false;
		$block['rows']        = [
			[
				'id'       => 5,
				'name'     => 'S',
				'badge'    => Card_State_Badge::of( Link_Record::STATE_UNLINKED ),
				'offer_id' => 'ART-S',
				'planned'  => true,
				'stock'    => '',
				'price'    => '',
				'currency' => 'RUB',
			],
		];
		$html = $this->render_tab( [ $block ], [ 'export' => 't1' ] );

		$this->assertStringContainsString( '<p class="form-field woodev-mp-intro"><span class="woodev-mp-note">Остатки, цены и проверка — у каждого варианта, во вкладке «Вариации».</span></p>', $html );
		$this->assertStringContainsString( 'Это вариативный товар: на маркетплейс уходит каждый вариант отдельной карточкой.', $html );
		$this->assertMatchesRegularExpression( '#<summary>Подсказки</summary><span class="woodev-mp-note">Это вариативный товар#u', $html, 'the rest of the explanation is in «Подсказки»' );
		$this->assertStringContainsString( '<div class="woodev-mp-variations-wrap"><table class="widefat striped woodev-mp-variations">', $html );
		$this->assertStringContainsString( 'ART-S <span class="woodev-mp-note">(будет отправлен)</span>', $html );
		$this->assertStringContainsString( '</table></div>', $html );
		$this->assertStringContainsString( '<summary>Подсказки</summary>', $html );
	}

	public function test_the_numbers_end_on_the_last_number_and_the_link_names_the_marketplace(): void {
		$block                     = $this->block();
		$block['ids']['summary']   = 'ID 1 · SKU 2 · ';
		$block['ids']['card_url']  = 'https://example.test/card/1';
		$html                      = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span>ID 1 · SKU 2 · <a href="https://example.test/card/1"', $html );
		$this->assertStringContainsString( '>Открыть на Fake</a>', $html );
		$this->assertStringNotContainsString( '· </span>', $html );

		$block['ids']['card_url'] = '';
		$html                     = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( '<span>ID 1 · SKU 2</span>', $html, 'no separator dangles after the last number' );
	}

	public function test_a_tooltip_sits_inside_its_label(): void {
		$block                     = $this->block();
		$block['ids']['summary']   = 'ID 1';
		$html                      = $this->render_tab( [ $block ], [] );

		$this->assertMatchesRegularExpression( '#<label for="[^"]*remote_ids">Номера: Fake<span class="woocommerce-help-tip"[^>]*></span></label>#u', $html );
	}

	public function test_the_price_boxes_show_the_computed_price_with_the_shops_currency_formatting(): void {
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, $args = [] ): string {
				return '<span class="amount"><bdi>' . number_format( (float) $amount, 2, ',', '' ) . '&nbsp;<span class="symbol">&#8381;</span></bdi></span>';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$block                      = $this->block();
		$block['price']['computed'] = [
			'price'     => '99.00',
			'old_price' => '120.5',
			'min_price' => '',
		];
		$html                       = $this->render_tab( [ $block ], [] );

		$this->assertStringContainsString( 'placeholder="99,00 ₽"', $html );
		$this->assertStringContainsString( 'placeholder="120,50 ₽"', $html );
		$this->assertStringContainsString( 'placeholder="Не задана"', $html );
	}

	/**
	 * Renders the tab.
	 *
	 * @param array<int,array<string,mixed>> $blocks   Blocks.
	 * @param array<string,string>           $ops      Nonces of the buttons.
	 * @param bool                           $oversize Whether the marketplace has an oversize flag.
	 * @param array<string,mixed>            $extra    Keys of the screen to add or replace.
	 * @return string
	 */
	private function render_tab( array $blocks, array $ops, bool $oversize = false, array $extra = [] ): string {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ), $oversize ) )->render_tab(
			array_merge(
				[
					'blocks'       => $blocks,
					'nonce'        => 'save-nonce',
					'ops'          => $ops,
					'link_scope'   => '',
					'settings_url' => 'https://example.test/settings',
					'mappings_url' => 'https://example.test/mappings',
				],
				$extra
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * Builds the block of an offer of account 3 whose card exists.
	 *
	 * @return array<string,mixed>
	 */
	private function block(): array {
		return [
			'kind'         => 'offer',
			'product_id'   => 41,
			'account'      => new Account( 3, 'fake', 'Основной', true ),
			'link_id'      => 0,
			'state'        => Link_Record::STATE_CREATED,
			'badge'        => Card_State_Badge::of( Link_Record::STATE_CREATED ),
			'remote_error' => '',
			'ids'          => [
				'editable' => false,
				'offer_id' => 'ART-1',
				'auto'     => 'ART-1',
				'summary'  => '',
				'card_url' => '',
			],
			'validation'   => [
				'errors'   => [],
				'warnings' => [],
				'failed'   => false,
			],
			'sync_stock'   => true,
			'sync_price'   => true,
			'oversize'     => false,
			'warehouse'    => [
				'selected' => null,
				'options'  => [
					[
						'value' => '',
						'label' => '',
					],
				],
				'default'  => '',
			],
			'price'        => [
				'override' => [],
				'computed' => [
					'price'     => '',
					'old_price' => '',
					'min_price' => '',
				],
				'currency' => 'RUB',
				'problem'  => '',
			],
			'mapping'      => [
				'selected' => null,
				'options'  => [],
				'resolved' => '',
			],
			'reasons'      => [
				'visible' => false,
				'list'    => [],
			],
			'can_detach'   => true,
			'can_archive'  => true,
		];
	}
}
