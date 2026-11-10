<?php
/**
 * Catalogue field description.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * A normalised description of one field of a marketplace card (an Ozon attribute, a Wildberries characteristic). The
 * mapping screens and the validator work on this shape, not on the marketplace's schema.
 *
 * @since 2.0.29
 */
final class Field_Definition {
	public const TYPE_STRING     = 'string';
	public const TYPE_INTEGER    = 'integer';
	public const TYPE_DECIMAL    = 'decimal';
	public const TYPE_BOOLEAN    = 'boolean';
	public const TYPE_DICTIONARY = 'dictionary';
	public const TYPE_IMAGE      = 'image';
	public const TYPE_URL        = 'url';
	public const TYPE_MULTILINE  = 'multiline';

	/**
	 * Field id; numeric marketplace ids are kept as strings.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Label.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Whether the marketplace requires the field.
	 *
	 * @var bool
	 */
	private $required;

	/**
	 * One of the `TYPE_*` constants.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Whether the field takes several values.
	 *
	 * @var bool
	 */
	private $multi;

	/**
	 * Unit of the value (`mm`, `g`), or null.
	 *
	 * @var string|null
	 */
	private $unit;

	/**
	 * Searches dictionary values for a term; null for non-dictionary fields. Wildberries returns its fixed directory.
	 *
	 * @var callable|null
	 */
	private $dictionary_provider;

	/**
	 * Creates a field description.
	 *
	 * @since 2.0.29
	 *
	 * @param string        $id                  Field id.
	 * @param string        $name                Label.
	 * @param bool          $required            Whether the marketplace requires it.
	 * @param string        $type                `TYPE_*`.
	 * @param bool          $multi               Whether it takes several values.
	 * @param string|null   $unit                Unit of the value.
	 * @param callable|null $dictionary_provider `function ( string $term ): array` of dictionary values.
	 * @throws \InvalidArgumentException On an empty id or an unknown type.
	 */
	public function __construct( string $id, string $name, bool $required, string $type, bool $multi = false, ?string $unit = null, ?callable $dictionary_provider = null ) {
		$types = [ self::TYPE_STRING, self::TYPE_INTEGER, self::TYPE_DECIMAL, self::TYPE_BOOLEAN, self::TYPE_DICTIONARY, self::TYPE_IMAGE, self::TYPE_URL, self::TYPE_MULTILINE ];
		if ( '' === $id || ! in_array( $type, $types, true ) ) {
			throw new \InvalidArgumentException( 'A field needs an id and a known type.' );
		}

		$this->id                  = $id;
		$this->name                = $name;
		$this->required            = $required;
		$this->type                = $type;
		$this->multi               = $multi;
		$this->unit                = $unit;
		$this->dictionary_provider = $dictionary_provider;
	}

	/**
	 * Returns the field id.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the label.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Tells whether the marketplace requires the field.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return $this->required;
	}

	/**
	 * Returns the type.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Tells whether the field takes several values.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_multi(): bool {
		return $this->multi;
	}

	/**
	 * Returns the unit of the value, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function unit(): ?string {
		return $this->unit;
	}

	/**
	 * Returns the dictionary provider, or null for a field without a dictionary.
	 *
	 * @since 2.0.29
	 *
	 * @return callable|null
	 */
	public function dictionary_provider(): ?callable {
		return $this->dictionary_provider;
	}
}
