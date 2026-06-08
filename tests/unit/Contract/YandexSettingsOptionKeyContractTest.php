<?php
/**
 * Contract guard: the yandex settings option key is exactly
 * 'woocommerce_yandex_delivery_settings'.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the installed-site yandex settings-option-key data contract (second pilot).
 *
 * Source-level guard for the same reason as YandexShippingMethodIdContractTest: the
 * contract is the literal WP option key, read via get_option() in the plugin's
 * integration accessor. The guard extracts the key from the canonical source and asserts
 * the exact contract string, so any rename flips this test RED -- proven mechanically by
 * mutation-check.ps1.
 *
 * Mutation-recipe: tests/unit/Contract/recipes/yandex-settings-option-key.recipe.json
 */
final class YandexSettingsOptionKeyContractTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! is_dir( dirname( __DIR__, 3 ) . '/plugins-reference/woocommerce-yandex-delivery' ) ) {
			$this->markTestSkipped( 'plugins-reference/woocommerce-yandex-delivery is not present (gitignored); this yandex contract guard runs where the reference plugin copy exists.' );
		}
	}

	/**
	 * Canonical read-only reference source declaring the settings option key.
	 *
	 * @return string
	 */
	private static function source_file(): string {
		return dirname( __DIR__, 3 )
			. '/plugins-reference/woocommerce-yandex-delivery/woocommerce-yandex-delivery.php';
	}

	/**
	 * The integration settings option key must be exactly
	 * 'woocommerce_yandex_delivery_settings'.
	 *
	 * A live site stores its yandex configuration under this WP option key; renaming it
	 * silently abandons every merchant's saved settings. Release-blocking installed-site
	 * data contract (yandex data-preservation checklist).
	 *
	 * @return void
	 */
	public function test_settings_option_key_is_exactly_woocommerce_yandex_delivery_settings(): void {
		$file = self::source_file();
		$this->assertFileExists( $file, 'Canonical settings-option source must exist.' );

		$source = (string) file_get_contents( $file );

		$this->assertSame(
			1,
			preg_match( "/get_option\\(\\s*'(woocommerce_yandex_delivery_settings)'/", $source, $matches ),
			'A get_option() call for the yandex settings key must exist in the canonical source.'
		);
		$this->assertSame(
			'woocommerce_yandex_delivery_settings',
			$matches[1],
			'Installed-site contract: settings option key must be exactly "woocommerce_yandex_delivery_settings".'
		);
	}
}
