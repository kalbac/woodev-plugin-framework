<?php
/**
 * Contract guard: yandex order meta keys use the exact prefix '_yandex_delivery_'.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the installed-site yandex order-meta-prefix data contract (second pilot).
 *
 * The plugin persists every yandex order field under a `_yandex_delivery_*` meta key
 * (request id, destination station, intervals, sharing url, state status). The shared
 * prefix is the contract: renaming it orphans all live order meta. This guard pins the
 * canonical `_yandex_delivery_request_id` key literal and asserts it carries the exact
 * prefix, so flipping the prefix in the source flips this test RED -- proven by
 * mutation-check.ps1.
 *
 * Mutation-recipe: tests/unit/Contract/recipes/yandex-order-meta-prefix.recipe.json
 */
final class YandexOrderMetaPrefixContractTest extends TestCase {

	/**
	 * The contract prefix for all yandex order meta keys.
	 */
	private const META_PREFIX = '_yandex_delivery_';

	/**
	 * Canonical read-only reference source declaring yandex order meta keys.
	 *
	 * @return string
	 */
	private static function source_file(): string {
		return dirname( __DIR__, 3 )
			. '/plugins-reference/woocommerce-yandex-delivery/includes/class-order.php';
	}

	/**
	 * The order meta keys must carry the exact prefix '_yandex_delivery_'.
	 *
	 * Order meta is read HPOS-safe via get_meta()/update_meta_data(); the eventual rewrite
	 * must preserve the key prefix byte-for-byte. Release-blocking installed-site data
	 * contract (yandex data-preservation checklist; order_session_meta zone).
	 *
	 * @return void
	 */
	public function test_order_meta_keys_use_exact_yandex_delivery_prefix(): void {
		$file = self::source_file();
		$this->assertFileExists( $file, 'Canonical order-meta source must exist.' );

		$source = (string) file_get_contents( $file );

		$this->assertSame(
			1,
			preg_match( "/'(_yandex_delivery_request_id)'/", $source, $matches ),
			'The canonical "_yandex_delivery_request_id" meta key literal must exist in the source.'
		);
		$this->assertStringStartsWith(
			self::META_PREFIX,
			$matches[1],
			'Installed-site contract: yandex order meta keys must use the exact prefix "_yandex_delivery_".'
		);
		$this->assertSame(
			self::META_PREFIX,
			substr( $matches[1], 0, strlen( self::META_PREFIX ) ),
			'Installed-site contract: yandex order meta prefix must be exactly "_yandex_delivery_".'
		);
	}
}
