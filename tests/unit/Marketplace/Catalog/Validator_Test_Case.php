<?php
/**
 * Shared set-up of the export validator tests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Rules\Category_Exists_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Category_Mapped_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Currency_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Dictionary_Values_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Dimensions_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Images_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Offer_Id_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Required_Fields_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Selling_Price_Rule;
use Woodev\Framework\Marketplace\Catalog\Rules\Variation_Group_Rule;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Field_Price_Source;

/**
 * Builds a product that passes every rule, so a test breaks exactly one thing and reads exactly one issue.
 */
abstract class Validator_Test_Case extends TestCase {
	/**
	 * Closes Mockery.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Mockery::close();
		parent::tearDown();
	}

	/**
	 * Builds a product that passes the product-dependent rules; `$props` replaces getter results (a closure gets the call arguments).
	 *
	 * @param array<string,mixed> $props Getter name => value or closure.
	 * @return \WC_Product
	 */
	protected function product( array $props = array() ): \WC_Product {
		$product  = \Mockery::mock( 'WC_Product' )->shouldIgnoreMissing();
		$defaults = array(
			'get_id'            => 10,
			'get_name'          => 'Диван',
			'get_sku'           => 'SOFA-1',
			'get_weight'        => '2',
			'get_length'        => '30',
			'get_width'         => '20',
			'get_height'        => '10',
			'get_price'         => '1000',
			'get_regular_price' => '1000',
		);

		foreach ( array_merge( $defaults, $props ) as $getter => $value ) {
			if ( $value instanceof \Closure ) {
				$product->shouldReceive( $getter )->andReturnUsing( $value );
			} else {
				$product->shouldReceive( $getter )->andReturn( $value );
			}
		}

		return $product;
	}

	/**
	 * Returns a mapped category with the brand source filled, plus extra sources.
	 *
	 * @param array<string,Source> $extra Extra sources by field id.
	 * @return Mapping
	 */
	protected function mapping( array $extra = array() ): Mapping {
		return new Mapping(
			5,
			array(
				'description_category_id' => 17029018,
				'type_id'                 => 94091,
			),
			null,
			$extra + array( '85' => new Source( Source::FIXED, 'Nike', '', '115840' ) )
		);
	}

	/**
	 * Returns the context of a product that passes every rule.
	 *
	 * @param array<string,mixed> $override Keys that replace the defaults.
	 * @return array<string,mixed>
	 */
	protected function context( array $override = array() ): array {
		return array_merge(
			array(
				'fields'                 => array( new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ) ),
				'remote_category_exists' => true,
				'image_urls'             => array( 'https://shop.example.ru/wp-content/uploads/sofa.jpg' ),
				'offer_id_owner'         => static function ( string $offer_id ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
					return null;
				},
				'article_policy'         => new Fake_Article_Policy(),
				'price_source'           => new Fake_Field_Price_Source(),
				'shop_currency'          => 'RUB',
				'account_currency'       => 'RUB',
			),
			$override
		);
	}

	/**
	 * Validates with the full scope.
	 *
	 * @param \WC_Product         $product Product.
	 * @param Mapping             $mapping Mapping.
	 * @param array<string,mixed> $context Context.
	 * @param Profile|null        $profile Profile; an empty one when null.
	 * @return Validation_Report
	 */
	protected function validate( \WC_Product $product, Mapping $mapping, array $context, ?Profile $profile = null ): Validation_Report {
		return $this->validator()->validate( $product, $mapping, $profile ?? new Profile( null, 'Профиль' ), $context );
	}

	/**
	 * Returns a validator with the rule list a marketplace of the Ozon kind passes in: all ten rules, in the merchant order.
	 *
	 * @return Export_Validator
	 */
	protected function validator(): Export_Validator {
		return new Export_Validator(
			array(
				new Category_Mapped_Rule(),
				new Category_Exists_Rule(),
				new Required_Fields_Rule( true ),
				new Dictionary_Values_Rule(),
				new Dimensions_Rule(),
				new Images_Rule(),
				new Offer_Id_Rule(),
				new Selling_Price_Rule(),
				new Currency_Rule(),
				new Variation_Group_Rule(),
			)
		);
	}

	/**
	 * Returns the codes of the report's errors.
	 *
	 * @param Validation_Report $report Report.
	 * @return string[]
	 */
	protected function error_codes( Validation_Report $report ): array {
		return array_column( $report->errors(), 'code' );
	}
}
