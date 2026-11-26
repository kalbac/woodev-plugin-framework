<?php
/**
 * A sentence a plugin keeps for one link.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * What a plugin has to say about one card at its product ({@see Link_Notes}): a sentence for the merchant, already translated, and
 * how serious it is. The tone decides how the screens show it: `info` is only text, `warning` and `error` also mark the state badge.
 *
 * @since 2.1.24
 */
final class Link_Note {
	public const TONE_INFO    = 'info';
	public const TONE_WARNING = 'warning';
	public const TONE_ERROR   = 'error';

	/**
	 * The sentence.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * `self::TONE_*`.
	 *
	 * @var string
	 */
	private $tone;

	/**
	 * Creates the note.
	 *
	 * @since 2.1.24
	 *
	 * @param string $text Sentence for the merchant, plain text; several sentences may be separated by line breaks.
	 * @param string $tone `self::TONE_*`.
	 * @throws \InvalidArgumentException When the text is empty or the tone is unknown.
	 */
	public function __construct( string $text, string $tone = self::TONE_WARNING ) {
		if ( '' === trim( $text ) ) {
			throw new \InvalidArgumentException( 'A link note needs a text.' );
		}

		if ( ! in_array( $tone, [ self::TONE_INFO, self::TONE_WARNING, self::TONE_ERROR ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown tone of a link note.' );
		}

		$this->text = trim( $text );
		$this->tone = $tone;
	}

	/**
	 * Returns the sentence.
	 *
	 * @since 2.1.24
	 *
	 * @return string Plain text, never empty.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Returns how serious the note is.
	 *
	 * @since 2.1.24
	 *
	 * @return string `self::TONE_*`.
	 */
	public function tone(): string {
		return $this->tone;
	}

	/**
	 * Tells whether the merchant has something to do about the card, so the state badge must show it.
	 *
	 * @since 2.1.24
	 *
	 * @return bool
	 */
	public function needs_attention(): bool {
		return self::TONE_INFO !== $this->tone;
	}
}
