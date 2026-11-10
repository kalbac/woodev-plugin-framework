<?php
/**
 * Copyable support report.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the text the merchant pastes to support instead of wp-admin credentials. Lines whose label names a secret are
 * masked here as a last line of defence, whatever a section put in them; identifiers are shown as `***1234`.
 *
 * @since 2.0.29
 */
final class Support_Report {
	/**
	 * Placeholder that replaces a secret value.
	 */
	private const HIDDEN = '[скрыто]';

	/**
	 * Pattern of labels that name a secret.
	 */
	private const SECRET_LABEL = '/api[\s_-]?key|api[\s_-]?ключ|ключ[\s_-]?api|token|токен|secret|секрет|password|парол|authorization/iu';

	/**
	 * Characters left visible by `mask_identifier()`.
	 */
	private const VISIBLE_TAIL = 4;

	/**
	 * Renders sections as plain text.
	 *
	 * @since 2.0.29
	 *
	 * @param Report_Section[] $sections Sections in display order.
	 * @return string
	 */
	public static function build( array $sections ): string {
		$blocks = [];
		foreach ( $sections as $section ) {
			$lines = [ '== ' . $section->title() . ' ==' ];
			foreach ( $section->lines() as $label => $value ) {
				$lines[] = $label . ': ' . ( 1 === preg_match( self::SECRET_LABEL, (string) $label ) ? self::HIDDEN : $value );
			}
			$blocks[] = implode( "\n", $lines );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Masks an identifier so only its tail stays readable (`1234567` becomes `***4567`).
	 *
	 * @since 2.0.29
	 *
	 * @param string $value Identifier such as a Client-Id.
	 * @return string
	 */
	public static function mask_identifier( string $value ): string {
		return '***' . substr( $value, -self::VISIBLE_TAIL );
	}
}
