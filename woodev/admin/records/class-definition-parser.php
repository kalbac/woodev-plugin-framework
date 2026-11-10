<?php
/**
 * Records engine — definition parser.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a provider's raw definition into the normalised shape of {@see Records_Definition}.
 *
 * Every method either returns the normalised part or throws {@see \InvalidArgumentException}
 * naming the offending path (`definition.columns[2].cell_type`) — messages are for developers.
 * Unknown keys are rejected at every level: a typo must not silently drop a declaration.
 *
 * @since 2.0.29
 */
final class Definition_Parser {
	/**
	 * Parses a whole definition.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $raw Raw definition.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On any violation.
	 */
	public static function parse( array $raw ): array {
		self::allow_keys(
			$raw,
			[ 'context', 'columns', 'filters', 'sorts', 'pagination', 'row_actions', 'bulk_actions', 'children', 'activity', 'messages', 'analytics' ],
			'definition'
		);

		if ( null !== ( $raw['analytics'] ?? null ) ) {
			throw new \InvalidArgumentException( 'definition.analytics must be null: analytics is a reserved slot.' );
		}

		$sorts   = self::sorts( $raw['sorts'] ?? null );
		$columns = self::columns( $raw['columns'] ?? null, $sorts['keys'] );
		$actions = self::actions( $raw );

		return [
			'schema_version' => Records_Definition::SCHEMA_VERSION,
			'context'        => self::context( $raw['context'] ?? null ),
			'columns'        => $columns,
			'filters'        => self::filters( $raw['filters'] ?? [] ),
			'sorts'          => $sorts,
			'pagination'     => self::pagination( $raw['pagination'] ?? null ),
			'row_actions'    => $actions['row_actions'],
			'bulk_actions'   => $actions['bulk_actions'],
			'children'       => self::children( $raw['children'] ?? null ),
			'activity'       => self::activity( $raw['activity'] ?? null ),
			'messages'       => self::messages( $raw['messages'] ?? [] ),
			'analytics'      => null,
		];
	}

	/**
	 * The account selector, or null when the table is not account-scoped.
	 *
	 * @param mixed $raw Raw `context`.
	 * @return array<string,mixed>|null
	 */
	private static function context( $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		$raw = self::map( $raw, 'definition.context' );
		self::allow_keys( $raw, [ 'label', 'options', 'default' ], 'definition.context' );

		$options = self::options( $raw['options'] ?? null, 'definition.context.options' );
		$default = self::text( $raw, 'default', 'definition.context', true, 191 );

		if ( [] !== $options && ! in_array( $default, array_column( $options, 'value' ), true ) ) {
			throw new \InvalidArgumentException( 'definition.context.default must be one of the declared options.' );
		}

		return [
			'id'      => Records_Definition::CONTEXT_ACCOUNT,
			'label'   => self::text( $raw, 'label', 'definition.context' ),
			'options' => $options,
			'default' => $default,
		];
	}

	/**
	 * @param mixed    $raw       Raw `columns`.
	 * @param string[] $sort_keys Declared sort keys.
	 * @return array<int,array<string,mixed>>
	 */
	private static function columns( $raw, array $sort_keys ): array {
		$list = self::list( $raw, 'definition.columns', 1, Records_Definition::MAX_COLUMNS );
		$out  = [];

		foreach ( $list as $i => $column ) {
			$path   = "definition.columns[$i]";
			$column = self::map( $column, $path );
			self::allow_keys( $column, [ 'id', 'label', 'cell_type', 'sort_key', 'required', 'visible', 'align' ], $path );

			$sort_key = isset( $column['sort_key'] ) ? self::slug( $column, 'sort_key', $path ) : null;
			if ( null !== $sort_key && ! in_array( $sort_key, $sort_keys, true ) ) {
				throw new \InvalidArgumentException( "$path.sort_key is not a declared sort key." );
			}

			$out[] = [
				'id'        => self::slug( $column, 'id', $path ),
				'label'     => self::text( $column, 'label', $path, true ), // An actions column has no header text.
				'cell_type' => self::one_of( $column, 'cell_type', $path, Records_Definition::CELL_TYPES ),
				'sort_key'  => $sort_key,
				'required'  => self::flag( $column, 'required', $path, false ),
				'visible'   => self::flag( $column, 'visible', $path, true ),
				'align'     => self::one_of( $column, 'align', $path, Records_Definition::ALIGNMENTS, 'left' ),
			];
		}

		self::unique_ids( $out, 'definition.columns' );

		return $out;
	}

