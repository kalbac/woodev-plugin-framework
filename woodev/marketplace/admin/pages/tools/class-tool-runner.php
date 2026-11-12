<?php
/**
 * Runs one tool of the «Инструменты» page for an account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Shipping\Settings\Tool_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a confirmed tool run and takes the account claim for a tool that needs it.
 *
 * The claim is taken before the callback and released after it, also when the callback throws, so a tool never leaves
 * the account locked. A claim-taking tool that finds the claim held does not run; the page shows the same sentence it
 * disables the button for. The claim calls are injected so the order can be tested without a database: in the plugin they
 * are {@see \Woodev\Framework\Marketplace\Jobs\Job_Gate::claim()} and {@see \Woodev\Framework\Marketplace\Jobs\Job_Gate::release()}.
 *
 * @since 2.1.5
 */
final class Tool_Runner {
	/**
	 * Tools by id.
	 *
	 * @var Tool_Registry
	 */
	private $registry;

	/**
	 * Takes the account claim: `function ( int $account_id ): ?Claim_Fence`, null when another run holds it.
	 *
	 * @var callable
	 */
	private $take_claim;

	/**
	 * Releases a claim taken by {@see self::$take_claim}: `function ( int $account_id, Claim_Fence $fence ): void`.
	 *
	 * @var callable
	 */
	private $release_claim;

	/**
	 * @since 2.1.5
	 *
	 * @param Tool_Registry $registry      Tools of the page.
	 * @param callable      $take_claim    `function ( int $account_id ): ?Claim_Fence`.
	 * @param callable      $release_claim `function ( int $account_id, Claim_Fence $fence ): void`.
	 */
	public function __construct( Tool_Registry $registry, callable $take_claim, callable $release_claim ) {
		$this->registry      = $registry;
		$this->take_claim    = $take_claim;
		$this->release_claim = $release_claim;
	}

	/**
	 * Runs a tool for an account, after the merchant confirmed it.
	 *
	 * @since 2.1.5
	 *
	 * @param string $tool_id    Tool id.
	 * @param int    $account_id Account id.
	 * @return Tool_Result Failure when the tool does not exist or its claim is held; otherwise what the tool answered.
	 */
	public function run( string $tool_id, int $account_id ): Tool_Result {
		$tool = $this->registry->get( $tool_id );

		if ( null === $tool ) {
			return Tool_Result::failure( __( 'Такого инструмента нет.', 'woodev-plugin-framework' ) );
		}

		if ( ! $tool->takes_claim() ) {
			return $tool->run( $account_id );
		}

		return $this->run_under_claim( $tool, $account_id );
	}

	/**
	 * Runs a claim-taking tool between taking and releasing the claim.
	 *
	 * @since 2.1.5
	 *
	 * @param Tool $tool       Tool that takes the claim.
	 * @param int  $account_id Account id.
	 * @return Tool_Result
	 */
	private function run_under_claim( Tool $tool, int $account_id ): Tool_Result {
		$fence = call_user_func( $this->take_claim, $account_id );

		if ( ! $fence instanceof Claim_Fence ) {
			return Tool_Result::failure( __( 'По аккаунту уже идёт фоновая работа. Запустите инструмент, когда она закончится.', 'woodev-plugin-framework' ) );
		}

		try {
			return $tool->run( $account_id );
		} finally {
			call_user_func( $this->release_claim, $account_id, $fence );
		}
	}
}
