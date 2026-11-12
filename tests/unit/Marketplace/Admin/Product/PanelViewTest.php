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

	/**
	 * Renders the tab.
	 *
	 * @param array<int,array<string,mixed>> $blocks   Blocks.
	 * @param array<string,string>           $ops      Nonces of the buttons.
	 * @param bool                           $oversize Whether the marketplace has an oversize flag.
	 * @return string
	 */
	private function render_tab( array $blocks, array $ops, bool $oversize = false ): string {
		ob_start();
		( new Panel_View( new Screen_Names( 'fake', 'Fake' ), $oversize ) )->render_tab(
			[
				'blocks'       => $blocks,
				'nonce'        => 'save-nonce',
				'ops'          => $ops,
				'link_scope'   => '',
				'settings_url' => 'https://example.test/settings',
				'mappings_url' => 'https://example.test/mappings',
			]
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
