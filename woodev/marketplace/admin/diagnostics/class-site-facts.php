<?php
/**
 * WordPress, WooCommerce, PHP and catalogue-cache environment facts for diagnostics.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Collects local site and environment facts for the diagnostics screen and copyable support report.
 *
 * Reads only local state (`Wpdb_Catalog_Cache`, WordPress options and constants) and never sends URLs, paths or
 * credentials.
 *
 * @since 2.1.2
 */
final class Site_Facts {
	/**
	 * Marketplace adapter.
	 *
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Catalogue cache reader.
	 *
	 * @var Wpdb_Catalog_Cache
	 */
	private $catalog_cache;

	/**
	 * Plugin display name (e.g. `OZON Seller`).
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Clock (`function (): int`).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the site-facts reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Marketplace_Adapter $adapter        Marketplace adapter.
	 * @param Wpdb_Catalog_Cache  $catalog_cache  Catalogue cache.
	 * @param string              $plugin_name    Plugin display name.
	 * @param string              $plugin_version Plugin version.
	 * @param callable|null       $clock          `function (): int`, defaults to `time`.
	 */
	public function __construct(
		Marketplace_Adapter $adapter,
		Wpdb_Catalog_Cache $catalog_cache,
		string $plugin_name,
		string $plugin_version,
		?callable $clock = null
	) {
		$this->adapter        = $adapter;
		$this->catalog_cache  = $catalog_cache;
		$this->plugin_name    = $plugin_name;
		$this->plugin_version = $plugin_version;
		$this->clock          = $clock ?? 'time';
	}

	/**
	 * Builds the «Сайт» diagnostic section (shown only when debug logging is enabled).
	 *
	 * @since 2.1.2
	 *
	 * @return Report_Section
	 */
	public function site_section(): Report_Section {
		$lines = [
			'WordPress / WooCommerce / PHP' => sprintf(
				'%s / %s / %s',
				$this->wp_version(),
				$this->wc_version(),
				PHP_VERSION
			),
			'HPOS'                          => $this->is_hpos_enabled()
				? __( 'включён', 'woodev-plugin-framework' )
				: __( 'выключен', 'woodev-plugin-framework' ),
			'WP-Cron'                       => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
				? __( 'отключён в wp-config.php (ожидается системный cron)', 'woodev-plugin-framework' )
				: __( 'включён', 'woodev-plugin-framework' ),
			'HTTPS'                         => $this->is_https()
				? __( 'да', 'woodev-plugin-framework' )
				: __( 'нет', 'woodev-plugin-framework' ),
		];

		if ( $this->adapter->supports( Capability::IMPORT_PRODUCTS ) ) {
			/* translators: %s: marketplace name. */
			$tree_label           = sprintf( __( 'Дерево категорий %s', 'woodev-plugin-framework' ), $this->adapter->settings_descriptor()->marketplace_name() );
			$lines[ $tree_label ] = $this->tree_age_text();
		}

		return new Report_Section( __( 'Сайт', 'woodev-plugin-framework' ), $lines );
	}

	/**
	 * Builds the «Версии» and «Активные плагины» sections appended to the copyable support report.
	 *
	 * @since 2.1.2
	 *
	 * @return Report_Section[]
	 */
	public function environment_sections(): array {
		$framework_version = class_exists( '\\Woodev_Plugin' ) ? \Woodev_Plugin::VERSION : '';

		return [
			new Report_Section(
				__( 'Версии', 'woodev-plugin-framework' ),
				[
					$this->plugin_name => $this->plugin_version,
					'Woodev Framework' => $framework_version,
				]
			),
			new Report_Section(
				__( 'Активные плагины', 'woodev-plugin-framework' ),
				$this->active_plugins()
			),
		];
	}

	/**
	 * Returns the age in whole days of the cached category tree, or null when never fetched.
	 *
	 * @since 2.1.2
	 *
	 * @return int|null
	 */
	public function catalog_tree_age_days(): ?int {
		$fetched_at = $this->catalog_cache->fetched_at( Wpdb_Catalog_Cache::KEY_TREE );

		if ( null === $fetched_at ) {
			return null;
		}

		$now = (int) call_user_func( $this->clock );

		return max( 0, intdiv( max( 0, $now - $fetched_at ), DAY_IN_SECONDS ) );
	}

	/**
	 * Formats the cached category tree age in Russian.
	 *
	 * @return string
	 */
	private function tree_age_text(): string {
		$days = $this->catalog_tree_age_days();

		if ( null === $days ) {
			return __( 'не загружалось', 'woodev-plugin-framework' );
		}

		if ( 0 === $days ) {
			return __( 'обновлено сегодня', 'woodev-plugin-framework' );
		}

		/* translators: %d: age of the cached category tree in whole days. */
		return sprintf( __( 'обновлено %d дн. назад', 'woodev-plugin-framework' ), $days );
	}

	/**
	 * Returns the WordPress version string.
	 *
	 * @return string
	 */
	private function wp_version(): string {
		$version = get_bloginfo( 'version' );

		return is_string( $version ) && '' !== $version ? $version : 'unknown';
	}

	/**
	 * Returns the WooCommerce version string.
	 *
	 * @return string
	 */
	private function wc_version(): string {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : 'unknown';
	}

	/**
	 * Tells whether WooCommerce High-Performance Order Storage is active.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Tells whether the site's home URL uses HTTPS.
	 *
	 * @return bool
	 */
	private function is_https(): bool {
		$home = home_url();

		return is_string( $home ) && 0 === strpos( strtolower( $home ), 'https://' );
	}

	/**
	 * Lists active plugins (`Name => Version`), falling back to plugin basename when plugin headers are unavailable.
	 *
	 * @return array<string,string>
	 */
	private function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
			$active  = array_values( array_unique( array_merge( $active, $network ) ) );
		}

		$all    = function_exists( 'get_plugins' ) ? (array) get_plugins() : [];
		$result = [];

		foreach ( $active as $plugin_file ) {
			if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
				continue;
			}

			$meta  = isset( $all[ $plugin_file ] ) && is_array( $all[ $plugin_file ] ) ? $all[ $plugin_file ] : [];
			$name  = isset( $meta['Name'] ) && '' !== trim( (string) $meta['Name'] ) ? trim( (string) $meta['Name'] ) : $plugin_file;
			$ver   = isset( $meta['Version'] ) && '' !== trim( (string) $meta['Version'] ) ? trim( (string) $meta['Version'] ) : '—';

			$result[ $name ] = $ver;
		}

		if ( [] === $result ) {
			return [ __( 'Список плагинов', 'woodev-plugin-framework' ) => '—' ];
		}

		return $result;
	}
}
