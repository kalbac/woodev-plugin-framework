<?php
/**
 * Release check of a plugin's help-topic table.
 *
 * @package Woodev\Framework\Help
 */

namespace Woodev\Framework\Help;

defined( 'ABSPATH' ) || exit;

/**
 * Requests every address of a topic table and reports the ones that do not answer with a 2xx or 3xx.
 *
 * The request itself is injected, so the rule («non-2xx/3xx fails») is testable without a network; the WP-CLI
 * command ({@see Help_Links_Cli}) passes the real one.
 *
 * @since 2.1.11
 */
final class Help_Links_Checker {
	/**
	 * @var callable fn( string $url ): int — the HTTP status of the address, 0 when it could not be reached.
	 */
	private $fetch_status;

	/**
	 * @since 2.1.11
	 *
	 * @param callable $fetch_status fn( string $url ): int — the HTTP status, 0 when the address was not reached.
	 */
	public function __construct( callable $fetch_status ) {
		$this->fetch_status = $fetch_status;
	}

	/**
	 * Checks each address of the table once, even when several topics share it.
	 *
	 * @since 2.1.11
	 *
	 * @param array<string,string> $topics Topic => URL.
	 * @return array<string,int> The failures, topic => status (0 = not reached); empty when every address answers.
	 */
	public function find_broken( array $topics ): array {
		$statuses = [];
		$broken   = [];

		foreach ( $topics as $topic => $url ) {
			if ( ! isset( $statuses[ $url ] ) ) {
				$statuses[ $url ] = (int) ( $this->fetch_status )( $url );
			}

			if ( $statuses[ $url ] < 200 || $statuses[ $url ] >= 400 ) {
				$broken[ $topic ] = $statuses[ $url ];
			}
		}

		return $broken;
	}
}
