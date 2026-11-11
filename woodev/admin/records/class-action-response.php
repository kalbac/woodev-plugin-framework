<?php
/**
 * Records engine — what a provider answers to a preview or an apply.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a provider's preview or apply answer from a whitelist, the way {@see Row_Normalizer}
 * does for rows: counts must be whole numbers that add up, text is plain and bounded, URLs are
 * on the allowlist, and a key the shape does not know is dropped, not forwarded. A provider that
 * breaks the contract is a developer error and becomes a generic 500.
 *
 * What the PROVIDER returns from `preview()`:
 *
 * - `requested`, `eligible`, `skipped` — whole numbers; `requested` counts distinct targets
 *   after parent/child normalisation, or the whole account for an account-wide action;
 * - optional `groups`, `warnings`, `messages`, and `scope_sentence`, `confirm_label`,
 *   `can_apply` to refine what the definition declared.
 *
 * What it returns from `apply()` (which replans from current state — there is no receipt):
 *
 * - `requested`, `accepted`, `skipped`, `failed` — "accepted" means saved or queued, never
 *   completed remotely;
 * - optional `queued` (true when the work runs in the background), `rows` (the changed rows),
 *   `removed_ids`, `groups`, `messages`, and `download` (`url`, optional `filename`) for a
 *   download action.
 *
 * Each `groups` entry is `reason` plus `count`, and optionally `kind` (`skipped`, `failed` or
 * `warning`), `fix_label`, `fix_url` and a few `examples`.
 *
 * The engine, not the provider, decides the `status` of an apply from the counts, so a
 * partial result can never be reported as done.
 *
 * @since 2.0.30
 */
final class Action_Response {

	/** Statuses of an apply, derived from its counts. */
	const STATUS_DONE    = 'done';
	const STATUS_PARTIAL = 'partial';
	const STATUS_QUEUED  = 'queued';
	const STATUS_FAILED  = 'failed';

	/** What a group of reasons is about. */
	const GROUP_KINDS = [ 'skipped', 'failed', 'warning' ];

	/** Most groups, warnings and examples one answer may carry. */
	const MAX_GROUPS   = 50;
	const MAX_WARNINGS = 10;
	const MAX_EXAMPLES = 5;

	/** Longest example, file name and short label. */
	const MAX_SHORT_LENGTH = 200;

	/**
	 * Builds the body of the preview route.
	 *
	 * @since 2.0.30
	 *
	 * @param array<string,mixed> $action Normalised action descriptor.
	 * @param array<string,mixed> $result Provider result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function preview( array $action, array $result ) {
		try {
			$counts = self::counts( $result, [ 'eligible', 'skipped' ] );

			return [
				'action'        => $action['id'],
				'scope'         => [
					'type'     => $action['scope'],
					'sentence' => self::text_or( $result, 'scope_sentence', Records_Definition::MAX_STRING_LENGTH, $action['scope_sentence'] ),
				],
				'requested'     => $counts['requested'],
				'eligible'      => $counts['eligible'],
				'skipped'       => $counts['skipped'],
				'warnings'      => self::warnings( $result['warnings'] ?? [] ),
				'groups'        => self::groups( $result['groups'] ?? [] ),
				'messages'      => Row_Normalizer::messages( $result['messages'] ?? [] ),
				'fields'        => $action['fields'],
				'confirm_label' => self::text_or( $result, 'confirm_label', self::MAX_SHORT_LENGTH, $action['confirm_label'] ),
				'can_apply'     => $counts['eligible'] > 0 && false !== ( $result['can_apply'] ?? true ),
			];
		} catch ( \UnexpectedValueException $e ) {
			return Records_Error::internal( 'woodev_records_invalid_provider_response' );
		}
	}

	/**
	 * Builds the body of the apply route.
	 *
	 * @since 2.0.30
	 *
	 * @param Records_Definition  $definition Definition the request was validated against.
	 * @param array<string,mixed> $action     Normalised action descriptor.
	 * @param array<string,mixed> $result     Provider result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function apply( Records_Definition $definition, array $action, array $result ) {
		try {
			$counts = self::counts( $result, [ 'accepted', 'skipped', 'failed' ] );
			$limit  = $definition->get_pagination()['max_selection'];

			$body = [
				'status'      => self::status( $counts, true === ( $result['queued'] ?? false ) ),
				'rows'        => Row_Normalizer::rows( $definition, $result['rows'] ?? [], $limit ),
				'removed_ids' => self::row_ids( $result['removed_ids'] ?? [], $limit ),
				'requested'   => $counts['requested'],
				'accepted'    => $counts['accepted'],
				'skipped'     => $counts['skipped'],
				'failed'      => $counts['failed'],
				'groups'      => self::groups( $result['groups'] ?? [] ),
				'messages'    => Row_Normalizer::messages( $result['messages'] ?? [] ),
			];

			if ( 'download' === $action['interaction'] && isset( $result['download'] ) ) {
				$body['download'] = self::download( $result['download'] );
			}

			return $body;
		} catch ( \UnexpectedValueException $e ) {
			return Records_Error::internal( 'woodev_records_invalid_provider_response' );
		}
	}

	/**
	 * Whether a URL may be opened by the browser as a download: a path on this site, or an
	 * http(s) URL on one of this site's own origins ({@see self::own_origins()}). The server
	 * applies it to what a provider returns and sends the origins along, so the browser checks
	 * the same list before it offers the link.
	 *
	 * @since 2.0.30
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_own_url( string $url ): bool {
		try {
			Row_Normalizer::safe_url( $url );
		} catch ( \UnexpectedValueException $e ) {
			return false;
		}

		return 0 === strpos( $url, '/' ) || in_array( self::origin_of( $url ), self::own_origins(), true );
	}

	/**
	 * The origins this site is served from: the front end and the admin may sit on different hosts.
	 *
	 * @since 2.0.30
	 *
	 * @return string[] `scheme://host[:port]`, lower-case, distinct.
	 */
	public static function own_origins(): array {
		$origins = array_map( [ self::class, 'origin_of' ], [ home_url(), site_url(), admin_url() ] );

		return array_values( array_unique( array_filter( $origins ) ) );
	}

