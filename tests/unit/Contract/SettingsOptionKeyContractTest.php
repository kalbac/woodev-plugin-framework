<?php
/**
 * Contract guard: the edostavka settings option key is exactly
 * 'woocommerce_edostavka_settings'.
 *
 * @package Woodev\Tests\Unit\Contract
 */

namespace Woodev\Tests\Unit\Contract;

use Woodev\Tests\Unit\TestCase;

/**
 * Guards the installed-site settings-option-key data contract.
 *
 * Source-level guard for the same reason as ShippingMethodIdContractTest: the contract
 * is the literal option key, declared as a class constant. The guard extracts the
 * declared value from the canonical source and asserts the exact contract string, so
 * any rename flips this test RED -- proven mechanically by mutation-check.ps1.
 *
 * Mutation-recipe: tests/unit/Contract/recipes/settings-option-key-edostavka.recipe.json
 */
final class SettingsOptionKeyContractTest extends TestCase {

	/**
	 * Canonical source declaring the settings option-key constant.
	 *
	 * @return string
	 */
	private static function source_file(): string {
		return dirname( __DIR__, 2 )
			. '/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-integration.php';
	}

	/**
	 * The SETTINGS_OPTION_NAME constant must be exactly 'woocommerce_edostavka_settings'.
	 *
	 * A live site stores its edostavka configuration under this WP option key; renaming
	 * it silently abandons every merchant's saved settings. Release-blocking
	 * installed-site data contract (edostavka data-preservation checklist).
	 *
	 * @return void
	 */
	public function test_settings_option_key_is_exactly_woocommerce_edostavka_settings(): void {
		$file = self::source_file();
		$this->assertFileExists( $file, 'Canonical settings-integration source must exist.' );

		$source = (string) file_get_contents( $file );

		$this->assertSame(
			1,
			preg_match( "/const\\s+SETTINGS_OPTION_NAME\\s*=\\s*'([^']*)'/", $source, $matches ),
			'A SETTINGS_OPTION_NAME string-constant declaration must exist in the canonical source.'
		);
		$this->assertSame(
			'woocommerce_edostavka_settings',
			$matches[1],
			'Installed-site contract: settings option key must be exactly "woocommerce_edostavka_settings".'
		);
	}
}