	/**
	 * @param mixed $raw Raw `filters`.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filters( $raw ): array {
		$list = self::list( $raw, 'definition.filters', 0, Records_Definition::MAX_FILTERS );
		$out  = [];

		foreach ( $list as $i => $filter ) {
			$path   = "definition.filters[$i]";
			$filter = self::map( $filter, $path );
			self::allow_keys( $filter, [ 'id', 'label', 'type', 'options', 'options_source', 'default' ], $path );

			$entry = [
				'id'             => self::slug( $filter, 'id', $path ),
				'label'          => self::text( $filter, 'label', $path ),
				'type'           => self::one_of( $filter, 'type', $path, Records_Definition::FILTER_TYPES ),
				'options'        => [],
				'options_source' => self::flag( $filter, 'options_source', $path, false ),
			];

			$entry = self::filter_options( $entry, $filter, $path );

			if ( array_key_exists( 'default', $filter ) ) {
				$default = Query_Validator::normalize_filter_value( $entry, $filter['default'] );
				if ( null === $default ) {
					throw new \InvalidArgumentException( "$path.default does not fit the filter type." );
				}
				$entry['default'] = $default;
			}

			$out[] = $entry;
		}

		self::unique_ids( $out, 'definition.filters' );

		return $out;
	}

	/**
	 * Select-like filters need inline options or a source; the others take neither.
	 *
	 * @param array<string,mixed> $entry  Filter being built.
	 * @param array<string,mixed> $filter Raw filter.
	 * @param string              $path   Error path.
	 * @return array<string,mixed>
	 */
	private static function filter_options( array $entry, array $filter, string $path ): array {
		$selectable = in_array( $entry['type'], [ 'select', 'multi_select' ], true );
		$has_inline = ! empty( $filter['options'] );

		if ( ! $selectable ) {
			if ( $has_inline || $entry['options_source'] ) {
				throw new \InvalidArgumentException( "$path: only select and multi_select filters take options." );
			}

			return $entry;
		}

		if ( $has_inline === $entry['options_source'] ) {
			throw new \InvalidArgumentException( "$path: a select filter declares either options or options_source, not both or neither." );
		}

		$entry['options'] = $has_inline ? self::options( $filter['options'], "$path.options" ) : [];

		return $entry;
	}

	/**
	 * @param mixed $raw Raw `sorts`.
	 * @return array{keys:string[],default:array{key:string,direction:string}|null,directions:string[]}
	 */
	private static function sorts( $raw ): array {
		$raw = self::map( $raw ?? [ 'keys' => [] ], 'definition.sorts' );
		self::allow_keys( $raw, [ 'keys', 'default' ], 'definition.sorts' );

		$keys = [];
		foreach ( self::list( $raw['keys'] ?? [], 'definition.sorts.keys', 0, Records_Definition::MAX_SORT_KEYS ) as $i => $key ) {
			$keys[] = self::slug( [ 'key' => $key ], 'key', "definition.sorts.keys[$i]" );
		}
		if ( count( $keys ) !== count( array_unique( $keys ) ) ) {
			throw new \InvalidArgumentException( 'definition.sorts.keys has duplicates.' );
		}

		$default = null;
		if ( isset( $raw['default'] ) ) {
			$default = self::map( $raw['default'], 'definition.sorts.default' );
			self::allow_keys( $default, [ 'key', 'direction' ], 'definition.sorts.default' );
			$default = [
				'key'       => self::one_of( $default, 'key', 'definition.sorts.default', $keys ),
				'direction' => self::one_of( $default, 'direction', 'definition.sorts.default', Records_Definition::DIRECTIONS, 'asc' ),
			];
		}

		if ( [] !== $keys && null === $default ) {
			throw new \InvalidArgumentException( 'definition.sorts.default is required when sort keys are declared.' );
		}

		return [
			'keys'       => $keys,
			'directions' => Records_Definition::DIRECTIONS,
			'default'    => $default,
		];
	}

