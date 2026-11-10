<?php
/**
 * Export validator: the product-dependent rules of spec 5.1.1.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Offer_Id;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Rules\Images_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Selling_Price_Rule;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Framework\Marketplace\Price\Price_Pair;

/**
 * One group of cases per rule, on the baseline product that passes everything.
 */
final class ProductRulesTest extends Validator_Test_Case {
	/**
	 * Rule: weight above zero, product first, profile fallback second.
	 *
	 * @return void
	 */
	public function test_weight(): void {
		$none = $this->validate( $this->product( array( 'get_weight' => '' ) ), $this->mapping(), $this->context() );
		$this->assertSame( array( 'weight_missing' ), $this->error_codes( $none ) );
		$this->assertSame( 'нет веса', $none->first_error()['summary'] );
		$this->assertSame( Validation_Report::FIX_PRODUCT, $none->first_error()['fix'] );

		$zero = $this->validate( $this->product( array( 'get_weight' => '0' ) ), $this->mapping(), $this->context() );
		$this->assertSame( array( 'weight_missing' ), $this->error_codes( $zero ) );

		$fallback = new Profile( null, 'П', array( 'fallback_weight' => 0.5 ) );
		$this->assertTrue( $this->validate( $this->product( array( 'get_weight' => '' ) ), $this->mapping(), $this->context(), $fallback )->is_valid() );
	}

	/**
	 * Rule: all three dimensions above zero; the message names the missing ones; the profile's depth / width / height are the fallbacks.
	 *
	 * @return void
	 */
	public function test_dimensions(): void {
		$product = $this->product(
			array(
				'get_length' => '',
				'get_height' => '0',
			)
		);
		$report  = $this->validate( $product, $this->mapping(), $this->context() );

		$this->assertSame( array( 'dimensions_missing' ), $this->error_codes( $report ) );
		$this->assertStringContainsString( 'длина, высота', $report->first_error()['message'] );
		$this->assertStringNotContainsString( 'ширина', $report->first_error()['message'] );

		$profile = new Profile(
			null,
			'П',
			array(
				'fallback_depth'  => 40,
				'fallback_height' => 15,
			)
		);
		$this->assertTrue( $this->validate( $product, $this->mapping(), $this->context(), $profile )->is_valid() );
	}

