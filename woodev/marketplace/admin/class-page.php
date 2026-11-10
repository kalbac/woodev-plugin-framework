<?php
/**
 * One page of a marketplace menu.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace\Admin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Page::class, false ) ) :

	/**
	 * Immutable description of one stand-alone marketplace page: «Настройки», «Товары», ….
	 *
	 * A plugin declares pages as arrays ({@see Marketplace_Plugin::get_marketplace_pages()}); this class is the one place that
	 * turns such an array into a checked value, so the menu, the manifest and the legacy redirects cannot disagree about a page.
	 *
	 * Descriptor keys: `slug`, `title`, `kind`, `tables` (for `records`), `view` (for `custom`), `capability`, `legacy_page`, and
	 * `analytics` — reserved, always `null` (ADR-013: the analytics slot exists, nothing is computed or requested for it).
	 *
	 * @since 2.0.29
	 */
	final class Page {

		/**
		 * A table of records, rendered by the framework's records engine.
		 */
		public const KIND_RECORDS = 'records';

		/**
		 * A page whose body is a React view named by `view`; the framework or the plugin supplies it.
		 */
		public const KIND_CUSTOM = 'custom';

		/**
		 * The settings body of the marketplace's own «Настройки» page.
		 */
		public const KIND_SETTINGS = 'settings';

		/**
		 * Kinds a page may have.
		 */
		private const KINDS = [ self::KIND_RECORDS, self::KIND_CUSTOM, self::KIND_SETTINGS ];

		/**
		 * Slug of the «Настройки» page: first while the plugin is not configured, last once it is.
		 */
		public const SETTINGS_SLUG = 'settings';

		/**
		 * Slug of «Товары», the first page of a configured plugin's menu — the one its root opens.
		 */
		public const LANDING_SLUG = 'products';

		/**
		 * Slugs and menu ids are lower-case words joined by dashes, so they are safe inside a URL path and a DOM id.
		 */
		public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

		/**
		 * @var string
		 */
		private $slug;

		/**
		 * @var string
		 */
		private $title;

		/**
		 * @var string
		 */
		private $kind;

		/**
		 * @var string[]
		 */
		private $tables;

		/**
		 * @var string
		 */
		private $view;

		/**
		 * @var string|null
		 */
		private $capability;

		/**
		 * @var string[]
		 */
		private $legacy_pages;

		/**
		 * @param string      $slug         Page slug.
		 * @param string      $title        Menu and page title, already translated.
		 * @param string      $kind         One of the KIND_* constants.
		 * @param string[]    $tables       Records table ids (`records` pages).
		 * @param string      $view         View handle (`custom` pages).
		 * @param string|null $capability   Capability that overrides the menu's; null inherits it.
		 * @param string[]    $legacy_pages v1 admin URLs that now land here.
		 */
		private function __construct( string $slug, string $title, string $kind, array $tables, string $view, ?string $capability, array $legacy_pages ) {
			$this->slug         = $slug;
			$this->title        = $title;
			$this->kind         = $kind;
			$this->tables       = $tables;
			$this->view         = $view;
			$this->capability   = $capability;
			$this->legacy_pages = $legacy_pages;
		}

		/**
		 * Builds a page from a plugin's descriptor.
		 *
		 * @since 2.0.29
		 *
		 * @param array<string,mixed> $descriptor Page descriptor.
		 * @return self
		 * @throws \InvalidArgumentException When the descriptor cannot describe a page.
		 */
		public static function from_array( array $descriptor ): self {
			$slug = isset( $descriptor['slug'] ) ? (string) $descriptor['slug'] : '';

			if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
				throw new \InvalidArgumentException( 'A marketplace page slug must be lower-case words joined by dashes.' );
			}

			$title = isset( $descriptor['title'] ) ? trim( (string) $descriptor['title'] ) : '';

			if ( '' === $title ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace page "%s" has no title.', $slug ) );
			}

			$kind = isset( $descriptor['kind'] ) ? (string) $descriptor['kind'] : '';

			if ( ! in_array( $kind, self::KINDS, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace page "%s" has an unknown kind.', $slug ) );
			}

			$tables = self::string_list( $descriptor['tables'] ?? [] );
			$view   = isset( $descriptor['view'] ) ? (string) $descriptor['view'] : '';

			if ( self::KIND_RECORDS === $kind && [] === $tables ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace records page "%s" names no table.', $slug ) );
			}

			if ( self::KIND_CUSTOM === $kind && '' === $view ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace custom page "%s" names no view.', $slug ) );
			}

			if ( isset( $descriptor['analytics'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Marketplace page "%s": the analytics slot is reserved and must stay null.', $slug ) );
			}

			$capability = isset( $descriptor['capability'] ) && '' !== (string) $descriptor['capability']
				? (string) $descriptor['capability']
				: null;

			return new self(
				$slug,
				$title,
				$kind,
				$tables,
				$view,
				$capability,
				self::string_list( $descriptor['legacy_page'] ?? [] )
			);
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_slug(): string {
			return $this->slug;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_title(): string {
			return $this->title;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string One of the KIND_* constants.
		 */
		public function get_kind(): string {
			return $this->kind;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string[]
		 */
		public function get_tables(): array {
			return $this->tables;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string
		 */
		public function get_view(): string {
			return $this->view;
		}

		/**
		 * @since 2.0.29
		 *
		 * @param string $menu_capability The menu's capability, inherited when the page names none.
		 * @return string
		 */
		public function get_capability( string $menu_capability ): string {
			return $this->capability ?? $menu_capability;
		}

		/**
		 * @since 2.0.29
		 *
		 * @return string[]
		 */
		public function get_legacy_pages(): array {
			return $this->legacy_pages;
		}

		/**
		 * Whether a request is one of this page's v1 URLs.
		 *
		 * Same semantics as the settings registry's legacy redirect (issue #396): every argument the declaration names must be in
		 * the request with the SAME value; extra request arguments are ignored. A declaration that parses to nothing never matches.
		 * A bare first segment (`ozon-seller`) is the `page` value.
		 *
		 * @since 2.0.29
		 *
		 * @param array<string,string> $request_args The request's parsed query arguments.
		 * @return bool
		 */
		public function matches_legacy_request( array $request_args ): bool {
			foreach ( $this->legacy_pages as $declaration ) {
				$expected = self::parse_query( $declaration );

				if ( [] === $expected ) {
					continue;
				}

				if ( self::contains_all( $request_args, $expected ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Parses a query string into its arguments; the last of a repeated argument wins, as it does in PHP.
		 *
		 * @since 2.0.29
		 *
		 * @param string $query Query string, with or without a leading `?`.
		 * @return array<string,string>
		 */
		public static function parse_query( string $query ): array {
			$args = [];

			foreach ( explode( '&', ltrim( $query, '?' ) ) as $index => $segment ) {
				if ( '' === $segment ) {
					continue;
				}

				// The leading segment may be the page value with no `page=` in front of it.
				if ( 0 === $index && false === strpos( $segment, '=' ) ) {
					$segment = 'page=' . $segment;
				}

				$parts                              = explode( '=', $segment, 2 );
				$args[ rawurldecode( $parts[0] ) ] = isset( $parts[1] ) ? rawurldecode( str_replace( '+', ' ', $parts[1] ) ) : '';
			}

			return $args;
		}

		/**
		 * @param array<string,string> $request  Parsed request.
		 * @param array<string,string> $expected Parsed declaration.
		 * @return bool
		 */
		private static function contains_all( array $request, array $expected ): bool {
			foreach ( $expected as $key => $value ) {
				if ( ! isset( $request[ $key ] ) || $request[ $key ] !== $value ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Normalises a string or a list of strings to a list of non-empty strings.
		 *
		 * @param mixed $value Raw descriptor value.
		 * @return string[]
		 */
		private static function string_list( $value ): array {
			$items = array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) );

			return array_values( array_filter( $items, 'strlen' ) );
		}
	}

endif;
