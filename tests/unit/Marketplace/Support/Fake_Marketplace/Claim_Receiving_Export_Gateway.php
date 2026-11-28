<?php
/**
 * The scripted export gateway of a marketplace that asks for the run's account claim.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Validation_Context;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Flows\Export_Gateway;
use Woodev\Framework\Marketplace\Flows\Receives_Export_Claim;

/**
 * Answers as {@see Scripted_Export_Gateway} does, keeps every claim it was handed and notes the order of the calls.
 */
final class Claim_Receiving_Export_Gateway implements Export_Gateway, Receives_Export_Claim {
	/**
	 * The calls the job made: `claim <account id>`, `build <link id>`, `submit`.
	 *
	 * @var string[]
	 */
	public $calls = [];

	/**
	 * The claim of each run: `function (): void`.
	 *
	 * @var callable[]
	 */
	public $holds = [];

	/**
	 * Builds the cards and answers the submits.
	 *
	 * @var Scripted_Export_Gateway
	 */
	private $scripted;

	/**
	 * Creates the gateway.
	 *
	 * @param Scripted_Export_Gateway $scripted Builds the cards and answers the submits.
	 */
	public function __construct( Scripted_Export_Gateway $scripted ) {
		$this->scripted = $scripted;
	}

	/**
	 * Keeps the claim of a run.
	 *
	 * @param Account  $account Account.
	 * @param callable $hold    `function (): void`.
	 * @return void
	 */
	public function receive_export_claim( Account $account, callable $hold ): void {
		$this->calls[] = 'claim ' . $account->id();
		$this->holds[] = $hold;
	}

	/**
	 * Builds a card.
	 *
	 * @param Link_Record        $link      Link.
	 * @param \WC_Product        $product   Product.
	 * @param Mapping            $mapping   Mapping.
	 * @param array              $overrides Overrides.
	 * @param Profile            $profile   Profile.
	 * @param Validation_Context $context   Context.
	 * @return array<string,mixed>
	 */
	public function build_item( Link_Record $link, \WC_Product $product, Mapping $mapping, array $overrides, Profile $profile, Validation_Context $context ): array {
		$this->calls[] = 'build ' . $link->link_id();

		return $this->scripted->build_item( $link, $product, $mapping, $overrides, $profile, $context );
	}

	/**
	 * Sends the cards.
	 *
	 * @param Account                        $account Account.
	 * @param array<int,array<string,mixed>> $items   Link id => card.
	 * @param string                         $context Job.
	 * @return string
	 * @throws \Throwable The scripted failure.
	 */
	public function submit( Account $account, array $items, string $context ): string {
		$this->calls[] = 'submit';

		return $this->scripted->submit( $account, $items, $context );
	}
}
