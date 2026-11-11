<?php
/**
 * Whether a card's detail is still the card the list showed.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Flows\Card_Identity;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Identity
 */
final class CardIdentityTest extends TestCase {
	/**
	 * Builds a card.
	 *
	 * @param array<string,int|string> $ids Remote identifiers.
	 * @return array<string,mixed>
	 */
	private function card( array $ids ): array {
		return [
			'offer_id'   => 'A-1',
			'remote_ids' => $ids,
		];
	}

	/**
	 * The same card, with an identifier that only the detail carries, is the same card.
	 *
	 * @return void
	 */
	public function test_the_same_card_with_a_late_identifier_matches(): void {
		$this->assertTrue( Card_Identity::same( $this->card( [ 'card_ref' => 11 ] ), $this->card( [ 'card_ref' => '11', 'model_ref' => 500 ] ) ) );
	}

	/**
	 * Another remote card under the same article does not match.
	 *
	 * @return void
	 */
	public function test_another_remote_card_with_the_same_article_does_not_match(): void {
		$this->assertFalse( Card_Identity::same( $this->card( [ 'card_ref' => 11 ] ), $this->card( [ 'card_ref' => 911 ] ) ) );
	}

	/**
	 * Any shared identifier that disagrees is a different card, even when another one agrees.
	 *
	 * @return void
	 */
	public function test_one_disagreeing_identifier_is_enough(): void {
		$this->assertFalse( Card_Identity::same( $this->card( [ 'card_ref' => 11, 'model_ref' => 1 ] ), $this->card( [ 'card_ref' => 11, 'model_ref' => 2 ] ) ) );
	}

	/**
	 * Nothing in common is nothing to confirm by.
	 *
	 * @return void
	 */
	public function test_no_shared_identifier_does_not_match(): void {
		$this->assertFalse( Card_Identity::same( $this->card( [ 'card_ref' => 11 ] ), $this->card( [ 'model_ref' => 500 ] ) ) );
		$this->assertFalse( Card_Identity::same( $this->card( [] ), $this->card( [] ) ) );
	}
}
