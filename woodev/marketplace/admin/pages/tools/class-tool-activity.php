<?php
/**
 * Whether an account is busy with a background run, for the «Инструменты» page.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Tools;

use Woodev\Framework\Admin\Records\Activity_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * The account line of the tools page. It reads the account row's claim once and claims nothing itself: the claim belongs
 * to the run that holds it, and a claim whose time has passed no longer counts.
 *
 * The answer has the shape of {@see Activity_Normalizer::account()} (`active`, `text`, `blocked_actions`, `observed_at`),
 * so the page reads it the same way as the records tables read the account line. While `active` is true the page disables
 * every tool that takes the claim; `blocked_actions` stays empty because the tools are not records actions.
 *
 * @since 2.1.5
 */
final class Tool_Activity {
	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Full name of the accounts table.
	 *
	 * @var string
	 */
	private $accounts_table;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @since 2.1.5
	 *
	 * @param \wpdb         $db             Database.
	 * @param string        $accounts_table Full name of the accounts table.
	 * @param callable|null $clock          Returns the Unix time; the system clock when null.
	 */
	public function __construct( \wpdb $db, string $accounts_table, ?callable $clock = null ) {
		$this->db             = $db;
		$this->accounts_table = $accounts_table;
		$this->clock          = $clock ?? 'time';
	}

	/**
	 * Tells whether a claim time still holds, given the clock. An empty or past time does not.
	 *
	 * @since 2.1.5
	 *
	 * @param string|null $claim_until Claim expiry as stored (UTC, `Y-m-d H:i:s`), or null when no claim is held.
	 * @param int         $now         Current Unix time.
	 * @return bool
	 */
	public static function is_held( ?string $claim_until, int $now ): bool {
		if ( null === $claim_until || '' === $claim_until ) {
			return false;
		}

		$until = strtotime( $claim_until . ' UTC' );

		return false !== $until && $until > $now;
	}

	/**
	 * Tells whether a background run holds the account claim right now.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	public function claim_held( int $account_id ): bool {
		$until = $this->db->get_var(
			$this->db->prepare( 'SELECT claim_until FROM %i WHERE account_id = %d AND claim_token IS NOT NULL', $this->accounts_table, $account_id ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
		);

		return self::is_held( is_string( $until ) ? $until : null, (int) call_user_func( $this->clock ) );
	}

	/**
	 * The account line: what is going on with the account, and which tools its claim blocks.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return array{active:bool,text:string,blocked_actions:string[],observed_at:string}
	 */
	public function account( int $account_id ): array {
		if ( ! $this->claim_held( $account_id ) ) {
			return Activity_Normalizer::idle_account();
		}

		return [
			'active'          => true,
			'text'            => __( 'По аккаунту идёт фоновая работа. Инструменты, которым нужна эта работа, станут доступны, когда она закончится.', 'woodev-plugin-framework' ),
			'blocked_actions' => [],
			'observed_at'     => Activity_Normalizer::now(),
		];
	}
}
