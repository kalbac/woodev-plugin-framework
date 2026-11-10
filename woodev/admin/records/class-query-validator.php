<?php
/**
 * Records engine — strict request contracts.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Validates request input against a {@see Records_Definition} BEFORE any provider query runs.
 *
 * Everything unknown is refused (400): an undeclared filter, a sort key outside the whitelist,
 * a page size outside the declared options, a list longer than the declared maximum. Nothing
 * is truncated or coerced into something the caller did not send. Methods return the
 * normalised value or a {@see \WP_Error} built by {@see Records_Error}.
 *
 * @since 2.0.29
 */
final class Query_Validator {

	/** Longest search / cursor text accepted. */
	const MAX_SEARCH_LENGTH = 200;

	/** Highest page number a request may ask for. */
	const MAX_PAGE = 100000;

	/** Most values one multi-select filter may carry. */
	const MAX_MULTI_VALUES = 50;

	/** Query parameters WordPress itself adds to a REST request; never ours, never an error. */
	const CORE_QUERY_KEYS = [ '_wpnonce', '_locale', '_fields', '_embed', '_envelope', '_jsonp', '_method', 'rest_route' ];

	/**
	 * Refuses query parameters the route does not declare.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $query_params Raw query parameters.
	 * @param string[]            $allowed      Parameters the route accepts.
	 * @return \WP_Error|null Null when every parameter is known.
	 */
	public static function reject_unknown( array $query_params, array $allowed ): ?\WP_Error {
		$known = array_merge( $allowed, self::CORE_QUERY_KEYS );

		foreach ( array_keys( $query_params ) as $name ) {
			if ( ! in_array( (string) $name, $known, true ) ) {
				return Records_Error::invalid( (string) $name );
			}
		}

		return null;
	}

	/**
	 * Shape check of the requested context, before the provider is asked for a definition.
	 *
	 * @since 2.0.29
	 *
	 * @param mixed $raw Raw `context` parameter.
	 * @return array{account_id:string}|\WP_Error `account_id` is an empty string when not sent.
	 */
	public static function requested_context( $raw ) {
		if ( null === $raw ) {
			return [ Records_Definition::CONTEXT_ACCOUNT => '' ];
		}

		if ( ! is_array( $raw ) || [] !== array_diff( array_keys( $raw ), [ Records_Definition::CONTEXT_ACCOUNT ] ) ) {
			return Records_Error::invalid( 'context' );
		}

		$account = $raw[ Records_Definition::CONTEXT_ACCOUNT ] ?? '';
		if ( ! is_string( $account ) || ( '' !== $account && 1 !== preg_match( Records_Definition::VALUE_PATTERN, $account ) ) ) {
			return Records_Error::invalid( 'context.account_id' );
		}

		return [ Records_Definition::CONTEXT_ACCOUNT => $account ];
	}

	/**
	 * Resolves the requested account against the options the provider itself offered.
	 *
	 * An account that is not among them — forged, stale or another manager's — is a 404, the
	 * same answer as an unknown table. An omitted account falls back to the declared default;
	 * writes never use that fallback (they must name the account).
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition       $definition Definition built for the current user.
	 * @param array{account_id:string} $requested Output of {@see self::requested_context()}.
	 * @return array{account_id:string}|\WP_Error
	 */
	public static function resolve_context( Records_Definition $definition, array $requested ) {
		$selector = $definition->get_context();
		$account  = $requested[ Records_Definition::CONTEXT_ACCOUNT ];

		if ( null === $selector ) {
			return '' === $account ? [ Records_Definition::CONTEXT_ACCOUNT => '' ] : Records_Error::invalid( 'context.account_id' );
		}

		$account = '' === $account ? $selector['default'] : $account;
		if ( '' !== $account && ! in_array( $account, array_column( $selector['options'], 'value' ), true ) ) {
			return Records_Error::not_found();
		}

		return [ Records_Definition::CONTEXT_ACCOUNT => $account ];
	}

	/**
	 * Validates the parameters of the list route.
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition  $definition Definition.
	 * @param array<string,mixed> $params     Raw parameters without `context`.
	 * @return array{page:int,per_page:int,search:string,sort:string,direction:string,filters:array<string,mixed>}|\WP_Error
	 */
	public static function list_query( Records_Definition $definition, array $params ) {
		$paging = self::paging( $definition->get_pagination(), $params );
		if ( $paging instanceof \WP_Error ) {
			return $paging;
		}

		$search = self::search( $definition, $params['search'] ?? null );
		$sort   = self::sort( $definition, $params['sort'] ?? null, $params['direction'] ?? null );
		$filter = self::filters( $definition, $params['filters'] ?? [] );

		foreach ( [ $search, $sort, $filter ] as $part ) {
			if ( $part instanceof \WP_Error ) {
				return $part;
			}
		}

		return $paging + [
			'search'    => $search,
			'sort'      => $sort['key'],
			'direction' => $sort['direction'],
			'filters'   => $filter,
		];
	}

