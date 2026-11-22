<?php
/**
 * The WordPress and WooCommerce functions the importer's attribute naming calls.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Brain\Monkey\Functions;

/**
 * Stubs for `sanitize_title()`, `sanitize_text_field()` and `wc_attribute_taxonomy_name()`.
 *
 * The default `sanitize_title()` keeps a whole name (URL-encoded, lower-cased), as the shop doubles of the group tests always did.
 * {@see self::lossy_slug()} is the real function's habit that matters for attribute names: it drops punctuation and cuts the
 * URL-encoded name at 200 characters, so two long names can share one slug.
 */
final class Wc_Attribute_Functions {
	/**
	 * Installs the stubs.
	 *
	 * @return void
	 */
	public static function install(): void {
		Functions\when( 'sanitize_title' )->alias(
			static function ( string $title ): string {
				return strtolower( rawurlencode( $title ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( string $text ): string {
				return trim( strip_tags( $text ) );
			}
		);
		Functions\when( 'wc_attribute_taxonomy_name' )->alias(
			static function ( string $name ): string {
				return 'pa_' . str_replace( [ '-', ' ' ], '_', mb_strtolower( trim( $name ) ) );
			}
		);
	}

	/**
	 * Makes `sanitize_title()` lose what WordPress's does: punctuation, the case, and everything past 200 characters of the
	 * URL-encoded name.
	 *
	 * @return void
	 */
	public static function lossy_slug(): void {
		Functions\when( 'sanitize_title' )->alias(
			static function ( string $title ): string {
				$slug = (string) preg_replace( '/[^\p{L}\p{N}\s_-]+/u', '', mb_strtolower( trim( strip_tags( $title ) ) ) );
				$slug = (string) preg_replace( '/\s+/u', '-', $slug );

				return trim( substr( strtolower( rawurlencode( $slug ) ), 0, 200 ), '-' );
			}
		);
	}
}
