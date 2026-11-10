<?php
/**
 * Records engine — owner registry.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Which provider serves `woodev/v1/admin/records/{owner}/{table}`.
 *
 * The owner is the registering plugin's id and table ids are unique per owner, so two
 * independent plugins can both register a table called `products` without meeting. A second
 * registration of the same owner and table is a developer error: it is reported and ignored,
 * and never replaces the first — another owner's table cannot be taken over.
 *
 * The first registration also hooks the REST controller through {@see \Woodev_REST_V1_Registrar}
 * (once, however many providers follow). Register before the `rest_api_init` callbacks of the
 * registrar run, for example on `init` or at `rest_api_init` priority 5.
 *
 * @since 2.0.29
 */
final class Records_Registry {

	/** Capability required when a registration names none. */
	const DEFAULT_CAPABILITY = 'manage_woocommerce';

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var array<string,array<string,Records_Registration>> Owner => table id => registration.
	 */
	private $registrations = [];

	/**
	 * @var bool Whether the REST controller has been handed to the registrar.
	 */
	private $controller_hooked = false;

	/**
	 * @since 2.0.29
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Drops the shared instance. For tests only.
	 *
	 * @internal
	 *
	 * @since 2.0.29
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$instance = null;
	}

	/**
	 * Registers a provider under an owner.
	 *
	 * @since 2.0.29
	 *
	 * @param string           $owner      Plugin id, a lower-case slug.
	 * @param Records_Provider $provider   Provider; its `get_id()` is the table id.
	 * @param string           $capability Capability that gates every request to the table.
	 * @return bool False (and a developer notice) for a malformed id or a duplicate.
	 */
	public function register( string $owner, Records_Provider $provider, string $capability = self::DEFAULT_CAPABILITY ): bool {
		$table = $provider->get_id();

		if ( 1 !== preg_match( Records_Definition::SLUG_PATTERN, $owner ) || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $table ) || '' === $capability ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Records owner "%s" or table "%s" is not a valid slug.', $owner, $table ) ), '2.0.29' );

			return false;
		}

		if ( isset( $this->registrations[ $owner ][ $table ] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Records table "%s" is already registered for owner "%s".', $table, $owner ) ), '2.0.29' );

			return false;
		}

		$this->registrations[ $owner ][ $table ] = new Records_Registration( $owner, $provider, $capability );
		$this->hook_controller();

		return true;
	}

	/**
	 * @since 2.0.29
	 *
	 * @param string $owner Owner id from the route.
	 * @param string $table Table id from the route.
	 * @return Records_Registration|null
	 */
	public function get( string $owner, string $table ): ?Records_Registration {
		return $this->registrations[ $owner ][ $table ] ?? null;
	}

	/**
	 * @return void
	 */
	private function hook_controller(): void {
		if ( $this->controller_hooked ) {
			return;
		}

		\Woodev_REST_V1_Registrar::register_controller( new Records_Rest_Controller( $this ), Records_Rest_Controller::class );
		$this->controller_hooked = true;
	}
}
