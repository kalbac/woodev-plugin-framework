<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Value object for a single competitor rule.
 *
 * Normalizes a plain rule array declared by a plugin's get_competitor_rules()
 * into a validated, typed object. `detect` becomes a string[] (any-match);
 * `mode` is validated against the allowed set; optional keys get safe defaults.
 *
 * @since 2.0.2
 */
final class Competitor_Rule {

	/** @since 2.0.2 */
	public const MODE_RECOMMEND = 'recommend';

	/** @since 2.0.2 */
	public const MODE_CONFLICT = 'conflict';

	/** @var string[] competitor plugin basenames; note fires if ANY is active */
	private array $detect_slugs;

	/** @var string one of MODE_* */
	private string $mode;

	/** @var int our EDD download id for the smart link target (0 when absent) */
	private int $our_download_id;

	/** @var string public product/buy URL */
	private string $our_url;

	/** @var string our product display name */
	private string $our_name;

	/** @var string|null our equivalent plugin basename; suppress recommend when active */
	private ?string $our_plugin_file;

	/** @var string competitor display name used in default templates */
	private string $competitor_name;

	/** @var string|null per-rule title override */
	private ?string $title_override;

	/** @var string|null per-rule content override */
	private ?string $content_override;

	/** @var string|null per-rule image URL override */
	private ?string $image_override;

	/**
	 * @since 2.0.2
	 *
	 * @param array<string,mixed> $raw raw rule as declared by the plugin
	 *
	 * @throws InvalidArgumentException when detect is empty or mode is invalid
	 */
	public function __construct( array $raw ) {

		$detect = $raw['detect'] ?? [];
		$detect = is_array( $detect ) ? array_values( $detect ) : [ $detect ];
		$detect = array_values( array_filter( array_map( 'strval', $detect ), static fn( $s ) => '' !== $s ) );

		if ( empty( $detect ) ) {
			throw new InvalidArgumentException( 'Competitor_Rule requires a non-empty "detect".' );
		}

		$mode = (string) ( $raw['mode'] ?? '' );

		if ( ! in_array( $mode, [ self::MODE_RECOMMEND, self::MODE_CONFLICT ], true ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid competitor rule mode "%s".', $mode ) );
		}

		$this->detect_slugs    = $detect;
		$this->mode            = $mode;
		$this->our_download_id = (int) ( $raw['our_download_id'] ?? 0 );
		$this->our_url         = (string) ( $raw['our_url'] ?? '' );
		$this->our_name        = (string) ( $raw['our_name'] ?? '' );
		$this->our_plugin_file = isset( $raw['our_plugin_file'] ) ? (string) $raw['our_plugin_file'] : null;
		$this->competitor_name = (string) ( $raw['competitor_name'] ?? '' );
		$this->title_override  = isset( $raw['title'] ) ? (string) $raw['title'] : null;
		$this->content_override = isset( $raw['content'] ) ? (string) $raw['content'] : null;
		$this->image_override  = isset( $raw['image'] ) ? (string) $raw['image'] : null;
	}

	/**
	 * @since 2.0.2
	 *
	 * @return string[]
	 */
	public function get_detect_slugs(): array {
		return $this->detect_slugs;
	}

	/** @since 2.0.2 */
	public function get_mode(): string {
		return $this->mode;
	}

	/** @since 2.0.2 */
	public function is_recommend(): bool {
		return self::MODE_RECOMMEND === $this->mode;
	}

	/** @since 2.0.2 */
	public function get_our_download_id(): int {
		return $this->our_download_id;
	}

	/** @since 2.0.2 */
	public function get_our_url(): string {
		return $this->our_url;
	}

	/** @since 2.0.2 */
	public function get_our_name(): string {
		return $this->our_name;
	}

	/** @since 2.0.2 */
	public function get_our_plugin_file(): ?string {
		return $this->our_plugin_file;
	}

	/** @since 2.0.2 */
	public function get_competitor_name(): string {
		return $this->competitor_name;
	}

	/** @since 2.0.2 */
	public function get_title_override(): ?string {
		return $this->title_override;
	}

	/** @since 2.0.2 */
	public function get_content_override(): ?string {
		return $this->content_override;
	}

	/** @since 2.0.2 */
	public function get_image_override(): ?string {
		return $this->image_override;
	}

	/**
	 * Stable per-rule note name used for dedup + auto-delete.
	 *
	 * `woodev-competitor-{mode}-{first-slug}` with the slug dasherized
	 * (dots/underscores → dashes) so it is a safe note name / message id.
	 *
	 * @since 2.0.2
	 */
	public function get_note_name(): string {
		$slug = preg_replace( '/[^a-z0-9]+/i', '-', $this->detect_slugs[0] );
		$slug = trim( strtolower( (string) $slug ), '-' );

		return sprintf( 'woodev-competitor-%s-%s', $this->mode, $slug );
	}
}
