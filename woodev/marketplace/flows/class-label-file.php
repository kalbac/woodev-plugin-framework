<?php
/**
 * One file of a set of labels.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * A file the marketplace answered with: its real name, its media type and its bytes, handed to {@see Label_Answer::files()}.
 *
 * The media type decides how the set reaches the merchant ({@see Label_Bundle}): `image/png` stickers are laid out on a page the
 * browser prints, a media type that contains `zpl` is a label printer's file, anything else is downloaded as it is.
 *
 * @since 2.1.28
 */
final class Label_File {
	public const PNG = 'image/png';

	/**
	 * File name, without a path.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Media type.
	 *
	 * @var string
	 */
	private $content_type;

	/**
	 * The bytes.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Printed width in millimetres; 0 when not said.
	 *
	 * @var int
	 */
	private $width_mm;

	/**
	 * Printed height in millimetres; 0 when not said.
	 *
	 * @var int
	 */
	private $height_mm;

	/**
	 * Creates the file.
	 *
	 * @since 2.1.28
	 *
	 * @param string $name         File name the merchant downloads it under, with its extension.
	 * @param string $content_type Media type, for example `image/png` or `application/zpl`.
	 * @param string $body         The bytes (decoded, not base64).
	 * @param int    $width_mm     Printed width of a sticker image in millimetres; 0 prints it at its own size.
	 * @param int    $height_mm    Printed height of a sticker image in millimetres; 0 prints it at its own size.
	 */
	public function __construct( string $name, string $content_type, string $body, int $width_mm = 0, int $height_mm = 0 ) {
		$name = trim( str_replace( [ '/', '\\' ], '-', $name ) );

		$this->name         = '' === $name ? 'label' : $name;
		$this->content_type = strtolower( trim( $content_type ) );
		$this->body         = $body;
		$this->width_mm     = max( 0, $width_mm );
		$this->height_mm    = max( 0, $height_mm );
	}

	/**
	 * Returns the file name.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the media type, in lower case.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function content_type(): string {
		return $this->content_type;
	}

	/**
	 * Returns the bytes.
	 *
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Returns the printed width.
	 *
	 * @since 2.1.28
	 *
	 * @return int Millimetres; 0 when not said.
	 */
	public function width_mm(): int {
		return $this->width_mm;
	}

	/**
	 * Returns the printed height.
	 *
	 * @since 2.1.28
	 *
	 * @return int Millimetres; 0 when not said.
	 */
	public function height_mm(): int {
		return $this->height_mm;
	}

	/**
	 * Tells whether the file is a PNG image, the one kind the print page lays out.
	 *
	 * @since 2.1.28
	 *
	 * @return bool
	 */
	public function is_png(): bool {
		return self::PNG === $this->content_type;
	}

	/**
	 * Tells whether the file is a label printer's ZPL.
	 *
	 * @since 2.1.28
	 *
	 * @return bool
	 */
	public function is_zpl(): bool {
		return false !== strpos( $this->content_type, 'zpl' );
	}
}
