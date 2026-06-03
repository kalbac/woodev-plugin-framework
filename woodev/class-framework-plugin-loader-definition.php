<?php
/**
 * Platform v2 plugin loader definition.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Framework_Plugin_Loader_Definition::class, false ) ) :

	/**
	 * Normalized plugin loader definition for the minimal framework resolver.
	 *
	 * Loader metadata is early infrastructure only. Runtime plugin behavior must be
	 * validated through inheritance or contracts once the plugin class is available.
	 *
	 * @since 2.0.0
	 */
	class Framework_Plugin_Loader_Definition {

		public const PLATFORM_WORDPRESS   = 'wordpress';
		public const PLATFORM_WOOCOMMERCE = 'woocommerce';
		public const PLATFORM_EDD         = 'edd';

		public const CAPABILITY_WORDPRESS_PLUGIN   = 'wordpress_plugin';
		public const CAPABILITY_WOOCOMMERCE_PLUGIN = 'woocommerce_plugin';
		public const CAPABILITY_PAYMENT_GATEWAY    = 'payment_gateway';
		public const CAPABILITY_SHIPPING_METHOD    = 'shipping_method';

		/** @var string Stable internal plugin ID. */
		protected string $plugin_id;

		/** @var string Human-readable plugin name. */
		protected string $plugin_name;

		/** @var string Plugin version. */
		protected string $plugin_version;

		/** @var string Vendored framework version. */
		protected string $framework_version;

		/** @var string Main plugin file path. */
		protected string $plugin_file;

		/** @var string Platform value. */
		protected string $platform;

		/** @var array<string,string> Requirements map. */
		protected array $requirements;

		/** @var string|null Main plugin class name. */
		protected ?string $main_class;

		/** @var callable|null Initialization callback. */
		protected $callback;

		/** @var string[] Early class availability capabilities. */
		protected array $capabilities;

		/**
		 * Constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 */
		private function __construct( array $definition ) {
			$this->plugin_id         = (string) $definition['plugin_id'];
			$this->plugin_name       = (string) $definition['plugin_name'];
			$this->plugin_version    = (string) $definition['plugin_version'];
			$this->framework_version = (string) $definition['framework_version'];
			$this->plugin_file       = (string) $definition['plugin_file'];
			$this->platform          = (string) $definition['platform'];
			$this->requirements      = $this->normalize_requirements( (array) $definition['requirements'] );
			$this->main_class        = isset( $definition['main_class'] ) ? (string) $definition['main_class'] : null;
			$this->callback          = $definition['callback'] ?? null;
			$this->capabilities      = $this->normalize_capabilities( $definition['capabilities'] ?? [] );
		}

		/**
		 * Creates a validated loader definition from an explicit array.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 * @param string[]            $errors     Validation errors.
		 * @return self|null
		 */
		public static function from_array( array $definition, array &$errors = [] ): ?self {
			$errors = self::validate_raw_definition( $definition );

			if ( [] !== $errors ) {
				return null;
			}

			return new self( $definition );
		}

		/**
		 * Gets the plugin ID.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_plugin_id(): string {
			return $this->plugin_id;
		}

		/**
		 * Gets the plugin name.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_plugin_name(): string {
			return $this->plugin_name;
		}

		/**
		 * Gets the plugin version.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_plugin_version(): string {
			return $this->plugin_version;
		}

		/**
		 * Gets the framework version.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_framework_version(): string {
			return $this->framework_version;
		}

		/**
		 * Gets the plugin file path.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_plugin_file(): string {
			return $this->plugin_file;
		}

		/**
		 * Gets the platform value.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_platform(): string {
			return $this->platform;
		}

		/**
		 * Gets the requirements map.
		 *
		 * @since 2.0.0
		 *
		 * @return array<string,string>
		 */
		public function get_requirements(): array {
			return $this->requirements;
		}

		/**
		 * Gets the main class name.
		 *
		 * @since 2.0.0
		 *
		 * @return string|null
		 */
		public function get_main_class(): ?string {
			return $this->main_class;
		}

		/**
		 * Gets the initialization callback.
		 *
		 * @since 2.0.0
		 *
		 * @return callable|null
		 */
		public function get_callback(): ?callable {
			return $this->callback;
		}

		/**
		 * Gets early class availability capabilities.
		 *
		 * @since 2.0.0
		 *
		 * @return string[]
		 */
		public function get_capabilities(): array {
			return $this->capabilities;
		}

		/**
		 * Converts this definition to the legacy plugin array used by existing notices.
		 *
		 * @since 2.0.0
		 *
		 * @param array $args Additional legacy args to preserve.
		 * @return array<string,mixed>
		 */
		public function to_legacy_plugin( array $args = [] ): array {
			$requirements = $this->get_requirements();

			if ( isset( $requirements['php'] ) && '0' !== $requirements['php'] ) {
				$args['minimum_php_version'] = $requirements['php'];
			}

			if ( isset( $requirements['wordpress'] ) && '0' !== $requirements['wordpress'] ) {
				$args['minimum_wp_version'] = $requirements['wordpress'];
			}

			if ( isset( $requirements['woocommerce'] ) ) {
				$args['minimum_wc_version'] = $requirements['woocommerce'];
			}

			return [
				'version'     => $this->get_framework_version(),
				'plugin_name' => $this->get_plugin_name(),
				'path'        => $this->get_plugin_file(),
				'callback'    => $this->get_callback(),
				'args'        => $args,
				'definition'  => $this,
			];
		}

		/**
		 * Validates a raw loader definition.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 * @return string[]
		 */
		protected static function validate_raw_definition( array $definition ): array {
			$errors = [];

			foreach ( [ 'plugin_id', 'plugin_name', 'plugin_version', 'framework_version', 'plugin_file', 'platform', 'requirements' ] as $field ) {
				if ( ! array_key_exists( $field, $definition ) || '' === $definition[ $field ] || [] === $definition[ $field ] ) {
					$errors[] = sprintf( 'Missing required loader definition field: %s.', $field );
				}
			}

			if ( empty( $definition['main_class'] ) && empty( $definition['callback'] ) ) {
				$errors[] = 'Loader definition requires at least one of main_class or callback.';
			}

			if ( isset( $definition['callback'] ) && ! is_callable( $definition['callback'] ) ) {
				$errors[] = 'Loader definition callback must be callable.';
			}

			$platform = $definition['platform'] ?? '';
			if ( ! in_array( $platform, self::get_allowed_platforms(), true ) ) {
				$errors[] = sprintf( 'Unsupported loader definition platform: %s.', (string) $platform );
			}

			if ( self::PLATFORM_EDD === $platform ) {
				$errors[] = 'EDD loader definitions are reserved and unsupported in Platform v2.0.';
			}

			$requirements = isset( $definition['requirements'] ) && is_array( $definition['requirements'] ) ? $definition['requirements'] : [];

			foreach ( [ 'php', 'wordpress' ] as $field ) {
				if ( ! array_key_exists( $field, $requirements ) || '' === $requirements[ $field ] ) {
					$errors[] = sprintf( 'Missing required loader requirement: %s.', $field );
				}
			}

			if ( self::PLATFORM_WOOCOMMERCE === $platform && ( ! array_key_exists( 'woocommerce', $requirements ) || '' === $requirements['woocommerce'] ) ) {
				$errors[] = 'WooCommerce loader definitions require a woocommerce requirement.';
			}

			$capabilities = isset( $definition['capabilities'] ) ? (array) $definition['capabilities'] : [];
			foreach ( $capabilities as $capability ) {
				if ( ! in_array( $capability, self::get_allowed_capabilities(), true ) ) {
					$errors[] = sprintf( 'Unsupported loader capability: %s.', (string) $capability );
				}
			}

			if ( in_array( self::CAPABILITY_PAYMENT_GATEWAY, $capabilities, true ) && self::PLATFORM_WOOCOMMERCE !== $platform ) {
				$errors[] = 'Payment gateway capability requires the woocommerce platform.';
			}

			if ( in_array( self::CAPABILITY_SHIPPING_METHOD, $capabilities, true ) && self::PLATFORM_WOOCOMMERCE !== $platform ) {
				$errors[] = 'Shipping method capability requires the woocommerce platform.';
			}

			return $errors;
		}

		/**
		 * Gets allowed platform values.
		 *
		 * @since 2.0.0
		 *
		 * @return string[]
		 */
		protected static function get_allowed_platforms(): array {
			return [
				self::PLATFORM_WORDPRESS,
				self::PLATFORM_WOOCOMMERCE,
				self::PLATFORM_EDD,
			];
		}

		/**
		 * Gets allowed capability values.
		 *
		 * @since 2.0.0
		 *
		 * @return string[]
		 */
		protected static function get_allowed_capabilities(): array {
			return [
				self::CAPABILITY_WORDPRESS_PLUGIN,
				self::CAPABILITY_WOOCOMMERCE_PLUGIN,
				self::CAPABILITY_PAYMENT_GATEWAY,
				self::CAPABILITY_SHIPPING_METHOD,
			];
		}

		/**
		 * Sanitizes a plugin ID without depending on WordPress functions during early loading.
		 *
		 * @since 2.0.0
		 *
		 * @param string $plugin_id Raw plugin ID.
		 * @return string
		 */
		protected static function sanitize_plugin_id( string $plugin_id ): string {
			$plugin_id = strtolower( $plugin_id );
			$plugin_id = preg_replace( '/[^a-z0-9_\-]/', '-', $plugin_id );

			return trim( (string) $plugin_id, '-' );
		}

		/**
		 * Normalizes requirements to strings.
		 *
		 * @since 2.0.0
		 *
		 * @param array $requirements Raw requirements.
		 * @return array<string,string>
		 */
		protected function normalize_requirements( array $requirements ): array {
			$normalized = [];

			foreach ( $requirements as $key => $value ) {
				$normalized[ (string) $key ] = (string) $value;
			}

			return $normalized;
		}

		/**
		 * Normalizes capabilities to strings.
		 *
		 * @since 2.0.0
		 *
		 * @param mixed $capabilities Raw capabilities.
		 * @return string[]
		 */
		protected function normalize_capabilities( $capabilities ): array {
			return array_values( array_unique( array_map( 'strval', (array) $capabilities ) ) );
		}
	}

endif;