	/**
	 * Validates the parameters of the children route.
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition  $definition Definition (must declare `children`).
	 * @param array<string,mixed> $params     Raw `page` / `per_page`.
	 * @return array{page:int,per_page:int}|\WP_Error
	 */
	public static function children_query( Records_Definition $definition, array $params ) {
		$children = $definition->get_children();
		if ( null === $children ) {
			return Records_Error::not_found();
		}

		return self::paging(
			[
				'default_per_page' => $children['default_per_page'],
				'per_page_options' => null,
				'max_per_page'     => $children['max_per_page'],
			],
			$params
		);
	}

	/**
	 * Validates a flat, distinct list of row ids against the table's selection ceiling.
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $ids        Raw id list.
	 * @return string[]|\WP_Error
	 */
	public static function row_ids( Records_Definition $definition, $ids ) {
		if ( ! is_array( $ids ) || [] === $ids || array_values( $ids ) !== $ids ) {
			return Records_Error::invalid( 'ids' );
		}

		if ( count( $ids ) > $definition->get_pagination()['max_selection'] ) {
			return Records_Error::too_large( 'woodev_records_selection_too_large', 'ids' );
		}

		foreach ( $ids as $id ) {
			if ( ! self::is_row_id( $id ) ) {
				return Records_Error::invalid( 'ids' );
			}
		}

		return count( $ids ) === count( array_unique( $ids ) ) ? $ids : Records_Error::invalid( 'ids' );
	}