	/**
	 * @param mixed $raw Raw `pagination`.
	 * @return array{default_per_page:int,per_page_options:int[],max_per_page:int,max_selection:int}
	 */
	private static function pagination( $raw ): array {
		$raw = self::map( $raw, 'definition.pagination' );
		self::allow_keys( $raw, [ 'default_per_page', 'per_page_options', 'max_per_page', 'max_selection' ], 'definition.pagination' );

		$path    = 'definition.pagination';
		$options = [];
		foreach ( self::list( $raw['per_page_options'] ?? null, "$path.per_page_options", 1, 10 ) as $i => $option ) {
			$options[] = self::bounded_int( $option, "$path.per_page_options[$i]", 1, Records_Definition::ABSOLUTE_MAX_PER_PAGE );
		}
		sort( $options );

		$max_per_page = self::bounded_int( $raw['max_per_page'] ?? null, "$path.max_per_page", 1, Records_Definition::ABSOLUTE_MAX_PER_PAGE );
		$default      = self::bounded_int( $raw['default_per_page'] ?? null, "$path.default_per_page", 1, $max_per_page );

		if ( count( $options ) !== count( array_unique( $options ) ) || max( $options ) > $max_per_page || ! in_array( $default, $options, true ) ) {
			throw new \InvalidArgumentException( "$path: per_page_options must be distinct, within max_per_page and include default_per_page." );
		}

		return [
			'default_per_page' => $default,
			'per_page_options' => $options,
			'max_per_page'     => $max_per_page,
			'max_selection'    => self::bounded_int( $raw['max_selection'] ?? null, "$path.max_selection", 1, Records_Definition::ABSOLUTE_MAX_SELECTION ),
		];
	}

	/**
	 * @param mixed $raw Raw `children`.
	 * @return array{default_per_page:int,max_per_page:int}|null
	 */
	private static function children( $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		$raw = self::map( $raw, 'definition.children' );
		self::allow_keys( $raw, [ 'default_per_page', 'max_per_page' ], 'definition.children' );

		$max = self::bounded_int( $raw['max_per_page'] ?? null, 'definition.children.max_per_page', 1, Records_Definition::ABSOLUTE_MAX_PER_PAGE );

		return [
			'default_per_page' => self::bounded_int( $raw['default_per_page'] ?? null, 'definition.children.default_per_page', 1, $max ),
			'max_per_page'     => $max,
		];
	}

	/**
	 * The polling cadence the activity card consumes.
	 *
	 * @param mixed $raw Raw `activity`.
	 * @return array{refresh_ms:int,max_refresh_ms:int,stale_after_ms:int,id_limit:int}|null
	 */
	private static function activity( $raw ): ?array {
		if ( null === $raw ) {
			return null;
		}

		$raw = self::map( $raw, 'definition.activity' );
		self::allow_keys( $raw, [ 'refresh_ms', 'max_refresh_ms', 'stale_after_ms', 'id_limit' ], 'definition.activity' );

		$refresh = self::bounded_int( $raw['refresh_ms'] ?? null, 'definition.activity.refresh_ms', 1000, 3600000 );

		return [
			'refresh_ms'     => $refresh,
			'max_refresh_ms' => self::bounded_int( $raw['max_refresh_ms'] ?? null, 'definition.activity.max_refresh_ms', $refresh, 3600000 ),
			'stale_after_ms' => self::bounded_int( $raw['stale_after_ms'] ?? null, 'definition.activity.stale_after_ms', $refresh, 3600000 ),
			'id_limit'       => self::bounded_int( $raw['id_limit'] ?? null, 'definition.activity.id_limit', 1, Records_Definition::ABSOLUTE_MAX_PER_PAGE ),
		];
	}