	/**
	 * @param string $url Absolute URL.
	 * @return string Origin; empty when the URL has no http(s) scheme or host.
	 */
	private static function origin_of( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && is_string( $parts['scheme'] ?? null ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? strtolower( $parts['host'] ) : '';

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return '';
		}

		$default = 'https' === $scheme ? 443 : 80;
		$port    = (int) ( $parts['port'] ?? $default );

		return $scheme . '://' . $host . ( $port === $default ? '' : ':' . $port );
	}

	/**
	 * `requested` plus the named counts, each a whole number; the named ones must add up to it
	 * exactly, so no requested target goes unaccounted for.
	 *
	 * @param array<string,mixed> $result Provider result.
	 * @param string[]            $names  Counts besides `requested`.
	 * @return array<string,int>
	 * @throws \UnexpectedValueException When a count is missing, not whole, or they do not add up.
	 */
	private static function counts( array $result, array $names ): array {
		$counts = [ 'requested' => self::whole( $result['requested'] ?? null, 'requested' ) ];

		foreach ( $names as $name ) {
			$counts[ $name ] = self::whole( $result[ $name ] ?? null, $name );
		}

		if ( array_sum( $counts ) - $counts['requested'] !== $counts['requested'] ) {
			throw new \UnexpectedValueException( 'the counts must add up to requested.' );
		}

		return $counts;
	}

	/**
	 * The status follows from the counts: nothing skipped or failed is done, something taken
	 * with something refused is partial, nothing taken is failed; background work is queued.
	 *
	 * @param array<string,int> $counts Apply counts.
	 * @param bool              $queued Whether the provider queued the work.
	 * @return string
	 */
	private static function status( array $counts, bool $queued ): string {
		if ( $queued ) {
			return self::STATUS_QUEUED;
		}
		if ( 0 === $counts['skipped'] + $counts['failed'] ) {
			return self::STATUS_DONE;
		}

		return $counts['accepted'] > 0 ? self::STATUS_PARTIAL : self::STATUS_FAILED;
	}

	/**
	 * @param mixed  $value Raw count.
	 * @param string $name  Name for the error.
	 * @return int
	 * @throws \UnexpectedValueException When it is not a non-negative integer.
	 */
	private static function whole( $value, string $name ): int {
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new \UnexpectedValueException( "$name must be a non-negative integer." );
		}

