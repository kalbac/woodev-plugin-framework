<?php
/**
 * Records engine — provider contract.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * One server-driven table: what it shows, who may see it, and where its rows come from.
 *
 * A provider is generic — it knows neither a marketplace nor a carrier. The records engine
 * ({@see Records_Rest_Controller}) validates every request against the provider's own
 * {@see Records_Definition} BEFORE it calls {@see self::query()} or {@see self::get_children()},
 * so a provider receives only declared, typed, bounded input.
 *
 * `$context` is always the resolved context: `[ 'account_id' => string ]` (empty string when the
 * table declares no account selector). The engine has already checked that the account belongs to
 * the options the provider itself returned from {@see self::get_definition()}.
 *
 * `preview()`, `apply()` and `get_activity()` are the seams of the action and activity cards
 * (the engine routes to them there); this contract is complete so those cards do not change it.
 *
 * @since 2.0.29
 */
interface Records_Provider {
	/**
	 * The table id, unique within the owner (the plugin id) that registers the provider.
	 *
	 * @since 2.0.29
	 *
	 * @return string Lower-case slug, `[a-z0-9_-]`, at most 64 characters.
	 */
	public function get_id(): string;

	/**
	 * Declares the table for the current user.
	 *
	 * Called on every request, so the account options may be limited to the accounts the current
	 * user may use. The shape is validated by {@see Records_Definition::from_array()}.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string> $context Requested context (`account_id` may be an unverified string).
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array;

	/**
	 * Object-level permission check, in addition to the registration's capability.
	 *
	 * Anything but a strict `true` is refused. A refusal is reported exactly like an unknown id,
	 * so the response does not reveal which ids or accounts exist.
	 *
	 * @since 2.0.29
	 *
	 * @param string               $operation `definition`, `list`, `children`, `options`, or an action id.
	 * @param array<string,string> $context   Resolved context.
	 * @param string[]             $ids       Row ids the operation targets (empty for table-wide reads).
	 * @return bool
	 */
	public function authorize( string $operation, array $context, array $ids ): bool;

	/**
	 * Returns one page of rows.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $query   Validated query: `page`, `per_page`, `search`, `sort`,
	 *                                      `direction`, `filters` (only the filters that were sent).
	 * @return array{rows:array<int,array<string,mixed>>,total:int,facets?:array<string,mixed>,messages?:array<string,string>}
	 */
	public function query( array $context, array $query ): array;

	/**
	 * Returns one page of the child rows of a parent row (for example the variations of a product).
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string> $context   Resolved context.
	 * @param string               $parent_id Row id the engine has already authorised for `children`.
	 * @param array<string,mixed>  $query     Validated query: `page`, `per_page`.
	 * @return array{rows:array<int,array<string,mixed>>,total:int,messages?:array<string,string>}
	 */
	public function get_children( array $context, string $parent_id, array $query ): array;

	/**
	 * Read-only plan of an action. Implemented by the action card; never mutates.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Declared action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated action fields.
	 * @return array<string,mixed>
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array;

	/**
	 * Replans from current state and applies an action. Implemented by the action card.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Declared action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated action fields.
	 * @return array<string,mixed>
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array;

	/**
	 * Local, batched read of the activity of the rows on screen. Implemented by the activity card.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string[]             $ids     Visible or expanded row ids.
	 * @return array<string,mixed>
	 */
	public function get_activity( array $context, array $ids ): array;
}
