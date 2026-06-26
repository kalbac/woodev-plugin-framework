<?php
/**
 * Settings-page registry.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton aggregator for the neutral Woodev > Настройки page.
 *
 * Collects Settings_Provider descriptors from every active plugin's
 * get_settings_providers() seam plus framework-service stubs, resolves each
 * tab's capability, builds the aggregated schema, and registers the submenu,
 * React assets, and legacy-URL redirect.
 *
 * @since 2.0.2
 */
final class Settings_Page_Registry {

	/** @var string admin page slug. */
	const PAGE_SLUG = 'woodev-settings';

	/** @var self|null singleton. */
	private static $instance = null;

	/** @var \Woodev_Plugin[] plugins that may contribute providers. */
	private array $plugins = [];

	/** @var Settings_Provider[] framework-service providers (no owning plugin). */
	private array $services = [];

	/** @var bool whether the shared hooks were added. */
	private bool $hooked = false;

	/** @var array<int,array<string,mixed>>|null memoized tabs for this request. */
	private $tabs_cache = null;

	/** @var array<int,array<string,mixed>>|null memoized provider entries for this request. */
	private $entries_cache = null;

	/**
	 * Returns the singleton.
	 *
	 * @since 2.0.2
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
	 * Resolves a provider's capability per the spec rules.
	 *
	 * 1. explicit declaration wins; 2. WC-dependent plugin → manage_woocommerce;
	 * 3. otherwise neutral manage_options.
	 *
	 * @since 2.0.2
	 *
	 * @param string|null $declared       explicit capability or null.
	 * @param bool        $is_woocommerce whether the owning plugin is WC-dependent.
	 * @return string
	 */
	public static function resolve_capability( ?string $declared, bool $is_woocommerce ): string {
		if ( null !== $declared && '' !== $declared ) {
			return $declared;
		}

		return $is_woocommerce ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Resolves the page (submenu) capability: the broadest-reach cap among tabs.
	 *
	 * A WordPress submenu carries a single capability, but a user should be able
	 * to open the page when they can access ANY tab. manage_woocommerce reaches a
	 * wider audience (admins + shop managers) than admin-only manage_options, so
	 * the page opens under the broadest cap present; per-tab visibility + the
	 * per-provider REST route still enforce the exact capability. Custom caps not
	 * in the reach table rank narrowest (a known SP-1 limitation — real plugins
	 * use the two standard caps).
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $caps resolved per-tab capabilities.
	 * @return string
	 */
	public static function resolve_page_capability( array $caps ): string {
		$reach = [
			'manage_woocommerce' => 2,
			'manage_options'     => 1,
		];

		$best      = 'manage_options';
		$best_rank = 0;

		foreach ( $caps as $cap ) {
			$rank = $reach[ $cap ] ?? 0;
			if ( $rank > $best_rank ) {
				$best_rank = $rank;
				$best      = $cap;
			}
		}

		return $best;
	}

	/**
	 * Builds the cap-filtered, deduped tab list (pure; injectable for tests).
	 *
	 * Each entry: [ 'provider' => Settings_Provider, 'is_woocommerce' => bool ].
	 * Dedup is by provider id (first wins). Tabs whose resolved capability the
	 * current user lacks are omitted.
	 *
	 * @since 2.0.2
	 *
	 * @param array<int,array<string,mixed>> $entries provider entries.
	 * @param callable                       $can     predicate: (string $cap) => bool.
	 * @return array<int,array<string,mixed>> tab schema array.
	 */
	public function build_tabs( array $entries, callable $can ): array {
		$tabs = [];
		$seen = [];

		foreach ( $entries as $entry ) {
			$provider = $entry['provider'];
			$id       = $provider->get_id();

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$capability = self::resolve_capability(
				$provider->get_declared_capability(),
				! empty( $entry['is_woocommerce'] )
			);

			if ( ! $can( $capability ) ) {
				continue;
			}

			$tabs[] = [
				'id'         => $id,
				'label'      => $provider->get_label(),
				'capability' => $capability,
				'sections'   => $this->build_sections( $provider ),
			];
		}

		return $tabs;
	}

	/**
	 * Builds a provider's section schema (each section's fields resolved).
	 *
	 * @since 2.0.2
	 *
	 * @param Settings_Provider $provider provider.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_sections( Settings_Provider $provider ): array {
		$handler  = $provider->get_handler();
		$sections = [];

		foreach ( $provider->get_sections() as $section ) {
			$sections[] = [
				'id'     => $section->get_id(),
				'label'  => $section->get_label(),
				'fields' => Field_Schema::from_handler( $handler, $section->get_setting_ids() ),
			];
		}

		return $sections;
	}
}