	/**
	 * Rule: at least one image URL.
	 *
	 * @return void
	 */
	public function test_image_missing(): void {
		$report = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'image_urls' => array() ) ) );

		$this->assertSame( array( 'image_missing' ), $this->error_codes( $report ) );
		$this->assertSame( 'нет фото', $report->first_error()['summary'] );
	}

	/**
	 * Rule: an image URL the marketplace can fetch is absolute https on a public host.
	 *
	 * @dataProvider unusable_image_urls
	 * @param string $url Image URL.
	 * @return void
	 */
	public function test_image_url_the_marketplace_cannot_fetch( string $url ): void {
		$report = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'image_urls' => array( $url ) ) ) );

		$this->assertSame( array( 'image_not_public' ), $this->error_codes( $report ), $url );
		$this->assertStringContainsString( $url, $report->first_error()['message'] );
	}

	/**
	 * URLs that fail.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unusable_image_urls(): array {
		return array(
			'plain http'        => array( 'http://shop.example.ru/a.jpg' ),
			'relative path'     => array( '/wp-content/uploads/a.jpg' ),
			'localhost'         => array( 'https://localhost/a.jpg' ),
			'dot local domain'  => array( 'https://shop.local/a.jpg' ),
			'dot test domain'   => array( 'https://shop.test/a.jpg' ),
			'single-label host' => array( 'https://intranet/a.jpg' ),
			'private IPv4'      => array( 'https://192.168.1.10/a.jpg' ),
			'loopback IPv4'     => array( 'https://127.0.0.1/a.jpg' ),
			'loopback IPv6'     => array( 'https://[::1]/a.jpg' ),
			'not a URL'         => array( 'sofa.jpg' ),
		);
	}

	/**
	 * One usable image among unusable ones is enough; a public IP passes.
	 *
	 * @return void
	 */
	public function test_one_public_https_image_is_enough(): void {
		$urls = array( 'http://shop.example.ru/a.jpg', 'https://cdn.example.ru/b.jpg' );

		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $this->context( array( 'image_urls' => $urls ) ) )->is_valid() );
		$this->assertTrue( Images_Rule::is_public_https( 'https://93.184.216.34/a.jpg' ) );
	}

	/**
	 * Rule: the article is unique in the account, archived cards included.
	 *
	 * @return void
	 */
	public function test_offer_id_taken_by_another_product(): void {
		$owner  = static function ( string $offer_id ): ?int {
			return 'SOFA-1' === $offer_id ? 77 : null;
		};
		$report = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'offer_id_owner' => $owner ) ) );

		$this->assertSame( array( 'offer_id_taken' ), $this->error_codes( $report ) );
		$this->assertStringContainsString( 'SOFA-1', $report->first_error()['message'] );
		$this->assertStringContainsString( 'ID 77', $report->first_error()['message'] );

		$self = static function ( string $offer_id ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
			return 10;
		};
		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $this->context( array( 'offer_id_owner' => $self ) ) )->is_valid(), 'a re-export finds its own row' );
	}

	/**
	 * The rule checks the article the builder will send: frozen first, normalised SKU next, `wc-{id}` for no SKU.
	 *
	 * @return void
	 */
	public function test_offer_id_checked_is_the_one_the_builder_sends(): void {
		$seen  = array();
		$spy   = static function ( string $offer_id ) use ( &$seen ): ?int {
			$seen[] = $offer_id;

			return null;
		};
		$build = function ( array $props, array $extra = array() ) use ( $spy ): void {
			$this->validate( $this->product( $props ), $this->mapping(), $this->context( array_merge( array( 'offer_id_owner' => $spy ), $extra ) ) );
		};

		$build( array( 'get_sku' => '  Диван красный 3м  ' ) );
		$build( array( 'get_sku' => '' ) );
		$build( array( 'get_sku' => 'NEW-SKU' ), array( 'frozen_offer_id' => 'OLD-SKU' ) );
		$build( array( 'get_sku' => str_repeat( 'Я', 60 ) ) );

		$this->assertSame( array( 'Диван-красный-3м', 'wc-10', 'OLD-SKU', str_repeat( 'Я', 50 ) ), $seen );
		$this->assertSame( 'SKU-1', ( new Offer_Id( new Fake_Article_Policy() ) )->normalise( ' SKU 1' ) );
	}

	/**
	 * Without a lookup the article cannot be judged, and the rule stays silent.
	 *
	 * @return void
	 */
	public function test_offer_id_without_a_lookup_is_not_checked(): void {
		$context = $this->context();
		unset( $context['offer_id_owner'] );

		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $context )->is_valid() );
	}

	/**
	 * Rule: the price is above zero.
	 *
	 * @return void
	 */
	public function test_price_missing(): void {
		foreach ( array( '', '0', 'abc' ) as $price ) {
			$report = $this->validate( $this->product( array( 'get_price' => $price ) ), $this->mapping(), $this->context() );

			$this->assertSame( array( 'price_missing' ), $this->error_codes( $report ), 'price: ' . $price );
		}
	}

	/**
	 * Rule: an old price is absent or above the price; a sale product (regular above active) is fine.
	 *
	 * @return void
	 */
	public function test_old_price_must_be_above_the_price(): void {
		$sale = $this->product(
			array(
				'get_price'         => '800',
				'get_regular_price' => '1000',
			)
		);
		$this->assertTrue( $this->validate( $sale, $this->mapping(), $this->context() )->is_valid() );

		$rule = new Selling_Price_Rule();
		$base = new Validation_Report();
		$rub  = static function ( string $amount ): Money {
			return Money::from_string( $amount, 'RUB' );
		};

		$this->assertTrue( $rule->check_pair( new Price_Pair( $rub( '800' ), $rub( '1000' ) ), $base )->is_valid() );
		$this->assertTrue( $rule->check_pair( new Price_Pair( $rub( '800' ), null ), $base )->is_valid(), 'no old price' );
		$this->assertTrue( $rule->check_pair( new Price_Pair( $rub( '800' ), $rub( '0' ) ), $base )->is_valid(), 'a zero old price clears it' );

		$equal = $rule->check_pair( new Price_Pair( $rub( '800' ), $rub( '800' ) ), $base );
		$this->assertSame( array( 'old_price_below_price' ), $this->error_codes( $equal ) );
		$this->assertSame( array( 'old_price_below_price' ), $this->error_codes( $rule->check_pair( new Price_Pair( $rub( '800' ), $rub( '500' ) ), $base ) ) );
		$this->assertSame( Validation_Report::FIX_PROFILE, $equal->first_error()['fix'] );
	}

	/**
	 * Rule: shop and cabinet currency.
	 *
	 * @return void
	 */
	public function test_currency_mismatch(): void {
		$cny    = $this->context(
			array(
				'account_currency' => 'CNY',
				'marketplace_name' => 'Ozon',
			)
		);
		$report = $this->validate( $this->product(), $this->mapping(), $cny );

		$this->assertSame( array( 'currency_mismatch' ), $this->error_codes( $report ) );
		$this->assertSame( 'Валюта магазина RUB, валюта кабинета CNY — укажите курс или цену для Ozon в профиле.', $report->first_error()['message'] );
		$this->assertSame( Validation_Report::FIX_PROFILE, $report->first_error()['fix'] );
	}

	/**
	 * A currency rule in the profile resolves the mismatch; a rule for the wrong currency does not; an unknown cabinet is not judged.
	 *
	 * @return void
	 */
	public function test_currency_rule_in_the_profile(): void {
		$cny = $this->context( array( 'account_currency' => 'CNY' ) );

		$right = new Profile( null, 'П', array( 'currency_rule' => 'fixed:CNY' ) );
		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $cny, $right )->is_valid() );

		$rate = new Profile( null, 'П', array( 'currency_rule' => 'rate:0.08' ) );
		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $cny, $rate )->is_valid() );

		$wrong  = new Profile( null, 'П', array( 'currency_rule' => 'fixed:USD' ) );
		$report = $this->validate( $this->product(), $this->mapping(), $cny, $wrong );
		$this->assertSame( array( 'currency_mismatch' ), $this->error_codes( $report ) );
		$this->assertStringContainsString( 'USD', $report->first_error()['message'] );

		$unknown = $this->context();
		unset( $unknown['account_currency'] );
		$unknown['shop_currency'] = 'EUR';
		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $unknown )->is_valid() );
	}

	/**
	 * Rule: variations of one parent share the model name.
	 *
	 * @return void
	 */
	public function test_variations_must_share_the_model_name(): void {
		$sibling_mapping = $this->mapping( array( '9048' => new Source( Source::FIXED, 'Диван Лофт' ) ) );
		$own_mapping     = $this->mapping( array( '9048' => new Source( Source::FIXED, 'Диван Лофт-2' ) ) );
		$context         = $this->context(
			array(
				'shared_field_ids' => array( '9048' ),
				'siblings'         => array(
					array(
						'product' => $this->product( array( 'get_name' => 'Диван красный' ) ),
						'mapping' => $sibling_mapping,
					),
				),
			)
		);

		$report = $this->validate( $this->product(), $own_mapping, $context );
		$this->assertSame( array( 'variation_model_differs' ), $this->error_codes( $report ) );
		$this->assertSame( '9048', $report->first_error()['field_id'] );

		$this->assertTrue( $this->validate( $this->product(), $sibling_mapping, $context )->is_valid() );
	}

	/**
	 * Rule: each variation differs from every sibling in an aspect attribute.
	 *
	 * @return void
	 */
	public function test_variations_must_differ_in_an_aspect(): void {
		$colour = new Source( Source::WC_ATTRIBUTE, '', 'pa_color' );
		$with   = static function ( string $value ): array {
			return array(
				'get_attribute' => static function ( string $key ) use ( $value ): string {
					return 'pa_color' === $key ? $value : '';
				},
			);
		};
		$fields = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_STRING ),
		);
		$mapped = $this->mapping( array( '10096' => $colour ) );

		$build = function ( string $sibling_colour ) use ( $with, $mapped, $fields ): array {
			return $this->context(
				array(
					'fields'           => $fields,
					'aspect_field_ids' => array( '10096' ),
					'siblings'         => array(
						array(
							'product' => $this->product( array_merge( $with( $sibling_colour ), array( 'get_name' => 'Диван чёрный' ) ) ),
							'mapping' => $mapped,
						),
					),
				)
			);
		};

		$same = $this->validate( $this->product( $with( 'Красный' ) ), $mapped, $build( 'Красный' ) );
		$this->assertSame( array( 'variation_aspects_equal' ), $this->error_codes( $same ) );
		$this->assertStringContainsString( 'Диван чёрный', $same->first_error()['message'] );

		$this->assertTrue( $this->validate( $this->product( $with( 'Красный' ) ), $mapped, $build( 'Чёрный' ) )->is_valid() );

		$no_siblings = $this->context( array( 'aspect_field_ids' => array( '10096' ) ) );
		$this->assertTrue( $this->validate( $this->product(), $mapped, $no_siblings )->is_valid(), 'a simple product has no siblings' );
	}

	/**
	 * A product with several problems lists all of them in the merchant order; the first one is its group.
	 *
	 * @return void
	 */
	public function test_one_report_lists_every_problem_in_merchant_order(): void {
		$product = $this->product(
			array(
				'get_weight' => '',
				'get_price'  => '',
			)
		);
		$report  = $this->validate( $product, $this->mapping(), $this->context( array( 'image_urls' => array() ) ) );

		$this->assertSame( array( 'weight_missing', 'image_missing', 'price_missing' ), $this->error_codes( $report ) );
		$this->assertSame( 'weight_missing', $report->first_error()['code'] );
	}
}
