<?php
/**
 * Records engine — actions that apply to every row under a filter.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Optional capability of a {@see Records_Provider}: it can preview and apply an action to every row that
 * matches a filter, not only to a list of ticked row ids (the «все по фильтру» mode of the bulk toolbar).
 *
 * Kept apart from {@see Records_Provider} the way {@see Provides_Field_Options} is, so a provider whose
 * table has no such mode implements nothing extra. A `matching` request to a provider without this
 * interface, or for an action that does not declare `all_matching`, is refused with a 400 before any
 * provider code runs.
 *
 * The engine has already checked the query against the table's declared filters and search, and that the
 * user holds the action's capability. There are no row ids, so there is no object-level
 * {@see Records_Provider::authorize()} call: the provider decides what the filter reaches.
 *
 * @since 2.1.8
 */
interface Applies_To_Matching {
	/**
	 * Read-only plan of an action over every row that matches the query. Changes nothing.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Declared action id (it declares `all_matching`).
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search; `page`, `per_page` and sort are not part of it.
	 * @param array<string,mixed>                              $payload Validated action fields.
	 * @return array<string,mixed> Same shape as {@see Records_Provider::preview()}.
	 */
	public function preview_matching( array $context, string $action, array $query, array $payload ): array;

	/**
	 * Applies an action to every row that matches the query, replanning from current state.
	 *
	 * An action over a large catalogue runs in the background: the answer is `queued` and may carry a
	 * `run` block (`id`, `products_total`) that {@see Action_Response::apply()} keeps.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Declared action id (it declares `all_matching`).
	 * @param array{search:string,filters:array<string,mixed>} $query   Validated filter and search.
	 * @param array<string,mixed>                              $payload Validated action fields.
	 * @return array<string,mixed> Same shape as {@see Records_Provider::apply()}, plus an optional `run`.
	 */
	public function apply_matching( array $context, string $action, array $query, array $payload ): array;
}
