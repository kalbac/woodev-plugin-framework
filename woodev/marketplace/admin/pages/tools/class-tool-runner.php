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
 * the account locked. The callback gets the claim fence (for the fenced stores) and a renew callable (the claim lasts a
 * couple of minutes). A claim-taking tool that finds the claim held does not run; the page shows the same sentence it
 * disables the button for. A tool that throws gives the merchant a plain sentence, not the exception text. The claim calls
 * are injected so the order can be tested without a database: in the plugin they are
 * {@see \Woodev\Framework\Marketplace\Jobs\Job_Gate::claim()}, {@see \Woodev\Framework\Marketplace\Jobs\Job_Gate::release()} and
 * {@see \Woodev\Framework\Marketplace\Jobs\Job_Gate::still_holds()}.
 *
 * The licence and the account pause are not checked here: {@see Tool_Catalogue::state_for()} is the one place that decides
 * whether a tool may run, and the controller asks it before the runner is called.
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
	 * Extends a claim taken by {@see self::$take_claim}: `function ( int $account_id, Claim_Fence $fence ): bool`, false when the claim was lost.
	 *
	 * @var callable
	 */
	private $renew_claim;

	/**
	 * @since 2.1.5
	 *
	 * @param Tool_Registry $registry      Tools of the page.
	 * @param callable      $take_claim    `function ( int $account_id ): ?Claim_Fence`.
	 * @param callable      $release_claim `function ( int $account_id, Claim_Fence $fence ): void`.
	 * @param callable      $renew_claim   `function ( int $account_id, Claim_Fence $fence ): bool`.
	 */
	public function __construct( Tool_Registry $registry, callable $take_claim, callable $release_claim, callable $renew_claim ) {
		$this->registry      = $registry;
		$this->take_claim    = $take_claim;
		$this->release_claim = $release_claim;
		$this->renew_claim   = $renew_claim;
	}

	public function run( string $tool_id, int $account_id ): Tool_Result {
		$tool = $this->registry->get( $tool_id );

		if ( null === $tool ) {
			return Tool_Result::failure( __( 'Такого инструмента нет.', 'woodev-plugin-framework' ) );
		}

		try {
			return $tool->takes_claim() ? $this->run_under_claim( $tool, $account_id ) : $tool->run( $account_id );
		} catch ( \Throwable $e ) {
			// The exception text can echo a request (a key, an address): the merchant gets a sentence, never the text.
			return Tool_Result::failure( __( 'Инструмент остановился из-за ошибки. Попробуйте ещё раз позже.', 'woodev-plugin-framework' ) );
		}
	}

	private function run_under_claim( Tool $tool, int $account_id ): Tool_Result {
		$fence = call_user_func( $this->take_claim, $account_id );

		if ( ! $fence instanceof Claim_Fence ) {
			return Tool_Result::failure( __( 'По аккаунту уже идёт фоновая работа. Запустите инструмент, когда она закончится.', 'woodev-plugin-framework' ) );
		}

		$renew = function () use ( $account_id, $fence ): bool {
			return true === call_user_func( $this->renew_claim, $account_id, $fence );
		};

		try {
			return $tool->run( $account_id, $fence, $renew );
		} finally {
			call_user_func( $this->release_claim, $account_id, $fence );
		}
	}
}
