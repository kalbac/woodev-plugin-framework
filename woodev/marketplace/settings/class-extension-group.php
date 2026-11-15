<?php
/**
 * A titled group of {@see Extension_Field}s.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A card on «Общие», or a titled block on the product tab.
 *
 * @since 2.1.7
 */
final class Extension_Group {
	/**
	 * Id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Title.
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Fields.
	 *
	 * @var Extension_Field[]
	 */
	private $fields;

	/**
	 * Creates the group.
	 *
	 * @since 2.1.7
	 *
	 * @param string            $id     Id (`[a-z0-9_]+`).
	 * @param string            $title  Title shown above the fields.
	 * @param Extension_Field[] $fields Fields.
	 * @throws \InvalidArgumentException When the id is not `[a-z0-9_]+`.
	 */
	public function __construct( string $id, string $title, array $fields ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $id ) ) {
			throw new \InvalidArgumentException( 'An extension group id is lower-case letters, digits and underscores.' );
		}

		$this->id     = $id;
		$this->title  = $title;
		$this->fields = array_values(
			array_filter(
				$fields,
				static function ( $field ): bool {
					return $field instanceof Extension_Field;
				}
			)
		);
	}

	/**
	 * Id.
	 *
	 * @since 2.1.7
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Title.
	 *
	 * @since 2.1.7
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Fields.
	 *
	 * @since 2.1.7
	 *
	 * @return Extension_Field[]
	 */
	public function fields(): array {
		return $this->fields;
	}
}
