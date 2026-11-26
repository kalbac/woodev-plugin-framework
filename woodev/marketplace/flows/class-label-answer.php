<?php
/**
 * What a marketplace answered to a label poll.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's answer to "is the label ready?", already read by the adapter's {@see Label_Provider}: still being made, made
 * (one file, or several files with their names), refused (a sentence), or an answer of a shape the provider does not know, which
 * the service keeps as it came so nothing is lost.
 *
 * @since 2.1.0
 */
final class Label_Answer {
	public const PENDING = 'pending';
	public const FILE    = 'file';
	public const FILES   = 'files';
	public const BROKEN  = 'broken';
	public const OTHER   = 'other';

	/**
	 * The files of a `FILES` answer.
	 *
	 * @var Label_File[]
	 */
	private $files = [];

	/**
	 * Kind.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Merchant-readable sentence of a refusal.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Document body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Document content type.
	 *
	 * @var string
	 */
	private $content_type;

	/**
	 * The answer without the file, kept in the task row.
	 *
	 * @var array<mixed>
	 */
	private $raw;

	/**
	 * Use the named constructors.
	 *
	 * @param string       $kind         Kind.
	 * @param string       $message      Sentence.
	 * @param string       $body         Body.
	 * @param string       $content_type Content type.
	 * @param array<mixed> $raw          Answer without the file.
	 */
	private function __construct( string $kind, string $message, string $body, string $content_type, array $raw ) {
		$this->kind         = $kind;
		$this->message      = $message;
		$this->body         = $body;
		$this->content_type = $content_type;
		$this->raw          = $raw;
	}

	/**
	 * The marketplace is still making the label.
	 *
	 * @since 2.1.0
	 *
	 * @return self
	 */
	public static function pending(): self {
		return new self( self::PENDING, '', '', '', [] );
	}

	/**
	 * The label is ready.
	 *
	 * @since 2.1.0
	 *
	 * @param string       $body         File.
	 * @param string       $content_type Content type, for example `application/pdf`.
	 * @param array<mixed> $raw          The answer without the file, for the task row.
	 * @return self
	 */
	public static function file( string $body, string $content_type, array $raw = [] ): self {
		return new self( self::FILE, '', $body, $content_type, $raw );
	}

	/**
	 * The labels are ready as separate files: PNG stickers become a page to print, other files are downloaded under their names.
	 *
	 * @since 2.1.28
	 *
	 * @param Label_File[] $files Files in the order they are printed; anything else in the list is dropped.
	 * @param array<mixed> $raw   The answer without the files, for the task row.
	 * @return self A `BROKEN` answer when the list holds no file.
	 */
	public static function files( array $files, array $raw = [] ): self {
		$files = array_values(
			array_filter(
				$files,
				static function ( $file ): bool {
					return $file instanceof Label_File;
				}
			)
		);

		if ( [] === $files ) {
			return self::broken( '', $raw );
		}

		$answer        = new self( self::FILES, '', '', '', $raw );
		$answer->files = $files;

		return $answer;
	}

	/**
	 * The marketplace could not make the label.
	 *
	 * @since 2.1.0
	 *
	 * @param string       $message Sentence for the merchant; empty to let the service say it.
	 * @param array<mixed> $raw     The answer, for the task row.
	 * @return self
	 */
	public static function broken( string $message, array $raw = [] ): self {
		return new self( self::BROKEN, $message, '', '', $raw );
	}

	/**
	 * An answer of an unknown shape: the service stores it as JSON.
	 *
	 * @since 2.1.0
	 *
	 * @param array<mixed> $raw The decoded answer without any file.
	 * @return self
	 */
	public static function other( array $raw ): self {
		return new self( self::OTHER, '', '', '', $raw );
	}

	/**
	 * Returns the kind.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the sentence of a refusal.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns the file.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Returns the content type of the file.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function content_type(): string {
		return $this->content_type;
	}

	/**
	 * Returns the files of a `FILES` answer.
	 *
	 * @since 2.1.28
	 *
	 * @return Label_File[] Empty for any other kind.
	 */
	public function file_list(): array {
		return $this->files;
	}

	/**
	 * Returns the answer without the file.
	 *
	 * @since 2.1.0
	 *
	 * @return array<mixed>
	 */
	public function raw(): array {
		return $this->raw;
	}
}
