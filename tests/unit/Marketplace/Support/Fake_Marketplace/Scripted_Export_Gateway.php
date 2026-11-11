<?php
/**
 * The marketplace's card builder and import request, scripted.
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

/**
 * Builds `{offer_id, product}` cards and answers a submit from a script: the next entry of {@see self::$answers} is returned (a
 * string is the remote task number) or thrown (a `\Throwable`); with the script empty it returns {@see self::$task_number}.
 */
final class Scripted_Export_Gateway implements Export_Gateway {
	/**
	 * Product ids whose card cannot be built.
	 *
	 * @var int[]
	 */
	public $unbuildable = [];

	/**
	 * The number an unscripted submit returns.
	 *
	 * @var string
	 */
	public $task_number = 'task-9001';

	/**
	 * Scripted answers of the next submits.
	 *
	 * @var array<int,string|\Throwable>
	 */
	public $answers = [];

	/**
	 * What each submit carried: `[ job context, link id => card ]`.
	 *
	 * @var array<int,array{0:string,1:array<int,array<string,mixed>>}>
	 */
	public $submitted = [];

	/**
	 * Runs before a submit answers (a test can reset a link there).
	 *
	 * @var callable|null
	 */
	public $before_submit;

	/**
	 * Runs while a card is built (a test can reset a link there).
	 *
	 * @var callable|null
	 */
	public $on_build;

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
	 * @throws \InvalidArgumentException For a product in {@see self::$unbuildable}.
	 */
	public function build_item( Link_Record $link, \WC_Product $product, Mapping $mapping, array $overrides, Profile $profile, Validation_Context $context ): array {
		if ( null !== $this->on_build ) {
			call_user_func( $this->on_build, $link );
		}

		if ( in_array( (int) $product->get_id(), $this->unbuildable, true ) ) {
			throw new \InvalidArgumentException( 'no images' );
		}

		return [
			'offer_id' => (string) $link->offer_id(),
			'product'  => (int) $product->get_id(),
		];
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
		$this->submitted[] = [ $context, $items ];

		if ( null !== $this->before_submit ) {
			call_user_func( $this->before_submit );
		}

		$answer = [] === $this->answers ? $this->task_number : array_shift( $this->answers );

		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}

		return $answer;
	}
}
