<?php
/**
 * What the «Инструменты» page shows for an account: each tool, and whether it may run now.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the tool list of the page. The disabled rule lives in one pure function, {@see self::state()}, and one place that
 * feeds it, {@see self::state_for()}, so the list, the preview and the runner's refusal (409) always agree:
 *
 * - a remote tool is held while a new remote action is not allowed: the licence is not in force, or the account is paused;
 * - a tool that takes the claim is held while a background run holds the account.
 *
 * @since 2.1.5
 */
final class Tool_Catalogue {
	/**
	 * @var Tool_Registry
	 */
	private $registry;

	/**
	 * @var Tool_Activity
	 */
	private $activity;

	/**
	 * Tells why a new remote action may not start: `function ( int $account_id ): ?string`, null when it may. The account id
	 * is 0 while the page has no account chosen yet, which has no pause to check.
	 *
	 * @var callable
	 */
	private $remote_hold;

	/**
	 * @since 2.1.5
	 *
	 * @param Tool_Registry $registry    Tools of the page.
	 * @param Tool_Activity $activity    Account activity reader.
	 * @param callable      $remote_hold `function ( int $account_id ): ?string` — the sentence that holds a remote tool, or null.
	 */
	public function __construct( Tool_Registry $registry, Tool_Activity $activity, callable $remote_hold ) {
		$this->registry    = $registry;
		$this->activity    = $activity;
		$this->remote_hold = $remote_hold;
	}

	/**
	 * The disabled state of one tool, given whether its account is busy and why a remote action is held. Pure: the same
	 * inputs always give the same answer.
	 *
	 * @since 2.1.5
	 *
	 * @param bool        $takes_claim  Whether the tool takes the account claim.
	 * @param bool        $account_busy Whether a background run holds the account claim.
	 * @param string|null $remote_hold  The sentence that holds a remote tool (licence, pause), or null when none does.
	 * @return array{disabled:bool,reason:string}
	 */
	public static function state( bool $takes_claim, bool $account_busy, ?string $remote_hold = null ): array {
		if ( null !== $remote_hold && '' !== $remote_hold ) {
			return [
				'disabled' => true,
				'reason'   => $remote_hold,
			];
		}

		if ( $takes_claim && $account_busy ) {
			return [
				'disabled' => true,
				'reason'   => __( 'Сейчас по аккаунту идёт фоновая работа. Инструмент станет доступен, когда она закончится.', 'woodev-plugin-framework' ),
			];
		}

		return [
			'disabled' => false,
			'reason'   => '',
		];
	}

	/**
	 * The disabled state of a tool for an account, read now.
	 *
	 * @since 2.1.5
	 *
	 * @param Tool $tool       Tool.
	 * @param int  $account_id Account the merchant chose; 0 when none is chosen yet.
	 * @return array{disabled:bool,reason:string}
	 */
	public function state_for( Tool $tool, int $account_id ): array {
		$remote_hold = $tool->is_remote() ? call_user_func( $this->remote_hold, $account_id ) : null;
		$busy        = $tool->takes_claim() && $this->activity->claim_held( $account_id );

		return self::state( $tool->takes_claim(), $busy, is_string( $remote_hold ) ? $remote_hold : null );
	}

	/**
	 * The tools of the page for an account, in the order the plugin gave them.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account the merchant chose; 0 when none is chosen yet.
	 * @return array<int,array{id:string,title:string,description:string,button:string,takes_claim:bool,disabled:bool,disabled_reason:string}>
	 */
	public function list( int $account_id ): array {
		$rows = [];

		foreach ( $this->registry->all() as $tool ) {
			$state  = $this->state_for( $tool, $account_id );
			$rows[] = array_merge(
				$tool->describe(),
				[
					'disabled'        => $state['disabled'],
					'disabled_reason' => $state['reason'],
				]
			);
		}

		return $rows;
	}
}
