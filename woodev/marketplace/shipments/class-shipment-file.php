<?php
/**
 * Marketplace domain — a downloaded document.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * The bytes of a document with their media type and a file name for the merchant.
 *
 * @since 2.1.28
 */
final class Shipment_File {
	private const TYPE_PATTERN = '#^[a-z0-9][a-z0-9.+-]{0,40}/[a-z0-9][a-z0-9.+-]{0,80}$#D';

	/**
	 * @var string
	 */
	private $body;

	/**
	 * @var string
	 */
	private $content_type;

	/**
	 * @var string
	 */
	private $filename;

	/**
	 * @since 2.1.28
	 *
	 * @param string $body         The bytes.
	 * @param string $content_type Media type, without parameters: `image/png`.
	 * @param string $filename     File name with its extension.
	 * @throws \InvalidArgumentException When the document is empty or the type is not a media type.
	 */
	public function __construct( string $body, string $content_type, string $filename ) {
		if ( '' === $body || 1 !== preg_match( self::TYPE_PATTERN, $content_type ) || '' === trim( $filename ) ) {
			throw new \InvalidArgumentException( 'A shipment file needs bytes, a media type and a file name.' );
		}

		$this->body         = $body;
		$this->content_type = $content_type;
		$this->filename     = trim( $filename );
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function content_type(): string {
		return $this->content_type;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function filename(): string {
		return $this->filename;
	}
}
