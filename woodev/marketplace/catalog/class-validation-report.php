<?php
/**
 * Pre-export validation outcome.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The issues found for one product before export. Errors keep the product out of the queue; warnings do not. Messages
 * are Russian and addressed to the merchant.
 *
 * @since 2.0.29
 */
final class Validation_Report {
	public const ERROR   = 'error';
	public const WARNING = 'warning';

	/**
	 * Where the merchant fixes an issue. The report names the place; the screen that shows the report builds the link, so the
	 * layer holds no admin URL.
	 */
	public const FIX_MAPPING    = 'mapping';
	public const FIX_PRODUCT    = 'product';
	public const FIX_PROFILE    = 'profile';
	public const FIX_DICTIONARY = 'dictionary';
	public const FIX_ACCOUNT    = 'account';

	/**
	 * Issues in the order they were found.
	 *
	 * @var array<int,array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}>
	 */
	private $issues;

	/**
	 * Creates a report.
	 *
	 * @since 2.0.29
	 *
	 * @param array<int,array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}> $issues Issues found so far.
	 */
	public function __construct( array $issues = [] ) {
		$this->issues = $issues;
	}

	/**
	 * Returns a copy with one more issue.
	 *
	 * @since 2.0.29
	 *
	 * @param string      $level    `ERROR` or `WARNING`.
	 * @param string      $code     Machine-readable check name; the bulk report groups by it.
	 * @param string      $message  Russian reason for the merchant.
	 * @param string|null $field_id Field the issue is about, if any.
	 * @param string|null $fix      `FIX_*`: where the merchant fixes it, or null when there is no single place.
	 * @param string|null $summary  Short label for the grouped bulk report («нет веса»); the message when null.
	 * @return self
	 * @throws \InvalidArgumentException On an unknown level.
	 */
	public function with_issue( string $level, string $code, string $message, ?string $field_id = null, ?string $fix = null, ?string $summary = null ): self {
		if ( ! in_array( $level, [ self::ERROR, self::WARNING ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown issue level.' );
		}

		return new self(
			array_merge(
				$this->issues,
				[
					[
						'level'    => $level,
						'code'     => $code,
						'message'  => $message,
						'field_id' => $field_id,
						'fix'      => $fix,
						'summary'  => $summary ?? $message,
					],
				]
			)
		);
	}

	/**
	 * Tells whether the product may be exported: no error was found.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return [] === $this->errors();
	}

	/**
	 * Returns every issue.
	 *
	 * @since 2.0.29
	 *
	 * @return array<int,array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}>
	 */
	public function issues(): array {
		return $this->issues;
	}

	/**
	 * Returns the errors.
	 *
	 * @since 2.0.29
	 *
	 * @return array<int,array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}>
	 */
	public function errors(): array {
		return $this->with_level( self::ERROR );
	}

	/**
	 * Returns the first error: the check the bulk report groups the product under.
	 *
	 * @since 2.0.29
	 *
	 * @return array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}|null Null when the product has no error.
	 */
	public function first_error(): ?array {
		return $this->errors()[0] ?? null;
	}

	/**
	 * Returns the warnings.
	 *
	 * @since 2.0.29
	 *
	 * @return array<int,array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}>
	 */
	public function warnings(): array {
		return $this->with_level( self::WARNING );
	}

	/**
	 * Filters the issues by level.
	 *
	 * @param string $level Level.
	 * @return array<int,array{level:string,code:string,message:string,field_id:?string,fix:?string,summary:string}>
	 */
	private function with_level( string $level ): array {
		$matching = [];
		foreach ( $this->issues as $issue ) {
			if ( $level === $issue['level'] ) {
				$matching[] = $issue;
			}
		}

		return $matching;
	}
}
