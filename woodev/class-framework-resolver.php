<?php
/**
 * Platform v2 minimal framework resolver.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Framework_Resolver::class, false ) ) :

	/**
	 * Resolves registered framework copies and invokes compatible plugin callbacks.
	 *
	 * This class owns early loading infrastructure only. Runtime behavior stays in
	 * platform plugin classes and specialized modules.
	 *
	 * @since 2.0.0
	 */
	class Framework_Resolver {

		/** @var string Transient key prefix (suffixed by the current user id) for a queued activation-guard notice — same post-redirect pattern as {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::NOTICE_TRANSIENT_KEY}. */
		public const ACTIVATION_GUARD_NOTICE_TRANSIENT = 'woodev_download_id_guard_notice_';

		/** @var array<int,array<string,mixed>> Registered plugin arrays. */
		protected array $registered_plugins = [];

		/** @var array<int,array<string,mixed>> Active plugin arrays. */
		protected array $active_plugins = [];

		/** @var array<int,array<string,mixed>> Framework-incompatible plugins. */
		protected array $incompatible_framework_plugins = [];

		/** @var array<int,array<string,mixed>> WooCommerce-incompatible plugins. */
		protected array $incompatible_wc_version_plugins = [];

		/** @var array<int,array<string,mixed>> WordPress-incompatible plugins. */
		protected array $incompatible_wp_version_plugins = [];

		/** @var array<int,array<string,mixed>> PHP-incompatible plugins. */
		protected array $incompatible_php_version_plugins = [];

		/** @var array<int,array<string,mixed>> Invalid loader definitions. */
		protected array $invalid_loader_definitions = [];

		/** @var array<int,array<string,mixed>> Plugins refused a download id already claimed by another loaded/holder plugin — each entry carries the legacy plugin array plus a 'claimed_by' holder plugin name. */
		protected array $quarantined_download_id_plugins = [];

		/** @var array<string,bool> Plugin IDs already registered — prevents duplicates from colliding on options, cron, license keys, and logger handles. */
		protected array $plugin_ids = [];

		/** @var array<string,\Woodev\Framework\Framework_Plugin_Loader_Definition> Registered loader definitions keyed by plugin_id — the single source of truth Woodev_Plugin::get_download_id() reads from. */
		protected array $definitions_by_plugin_id = [];

		/** @var array<string,\Woodev\Framework\Framework_Plugin_Loader_Definition> Loader definitions keyed by their `main_class`, recorded at invocation time — the PRIMARY source Woodev_Plugin::get_download_id() reads from, since a plugin's `get_id()` is not guaranteed to equal its own definition's `plugin_id` (#916 follow-up: a plugin author error, not enforced anywhere else). */
		protected array $definitions_by_main_class = [];

		/** @var array<int,array<string,mixed>>|null Download ids already claimed at the `activated_plugin` guard, keyed by download id, lazily seeded from the already-loaded active plugins on first activation in this request. Null until seeded. */
		protected ?array $activation_guard_claims = null;

		/** @var bool Guards load_plugins() against double execution in long-running processes (WP-Cron, Action Scheduler). */
		protected bool $loaded = false;

		/** @var callable Wired to admin_notices when incompatible plugins are registered. Defaults to a no-op so the resolver stays decoupled from the legacy bootstrap. */
		protected $update_notice_renderer;

		/** @var callable Wired to admin_notices when the deactivation action is requested. Defaults to a no-op for the same reason. */
		protected $deactivation_notice_renderer;

		/**
		 * Constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param callable|null $update_notice_renderer       Callback wired to admin_notices when incompatible plugins are registered. Default no-op keeps the resolver decoupled from the legacy bootstrap.
		 * @param callable|null $deactivation_notice_renderer Callback wired to admin_notices when the framework deactivation action is requested. Default no-op for the same reason.
		 */
		public function __construct( ?callable $update_notice_renderer = null, ?callable $deactivation_notice_renderer = null ) {
			$this->update_notice_renderer       = $update_notice_renderer ?? static function (): void {};
			$this->deactivation_notice_renderer = $deactivation_notice_renderer ?? static function (): void {};
		}

		/**
		 * Registers an explicit Platform v2 loader definition.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 * @return bool True when the definition is accepted.
		 */
		public function register_loader_definition( array $definition ): bool {
			$errors            = [];
			$loader_definition = Framework_Plugin_Loader_Definition::from_array( $definition, $errors );

			if ( null === $loader_definition ) {
				$this->invalid_loader_definitions[] = [
					'definition' => $definition,
					'errors'     => $errors,
				];

				return false;
			}

			if ( isset( $this->plugin_ids[ $loader_definition->get_plugin_id() ] ) ) {
				$this->invalid_loader_definitions[] = [
					'definition' => $definition,
					'errors'     => [ sprintf( 'Duplicate plugin_id: %s.', $loader_definition->get_plugin_id() ) ],
				];

				return false;
			}

			$this->plugin_ids[ $loader_definition->get_plugin_id() ]              = true;
			$this->definitions_by_plugin_id[ $loader_definition->get_plugin_id() ] = $loader_definition;
			$this->registered_plugins[]                                           = $loader_definition->to_legacy_plugin();

			return true;
		}

		/**
		 * Gets the registered loader definition for a plugin id.
		 *
		 * The single source of truth {@see Woodev_Plugin::get_download_id()} reads through —
		 * a plugin instance cannot reach its own loader definition directly (its constructor
		 * is invoked with no reference to it), but its `plugin_id` (the id it passes to
		 * `Woodev_Plugin::__construct()`) is, by framework convention, the same string it
		 * declared as `plugin_id` in its own loader definition.
		 *
		 * @since 2.0.2
		 *
		 * @param string $plugin_id Plugin id, as returned by `Woodev_Plugin::get_id()`.
		 * @return Framework_Plugin_Loader_Definition|null
		 */
		public function get_loader_definition_for_plugin_id( string $plugin_id ): ?Framework_Plugin_Loader_Definition {
			return $this->definitions_by_plugin_id[ $plugin_id ] ?? null;
		}

		/**
		 * Gets the registered loader definition for a plugin's own class, EXACT match only —
		 * no ancestor walk. Callers wanting the ancestor fallback too must try
		 * {@see self::get_loader_definition_for_plugin_id()} first and only reach for
		 * {@see self::get_loader_definition_for_class_ancestor()} last: an exact `plugin_id`
		 * match is a real registration for THIS class, while an ancestor match merely means
		 * some OTHER, unrelated plugin's `main_class` happens to be a parent of this one — a
		 * real shape when a callback-only plugin's class extends another plugin's main class
		 * without registering its own main_class (#916 round 2).
		 *
		 * The class-name match is case-insensitive, same as PHP itself treats class names.
		 *
		 * The PRIMARY lookup {@see Woodev_Plugin::get_download_id()} reads through, because a
		 * plugin's `get_id()` is a free-form string chosen by convention to match its own
		 * definition's `plugin_id` — nothing enforces that they agree, and a real fixture in this
		 * repo demonstrated the mismatch (#916 follow-up). The main class is authoritative: it is
		 * literally the class the definition names, recorded in {@see invoke_plugin()} at the
		 * moment this resolver invoked it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $class Plugin instance class, as returned by `get_class( $plugin )`.
		 * @return Framework_Plugin_Loader_Definition|null
		 */
		public function get_loader_definition_for_class( string $class ): ?Framework_Plugin_Loader_Definition {
			if ( isset( $this->definitions_by_main_class[ $class ] ) ) {
				return $this->definitions_by_main_class[ $class ];
			}

			return $this->find_definition_by_main_class_case_insensitive( $class );
		}

		/**
		 * Gets the registered loader definition for the nearest registered ANCESTOR of a
		 * plugin's own class — last-resort fallback only, after both an exact class match
		 * ({@see self::get_loader_definition_for_class()}) and an exact `plugin_id` match
		 * ({@see self::get_loader_definition_for_plugin_id()}) missed. An ancestor match means
		 * some other plugin's `main_class` is a parent of this class; that other plugin's own
		 * exact registration must never lose to it (#916 round 2).
		 *
		 * @since 2.0.2
		 *
		 * @param string $class Plugin instance class, as returned by `get_class( $plugin )`.
		 * @return Framework_Plugin_Loader_Definition|null
		 */
		public function get_loader_definition_for_class_ancestor( string $class ): ?Framework_Plugin_Loader_Definition {
			// class_parents() warns (and returns false) for a class that does not exist — never
			// itself invoked through this resolver is the common case for that, not a bug.
			if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
				return null;
			}

			foreach ( class_parents( $class ) as $ancestor ) {
				if ( isset( $this->definitions_by_main_class[ $ancestor ] ) ) {
					return $this->definitions_by_main_class[ $ancestor ];
				}

				$definition = $this->find_definition_by_main_class_case_insensitive( $ancestor );
				if ( null !== $definition ) {
					return $definition;
				}
			}

			return null;
		}

		/**
		 * Case-insensitive fallback for an exact {@see self::$definitions_by_main_class} lookup
		 * that missed — PHP class names are case-insensitive, but a loader definition's
		 * `main_class` is a plain string an author typed, so its casing is not guaranteed to
		 * match the class's actual declared casing (#916 round 2).
		 *
		 * @since 2.0.2
		 *
		 * @param string $class Class name to match, case-insensitively, against registered main classes.
		 * @return Framework_Plugin_Loader_Definition|null
		 */
		private function find_definition_by_main_class_case_insensitive( string $class ): ?Framework_Plugin_Loader_Definition {
			foreach ( $this->definitions_by_main_class as $main_class => $definition ) {
				if ( 0 === strcasecmp( $main_class, $class ) ) {
					return $definition;
				}
			}

			return null;
		}

		/**
		 * Loads compatible registered plugins.
		 *
		 * On a download id collision below, which plugin loads (and which is quarantined) is
		 * decided by `framework_compare()`'s sort — highest `framework_version` first, then
		 * registration order — NOT by which plugin was active first or longest. A merchant's
		 * long-installed, licensed plugin can lose to a newer-framework plugin that happens to
		 * collide with it. That is accepted (operator decision, #916: the resolver only prevents
		 * a SECOND plugin from ever running with a taken id — it does not arbitrate seniority),
		 * because the ACTIVATION guard ({@see guard_activated_plugin()}) is the primary defence:
		 * it refuses the second plugin's activation outright, so a fresh collision never reaches
		 * this load-order tiebreak at all. This tiebreak only matters for a duplicate that is
		 * already active by the time this runs (a bulk activation, or an update that introduced
		 * the collision), which the activation guard could not have seen.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function load_plugins(): void {
			if ( $this->loaded ) {
				return;
			}

			$this->loaded = true;

			usort( $this->registered_plugins, [ $this, 'framework_compare' ] );

			$loaded_framework    = null;
			$claimed_download_ids = [];
			foreach ( $this->registered_plugins as $plugin ) {
				if ( null === $loaded_framework ) {
					$loaded_framework = $plugin;

					// Register the framework autoloader against the winning copy (first after
					// the version sort) BEFORE any typed plugin class (`extends ...`) is parsed.
					// This is what lets a plugin declare its type by inheritance alone — no
					// capabilities hint, no hard-coded base-class requires.
					$winner_path = $this->get_plugin_path( $loaded_framework['path'] );
					$autoloader  = $winner_path . '/woodev/class-framework-autoloader.php';

					if ( ! class_exists( 'Woodev_Framework_Autoloader', false ) && is_readable( $autoloader ) ) {
						require_once $autoloader;
					}

					if ( class_exists( 'Woodev_Framework_Autoloader', false ) ) {
						\Woodev_Framework_Autoloader::register( $winner_path );
					}
				}

				$is_base_plugin_loaded = class_exists( '\Woodev_Plugin', false );
				if ( ! $is_base_plugin_loaded ) {
					require_once $this->get_plugin_path( $plugin['path'] ) . '/woodev/class-plugin.php';
				}

				$backwards_compatible     = $loaded_framework['args']['backwards_compatible'] ?? '';
				$is_framework_incompatible = '' !== $backwards_compatible && version_compare( $backwards_compatible, $plugin['version'], '>' );
				if ( $is_framework_incompatible ) {
					$this->incompatible_framework_plugins[] = $plugin;
					continue;
				}

				if ( $this->fails_php_requirement( $plugin ) ) {
					$this->incompatible_php_version_plugins[] = $plugin;
					continue;
				}

				if ( $this->fails_wordpress_requirement( $plugin ) ) {
					$this->incompatible_wp_version_plugins[] = $plugin;
					continue;
				}

				if ( $this->fails_woocommerce_requirement( $plugin ) ) {
					$this->incompatible_wc_version_plugins[] = $plugin;
					continue;
				}

				$definition  = $plugin['definition'] ?? null;
				$download_id = $definition instanceof Framework_Plugin_Loader_Definition ? $definition->get_download_id() : 0;

				// A claim is only ever recorded for a plugin that actually ran (below, after a
				// successful invoke_plugin()) — never here, up front. Recording it before invoking
				// would quarantine a later plugin for an id held by one that never actually loaded
				// (e.g. an invalid definition whose main_class does not exist), which is worse than
				// the collision itself: the id sits claimed forever by nothing (#916 round 2).
				if ( $download_id > 0 && isset( $claimed_download_ids[ $download_id ] ) ) {
					$holder = $claimed_download_ids[ $download_id ];
					$index  = count( $this->quarantined_download_id_plugins );

					$this->quarantined_download_id_plugins[] = $plugin + [
						'claimed_by'  => $holder['plugin_name'],
						'deactivated' => false,
					];

					// Both plugins were already active before this code ran (bulk activation,
					// or an update that introduced the collision): self-heal by removing the
					// duplicate from the active_plugins option, but only in a context where a
					// deliberate admin action is plausible — never for an anonymous front-end
					// request or a cron/AJAX tick, where deactivate_plugins() would be a
					// silent, unauthorized site change (#916).
					//
					// The actual call is DEFERRED to `admin_init` (blocker): `load_plugins()`
					// runs on `plugins_loaded`, which on every wp-admin request fires BEFORE
					// `wp-admin/admin.php` requires `wp-admin/includes/admin.php` (→
					// `wp-admin/includes/plugin.php`). `deactivate_plugins()` — and
					// `is_plugin_active_for_network()`, needed for the multisite check below —
					// are undefined at this point, so calling them here fatals every admin page
					// once two active plugins share a download id. By `admin_init` WordPress has
					// already required that file for every admin request.
					if ( is_admin() && ! defined( 'DOING_AJAX' ) && current_user_can( 'activate_plugins' ) ) {
						$plugin_file  = plugin_basename( $definition->get_plugin_file() );
						$plugin_name  = $plugin['plugin_name'];
						$holder_name  = $holder['plugin_name'];

						add_action(
							'admin_init',
							function () use ( $plugin_file, $index, $plugin_name, $holder_name ): void {
								// Never network-deactivate a duplicate from a subsite screen —
								// only self-heal a network-active duplicate from the network
								// admin, and pass network_wide explicitly rather than leaving
								// deactivate_plugins() to infer it (#916).
								$network_wide = is_multisite() && is_plugin_active_for_network( $plugin_file );

								if ( $network_wide && ! is_network_admin() ) {
									return;
								}

								deactivate_plugins( $plugin_file, false, $network_wide );

								$this->quarantined_download_id_plugins[ $index ]['deactivated'] = true;

								// admin-post.php processes an action and redirects/exits without
								// ever firing admin_notices or network_admin_notices on THIS
								// request, so the array-based render_update_notices() message
								// above would never be shown (and, once this plugin is
								// deactivated, never reappears on a later request either — the
								// collision that produced it is gone). Queue it through the same
								// single-use transient the activation guard uses instead, so
								// render_activation_guard_notice() shows it on the next request
								// that does render notices (#916 round 2).
								if ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
									set_transient(
										self::ACTIVATION_GUARD_NOTICE_TRANSIENT . get_current_user_id(),
										sprintf(
											/* translators: Placeholders: %1$s - the plugin that was refused, %2$s - the plugin already holding the same license identifier */
											__( 'Невозможно активировать «%1$s»: он использует тот же идентификатор лицензии, что и «%2$s». Плагин отключён. Обратитесь к автору плагина.', 'woodev-plugin-framework' ),
											$plugin_name,
											$holder_name
										),
										60
									);
								}
							}
						);
					}

					continue;
				}

				if ( ! $this->invoke_plugin( $plugin ) ) {
					continue;
				}

				if ( $download_id > 0 ) {
					$claimed_download_ids[ $download_id ] = $plugin;
				}

				if ( ! in_array( $plugin, $this->active_plugins, true ) ) {
					$this->active_plugins[] = $plugin;
				}
			}

			if ( $this->has_update_notices() && is_admin() && ! defined( 'DOING_AJAX' ) ) {
				if ( ! has_action( 'admin_notices', $this->update_notice_renderer ) ) {
					add_action( 'admin_notices', $this->update_notice_renderer );
				}

				if ( ! has_action( 'network_admin_notices', $this->update_notice_renderer ) ) {
					add_action( 'network_admin_notices', $this->update_notice_renderer );
				}
			}

			do_action( 'woodev_plugins_loaded' );
		}

		/**
		 * Handles the compatibility deactivation action.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function maybe_deactivate_framework_plugins(): void {
			if ( ! isset( $_GET['woodev_framework_deactivate_newer'] ) ) {
				return;
			}

			if ( 'yes' === sanitize_text_field( $_GET['woodev_framework_deactivate_newer'] ) ) {
				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'woodev_framework_deactivate' ) ) {
					return;
				}

				if ( 0 === count( $this->incompatible_framework_plugins ) ) {
					return;
				}

				$plugins = [];

				foreach ( $this->active_plugins as $plugin ) {
					$plugins[] = plugin_basename( $plugin['path'] );
				}

				deactivate_plugins( $plugins );

				wp_safe_redirect(
					add_query_arg(
						[
							'plugin_status'                     => 'inactive',
							'woodev_framework_deactivate_newer' => count( $plugins ),
						],
						admin_url( 'plugins.php' )
					)
				);

				exit;
			}

			add_action( 'admin_notices', $this->deactivation_notice_renderer );
		}


		/**
		 * Guards a single plugin activation against a download id collision.
		 *
		 * Hooked to WordPress's `activated_plugin`, which fires once per plugin — even inside
		 * a bulk activation — AFTER that plugin's own file has been included (so its loader
		 * definition is already registered) and after WordPress persisted it into the
		 * `active_plugins` option. `load_plugins()` itself already ran on this request's
		 * `plugins_loaded` (`$this->loaded` is true) and will not run again for it, so this is
		 * the only place a just-activated plugin's download id is checked before the NEXT
		 * request would otherwise load it. Deactivating it here is safe under WP-CLI (`wp
		 * plugin activate`) and silent AJAX activation alike: `deactivate_plugins()` and
		 * `set_transient()` require no admin screen, and the queued notice simply never
		 * renders when nobody with an admin session reads it back (#916).
		 *
		 * @since 2.0.2
		 *
		 * @param string $plugin Activated plugin's basename (`folder/file.php`).
		 * @return void
		 */
		public function guard_activated_plugin( string $plugin ): void {
			if ( ! $this->loaded ) {
				return;
			}

			$definition = null;
			$activated  = null;

			foreach ( $this->registered_plugins as $registered ) {
				$candidate = $registered['definition'] ?? null;

				if ( $candidate instanceof Framework_Plugin_Loader_Definition && plugin_basename( $candidate->get_plugin_file() ) === $plugin ) {
					$definition = $candidate;
					$activated  = $registered;
					break;
				}
			}

			if ( null === $definition ) {
				return;
			}

			$download_id = $definition->get_download_id();

			if ( $download_id <= 0 ) {
				return;
			}

			$this->seed_activation_guard_claims();

			$holder = $this->activation_guard_claims[ $download_id ] ?? null;

			if ( null !== $holder && ( $holder['plugin_name'] ?? '' ) !== $activated['plugin_name'] ) {
				deactivate_plugins( $plugin );

				set_transient(
					self::ACTIVATION_GUARD_NOTICE_TRANSIENT . get_current_user_id(),
					sprintf(
						/* translators: Placeholders: %1$s - the plugin that was refused, %2$s - the plugin already holding the same license identifier */
						__( 'Невозможно активировать «%1$s»: он использует тот же идентификатор лицензии, что и «%2$s». Плагин отключён. Обратитесь к автору плагина.', 'woodev-plugin-framework' ),
						$activated['plugin_name'],
						$holder['plugin_name']
					),
					60
				);

				return;
			}

			$this->activation_guard_claims[ $download_id ] = $activated;
		}

		/**
		 * Renders a flashed activation-guard refusal notice, if one is waiting for the current
		 * user. Same single-use transient/`admin_notices` mechanism as
		 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::render_action_notice()}
		 * and {@see \Woodev_Account_Connection::render_connect_notice()} use for a message that
		 * must survive `deactivate_plugins()`'s implicit redirect back to the plugins screen.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function render_activation_guard_notice(): void {
			$key     = self::ACTIVATION_GUARD_NOTICE_TRANSIENT . get_current_user_id();
			$message = get_transient( $key );

			if ( ! is_string( $message ) || '' === $message ) {
				return;
			}

			delete_transient( $key );

			printf( '<div class="error"><p>%s</p></div>', esc_html( $message ) );
		}

		/**
		 * Lazily seeds the activation guard's claimed-id map from plugins already loaded by
		 * `load_plugins()` earlier in this request. Seeded once per request: a plugin activated
		 * earlier in the SAME bulk-activation request claims its id here too, via
		 * {@see self::guard_activated_plugin()}'s own final assignment.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function seed_activation_guard_claims(): void {
			if ( null !== $this->activation_guard_claims ) {
				return;
			}

			$this->activation_guard_claims = [];

			foreach ( $this->active_plugins as $plugin ) {
				$definition = $plugin['definition'] ?? null;

				if ( $definition instanceof Framework_Plugin_Loader_Definition && $definition->get_download_id() > 0 ) {
					$this->activation_guard_claims[ $definition->get_download_id() ] = $plugin;
				}
			}
		}

		/**
		 * Renders update notices for incompatible registrations.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function render_update_notices(): void {
			// Must update plugin notice.
			if ( ! empty( $this->incompatible_framework_plugins ) ) {
				$incompatible_plugin_count = count( $this->incompatible_framework_plugins );
				$active_plugin_count       = count( $this->active_plugins );

				$message  = '<p>';
				$message .= sprintf(
					_n( '%1$sAttention!%2$s The plugin %3$s was disabled because it is out of date and incompatible with the', '%1$sAttention!%2$s The plugins %3$s were disabled because they are out of date and incompatible with the', $incompatible_plugin_count, 'woodev-plugin-framework' ),
					'<strong>',
					'</strong>',
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->incompatible_framework_plugins
						)
					)
				);
				$message .= sprintf(
					_n( ' newer plugin %s.', ' newer plugins %s.', $active_plugin_count, 'woodev-plugin-framework' ),
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->active_plugins
						)
					)
				);
				$message .= '</p><p>';
				$message .= sprintf(
					__( 'To resolve this, please %1$supdate%2$s (recommended) or %1$sdeactivate%2$s', 'woodev-plugin-framework' ),
					'<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">',
					'</a>'
				);
				$message .= sprintf(
					_n( ' the plugin %1$s, or %2$sdeactivate%3$s', ' the plugins %1$s, or %2$sdeactivate%3$s', $incompatible_plugin_count, 'woodev-plugin-framework' ),
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->incompatible_framework_plugins
						)
					),
					'<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?woodev_framework_deactivate_newer=yes' ), 'woodev_framework_deactivate' ) ) . '">',
					'</a>'
				);
				$message .= sprintf(
					_n( ' the plugin %s.', ' the plugins %s.', $active_plugin_count, 'woodev-plugin-framework' ),
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->active_plugins
						)
					)
				);
				$message .= '</p>';

				echo '<div class="error">';
				echo $message;
				echo '</div>';
			}

			if ( ! empty( $this->incompatible_php_version_plugins ) ) {
				printf( '<div class="error"><p>%s</p><ul>', count( $this->incompatible_php_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of PHP:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of PHP:', 'woodev-plugin-framework' ) );

				foreach ( $this->incompatible_php_version_plugins as $plugin ) {
					echo '<li>' . sprintf( esc_html__( '%1$s requires PHP %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_php_version'] ) ) . '</li>';
				}

				echo '</ul></div>';
			}

			if ( ! empty( $this->incompatible_wc_version_plugins ) ) {
				printf( '<div class="error"><p>%s</p><ul>', count( $this->incompatible_wc_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of WooCommerce:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of WooCommerce:', 'woodev-plugin-framework' ) );

				foreach ( $this->incompatible_wc_version_plugins as $plugin ) {
					/* translators: Placeholders: %1$s - plugin name, %2$s - WooCommerce version number */
					echo '<li>' . sprintf( esc_html__( '%1$s requires WooCommerce %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_wc_version'] ) ) . '</li>';
				}

				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				echo '</ul><p>' . sprintf( esc_html__( 'Please %1$supdate WooCommerce%2$s', 'woodev-plugin-framework' ), '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '&nbsp;&raquo;</a>' ) . '</p></div>';
			}

			if ( ! empty( $this->incompatible_wp_version_plugins ) ) {
				printf( '<div class="error"><p>%s</p>', count( $this->incompatible_wp_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of WordPress:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of WordPress:', 'woodev-plugin-framework' ) );
				echo '<ul>';

				foreach ( $this->incompatible_wp_version_plugins as $plugin ) {
					echo '<li>' . sprintf( esc_html__( '%1$s requires WordPress %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_wp_version'] ) ) . '</li>';
				}

				echo '</ul>';
				echo '<p>' . sprintf( esc_html__( 'Please %1$supdate WordPress%2$s', 'woodev-plugin-framework' ), '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '&nbsp;&raquo;</a>' ) . '</p></div>';
			}

			foreach ( $this->quarantined_download_id_plugins as $plugin ) {
				$message = ( $plugin['deactivated'] ?? false )
					? sprintf(
						/* translators: Placeholders: %1$s - the plugin that was refused, %2$s - the plugin already holding the same license identifier */
						__( 'Невозможно активировать «%1$s»: он использует тот же идентификатор лицензии, что и «%2$s». Плагин отключён. Обратитесь к автору плагина.', 'woodev-plugin-framework' ),
						$plugin['plugin_name'],
						$plugin['claimed_by']
					)
					: sprintf(
						/* translators: Placeholders: %1$s - the plugin that was not started, %2$s - the plugin already holding the same license identifier */
						__( 'Плагин «%1$s» не запущен: он использует тот же идентификатор лицензии, что и «%2$s». Обратитесь к автору плагина.', 'woodev-plugin-framework' ),
						$plugin['plugin_name'],
						$plugin['claimed_by']
					);

				printf( '<div class="error"><p>%s</p></div>', esc_html( $message ) );
			}

			$this->render_invalid_loader_definition_notices();
		}

		/**
		 * Renders one notice per plugin whose loader definition was rejected (#942).
		 *
		 * A rejected definition means the plugin never started — no fatal, no explanation.
		 * The screen gets a plain merchant-facing sentence naming the plugin; the validation
		 * errors are technical detail for the plugin author and go to the log instead. Shown
		 * only to users who can act on it (`activate_plugins`), the same audience as the
		 * plugin list itself.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function render_invalid_loader_definition_notices(): void {
			if ( empty( $this->invalid_loader_definitions ) || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			foreach ( $this->invalid_loader_definitions as $invalid ) {
				$plugin_name = $this->get_invalid_loader_definition_plugin_name( $invalid );

				$this->log_invalid_loader_definition( $plugin_name, $invalid['errors'] ?? [] );

				printf(
					'<div class="error"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: Placeholder: %s - the name of the plugin that did not start */
							__( 'Плагин «%s» не запущен: он собран с ошибкой. Обратитесь к автору плагина.', 'woodev-plugin-framework' ),
							$plugin_name
						)
					)
				);
			}
		}

		/**
		 * Gets a displayable plugin name for a rejected loader definition.
		 *
		 * The entry carries either the raw array (rejected at registration — its name may be
		 * missing or not a string) or a validated definition object (rejected at invocation).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $invalid Invalid loader definition entry.
		 * @return string
		 */
		protected function get_invalid_loader_definition_plugin_name( array $invalid ): string {
			$definition = $invalid['definition'] ?? null;

			if ( $definition instanceof Framework_Plugin_Loader_Definition ) {
				$name = $definition->get_plugin_name();
			} elseif ( is_array( $definition ) && isset( $definition['plugin_name'] ) && is_string( $definition['plugin_name'] ) ) {
				$name = trim( $definition['plugin_name'] );
			} else {
				$name = '';
			}

			return '' !== $name ? $name : __( 'Неизвестный плагин', 'woodev-plugin-framework' );
		}

		/**
		 * Logs the technical reasons a loader definition was rejected (#942).
		 *
		 * @since 2.0.2
		 *
		 * @param string            $plugin_name Displayable plugin name.
		 * @param array<int,string> $errors      Validation errors.
		 * @return void
		 */
		protected function log_invalid_loader_definition( string $plugin_name, array $errors ): void {
			error_log( sprintf( '[woodev] Plugin "%s" was not loaded, invalid loader definition: %s', $plugin_name, implode( ' ', array_map( 'strval', $errors ) ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the resolver runs before WooCommerce and the framework logger exist.
		}

		/**
		 * Gets registered plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_registered_plugins(): array {
			return $this->registered_plugins;
		}

		/**
		 * Gets active plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_active_plugins(): array {
			return $this->active_plugins;
		}

		/**
		 * Gets framework-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_framework_plugins(): array {
			return $this->incompatible_framework_plugins;
		}

		/**
		 * Gets WooCommerce-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_wc_version_plugins(): array {
			return $this->incompatible_wc_version_plugins;
		}

		/**
		 * Gets WordPress-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_wp_version_plugins(): array {
			return $this->incompatible_wp_version_plugins;
		}

		/**
		 * Gets PHP-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_php_version_plugins(): array {
			return $this->incompatible_php_version_plugins;
		}

		/**
		 * Gets invalid loader definitions.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_invalid_loader_definitions(): array {
			return $this->invalid_loader_definitions;
		}


		/**
		 * Gets plugins refused a download id already claimed by another loaded/holder plugin.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_quarantined_download_id_plugins(): array {
			return $this->quarantined_download_id_plugins;
		}

		/**
		 * Compares two framework versions for highest-first sorting.
		 *
		 * @since 2.0.0
		 *
		 * @param array $a First registered plugin.
		 * @param array $b Second registered plugin.
		 * @return int
		 */
		public function framework_compare( array $a, array $b ): int {
			return version_compare( $b['version'], $a['version'] );
		}

		/**
		 * Returns the plugin path for a plugin file.
		 *
		 * @since 2.0.0
		 *
		 * @param string $file Plugin file.
		 * @return string
		 */
		public function get_plugin_path( string $file ): string {
			return untrailingslashit( plugin_dir_path( $file ) );
		}

		/**
		 * Gets the currently loaded framework version.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_framework_version(): string {
			$is_base_plugin_loaded = class_exists( '\Woodev_Plugin', false );
			return $is_base_plugin_loaded ? \Woodev_Plugin::VERSION : '';
		}

		/**
		 * Checks whether a plugin fails the PHP requirement.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool
		 */
		protected function fails_php_requirement( array $plugin ): bool {
			$definition = $plugin['definition'] ?? null;

			if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
				return false;
			}

			$requirements = $definition->get_requirements();
			$minimum      = $requirements['php'] ?? null;

			if ( null === $minimum || '0' === $minimum ) {
				return false;
			}

			return version_compare( PHP_VERSION, $minimum, '<' );
		}

		/**
		 * Checks whether a plugin fails the WordPress requirement.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool
		 */
		protected function fails_wordpress_requirement( array $plugin ): bool {
			if ( empty( $plugin['args']['minimum_wp_version'] ) ) {
				return false;
			}

			return version_compare( get_bloginfo( 'version' ), $plugin['args']['minimum_wp_version'], '<' );
		}

		/**
		 * Checks whether a plugin fails the WooCommerce requirement.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool
		 */
		protected function fails_woocommerce_requirement( array $plugin ): bool {
			$definition = $plugin['definition'] ?? null;

			if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
				return false;
			}

			if ( Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE !== $definition->get_platform() ) {
				return false;
			}

			$requirements = $definition->get_requirements();
			$minimum      = $requirements['woocommerce'] ?? null;

			if ( null === $minimum ) {
				return false;
			}

			$current = $this->get_wc_version();

			if ( null === $current ) {
				return true;
			}

			return version_compare( $current, $minimum, '<' );
		}

		/**
		 * Invokes a registered plugin callback or main class bootstrap method.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool True when a plugin callback or main class was invoked.
		 */
		protected function invoke_plugin( array $plugin ): bool {
			// Recorded UNCONDITIONALLY, before the callback branch below can return early —
			// a definition can carry both a 'callback' (which wraps the main class definition
			// and its own construction) and a 'main_class', and get_download_id() needs this
			// mapping regardless of which path actually constructs the instance (#916 follow-up).
			$definition = $plugin['definition'] ?? null;
			if ( $definition instanceof Framework_Plugin_Loader_Definition ) {
				$main_class = $definition->get_main_class();

				if ( null !== $main_class ) {
					$this->definitions_by_main_class[ $main_class ] = $definition;
				}
			}

			if ( is_callable( $plugin['callback'] ) ) {
				$plugin['callback']();
				return true;
			}

			if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
				return false;
			}

			$main_class = $definition->get_main_class();
			if ( null === $main_class || ! class_exists( $main_class ) ) {
				$this->invalid_loader_definitions[] = [
					'definition' => $definition,
					'errors'     => [ sprintf( 'Loader definition main_class does not exist: %s.', (string) $main_class ) ],
				];
				return false;
			}

			if ( is_callable( [ $main_class, 'instance' ] ) ) {
				$main_class::instance();
				return true;
			}

			new $main_class();
			return true;
		}

		/**
		 * Determines whether any update notices are pending.
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		protected function has_update_notices(): bool {
			return $this->incompatible_framework_plugins || $this->incompatible_wc_version_plugins || $this->incompatible_wp_version_plugins || $this->incompatible_php_version_plugins || $this->quarantined_download_id_plugins || $this->invalid_loader_definitions;
		}

		/**
		 * Gets the WooCommerce version number.
		 *
		 * @since 2.0.0
		 *
		 * @return string|null
		 */
		protected function get_wc_version(): ?string {
			if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
				return WC_VERSION;
			}

			return null;
		}
	}

endif;
