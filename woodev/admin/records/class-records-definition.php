<?php
/**
 * Records engine — the validated table definition.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable, validated description of one table: finite vocabularies, no HTML, no callables.
 *
 * Built only through {@see self::from_array()}, which refuses anything outside the vocabularies
 * below, so the REST layer and the React shell can rely on the shape. A definition that fails
 * validation is a developer error ({@see \InvalidArgumentException}); the controller turns it
 * into a generic 500 and never shows the message to a merchant.
 *
 * @since 2.0.29
 */
final class Records_Definition {

	/** Wire schema version of the definition envelope. */
	const SCHEMA_VERSION = 1;

	/** The only context key: which account the table is read for. */
	const CONTEXT_ACCOUNT = 'account_id';

	/** Finite cell types a column may declare. */
	const CELL_TYPES = [ 'text', 'link', 'badge', 'number', 'date', 'stack', 'actions', 'progress' ];

	/** Finite filter types. */
	const FILTER_TYPES = [ 'search', 'select', 'multi_select', 'boolean', 'date_range' ];

	/** Column alignments. */
	const ALIGNMENTS = [ 'left', 'center', 'right' ];

	/** Sort directions. */
	const DIRECTIONS = [ 'asc', 'desc' ];

	/** Visual tone of an action button or a badge. */
	const TONES = [ 'neutral', 'primary', 'info', 'success', 'warning', 'danger' ];

	/** Whether an action only reads or changes state. */
	const EFFECTS = [ 'read', 'mutate' ];

	/** How the browser runs an action. */
	const INTERACTIONS = [ 'link', 'submit', 'dialog', 'download' ];

	/** What an action works on: the selected rows or the whole account. */
	const SCOPES = [ 'selection', 'account' ];

	/** Types of an action input field. */
	const FIELD_TYPES = [ 'text', 'textarea', 'number', 'select', 'multi_select', 'boolean' ];

	/** Hard ceilings: a definition may lower them, never raise them. */
	const ABSOLUTE_MAX_PER_PAGE  = 200;
	const ABSOLUTE_MAX_SELECTION = 500;
	const MAX_COLUMNS            = 30;
	const MAX_FILTERS            = 20;
	const MAX_SORT_KEYS          = 20;
	const MAX_ACTIONS            = 30;
	const MAX_ACTION_FIELDS      = 10;
	const MAX_OPTIONS            = 200;
	const MAX_STRING_LENGTH      = 500;

	/** Slugs: ids of columns, filters, sorts, actions and fields. */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';

	/** Opaque values: option values, row ids, cursors. */
	const VALUE_PATTERN = '/^[A-Za-z0-9_.:-]{1,191}$/D';

	/**
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * @param array<string,mixed> $data Normalised definition.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Validates and normalises a provider's definition.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,mixed> $raw Definition as returned by {@see Records_Provider::get_definition()}.
	 * @return self
	 * @throws \InvalidArgumentException When anything is outside the vocabularies or limits.
	 */
	public static function from_array( array $raw ): self {
		return new self( Definition_Parser::parse( $raw ) );
	}

	/**
	 * The definition as the `definition` REST route sends it.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * @since 2.0.29
	 *
	 * @return array{id:string,label:string,options:array<int,array{value:string,label:string}>,default:string}|null
	 */
	public function get_context(): ?array {
		return $this->data['context'];
	}

	/**
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function get_column_ids(): array {
		return array_column( $this->data['columns'], 'id' );
	}

	/**
	 * @since 2.0.29
	 *
	 * @param string $id Column id.
	 * @return array<string,mixed>|null
	 */
	public function get_column( string $id ): ?array {
		return $this->find_by_id( $this->data['columns'], $id );
	}

	/**
	 * @since 2.0.29
	 *
	 * @param string $id Filter id.
	 * @return array<string,mixed>|null
	 */
	public function get_filter( string $id ): ?array {
		return $this->find_by_id( $this->data['filters'], $id );
	}

	/**
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function get_sort_keys(): array {
		return $this->data['sorts']['keys'];
	}

	/**
	 * @since 2.0.29
	 *
	 * @return array{key:string,direction:string}|null Null when the table is not sortable.
	 */
	public function get_default_sort(): ?array {
		return $this->data['sorts']['default'];
	}

	/**
	 * @since 2.0.29
	 *
	 * @return array{default_per_page:int,per_page_options:int[],max_per_page:int,max_selection:int}
	 */
	public function get_pagination(): array {
		return $this->data['pagination'];
	}

	/**
	 * @since 2.0.29
	 *
	 * @return array{default_per_page:int,max_per_page:int}|null Null when rows have no children.
	 */
	public function get_children(): ?array {
		return $this->data['children'];
	}

	/**
	 * Finds a row or bulk action by id (ids are unique across both lists).
	 *
	 * @since 2.0.29
	 *
	 * @param string $id Action id.
	 * @return array<string,mixed>|null
	 */
	public function get_action( string $id ): ?array {
		return $this->find_by_id( $this->data['row_actions'], $id ) ?? $this->find_by_id( $this->data['bulk_actions'], $id );
	}

	/**
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public function get_row_action_ids(): array {
		return array_column( $this->data['row_actions'], 'id' );
	}

	/**
	 * Finds an action field by id.
	 *
	 * @since 2.0.29
	 *
	 * @param string $action_id Action id.
	 * @param string $field_id  Field id.
	 * @return array<string,mixed>|null
	 */
	public function get_action_field( string $action_id, string $field_id ): ?array {
		$action = $this->get_action( $action_id );

		return null === $action ? null : $this->find_by_id( $action['fields'], $field_id );
	}

	/**
	 * @param array<int,array<string,mixed>> $items Descriptors that carry an `id`.
	 * @param string                         $id    Wanted id.
	 * @return array<string,mixed>|null
	 */
	private function find_by_id( array $items, string $id ): ?array {
		foreach ( $items as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}

		return null;
	}
}