	/**
	 * @param mixed $raw Raw `messages`.
	 * @return array<string,string>
	 */
	private static function messages( $raw ): array {
		$raw = self::map( $raw, 'definition.messages' );
		$out = [];

		foreach ( $raw as $key => $message ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_.-]{1,64}$/D', $key ) || ! is_string( $message ) ) {
				throw new \InvalidArgumentException( 'definition.messages must map short keys to strings.' );
			}
			$out[ $key ] = self::clean( $message, 'definition.messages.' . $key );
		}

		return $out;
	}

	/**
	 * Row and bulk actions share one id space (the `actions/{action}` route has no list in it).
	 *
	 * @param array<string,mixed> $raw Raw definition.
	 * @return array{row_actions:array<int,array<string,mixed>>,bulk_actions:array<int,array<string,mixed>>}
	 */
	private static function actions( array $raw ): array {
		$row  = self::action_list( $raw['row_actions'] ?? [], 'definition.row_actions', false );
		$bulk = self::action_list( $raw['bulk_actions'] ?? [], 'definition.bulk_actions', true );

		self::unique_ids( array_merge( $row, $bulk ), 'definition.row_actions + bulk_actions' );

		return [
			'row_actions'  => $row,
			'bulk_actions' => $bulk,
		];
	}

	/**
	 * @param mixed  $raw  Raw list.
	 * @param string $path Error path.
	 * @param bool   $bulk Whether these are bulk actions (a mutating bulk action always previews).
	 * @return array<int,array<string,mixed>>
	 */
	private static function action_list( $raw, string $path, bool $bulk ): array {
		$out = [];

		foreach ( self::list( $raw, $path, 0, Records_Definition::MAX_ACTIONS ) as $i => $action ) {
			$out[] = self::action( self::map( $action, "{$path}[$i]" ), "{$path}[$i]", $bulk );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $action Raw action.
	 * @param string              $path   Error path.
	 * @param bool                $bulk   Whether it is a bulk action.
	 * @return array<string,mixed>
	 */
	private static function action( array $action, string $path, bool $bulk ): array {
		self::allow_keys(
			$action,
			[ 'id', 'label', 'tone', 'capability', 'effect', 'interaction', 'scope', 'scope_sentence', 'fields', 'skip_preview', 'confirm_label' ],
			$path
		);

		$entry = [
			'id'             => self::slug( $action, 'id', $path ),
			'label'          => self::text( $action, 'label', $path ),
			'tone'           => self::one_of( $action, 'tone', $path, Records_Definition::TONES, 'neutral' ),
			'capability'     => self::text( $action, 'capability', $path, true, 64 ),
			'effect'         => self::one_of( $action, 'effect', $path, Records_Definition::EFFECTS ),
			'interaction'    => self::one_of( $action, 'interaction', $path, Records_Definition::INTERACTIONS ),
			'scope'          => self::one_of( $action, 'scope', $path, Records_Definition::SCOPES, 'selection' ),
			'scope_sentence' => self::text( $action, 'scope_sentence', $path, true ),
			'confirm_label'  => self::text( $action, 'confirm_label', $path, true ),
			'skip_preview'   => self::flag( $action, 'skip_preview', $path, false ),
			'fields'         => self::action_fields( $action['fields'] ?? [], "$path.fields" ),
		];

		if ( 'account' === $entry['scope'] && '' === $entry['scope_sentence'] ) {
			throw new \InvalidArgumentException( "$path: an account-wide action must state its scope in scope_sentence." );
		}
		if ( $entry['skip_preview'] && ( $bulk || 'mutate' !== $entry['effect'] || 'danger' === $entry['tone'] ) ) {
			throw new \InvalidArgumentException( "$path: only a non-destructive mutating row action may skip the preview." );
		}

		return $entry;
	}

	/**
	 * @param mixed  $raw  Raw fields.
	 * @param string $path Error path.
	 * @return array<int,array<string,mixed>>
	 */
	private static function action_fields( $raw, string $path ): array {
		$out = [];

		foreach ( self::list( $raw, $path, 0, Records_Definition::MAX_ACTION_FIELDS ) as $i => $field ) {
			$field_path = "{$path}[$i]";
			$field      = self::map( $field, $field_path );
			self::allow_keys( $field, [ 'id', 'label', 'type', 'required', 'options', 'options_source', 'help' ], $field_path );

			$entry = [
				'id'             => self::slug( $field, 'id', $field_path ),
				'label'          => self::text( $field, 'label', $field_path ),
				'type'           => self::one_of( $field, 'type', $field_path, Records_Definition::FIELD_TYPES ),
				'required'       => self::flag( $field, 'required', $field_path, false ),
				'options'        => [],
				'options_source' => self::flag( $field, 'options_source', $field_path, false ),
				'help'           => self::text( $field, 'help', $field_path, true ),
			];

			$out[] = self::filter_options( $entry, $field, $field_path );
		}

		self::unique_ids( $out, $path );

		return $out;
	}

	/**
	 * @param mixed  $raw  Raw options.
	 * @param string $path Error path.
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function options( $raw, string $path ): array {
		$out = [];

		foreach ( self::list( $raw ?? [], $path, 0, Records_Definition::MAX_OPTIONS ) as $i => $option ) {
			$option = self::map( $option, "{$path}[$i]" );
			self::allow_keys( $option, [ 'value', 'label' ], "{$path}[$i]" );

			$value = $option['value'] ?? null;
			if ( ! is_string( $value ) || 1 !== preg_match( Records_Definition::VALUE_PATTERN, $value ) ) {
				throw new \InvalidArgumentException( "{$path}[$i].value must be an opaque token." );
			}

			$out[] = [
				'value' => $value,
				'label' => self::text( $option, 'label', "{$path}[$i]" ),
			];
		}

		if ( count( $out ) !== count( array_unique( array_column( $out, 'value' ) ) ) ) {
			throw new \InvalidArgumentException( "$path has duplicate values." );
		}

		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $items Normalised items with an `id`.
	 * @param string                         $path  Error path.
	 * @return void
	 */
	private static function unique_ids( array $items, string $path ): void {
		$ids = array_column( $items, 'id' );

		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new \InvalidArgumentException( "$path has duplicate ids." );
		}
	}

	/**
	 * @param array<mixed> $source Source array.
	 * @param string[]     $known  Allowed keys.
	 * @param string       $path   Error path.
	 * @return void
	 */
	private static function allow_keys( array $source, array $known, string $path ): void {
		$unknown = array_diff( array_map( 'strval', array_keys( $source ) ), $known );

		if ( [] !== $unknown ) {
			throw new \InvalidArgumentException( sprintf( '%s has unknown key(s): %s.', $path, implode( ', ', $unknown ) ) );
		}
	}

	/**
	 * @param mixed  $value Value to check.
	 * @param string $path  Error path.
	 * @return array<string,mixed>
	 */
	private static function map( $value, string $path ): array {
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( "$path must be an array." );
		}

		return $value;
	}

	/**
	 * @param mixed  $value Value to check.
	 * @param string $path  Error path.
	 * @param int    $min   Minimum item count.
	 * @param int    $max   Maximum item count.
	 * @return array<int,mixed>
	 */
	private static function list( $value, string $path, int $min, int $max ): array {
		if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) < $min || count( $value ) > $max ) {
			throw new \InvalidArgumentException( "$path must be a list of $min to $max items." );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $source Source map.
	 * @param string              $key    Key to read.
	 * @param string              $path   Error path.
	 * @return string
	 */
	private static function slug( array $source, string $key, string $path ): string {
		$value = $source[ $key ] ?? null;

		if ( ! is_string( $value ) || 1 !== preg_match( Records_Definition::SLUG_PATTERN, $value ) ) {
			throw new \InvalidArgumentException( "$path.$key must be a lower-case slug." );
		}

		return $value;
	}

	/**
	 * A plain-text string (tags stripped, bounded).
	 *
	 * @param array<string,mixed> $source   Source map.
	 * @param string              $key      Key to read.
	 * @param string              $path     Error path.
	 * @param bool                $optional Whether a missing key yields an empty string.
	 * @param int                 $max      Maximum length.
	 * @return string
	 */
	private static function text( array $source, string $key, string $path, bool $optional = false, int $max = Records_Definition::MAX_STRING_LENGTH ): string {
		if ( ! array_key_exists( $key, $source ) && $optional ) {
			return '';
		}

		$value = $source[ $key ] ?? null;
		if ( ! is_string( $value ) || ( ! $optional && '' === trim( $value ) ) || mb_strlen( $value ) > $max ) {
			throw new \InvalidArgumentException( "$path.$key must be a string of at most $max characters." );
		}

		return self::clean( $value, "$path.$key" );
	}

	/**
	 * @param string $value Raw string.
	 * @param string $path  Error path.
	 * @return string
	 */
	private static function clean( string $value, string $path ): string {
		if ( mb_strlen( $value ) > Records_Definition::MAX_STRING_LENGTH ) {
			throw new \InvalidArgumentException( "$path is too long." );
		}

		return wp_strip_all_tags( $value );
	}

	/**
	 * @param array<string,mixed> $source  Source map.
	 * @param string              $key     Key to read.
	 * @param string              $path    Error path.
	 * @param string[]            $allowed Allowed values.
	 * @param string|null         $default Value when the key is missing (null = required).
	 * @return string
	 */
	private static function one_of( array $source, string $key, string $path, array $allowed, ?string $default = null ): string {
		$value = array_key_exists( $key, $source ) ? $source[ $key ] : $default;

		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new \InvalidArgumentException( sprintf( '%s.%s must be one of: %s.', $path, $key, implode( ', ', $allowed ) ) );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $source  Source map.
	 * @param string              $key     Key to read.
	 * @param string              $path    Error path.
	 * @param bool                $default Value when the key is missing.
	 * @return bool
	 */
	private static function flag( array $source, string $key, string $path, bool $default ): bool {
		$value = array_key_exists( $key, $source ) ? $source[ $key ] : $default;

		if ( ! is_bool( $value ) ) {
			throw new \InvalidArgumentException( "$path.$key must be a boolean." );
		}

		return $value;
	}

	/**
	 * @param mixed  $value Value to check.
	 * @param string $path  Error path.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @return int
	 */
	private static function bounded_int( $value, string $path, int $min, int $max ): int {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw new \InvalidArgumentException( "$path must be an integer from $min to $max." );
		}

		return $value;
	}
}