	/**
	 * Whether a value is a well-formed opaque row id.
	 *
	 * @since 2.0.29
	 *
	 * @param mixed $id Candidate.
	 * @return bool
	 */
	public static function is_row_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( Records_Definition::VALUE_PATTERN, $id );
	}

	/**
	 * Normalises one filter value to its declared type; null when it does not fit.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $filter Normalised filter descriptor.
	 * @param mixed               $raw    Raw value.
	 * @return mixed Typed value, or null when invalid.
	 */
	public static function normalize_filter_value( array $filter, $raw ) {
		switch ( $filter['type'] ) {
			case 'boolean':
				return self::boolean( $raw );
			case 'select':
				return self::select_value( $filter, $raw );
			case 'multi_select':
				return self::multi_select_value( $filter, $raw );
			case 'date_range':
				return self::date_range( $raw );
			case 'search':
				return self::search_text( $raw );
		}

		return null;
	}

	/**
	 * Page and page size.
	 *
	 * @param array{default_per_page:int,per_page_options:int[]|null,max_per_page:int} $limits Declared limits.
	 * @param array<string,mixed>                                                      $params Raw `page` / `per_page`.
	 * @return array{page:int,per_page:int}|\WP_Error
	 */
	private static function paging( array $limits, array $params ) {
		$page = array_key_exists( 'page', $params ) ? self::whole_number( $params['page'] ) : 1;
		if ( null === $page || $page < 1 || $page > self::MAX_PAGE ) {
			return Records_Error::invalid( 'page' );
		}

		$per_page = array_key_exists( 'per_page', $params ) ? self::whole_number( $params['per_page'] ) : $limits['default_per_page'];
		if ( null === $per_page || $per_page < 1 ) {
			return Records_Error::invalid( 'per_page' );
		}
		if ( $per_page > $limits['max_per_page'] ) {
			return Records_Error::too_large( 'woodev_records_per_page_too_large', 'per_page' );
		}
		if ( null !== $limits['per_page_options'] && ! in_array( $per_page, $limits['per_page_options'], true ) ) {
			return Records_Error::invalid( 'per_page' );
		}

		return [
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * `search` is accepted only by a table that declares a search filter.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $raw        Raw `search`.
	 * @return string|\WP_Error Empty string when not sent.
	 */
	private static function search( Records_Definition $definition, $raw ) {
		if ( null === $raw ) {
			return '';
		}

		$declared = array_filter(
			$definition->to_array()['filters'],
			static function ( array $filter ): bool {
				return 'search' === $filter['type'];
			}
		);

		$text = [] === $declared ? null : self::search_text( $raw );

		return null === $text ? Records_Error::invalid( 'search' ) : $text;
	}

	/**
	 * Sort key and direction; an untouched request gets the declared default.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $key        Raw `sort`.
	 * @param mixed              $direction  Raw `direction`.
	 * @return array{key:string,direction:string}|\WP_Error Empty strings for a table that is not sortable.
	 */
	private static function sort( Records_Definition $definition, $key, $direction ) {
		$default = $definition->get_default_sort();

		if ( null === $default ) {
			if ( null !== $key || null !== $direction ) {
				return Records_Error::invalid( null === $key ? 'direction' : 'sort' );
			}

			return [
				'key'       => '',
				'direction' => '',
			];
		}

		if ( null !== $key && ( ! is_string( $key ) || ! in_array( $key, $definition->get_sort_keys(), true ) ) ) {
			return Records_Error::invalid( 'sort' );
		}
		if ( null !== $direction && ( ! is_string( $direction ) || ! in_array( $direction, Records_Definition::DIRECTIONS, true ) ) ) {
			return Records_Error::invalid( 'direction' );
		}

		return [
			'key'       => $key ?? $default['key'],
			'direction' => $direction ?? ( null === $key ? $default['direction'] : 'asc' ),
		];
	}

	/**
	 * Typed filters, keyed by filter id; only the ones the request sent.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $raw        Raw `filters`.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function filters( Records_Definition $definition, $raw ) {
		if ( ! is_array( $raw ) || ( [] !== $raw && array_values( $raw ) === $raw ) ) {
			return Records_Error::invalid( 'filters' );
		}

		$out = [];
		foreach ( $raw as $id => $value ) {
			$filter = $definition->get_filter( (string) $id );
			if ( null === $filter || 'search' === $filter['type'] ) {
				return Records_Error::invalid( 'filters.' . $id );
			}

			$typed = self::normalize_filter_value( $filter, $value );
			if ( null === $typed ) {
				return Records_Error::invalid( 'filters.' . $id );
			}
			$out[ $filter['id'] ] = $typed;
		}

		return $out;
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return int|null
	 */
	private static function whole_number( $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw;
		}

		return is_string( $raw ) && 1 === preg_match( '/^\d{1,9}$/D', $raw ) ? (int) $raw : null;
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return bool|null
	 */
	private static function boolean( $raw ): ?bool {
		if ( is_bool( $raw ) ) {
			return $raw;
		}

		$map = [
			'1'     => true,
			'true'  => true,
			'0'     => false,
			'false' => false,
		];

		return is_string( $raw ) || is_int( $raw ) ? ( $map[ (string) $raw ] ?? null ) : null;
	}

	/**
	 * @param array<string,mixed> $filter Descriptor.
	 * @param mixed               $raw    Raw value.
	 * @return string|null
	 */
	private static function select_value( array $filter, $raw ): ?string {
		if ( ! is_string( $raw ) || 1 !== preg_match( Records_Definition::VALUE_PATTERN, $raw ) ) {
			return null;
		}

		if ( ! $filter['options_source'] && ! in_array( $raw, array_column( $filter['options'], 'value' ), true ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * @param array<string,mixed> $filter Descriptor.
	 * @param mixed               $raw    Raw value.
	 * @return string[]|null
	 */
	private static function multi_select_value( array $filter, $raw ): ?array {
		if ( ! is_array( $raw ) || [] === $raw || array_values( $raw ) !== $raw || count( $raw ) > self::MAX_MULTI_VALUES ) {
			return null;
		}

		$out = [];
		foreach ( $raw as $value ) {
			$typed = self::select_value( $filter, $value );
			if ( null === $typed || in_array( $typed, $out, true ) ) {
				return null;
			}
			$out[] = $typed;
		}

		return $out;
	}

	/**
	 * @param mixed $raw Raw value: `[ 'from' => 'Y-m-d', 'to' => 'Y-m-d' ]`, either end optional.
	 * @return array<string,string>|null
	 */
	private static function date_range( $raw ): ?array {
		if ( ! is_array( $raw ) || [] === $raw || array_diff( array_keys( $raw ), [ 'from', 'to' ] ) ) {
			return null;
		}

		foreach ( $raw as $date ) {
			// Shape first: DateTime throws (PHP 8) on a NUL byte and is lenient about trailing data.
			$shaped = is_string( $date ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date );
			$parsed = $shaped ? \DateTime::createFromFormat( '!Y-m-d', $date ) : false;
			if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
				return null;
			}
		}

		return isset( $raw['from'], $raw['to'] ) && $raw['from'] > $raw['to'] ? null : $raw;
	}

	/**
	 * @param mixed $raw Raw value.
	 * @return string|null Trimmed text; null when not text, too long or holding control characters.
	 */
	private static function search_text( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$text = trim( $raw );

		return mb_strlen( $text ) > self::MAX_SEARCH_LENGTH || 1 === preg_match( '/[\x00-\x1F\x7F]/', $text ) ? null : $text;
	}
}
