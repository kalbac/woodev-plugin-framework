<?php
/**
 * Marketplace domain — a kind of document a shipment has.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * What a marketplace says about a document of its shipments once ({@see Shipment_Batches::shipment_documents()}).
 *
 * @since 2.1.28
 */
final class Shipment_Document_Type {
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';

	/**
	 * @var string
	 */
	private $id;

	/**
	 * @var string
	 */
	private $label;

	/**
	 * @var string
	 */
	private $short_label;

	/**
	 * @var string
	 */
	private $icon;

	/**
	 * @since 2.1.28
	 *
	 * @param string $id          Slug: lower-case letters, digits, `_` and `-`, at most 32.
	 * @param string $label       Name of the button, in Russian: «Скачать QR-код».
	 * @param string $short_label Short name of at most 32 characters.
	 * @param string $icon        Dashicons slug.
	 * @throws \InvalidArgumentException When the id or the label cannot work.
	 */
	public function __construct( string $id, string $label, string $short_label = '', string $icon = 'media-document' ) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || '' === trim( $label ) ) {
			throw new \InvalidArgumentException( 'A shipment document needs a slug id and a label.' );
		}

		$this->id          = $id;
		$this->label       = trim( $label );
		$this->short_label = trim( $short_label );
		$this->icon        = $icon;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string Empty for none.
	 */
	public function short_label(): string {
		return $this->short_label;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function icon(): string {
		return $this->icon;
	}
}
