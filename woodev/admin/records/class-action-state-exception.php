<?php
/**
 * Records engine — the current state refuses an action.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by {@see Records_Provider::preview()} or {@see Records_Provider::apply()} when the
 * action is known and the user may run it, but the whole request is refused by current state
 * (an account that is paused, a job already running). The engine answers 409 and the message
 * is the reason shown to the merchant, so it must be merchant-safe.
 *
 * A request where only some rows are refused is not an exception: those rows are skipped and
 * come back as grouped reasons.
 *
 * @since 2.0.30
 */
final class Action_State_Exception extends \RuntimeException {
}
