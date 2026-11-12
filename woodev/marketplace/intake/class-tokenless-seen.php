<?php
/**
 * Remembers that a notification arrived on the old address without a token.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

use Woodev\Framework\Marketplace\Data\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * One timestamp, `woodev_{plugin id}_tokenless_seen_at`, written at most once an hour. The intake notes every request on the old
 * tokenless address **before** it checks the sender, because behind a proxy that check refuses the request and stores nothing:
 * the «site is behind a proxy» notice must see exactly those requests. The option is the plugin's own, so uninstalling the
 * plugin's data removes it with the rest of the family.
 *
 * @since 2.1.2
 */
final class Tokenless_Seen {
	/**
	 * Setting id after the plugin's option prefix.
	 */
	public const SETTING_ID = 'tokenless_seen_at';

	/**
	 * Least seconds between two writes.
	 */
	public const WRITE_EVERY = HOUR_IN_SECONDS;

	/**
	 * Full option name.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Clock, `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the memory.
	 *
	 * @since 2.1.2
	 *
	 * @param string        $plugin_id Plugin id.
	 * @param callable|null $clock     Optional clock, `time` by default.
	 */
	public function __construct( string $plugin_id, ?callable $clock = null ) {
		$this->option = Uninstaller::option_prefix( $plugin_id ) . self::SETTING_ID;
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Notes that a request arrived on the old address, unless it was noted within the last hour.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	public function note(): void {
		$now = (int) call_user_func( $this->clock );

		if ( $now - (int) get_option( $this->option, 0 ) < self::WRITE_EVERY ) {
			return;
		}

		update_option( $this->option, $now, false );
	}

	/**
	 * Tells whether a request arrived on the old address since a moment (to the hour: the note is throttled).
	 *
	 * @since 2.1.2
	 *
	 * @param int $since Unix time.
	 * @return bool
	 */
	public function seen_since( int $since ): bool {
		return (int) get_option( $this->option, 0 ) >= $since;
	}
}
