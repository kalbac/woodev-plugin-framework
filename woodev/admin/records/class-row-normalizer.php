<?php
/**
 * Records engine — provider output normaliser.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what a provider returned against its own definition and rebuilds it from a whitelist.
 *
 * The browser renders rows without interpreting them, so this is where "cells follow the
 * declared type", "URLs are protocol-allowlisted" and "nothing outside the row shape leaves
 * the server" are enforced. Keys the shape does not know are dropped, not forwarded — a
 * provider cannot leak a credential by adding it to a row. A provider that breaks the contract
 * is a developer error: the whole page becomes a generic 500 rather than a half-trusted table.
 *
 * Row `activity` and the page's `account_activity` are rebuilt by {@see Activity_Normalizer}.
 *
 * @since 2.0.29
 */
final class Row_Normalizer {

	/** Longest text cell. */
	const MAX_TEXT_LENGTH = 2000;

	/** Longest URL. */
	const MAX_URL_LENGTH = 2048;

	/** Most items in one `stack` cell. */
	const MAX_STACK_ITEMS = 5;

	/** Most response messages one page may carry. */
	const MAX_MESSAGES = 20;

	/** Most options one page of an action field may carry. */
	const MAX_OPTIONS_PER_PAGE = 100;

	/**
	 * Builds the list envelope body from a provider result.
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition  $definition Definition the request was validated against.
	 * @param array<string,mixed> $result     Provider result (`rows`, `total`, optional `facets`, `messages`).
	 * @param int                 $per_page   Validated page size: the provider may not return more.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,facets:array<string,mixed>,account_activity:array<string,mixed>,messages:array<string,string>}|\WP_Error
	 */
	public static function page( Records_Definition $definition, array $result, int $per_page ) {
		try {
			$total = $result['total'] ?? null;
			if ( ! is_int( $total ) || $total < 0 ) {
				throw new \UnexpectedValueException( 'total must be a non-negative integer.' );
			}

			return [
				'rows'             => self::rows( $definition, $result['rows'] ?? null, $per_page ),
				'total'            => $total,
				'facets'           => self::facets( $definition, $result['facets'] ?? [] ),
				'account_activity' => Activity_Normalizer::account( $definition, $result['account_activity'] ?? null ),
				'messages'         => self::messages( $result['messages'] ?? [] ),
			];
		} catch ( \UnexpectedValueException $e ) {
			return Records_Error::internal( 'woodev_records_invalid_provider_response' );
		}
	}

	/**
	 * Builds the children envelope body (rows and messages; children have no facets).
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition  $definition Definition.
	 * @param array<string,mixed> $result     Provider result (`rows`, `total`, optional `messages`).
	 * @param int                 $per_page   Validated page size.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,facets:array<string,mixed>,account_activity:array<string,mixed>,messages:array<string,string>}|\WP_Error
	 */
	public static function children_page( Records_Definition $definition, array $result, int $per_page ) {
		unset( $result['facets'], $result['account_activity'] );

		$page = self::page( $definition, $result, $per_page );
		if ( $page instanceof \WP_Error || [] === array_filter( array_column( $page['rows'], 'has_children' ) ) ) {
			return $page;
		}

		// Expansion is one level deep: a child row cannot itself expand.
		return Records_Error::internal( 'woodev_records_invalid_provider_response' );
	}

	/**
	 * Builds the body of the field-options route from a provider result.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $result Provider result (`options`, optional `next_cursor`).
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:string|null}|\WP_Error
	 */
	public static function options_page( array $result ) {
		$options = $result['options'] ?? null;
		$cursor  = $result['next_cursor'] ?? null;
		$valid   = is_array( $options ) && array_values( $options ) === $options && count( $options ) <= self::MAX_OPTIONS_PER_PAGE
			&& ( null === $cursor || Query_Validator::is_row_id( $cursor ) );

		if ( ! $valid ) {
			return Records_Error::internal( 'woodev_records_invalid_provider_response' );
		}

		try {
			return [
				'options'     => array_map( [ self::class, 'option' ], $options ),
				'next_cursor' => $cursor,
			];
		} catch ( \UnexpectedValueException $e ) {
			return Records_Error::internal( 'woodev_records_invalid_provider_response' );
		}
	}

	/**
	 * @param mixed $option One provider option.
	 * @return array{value:string,label:string}
	 */
	private static function option( $option ): array {
		if ( ! is_array( $option ) || ! Query_Validator::is_row_id( $option['value'] ?? null ) || ! is_string( $option['label'] ?? null ) ) {
			throw new \UnexpectedValueException( 'an option is a value and a label.' );
		}

		return [
			'value' => $option['value'],
			'label' => self::plain( $option['label'], 200 ),
		];
	}

