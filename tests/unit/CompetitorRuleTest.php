<?php
/**
 * Tests for Woodev\Framework\Competitor\Competitor_Rule (s28).
 *
 * Covers: detect string→array normalization + any-match list; mode validation
 * (recommend/conflict accepted, anything else rejected); typed accessors and
 * defaults for our_* / overrides; image + actions defaults.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Woodev\Framework\Competitor\Competitor_Rule;
use InvalidArgumentException;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';

class CompetitorRuleTest extends TestCase {

	public function test_detect_string_is_normalized_to_array(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'cdek.php', 'mode' => 'recommend' ] );
		$this->assertSame( [ 'cdek.php' ], $rule->get_detect_slugs() );
	}

	public function test_detect_array_is_preserved(): void {
		$rule = new Competitor_Rule( [ 'detect' => [ 'a.php', 'b.php' ], 'mode' => 'conflict' ] );
		$this->assertSame( [ 'a.php', 'b.php' ], $rule->get_detect_slugs() );
	}

	public function test_recommend_mode_accepted(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'recommend' ] );
		$this->assertSame( 'recommend', $rule->get_mode() );
	}

	public function test_conflict_mode_accepted(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'conflict' ] );
		$this->assertSame( 'conflict', $rule->get_mode() );
	}

	public function test_invalid_mode_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'spam' ] );
	}

	public function test_missing_detect_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new Competitor_Rule( [ 'mode' => 'recommend' ] );
	}

	public function test_typed_accessors_and_defaults(): void {
		$rule = new Competitor_Rule(
			[
				'detect'          => 'cdek.php',
				'mode'            => 'recommend',
				'our_download_id' => 42,
				'our_url'         => 'https://woodev.ru/x',
				'our_name'        => 'СДЭК',
				'our_plugin_file' => 'woocommerce-edostavka.php',
				'competitor_name' => 'CDEKDelivery',
			]
		);

		$this->assertSame( 42, $rule->get_our_download_id() );
		$this->assertSame( 'https://woodev.ru/x', $rule->get_our_url() );
		$this->assertSame( 'СДЭК', $rule->get_our_name() );
		$this->assertSame( 'woocommerce-edostavka.php', $rule->get_our_plugin_file() );
		$this->assertSame( 'CDEKDelivery', $rule->get_competitor_name() );
	}

	public function test_defaults_when_optional_keys_absent(): void {
		$rule = new Competitor_Rule( [ 'detect' => 'x.php', 'mode' => 'conflict' ] );
		$this->assertSame( 0, $rule->get_our_download_id() );
		$this->assertSame( '', $rule->get_our_url() );
		$this->assertNull( $rule->get_our_plugin_file() );
		$this->assertNull( $rule->get_title_override() );
		$this->assertNull( $rule->get_content_override() );
	}

	public function test_note_name_is_mode_and_first_slug_scoped(): void {
		$rule = new Competitor_Rule( [ 'detect' => [ 'cdek.php', 'b.php' ], 'mode' => 'recommend' ] );
		$this->assertSame( 'woodev-competitor-recommend-cdek-php', $rule->get_note_name() );
	}
}
