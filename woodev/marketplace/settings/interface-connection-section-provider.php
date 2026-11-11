<?php
/**
 * Section provider that also owns connection test and stored connection status blocks.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Extends {@see Settings_Section_Provider} for sections created with `Settings_Section::create_connection()`.
 *
 * @since 2.1.2
 */
interface Connection_Section_Provider extends Settings_Section_Provider {
	/**
	 * Tells whether this provider owns the connection block `$section_id`.
	 *
	 * @since 2.1.2
	 *
	 * @param string $section_id Connection section id.
	 * @return bool
	 */
	public function handles_connection( string $section_id ): bool;

	/**
	 * Runs the live connection check for `$section_id`.
	 *
	 * @since 2.1.2
	 *
	 * @param string              $section_id Connection section id.
	 * @param array<string,mixed> $values     Merged block values.
	 * @return \Woodev_Connection_Result
	 */
	public function test_connection( string $section_id, array $values ): \Woodev_Connection_Result;

	/**
	 * Returns the stored connection status of `$section_id` without making a remote call, or null when unchecked.
	 *
	 * @since 2.1.2
	 *
	 * @param string $section_id Connection section id.
	 * @return \Woodev_Connection_Result|null
	 */
	public function connection_status( string $section_id ): ?\Woodev_Connection_Result;
}
