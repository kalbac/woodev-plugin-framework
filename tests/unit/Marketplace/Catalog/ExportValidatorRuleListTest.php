<?php
/**
 * The export validator runs the rule list its marketplace gives it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Export_Validator;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Rules\Category_Mapped_Rule;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;
use Woodev\Framework\Marketplace\Catalog\Validation_Rule;

/**
 * D5 of the marketplace design: the layer ships the rules and the runner, the marketplace names the rules of the full scope and
 * their order; the mapping scope keeps its two neutral rules whatever the list says.
 */
final class ExportValidatorRuleListTest extends TestCase {
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
	 * Builds a rule that adds one error with a code.
	 *
	 * @param string $code Issue code.
	 * @return Validation_Rule
	 */
	private function failing_rule( string $code ): Validation_Rule {
		return new class( $code ) implements Validation_Rule {
			/**
			 * Issue code.
			 *
			 * @var string
			 */
			private $code;

			/**
			 * Creates the rule.
			 *
			 * @param string $code Issue code.
			 */
			public function __construct( string $code ) {
				$this->code = $code;
			}

			/**
			 * Adds the issue.
			 *
			 * @param \WC_Product        $product Product.
			 * @param Mapping            $mapping Mapping.
			 * @param Profile            $profile Profile.
			 * @param Validation_Context $context Context.
			 * @param Validation_Report  $report  Report so far.
			 * @return Validation_Report
			 */
			public function check( \WC_Product $product, Mapping $mapping, Profile $profile, Validation_Context $context, Validation_Report $report ): Validation_Report {
				return $report->with_issue( Validation_Report::ERROR, $this->code, 'x', null, Validation_Report::FIX_PRODUCT, 'x' );
			}
		};
	}

	/**
	 * Validates a product with a validator.
	 *
	 * @param Export_Validator    $validator Validator.
	 * @param array<string,mixed> $context   Context.
	 * @return string[] Error codes in report order.
	 */
	private function codes( Export_Validator $validator, array $context = array() ): array {
		$report = $validator->validate( \Mockery::mock( 'WC_Product' ), new Mapping( 5, array(), null ), new Profile( null, 'P' ), $context );

		return array_column( $report->errors(), 'code' );
	}

	/**
	 * The rules run in the order the marketplace gave them, and a different list gives a different report.
	 *
	 * @return void
	 */
	public function test_the_full_scope_runs_the_given_rules_in_order(): void {
		$one = new Export_Validator( array( $this->failing_rule( 'b' ), $this->failing_rule( 'a' ) ) );
		$two = new Export_Validator( array( $this->failing_rule( 'a' ) ) );

		$this->assertSame( array( 'b', 'a' ), $this->codes( $one ) );
		$this->assertSame( array( 'a' ), $this->codes( $two ) );
	}

	/**
	 * With no rules a product has nothing wrong with it as far as the full scope goes.
	 *
	 * @return void
	 */
	public function test_an_empty_list_reports_nothing(): void {
		$this->assertSame( array(), $this->codes( new Export_Validator( array() ) ) );
	}

	/**
	 * The mapping scope keeps its two neutral rules and ignores the marketplace's list.
	 *
	 * @return void
	 */
	public function test_the_mapping_scope_ignores_the_list(): void {
		$validator = new Export_Validator( array( $this->failing_rule( 'only-in-full-scope' ) ) );

		$codes = $this->codes( $validator, array( 'scope' => Export_Validator::SCOPE_MAPPING ) );

		$this->assertSame( array( Category_Mapped_Rule::CODE ), $codes );
	}

	/**
	 * An element that is not a rule is refused where the list is made, not when a product is first validated.
	 *
	 * @return void
	 */
	public function test_a_list_with_a_non_rule_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Export_Validator( array( $this->failing_rule( 'a' ), 'not a rule' ) ); // @phpstan-ignore-line
	}
}
