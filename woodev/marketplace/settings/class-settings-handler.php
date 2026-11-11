<?php
/**
 * Settings handler bridging `Woodev_Abstract_Settings` with marketplace section providers and table-backed accounts.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Settings\Settings_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Composes one or more {@see Settings_Section_Provider} contributors into a single `Woodev_Abstract_Settings` handler,
 * routing option-backed fields to `wp_options` (`woodev_{plugin_id}_{setting_id}`) and table-backed fields to
 * {@see Table_Backed_Settings}.
 *
 * @since 2.1.2
 */
final class Settings_Handler extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status {
	/**
	 * Section providers in display order.
	 *
	 * @var Settings_Section_Provider[]
	 */
	private $providers = [];

	/**
	 * Tab title shown on the settings page.
	 *
	 * @var string
	 */
	private $tab_title;

	/**
	 * Optional `Settings_Provider` metadata (`capability`, `legacy_page`, `description`).
	 *
	 * @var array<string,mixed>
	 */
	private $provider_args;

	/**
	 * Creates the settings handler.
	 *
	 * @since 2.1.2
	 *
	 * @param string                      $plugin_id     Plugin id (used for option prefix `woodev_{plugin_id}_` and tab id).
	 * @param Settings_Section_Provider[] $providers     Ordered section providers.
	 * @param string                      $tab_title     Localized tab title (defaults to «Основные»).
	 * @param array<string,mixed>         $provider_args Optional extra metadata for `Settings_Provider::create()`.
	 */
	public function __construct(
		string $plugin_id,
		array $providers,
		string $tab_title = '',
		array $provider_args = []
	) {
		$this->providers     = array_values( $providers );
		$this->tab_title     = '' !== $tab_title ? $tab_title : __( 'Основные', 'woodev-plugin-framework' );
		$this->provider_args = $provider_args;

		parent::__construct( $plugin_id );
	}

	/**
	 * Registers every section provider's settings on this handler.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	protected function register_settings(): void {
		foreach ( $this->providers as $provider ) {
			$provider->register_settings( $this );
		}
	}

	/**
	 * Loads values for every registered setting, delegating table-backed ids to their provider and the rest to `wp_options`.
	 *
	 * @since 2.1.2
	 *
	 * @return void
	 */
	protected function load_settings(): void {
		foreach ( $this->settings as $setting_id => $setting ) {
			$table_provider = $this->table_provider_for( (string) $setting_id );

			$value = null !== $table_provider
				? $table_provider->read( (string) $setting_id )
				: $this->get_value_from_database( get_option( $this->option_name( (string) $setting_id ), null ), $setting );

			$setting->set_value( $value );
		}
	}

	/**
	 * Persists all settings or one setting id.
	 *
	 * @since 2.1.2
	 *
	 * @param string|null $setting_id Optional single setting id.
	 * @return void
	 */
	public function save( $setting_id = null ): void {
		$settings = ! empty( $setting_id ) ? [ $this->get_setting( (string) $setting_id ) ] : $this->settings;
		$settings = array_filter( $settings );

		foreach ( $settings as $setting ) {
			$id             = (string) $setting->get_id();
			$table_provider = $this->table_provider_for( $id );

			if ( null !== $table_provider ) {
				$table_provider->write( $id, $setting->get_value() );
				$setting->set_value( $table_provider->read( $id ) );
				continue;
			}

			if ( null === $setting->get_value() ) {
				delete_option( $this->option_name( $id ) );
			} else {
				update_option( $this->option_name( $id ), $this->get_value_for_database( $setting ) );
			}
		}
	}

	/**
	 * Validates single-field rules first, then runs cross-field validation on every {@see Table_Backed_Settings} provider.
	 *
	 * @since 2.1.2
	 *
	 * @param array<string,mixed> $values Submitted `setting_id => value` map.
	 * @return array<string,string>
	 */
	public function validate_values( array $values ): array {
		$errors = parent::validate_values( $values );

		foreach ( $this->providers as $provider ) {
			if ( ! $provider instanceof Table_Backed_Settings ) {
				continue;
			}

			foreach ( $provider->validate( $values ) as $field_id => $message ) {
				if ( ! isset( $errors[ $field_id ] ) ) {
					$errors[ $field_id ] = $message;
				}
			}
		}

		return $errors;
	}

	/**
	 * Runs a connection block's live check via the owning {@see Connection_Section_Provider}.
	 *
	 * @since 2.1.2
	 *
	 * @param string              $section_id Connection section id.
	 * @param array<string,mixed> $values     Merged values of the connection block.
	 * @return \Woodev_Connection_Result
	 */
	public function test_connection( string $section_id, array $values ): \Woodev_Connection_Result {
		$provider = $this->connection_provider_for( $section_id );

		if ( null === $provider ) {
			return \Woodev_Connection_Result::failure( __( 'Неизвестный блок подключения.', 'woodev-plugin-framework' ) );
		}

		return $provider->test_connection( $section_id, $values );
	}

	/**
	 * Reads the stored connection status of a connection block without making remote calls.
	 *
	 * @since 2.1.2
	 *
	 * @param string $section_id Connection section id.
	 * @return \Woodev_Connection_Result|null
	 */
	public function get_connection_status( string $section_id ): ?\Woodev_Connection_Result {
		$provider = $this->connection_provider_for( $section_id );

		if ( null === $provider ) {
			return null;
		}

		return $provider->connection_status( $section_id );
	}

	/**
	 * Builds the {@see Settings_Provider} tab descriptor for `Settings_Page_Registry`.
	 *
	 * @since 2.1.2
	 *
	 * @return Settings_Provider
	 */
	public function get_settings_provider(): Settings_Provider {
		// Lazy (#1727): a section reads the accounts table, and the plugin hands this descriptor out on every request that
		// only asks whether the tab exists. The sections are built when a settings page or route reads them.
		return Settings_Provider::create_lazy(
			$this->get_id(),
			$this->tab_title,
			$this,
			function (): array {
				$sections = [];

				foreach ( $this->providers as $provider ) {
					$sections = array_merge( $sections, $provider->sections() );
				}

				return $sections;
			},
			$this->provider_args
		);
	}

	/**
	 * Builds the `wp_options` key for an option-backed setting id: `woodev_{plugin id}_{setting id}`, the base class's name
	 * and the family {@see Uninstaller::option_prefix()} removes.
	 *
	 * @since 2.1.2
	 *
	 * @param string $setting_id Setting id.
	 * @return string
	 */
	public function option_name( string $setting_id ): string {
		return $this->get_option_name_prefix() . '_' . $setting_id;
	}

	/**
	 * Finds the table-backed provider that owns `$setting_id`, if any.
	 *
	 * @param string $setting_id Setting id.
	 * @return Table_Backed_Settings|null
	 */
	private function table_provider_for( string $setting_id ): ?Table_Backed_Settings {
		foreach ( $this->providers as $provider ) {
			if ( $provider instanceof Table_Backed_Settings && $provider->handles( $setting_id ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Finds the connection section provider that owns `$section_id`, if any.
	 *
	 * @param string $section_id Connection section id.
	 * @return Connection_Section_Provider|null
	 */
	private function connection_provider_for( string $section_id ): ?Connection_Section_Provider {
		foreach ( $this->providers as $provider ) {
			if ( $provider instanceof Connection_Section_Provider && $provider->handles_connection( $section_id ) ) {
				return $provider;
			}
		}

		return null;
	}
}
