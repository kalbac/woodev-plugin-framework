<?php
/**
 * Plugin Name: Woodev rig — signed licence for test fixtures (RIG ONLY)
 * Description: Local rig helper, never shipped. Gives the framework's test fixture carriers (download ids 9001-9006)
 *              an in-force signed licence claim under a rig-local Ed25519 key. Real carrier plugins (CDEK 216,
 *              Yandex 821) keep the production authority key and need a real licence key entered by hand.
 */

defined( 'ABSPATH' ) || exit;

const WOODEV_RIG_LICENSE_FIXTURE_IDS = [ '9001', '9002', '9003', '9004', '9005', '9006' ];

/**
 * The rig-local keypair, generated once and kept in an option.
 */
function woodev_rig_license_keypair(): string {
	$stored = get_option( 'woodev_rig_license_keypair' );
	if ( is_string( $stored ) && '' !== $stored ) {
		return base64_decode( $stored );
	}
	$keypair = sodium_crypto_sign_keypair();
	update_option( 'woodev_rig_license_keypair', base64_encode( $keypair ), false );
	return $keypair;
}

/**
 * The download id of the plugin whose claim store is asking for the key, or ''.
 */
function woodev_rig_license_asking_download_id(): string {
	foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 8 ) as $frame ) {
		$object = $frame['object'] ?? null;
		if ( $object instanceof Woodev_License_Authority_Claims ) {
			$property = new ReflectionProperty( $object, 'plugin' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$plugin = $property->getValue( $object );
			return is_object( $plugin ) && method_exists( $plugin, 'get_download_id' ) ? (string) $plugin->get_download_id() : '';
		}
	}
	return '';
}

add_filter(
	'woodev_license_authority_pubkey',
	static function ( $key ) {
		if ( in_array( woodev_rig_license_asking_download_id(), WOODEV_RIG_LICENSE_FIXTURE_IDS, true ) ) {
			return base64_encode( sodium_crypto_sign_publickey( woodev_rig_license_keypair() ) );
		}
		return $key;
	},
	10
);

/**
 * Canonical JSON exactly as the framework verifier builds it.
 */
function woodev_rig_license_canonical( array $payload ): string {
	$sort = static function ( array &$a ) use ( &$sort ) {
		ksort( $a, SORT_STRING );
		foreach ( $a as &$v ) {
			if ( is_array( $v ) ) {
				$sort( $v );
			}
		}
	};
	$sort( $payload );
	return (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

add_action(
	'init',
	static function () {
		if ( ! class_exists( 'Woodev_Plugins_License' ) || ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return;
		}
		foreach ( Woodev_Plugins_License::get_registered_instances() as $engine ) {
			$plugin = $engine->get_plugin();
			$id     = (string) $plugin->get_download_id();
			if ( ! in_array( $id, WOODEV_RIG_LICENSE_FIXTURE_IDS, true ) || $engine->is_license_in_force() ) {
				continue;
			}
			$now     = time();
			$payload = [
				'site'             => home_url(),
				'plugin_id'        => $id,
				'license_required' => true,
				'license_status'   => 'valid',
				'license_expires'  => null,
				'issued_at'        => $now,
				'expires_at'       => $now + 14 * DAY_IN_SECONDS,
			];
			$secret  = sodium_crypto_sign_secretkey( woodev_rig_license_keypair() );
			$engine->get_authority_claims()->consume_from_response(
				[
					'license_authority' => [
						'payload'   => $payload,
						'signature' => base64_encode( sodium_crypto_sign_detached( woodev_rig_license_canonical( $payload ), $secret ) ),
					],
				]
			);
		}
	},
	20
);
