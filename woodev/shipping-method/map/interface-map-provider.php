<?php
/**
 * Woodev Map Provider Interface
 *
 * Defines the pluggable seam for pickup-point (PVZ) map rendering. The seam asks
 * "where does the map come from" — our own Yandex map, or a carrier's own
 * widget/iframe embedded in the same modal shell (see {@see \Woodev\Framework\Shipping\Map\Embedded_Map_Provider}).
 * That axis has two real consumers, unlike the earlier "which library draws the
 * map" axis this interface used to describe: a five-method thin-adapter contract
 * cannot express the target UX (clustering, a viewport-synced drawer, map
 * controls, bounded geocoding search, custom balloon layouts), so a second
 * library was always going to be a second full build, never a thin adapter.
 *
 * A provider now owns EVERYTHING drawn inside its own container — enqueueing
 * (see {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_assets()},
 * which already registers `woodev-pickup-map-provider-{provider}` pointing at
 * `js/frontend/map-provider-{provider}.js`), rendering, interaction — and pulls
 * point data through a `dataSource` the framework hands its `init()`. The PHP
 * side declares only which script implements it and the config that script
 * needs; no markup is produced here.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Map\\Map_Provider' ) ) :

	/**
	 * Pickup-point map provider contract.
	 *
	 * Implementations own everything drawn inside the modal's map container — an
	 * own-rendered Yandex map (see {@see Yandex_Map_Provider}) or a carrier's own
	 * embedded widget/iframe (see {@see Embedded_Map_Provider}) — and describe to
	 * PHP which script implements it and what config that script needs. All
	 * actual rendering and interaction happens in the script identified by
	 * {@see self::get_script_handle()}.
	 *
	 * @since 1.5.0
	 */
	interface Map_Provider {

		/**
		 * Gets the provider's unique identifier.
		 *
		 * Used as the registry key, to select a provider from plugin configuration
		 * (e.g. 'yandex'), and — by convention, see {@see self::get_script_handle()} —
		 * to derive the script handle
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_assets()}
		 * enqueues (`woodev-pickup-map-provider-{id}`).
		 *
		 * @since 1.5.0
		 *
		 * @return string provider id
		 */
		public function get_id(): string;

		/**
		 * Gets the provider's human-readable label.
		 *
		 * Shown to the merchant when choosing a provider in the settings UI.
		 * User-facing — Russian, per project convention.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_label(): string;

		/**
		 * Gets the registered handle of the script that implements this provider.
		 *
		 * Should return the handle
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_assets()}
		 * enqueues for this provider's id (`woodev-pickup-map-provider-{id}`) — the
		 * handler already owns enqueueing (registering
		 * `js/frontend/map-provider-{provider}.js`, built from {@see self::get_id()},
		 * and skipping the asset while it does not yet exist on disk). Both concrete
		 * providers derive this from {@see self::get_id()} by convention; nothing
		 * ENFORCES the pattern, so a provider that strays from it silently points at
		 * a differently-named script file.
		 *
		 * @since 2.0.2
		 *
		 * @return string script handle
		 */
		public function get_script_handle(): string;

		/**
		 * Gets the provider-specific settings fields.
		 *
		 * Returned in the Woodev settings-API `register_setting()` args shape (`name`,
		 * `type`, `default`, `description`, `required`, `sensitive`, …) — see
		 * `woodev/settings-api/abstract-class-settings.php` — for merging into the
		 * shipping integration settings. A provider that needs no credential (e.g.
		 * {@see Embedded_Map_Provider}) returns an empty array.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, array<string, mixed>> settings field definitions keyed by field id
		 */
		public function get_settings_fields(): array;

		/**
		 * Gets the provider-specific configuration handed to the browser.
		 *
		 * Shaped against the current request via `$context` — a plugin-supplied
		 * bag of request-scoped values (e.g. plugin id) the provider may use to
		 * tailor its config, rather than a fixed blob computed once. Carries no
		 * installed-site contract data — AJAX action names, nonces and the like are
		 * merged in by the host plugin/handler. Must never emit a value shaped like
		 * a carrier or provider credential under a key name that invites treating
		 * it as a secret (e.g. `apiKey`) — a JS map key ships to the browser inside
		 * a script URL regardless and cannot be hidden; see
		 * {@see Yandex_Map_Provider} for how that is handled.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $context request-scoped context.
		 *
		 * @return array<string, mixed> configuration merged into `mapConfig`.
		 */
		public function get_js_config( array $context ): array;

		/**
		 * Declares who owns the rendering inside the container.
		 *
		 * `true` means the provider owns the WHOLE container: the framework renders no
		 * panels and hands the container over untouched — the only fit for a
		 * third-party widget/iframe that already comes with its own list, search and
		 * selection UI (see {@see Embedded_Map_Provider}). `false` means the provider
		 * draws ONLY the map canvas — camera, markers, clustering — while the
		 * framework renders the list panel, the point card, the search view and the
		 * type filter around it (see {@see Yandex_Map_Provider}).
		 *
		 * This narrows this interface's earlier "the provider owns everything drawn
		 * inside the container" contract, written for a balloon-based UX. See ADR-009
		 * and decision D-3 of the presentation rework for the rationale.
		 *
		 * @since 2.0.2
		 *
		 * @return bool true when the provider owns the whole container, false when it
		 *              draws only the map canvas.
		 */
		public function owns_chrome(): bool;
	}

endif;
