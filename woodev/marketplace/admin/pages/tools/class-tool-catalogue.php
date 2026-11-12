<?php
/**
 * What the «Инструменты» page shows for an account: each tool, and whether it may run now.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the tool list of the page. The disabled rule lives in one pure function, {@see self::state()}, so the page and the
 * runner's refusal agree on it: a tool that takes the claim is disabled while the account is busy, and no other tool is.
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
	 * @since 2.1.5
	 *
	 * @param Tool_Registry $registry Tools of the page.
	 * @param Tool_Activity $activity Account activity reader.
	 */
	public function __construct( Tool_Registry $registry, Tool_Activity $activity ) {
		$this->registry = $registry;
		$this->activity = $activity;
	}

	/**
	 * The disabled state of one tool, given whether its account is busy. Pure: the same inputs always give the same answer.
	 *
	 * @since 2.1.5
	 *
	 * @param bool $takes_claim  Whether the tool takes the account claim.
	 * @param bool $account_busy Whether a background run holds the account claim.
	 * @return array{disabled:bool,reason:string}
	 */
	public static function state( bool $takes_claim, bool $account_busy ): array {
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
	 * The tools of the page for an account, in the order the plugin gave them.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account the merchant chose.
	 * @return array<int,array{id:string,title:string,description:string,button:string,takes_claim:bool,disabled:bool,disabled_reason:string}>
	 */
	public function list( int $account_id ): array {
		$busy = $this->activity->claim_held( $account_id );
		$rows = [];

		foreach ( $this->registry->all() as $tool ) {
			$state  = self::state( $tool->takes_claim(), $busy );
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
