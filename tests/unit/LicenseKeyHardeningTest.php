<?php
/**
 * Regression: the licence authority key cannot be replaced without the test switch.
 *
 * Runs in a fresh PHP process so the legacy global key constant can be defined to a
 * DIFFERENT value before the framework loads, with WOODEV_LICENSE_TEST_KEYS absent
 * (the unit bootstrap defines it, so an in-process test could not prove the absent case).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

/**
 * Class LicenseKeyHardeningTest.
 */
class LicenseKeyHardeningTest extends TestCase {

	/**
	 * Another valid Ed25519 public key, generated per run (a literal would trip the secret scan):
	 * the legacy global is defined to this before the framework loads.
	 *
	 * @return string
	 */
	private static function other_key(): string {
		return base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
	}

	/**
	 * Runs the probe script in a fresh PHP process and returns its output lines.
	 *
	 * @return string[]
	 */
	private function run_probe(): array {

		$licensing = dirname( __DIR__, 2 ) . '/woodev/licensing';
		$script    = sprintf(
			<<<'PHP'
define( 'ABSPATH', '/' );
define( 'WOODEV_LICENSE_AUTHORITY_PUBKEY', %1$s );
function apply_filters( $hook, $value ) { echo "FILTER_CALLED\n"; return %1$s; }
require %2$s;
require %3$s;
require %4$s;
$claims = ( new ReflectionClass( 'Woodev_License_Authority_Claims' ) )->newInstanceWithoutConstructor();
$method = new ReflectionMethod( $claims, 'get_public_key' );
PHP_VERSION_ID < 80100 && $method->setAccessible( true );
echo 'claims=', $method->invoke( $claims ), "\n";
$method = new ReflectionMethod( 'Woodev_License_Command_Dispatcher', 'get_public_key' );
PHP_VERSION_ID < 80100 && $method->setAccessible( true );
echo 'commands=', $method->invoke( null ), "\n";
PHP,
			var_export( self::other_key(), true ),
			var_export( $licensing . '/class-license-envelope-verifier.php', true ),
			var_export( $licensing . '/class-license-authority-claims.php', true ),
			var_export( $licensing . '/class-license-command-dispatcher.php', true )
		);

		$file = tempnam( sys_get_temp_dir(), 'woodev-key-' );
		file_put_contents( $file, "<?php\n" . $script );

		$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
		unlink( $file );

		return array_values( array_filter( explode( "\n", $output ) ) );
	}

	/**
	 * Without the test switch both the claim store and the command dispatcher use the embedded key.
	 *
	 * @return void
	 */
	public function test_predefined_global_key_does_not_replace_the_embedded_key_without_the_test_switch(): void {

		require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';

		$this->assertSame(
			array(
				'claims=' . \Woodev_License_Envelope_Verifier::PRODUCTION_PUBKEY,
				'commands=' . \Woodev_License_Envelope_Verifier::PRODUCTION_PUBKEY,
			),
			$this->run_probe()
		);
	}
}
