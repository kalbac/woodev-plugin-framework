<?php
/**
 * Records engine — local option sources for action input fields.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Optional capability of a {@see Records_Provider}: it can list the options of an action
 * input field whose definition says `options_source => true` (a warehouse, a category…).
 *
 * Kept apart from {@see Records_Provider} so the provider contract stays as designed and a
 * provider without such fields implements nothing extra. The options come from local state only.
 *
 * @since 2.0.29
 */
interface Provides_Field_Options {
	/**
	 * Lists one page of options.
	 *
	 * @since 2.0.29
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Declared action id.
	 * @param string                                           $field   Declared field id of that action.
	 * @param array{row_id:string,search:string,cursor:string} $query Validated input; empty strings when not sent.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor?:string|null}
	 */
	public function get_field_options( array $context, string $action, string $field, array $query ): array;
}
