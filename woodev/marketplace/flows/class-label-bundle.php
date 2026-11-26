<?php
/**
 * A set of label files, as one stored document.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Several {@see Label_File}s in the one row a label document has: `body` holds them as JSON, `content_type` says whether the set
 * is a page to print (`SHEET_TYPE`: PNG stickers only) or a file to download (`FILES_TYPE`).
 *
 * What a download hands over ({@see self::download()}): one file under its own name; several ZPL files joined into one, which a
 * label printer prints label after label; any other set as a ZIP archive that keeps every file's name.
 *
 * @since 2.1.28
 */
final class Label_Bundle {
	/**
	 * Document content type of PNG stickers: shown as a page the browser prints.
	 */
	public const SHEET_TYPE = 'application/vnd.woodev.label-sheet+json';

	/**
	 * Document content type of every other set: downloaded.
	 */
	public const FILES_TYPE = 'application/vnd.woodev.label-files+json';

	/**
	 * The files, never empty.
	 *
	 * @var Label_File[]
	 */
	private $files;

	/**
	 * Creates the set.
	 *
	 * @since 2.1.28
	 *
	 * @param Label_File[] $files Files, not empty.
	 */
	public function __construct( array $files ) {
		$this->files = array_values( $files );
	}

	/**
	 * Reads a stored document body.
	 *
	 * @since 2.1.28
	 *
	 * @param string $body What {@see self::encode()} wrote.
	 * @return self|null Null when the body holds no readable file.
	 */
	public static function from_body( string $body ): ?self {
		$stored = json_decode( $body, true );
		$files  = [];

		foreach ( is_array( $stored ) ? $stored : [] as $file ) {
			$bytes = is_array( $file ) ? base64_decode( (string) ( $file['data'] ?? '' ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary files inside JSON.

			if ( false !== $bytes ) {
				$files[] = new Label_File( (string) ( $file['name'] ?? '' ), (string) ( $file['type'] ?? '' ), $bytes, (int) ( $file['width'] ?? 0 ), (int) ( $file['height'] ?? 0 ) );
			}
		}

		return [] === $files ? null : new self( $files );
	}

	/**
	 * Returns the files.
	 *
	 * @since 2.1.28
	 *
	 * @return Label_File[]
	 */
	public function files(): array {
		return $this->files;
	}

	/**
	 * Returns the content type the set is stored under.
	 *
	 * @since 2.1.28
	 *
	 * @return string `SHEET_TYPE` or `FILES_TYPE`.
	 */
	public function content_type(): string {
		foreach ( $this->files as $file ) {
			if ( ! $file->is_png() ) {
				return self::FILES_TYPE;
			}
		}

		return self::SHEET_TYPE;
	}

	/**
	 * Returns the body the set is stored as.
	 *
	 * @since 2.1.28
	 *
	 * @return string JSON.
	 */
	public function encode(): string {
		$stored = [];

		foreach ( $this->files as $file ) {
			$stored[] = [
				'name'   => $file->name(),
				'type'   => $file->content_type(),
				'data'   => base64_encode( $file->body() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary files inside JSON.
				'width'  => $file->width_mm(),
				'height' => $file->height_mm(),
			];
		}

		return (string) wp_json_encode( $stored );
	}

	/**
	 * Returns the one file a download of the set hands over.
	 *
	 * @since 2.1.28
	 *
	 * @param string $base_name Name without an extension, for a file made of several.
	 * @return Label_File
	 * @throws Label_Exception When the files need an archive and the server cannot make one.
	 */
	public function download( string $base_name ): Label_File {
		if ( 1 === count( $this->files ) ) {
			return $this->files[0];
		}

		if ( $this->is_all_zpl() ) {
			$bodies = array_map(
				static function ( Label_File $file ): string {
					return rtrim( $file->body(), "\r\n" );
				},
				$this->files
			);

			return new Label_File( $base_name . '.zpl', $this->files[0]->content_type(), implode( "\n", $bodies ) . "\n" );
		}

		return new Label_File( $base_name . '.zip', 'application/zip', $this->archive() );
	}

	/**
	 * @return bool Whether every file is a label printer's ZPL.
	 */
	private function is_all_zpl(): bool {
		foreach ( $this->files as $file ) {
			if ( ! $file->is_zpl() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Packs the files into a ZIP archive, each under its own name; a repeated name gets a number.
	 *
	 * @return string The archive.
	 * @throws Label_Exception When the server has no ZIP support or cannot write a temporary file.
	 */
	private function archive(): string {
		$refusal = __( 'Не удалось собрать этикетки в архив. Попросите хостинг включить расширение PHP «zip» и проверить папку временных файлов.', 'woodev-plugin-framework' );

		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new Label_Exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$path = wp_tempnam( 'woodev-labels' );
		$zip  = new \ZipArchive();

		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $path );

			throw new Label_Exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$taken = [];

		foreach ( $this->files as $file ) {
			$name           = self::free_name( sanitize_file_name( $file->name() ), $taken );
			$taken[ $name ] = true;

			$zip->addFromString( $name, $file->body() );
		}

		$written = $zip->close();
		$archive = $written ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local temporary file.

		wp_delete_file( $path );

		if ( false === $archive ) {
			throw new Label_Exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $archive;
	}

	/**
	 * @param string             $name  Wanted file name.
	 * @param array<string,bool> $taken Names already used.
	 * @return string The name, or `name-2.ext`, `name-3.ext`… when it is taken.
	 */
	private static function free_name( string $name, array $taken ): string {
		$dot  = strrpos( $name, '.' );
		$stem = false === $dot ? $name : substr( $name, 0, $dot );
		$ext  = false === $dot ? '' : substr( $name, $dot );

		for ( $number = 2; isset( $taken[ $name ] ); $number++ ) {
			$name = $stem . '-' . $number . $ext;
		}

		return $name;
	}
}
