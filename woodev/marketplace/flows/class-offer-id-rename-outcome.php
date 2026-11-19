<?php
/**
 * What the marketplace answered about one article change.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Renamed, or refused with the marketplace's own words (an article that is taken, one it does not accept). The adapter builds one per pair
 * ({@see Offer_Id_Renamer::rename_offer_ids()}).
 *
 * @since 2.1.13
 */
final class Offer_Id_Rename_Outcome {
	/**
	 * Whether the marketplace changed the article.
	 *
	 * @var bool
	 */
	private $renamed;

	/**
	 * The marketplace's answer for a refusal.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Use {@see self::renamed()} or {@see self::refused()}.
	 *
	 * @param bool   $renamed Whether the marketplace changed the article.
	 * @param string $message Answer of a refusal.
	 */
	private function __construct( bool $renamed, string $message ) {
		$this->renamed = $renamed;
		$this->message = $message;
	}

	/**
	 * The marketplace changed the article.
	 *
	 * @since 2.1.13
	 *
	 * @return self
	 */
	public static function renamed(): self {
		return new self( true, '' );
	}

	/**
	 * The marketplace refused.
	 *
	 * @since 2.1.13
	 *
	 * @param string $message The marketplace's answer, verbatim; the merchant reads it in the problems of the product.
	 * @return self
	 */
	public static function refused( string $message ): self {
		return new self( false, trim( $message ) );
	}

	/**
	 * Whether the marketplace changed the article.
	 *
	 * @since 2.1.13
	 *
	 * @return bool
	 */
	public function is_renamed(): bool {
		return $this->renamed;
	}

	/**
	 * The marketplace's answer of a refusal ('' for a renamed article).
	 *
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}
}
