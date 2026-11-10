<?php
/**
 * Category mapping value.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Ties WooCommerce data to one remote category: which category, which export profile and where each field's value comes
 * from.
 *
 * @since 2.0.29
 */
final class Mapping {
	/**
	 * Mapping id, null before it is stored.
	 *
	 * @var int|null
	 */
	private $id;

	/**
	 * Remote category descriptor, as `Catalog_Schema::categories()` returns it.
	 *
	 * @var array<string,mixed>
	 */
	private $remote_category;

	/**
	 * Export profile id, null for none.
	 *
	 * @var int|null
	 */
	private $profile_id;

	/**
	 * Field sources keyed by field id.
	 *
	 * @var array<string,Source>
	 */
	private $sources;

	/**
	 * Creates a mapping.
	 *
	 * @since 2.0.29
	 *
	 * @param int|null             $id              Mapping id.
	 * @param array<string,mixed>  $remote_category Remote category descriptor; empty means «not mapped».
	 * @param int|null             $profile_id      Export profile id.
	 * @param array<string,Source> $sources         Field sources keyed by field id.
	 */
	public function __construct( ?int $id, array $remote_category, ?int $profile_id, array $sources = [] ) {
		$this->id              = $id;
		$this->remote_category = $remote_category;
		$this->profile_id      = $profile_id;
		$this->sources         = $sources;
	}

	/**
	 * Returns the source of a field.
	 *
	 * @since 2.0.29
	 *
	 * @param string $field_id Field id.
	 * @return Source|null Null when the mapping has none.
	 */
	public function source_for( string $field_id ): ?Source {
		return $this->sources[ $field_id ] ?? null;
	}

	/**
	 * Tells whether a remote category is chosen.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function has_category(): bool {
		return [] !== $this->remote_category;
	}

	/**
	 * Returns the mapping id, or null before it is stored.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Returns the remote category descriptor.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function remote_category(): array {
		return $this->remote_category;
	}

	/**
	 * Returns the export profile id, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function profile_id(): ?int {
		return $this->profile_id;
	}

	/**
	 * Returns every field source.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,Source>
	 */
	public function sources(): array {
		return $this->sources;
	}
}