		return $value;
	}

	/**
	 * @param mixed $warnings Raw warnings.
	 * @return string[]
	 * @throws \UnexpectedValueException When it is not a short list of strings.
	 */
	private static function warnings( $warnings ): array {
		return self::text_list( $warnings, self::MAX_WARNINGS, Records_Definition::MAX_STRING_LENGTH );
	}

	/**
	 * @param mixed $groups Raw groups.
	 * @return array<int,array<string,mixed>>
	 * @throws \UnexpectedValueException When a group breaks the shape.
	 */
	private static function groups( $groups ): array {
		if ( ! is_array( $groups ) || array_values( $groups ) !== $groups || count( $groups ) > self::MAX_GROUPS ) {
			throw new \UnexpectedValueException( 'groups must be a short list.' );
		}

		return array_map( [ self::class, 'group' ], $groups );
	}

	/**
	 * @param mixed $group Raw group.
	 * @return array<string,mixed>
	 * @throws \UnexpectedValueException When the group breaks the shape.
	 */
	private static function group( $group ): array {
		if ( ! is_array( $group ) ) {
			throw new \UnexpectedValueException( 'a group is a map.' );
		}

		$kind = $group['kind'] ?? 'skipped';
		if ( ! in_array( $kind, self::GROUP_KINDS, true ) ) {
			throw new \UnexpectedValueException( 'a group kind is skipped, failed or warning.' );
		}

		$count = self::whole( $group['count'] ?? null, 'group count' );
		$url   = self::optional_text( $group, 'fix_url', Row_Normalizer::MAX_URL_LENGTH );
		$label = self::optional_text( $group, 'fix_label', self::MAX_SHORT_LENGTH );
		if ( 0 === $count || ( '' !== $url && '' === $label ) ) {
			throw new \UnexpectedValueException( 'a group counts at least one row and a fix link needs a label.' );
		}

		return [
			'kind'      => $kind,
			'reason'    => self::required_text( $group, 'reason', Records_Definition::MAX_STRING_LENGTH ),
			'count'     => $count,
			'fix_label' => $label,
			'fix_url'   => '' === $url ? '' : Row_Normalizer::safe_url( $url ),
			'examples'  => self::text_list( $group['examples'] ?? [], self::MAX_EXAMPLES, self::MAX_SHORT_LENGTH ),
		];
	}

	/**
	 * @param mixed $download Raw download.
	 * @return array{url:string,filename:string,origins:string[]}
	 * @throws \UnexpectedValueException When the URL is not this site's or the file name is not a bare name.
	 */
	private static function download( $download ): array {
		if ( ! is_array( $download ) || ! is_string( $download['url'] ?? null ) || ! self::is_own_url( $download['url'] ) ) {
			throw new \UnexpectedValueException( 'a download is a URL on this site.' );
		}

		$filename = self::optional_text( $download, 'filename', self::MAX_SHORT_LENGTH );
		if ( 1 === preg_match( '#[\\\\/]#', $filename ) ) {
			throw new \UnexpectedValueException( 'a download file name has no path.' );
		}

		return [
			'url'      => $download['url'],
			'filename' => $filename,
			'origins'  => self::own_origins(),
		];
	}

	/**
	 * @param mixed $ids   Raw ids.
	 * @param int   $limit Most ids.
	 * @return string[]
	 * @throws \UnexpectedValueException When it is not a short list of distinct row ids.
	 */
	private static function row_ids( $ids, int $limit ): array {
		if ( ! is_array( $ids ) || array_values( $ids ) !== $ids || count( $ids ) > $limit || count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new \UnexpectedValueException( 'removed_ids must be a short list of distinct ids.' );
		}

		foreach ( $ids as $id ) {
			if ( ! Query_Validator::is_row_id( $id ) ) {
				throw new \UnexpectedValueException( 'removed_ids holds a malformed id.' );
			}
		}

		return $ids;
	}

	/**
	 * @param mixed $list  Raw list of strings.
	 * @param int   $count Most items.
	 * @param int   $max   Longest item.
	 * @return string[]
	 * @throws \UnexpectedValueException When it is not a short list of strings.
	 */
	private static function text_list( $list, int $count, int $max ): array {
		if ( ! is_array( $list ) || array_values( $list ) !== $list || count( $list ) > $count ) {
			throw new \UnexpectedValueException( 'a text list must be short.' );
		}

		return array_map(
			static function ( $item ) use ( $max ): string {
				return self::plain( $item, $max );
			},
			$list
		);
	}

	/**
	 * @param array<string,mixed> $source Source map.
	 * @param string              $key    Key to read.
	 * @param int                 $max    Longest text.
	 * @return string Empty when the key is absent.
	 * @throws \UnexpectedValueException When it is present and not a string within the bound.
	 */
	private static function optional_text( array $source, string $key, int $max ): string {
		return array_key_exists( $key, $source ) ? self::plain( $source[ $key ], $max ) : '';
	}

	/**
	 * The provider's text when it sent one, else what the definition declared.
	 *
	 * @param array<string,mixed> $source   Source map.
	 * @param string              $key      Key to read.
	 * @param int                 $max      Longest text.
	 * @param string              $fallback Declared text.
	 * @return string
	 * @throws \UnexpectedValueException When it is present and not a string within the bound.
	 */
	private static function text_or( array $source, string $key, int $max, string $fallback ): string {
		$text = self::optional_text( $source, $key, $max );

		return '' === $text ? $fallback : $text;
	}

	/**
	 * @param array<string,mixed> $source Source map.
	 * @param string              $key    Key to read.
	 * @param int                 $max    Longest text.
	 * @return string
	 * @throws \UnexpectedValueException When it is absent, empty or too long.
	 */
	private static function required_text( array $source, string $key, int $max ): string {
		$text = self::optional_text( $source, $key, $max );
		if ( '' === $text ) {
			throw new \UnexpectedValueException( "$key must not be empty." );
		}

		return $text;
	}

	/**
	 * @param mixed $value Raw value.
	 * @param int   $max   Longest text.
	 * @return string Tags stripped, trimmed.
	 * @throws \UnexpectedValueException When it is not a string or is longer than allowed.
	 */
	private static function plain( $value, int $max ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max ) {
			throw new \UnexpectedValueException( 'a text is a string within its bound.' );
		}

		return trim( wp_strip_all_tags( $value ) );
	}
}