	/**
	 * Normalises a row list (used directly by the children route, which has no facets).
	 *
	 * @since 2.0.29
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $rows       Provider rows.
	 * @param int                $limit      Most rows allowed.
	 * @return array<int,array<string,mixed>>
	 * @throws \UnexpectedValueException When the rows break the contract.
	 */
	public static function rows( Records_Definition $definition, $rows, int $limit ): array {
		if ( ! is_array( $rows ) || array_values( $rows ) !== $rows || count( $rows ) > $limit ) {
			throw new \UnexpectedValueException( 'rows must be a list of at most the requested page size.' );
		}

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = self::row( $definition, $row );
		}

		if ( count( $out ) !== count( array_unique( array_column( $out, 'id' ) ) ) ) {
			throw new \UnexpectedValueException( 'row ids must be distinct within a page.' );
		}

		return $out;
	}

	/**
	 * Normalises the `messages` map of a response.
	 *
	 * @since 2.0.29
	 *
	 * @param mixed $messages Provider messages.
	 * @return array<string,string>
	 * @throws \UnexpectedValueException When it is not a short map of strings.
	 */
	public static function messages( $messages ): array {
		if ( ! is_array( $messages ) || count( $messages ) > self::MAX_MESSAGES ) {
			throw new \UnexpectedValueException( 'messages must be a short map.' );
		}

		$out = [];
		foreach ( $messages as $key => $message ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_.-]{1,64}$/D', $key ) || ! is_string( $message ) ) {
				throw new \UnexpectedValueException( 'messages must map short keys to strings.' );
			}
			$out[ $key ] = self::plain( $message, Records_Definition::MAX_STRING_LENGTH );
		}

		return $out;
	}

	/**
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $row        One provider row.
	 * @return array<string,mixed>
	 */
	private static function row( Records_Definition $definition, $row ): array {
		if ( ! is_array( $row ) || ! Query_Validator::is_row_id( $row['id'] ?? null ) ) {
			throw new \UnexpectedValueException( 'every row needs an opaque string id.' );
		}

		$revision     = $row['revision'] ?? '';
		$has_children = $row['has_children'] ?? false;
		if ( ! is_string( $revision ) || ( '' !== $revision && ! Query_Validator::is_row_id( $revision ) ) ) {
			throw new \UnexpectedValueException( 'revision must be an opaque string.' );
		}
		if ( ! is_bool( $has_children ) || ( $has_children && null === $definition->get_children() ) ) {
			throw new \UnexpectedValueException( 'has_children needs a declared children contract.' );
		}

		return [
			'id'           => $row['id'],
			'revision'     => $revision,
			'cells'        => self::cells( $definition, $row['cells'] ?? null ),
			'actions'      => self::row_actions( $definition, $row['actions'] ?? [] ),
			'selectable'   => self::flag( $row['selectable'] ?? false ),
			'has_children' => $has_children,
			'activity'     => Activity_Normalizer::row( $definition, $row['activity'] ?? null ),
		];
	}

	/**
	 * Every declared column gets a cell (null when the provider sent none); undeclared ones are refused.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $cells      Provider cells keyed by column id.
	 * @return array<string,mixed>
	 */
	private static function cells( Records_Definition $definition, $cells ): array {
		if ( ! is_array( $cells ) || [] !== array_diff( array_map( 'strval', array_keys( $cells ) ), $definition->get_column_ids() ) ) {
			throw new \UnexpectedValueException( 'cells must be keyed by declared column ids.' );
		}

		$out = [];
		foreach ( $definition->to_array()['columns'] as $column ) {
			$out[ $column['id'] ] = self::cell( $column['cell_type'], $cells[ $column['id'] ] ?? null );
		}

		return $out;
	}

	/**
	 * @param string $type  Declared cell type.
	 * @param mixed  $value Provider value.
	 * @return mixed Normalised cell value (null = empty cell).
	 */
	private static function cell( string $type, $value ) {
		if ( null === $value ) {
			return null;
		}

		switch ( $type ) {
			case 'text':
				return self::text_cell( $value );
			case 'number':
				return self::number_cell( $value );
			case 'date':
				return self::date_cell( $value );
			case 'badge':
				return self::badge_cell( $value );
			case 'link':
				return self::link_cell( $value );
			case 'stack':
				return self::stack_cell( $value );
			case 'progress':
				return self::progress_cell( $value );
		}

		// `actions`: rendered from the row's own `actions`; it carries no value.
		throw new \UnexpectedValueException( 'an actions cell carries no value.' );
	}

	/**
	 * @param mixed $value Provider value.
	 * @return string
	 */
	private static function text_cell( $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			throw new \UnexpectedValueException( 'a text cell is a string or a number.' );
		}

		return self::plain( (string) $value, self::MAX_TEXT_LENGTH );
	}

	/**
	 * @param mixed $value Provider value.
	 * @return int|float
	 */
	private static function number_cell( $value ) {
		if ( ! ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) ) {
			throw new \UnexpectedValueException( 'a number cell is a finite number.' );
		}

		return $value;
	}

	/**
	 * @param mixed $value Provider value: ISO 8601 date or date-time.
	 * @return string
	 */
	private static function date_cell( $value ): string {
		$pattern = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/D';

		if ( ! is_string( $value ) || 1 !== preg_match( $pattern, $value ) ) {
			throw new \UnexpectedValueException( 'a date cell is an ISO 8601 string.' );
		}

		return $value;
	}

	/**
	 * @param mixed $value Provider value: `[ 'label' => string, 'tone' => TONES ]`.
	 * @return array{label:string,tone:string}
	 */
	private static function badge_cell( $value ): array {
		$tone = is_array( $value ) ? ( $value['tone'] ?? 'neutral' ) : null;

		if ( ! is_array( $value ) || ! is_string( $value['label'] ?? null ) || ! in_array( $tone, Records_Definition::TONES, true ) ) {
			throw new \UnexpectedValueException( 'a badge cell is a label with a known tone.' );
		}

		return [
			'label' => self::plain( $value['label'], 200 ),
			'tone'  => $tone,
		];
	}

	/**
	 * @param mixed $value Provider value: `[ 'label' => string, 'url' => string ]`.
	 * @return array{label:string,url:string}
	 */
	private static function link_cell( $value ): array {
		if ( ! is_array( $value ) || ! is_string( $value['label'] ?? null ) || ! is_string( $value['url'] ?? null ) ) {
			throw new \UnexpectedValueException( 'a link cell is a label and a URL.' );
		}

		return [
			'label' => self::plain( $value['label'], 200 ),
			'url'   => self::safe_url( $value['url'] ),
		];
	}

	/**
	 * A short vertical list of simple cells: `[ [ 'type' => 'text', 'value' => … ], … ]`.
	 *
	 * @param mixed $value Provider value.
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private static function stack_cell( $value ): array {
		if ( ! is_array( $value ) || [] === $value || array_values( $value ) !== $value || count( $value ) > self::MAX_STACK_ITEMS ) {
			throw new \UnexpectedValueException( 'a stack cell is a short list of items.' );
		}

		$out = [];
		foreach ( $value as $item ) {
			$type = is_array( $item ) ? ( $item['type'] ?? null ) : null;
			if ( ! in_array( $type, [ 'text', 'badge', 'link' ], true ) ) {
				throw new \UnexpectedValueException( 'a stack item is a text, badge or link.' );
			}
			$out[] = [
				'type'  => $type,
				'value' => self::cell( $type, $item['value'] ?? null ),
			];
		}

		return $out;
	}

	/**
	 * @param mixed $value Provider value: counts and percent are null when not measurable.
	 * @return array{completed:int|null,total:int|null,percent:int|null,text:string}
	 */
	private static function progress_cell( $value ): array {
		if ( ! is_array( $value ) ) {
			throw new \UnexpectedValueException( 'a progress cell is a map.' );
		}

		$percent = $value['percent'] ?? null;
		$text    = $value['text'] ?? '';
		foreach ( [ 'completed', 'total', 'percent' ] as $key ) {
			$count = $value[ $key ] ?? null;
			if ( null !== $count && ( ! is_int( $count ) || $count < 0 ) ) {
				throw new \UnexpectedValueException( 'progress counts are non-negative integers or null.' );
			}
		}
		if ( ( null !== $percent && $percent > 100 ) || ! is_string( $text ) ) {
			throw new \UnexpectedValueException( 'progress percent is at most 100 and text is a string.' );
		}

		return [
			'completed' => $value['completed'] ?? null,
			'total'     => $value['total'] ?? null,
			'percent'   => $percent,
			'text'      => self::plain( $text, 200 ),
		];
	}

	/**
	 * A disabled action must say why: the reason is visible text, not colour alone.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $actions    Provider row actions.
	 * @return array<int,array{id:string,enabled:bool,reason:string}>
	 */
	private static function row_actions( Records_Definition $definition, $actions ): array {
		if ( ! is_array( $actions ) || array_values( $actions ) !== $actions ) {
			throw new \UnexpectedValueException( 'row actions must be a list.' );
		}

		$out = [];
		foreach ( $actions as $action ) {
			$id      = is_array( $action ) ? ( $action['id'] ?? null ) : null;
			$enabled = is_array( $action ) ? ( $action['enabled'] ?? null ) : null;
			$reason  = is_array( $action ) ? ( $action['reason'] ?? '' ) : '';

			if ( ! in_array( $id, $definition->get_row_action_ids(), true ) || ! is_bool( $enabled ) || ! is_string( $reason ) ) {
				throw new \UnexpectedValueException( 'a row action must be a declared one with an enabled flag.' );
			}

			$reason = self::plain( $reason, Records_Definition::MAX_STRING_LENGTH );
			if ( ! $enabled && '' === $reason ) {
				throw new \UnexpectedValueException( 'a disabled action must explain why.' );
			}

			$out[ $id ] = [
				'id'      => $id,
				'enabled' => $enabled,
				'reason'  => $reason,
			];
		}

		if ( count( $out ) !== count( $actions ) ) {
			throw new \UnexpectedValueException( 'a row action may appear once.' );
		}

		return array_values( $out );
	}

	/**
	 * Per-filter option counts: `[ filter id => [ [ value, label, count ], … ] ]`.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $facets     Provider facets.
	 * @return array<string,array<int,array{value:string,label:string,count:int}>>
	 */
	private static function facets( Records_Definition $definition, $facets ): array {
		if ( ! is_array( $facets ) ) {
			throw new \UnexpectedValueException( 'facets must be a map.' );
		}

		$out = [];
		foreach ( $facets as $filter_id => $entries ) {
			if ( null === $definition->get_filter( (string) $filter_id ) || ! is_array( $entries ) || count( $entries ) > Records_Definition::MAX_OPTIONS ) {
				throw new \UnexpectedValueException( 'facets must be keyed by declared filter ids.' );
			}
			foreach ( $entries as $entry ) {
				$out[ $filter_id ][] = self::facet( $entry );
			}
		}

		return $out;
	}

	/**
	 * @param mixed $entry One facet entry.
	 * @return array{value:string,label:string,count:int}
	 */
	private static function facet( $entry ): array {
		if ( ! is_array( $entry ) || ! is_string( $entry['value'] ?? null ) || 1 !== preg_match( Records_Definition::VALUE_PATTERN, $entry['value'] )
			|| ! is_string( $entry['label'] ?? null ) || ! is_int( $entry['count'] ?? null ) || $entry['count'] < 0 ) {
			throw new \UnexpectedValueException( 'a facet is a value, a label and a count.' );
		}

		return [
			'value' => $entry['value'],
			'label' => self::plain( $entry['label'], 200 ),
			'count' => $entry['count'],
		];
	}

	/**
	 * Accepts absolute http(s) URLs and root-relative paths only.
	 *
	 * `javascript:`, `data:`, protocol-relative `//host` and anything with whitespace or control
	 * characters are refused outright, not repaired.
	 *
	 * @since 2.0.29
	 *
	 * @param string $url Provider URL.
	 * @return string The same URL.
	 * @throws \UnexpectedValueException When the URL is not on the allowlist.
	 */
	public static function safe_url( string $url ): string {
		if ( '' === $url || strlen( $url ) > self::MAX_URL_LENGTH || 1 === preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			throw new \UnexpectedValueException( 'a link URL has no whitespace or control characters.' );
		}

		$absolute = 1 === preg_match( '#^https?://[^/?\#]+#i', $url );
		$rooted   = 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' );

		if ( ! $absolute && ! $rooted ) {
			throw new \UnexpectedValueException( 'a link URL is http(s) or a root-relative path.' );
		}

		return $url;
	}

	/**
	 * @param string $value Raw string.
	 * @param int    $max   Maximum length.
	 * @return string Tags stripped, trimmed.
	 */
	private static function plain( string $value, int $max ): string {
		if ( mb_strlen( $value ) > $max ) {
			throw new \UnexpectedValueException( 'a string is longer than allowed.' );
		}

		return wp_strip_all_tags( $value );
	}

	/**
	 * @param mixed $value Raw flag.
	 * @return bool
	 */
	private static function flag( $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new \UnexpectedValueException( 'selectable must be a boolean.' );
		}

		return $value;
	}
}
