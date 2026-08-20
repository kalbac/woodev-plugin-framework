<?php
/**
 * Woodev Location Provider Exception
 *
 * Issue #405: the {@see Location_Provider} contract had no way for a provider to say
 * "I could not answer this query" — `suggest()` is typed to return an `array`, so a
 * provider whose credentials are wrong, or whose upstream is unreachable, had no honest
 * signal to give besides `[]`, indistinguishable from a request that genuinely completed
 * and found nothing. This exception IS that signal.
 *
 * @since 2.1.0
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Provider_Exception' ) ) :

	/**
	 * Thrown by {@see Location_Provider::suggest()} to signal that the request itself
	 * FAILED — bad credentials, a network failure, a malformed upstream payload — as
	 * distinct from returning `[]` for a request that completed and legitimately found
	 * nothing (see {@see Location_Provider::suggest()}'s own docblock for the full
	 * contract). {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::perform_suggest()}
	 * and {@see \Woodev\Framework\Shipping\Rest_Api\Location_Controller::handle_list_request()}
	 * both already catch `\Throwable` here and answer a distinct 502 instead of `perform_suggest()`'s
	 * ordinary 200+empty — this type exists so a provider has ONE documented, contract-level
	 * exception to throw (or wrap a lower-level failure in) rather than every implementation
	 * inventing its own.
	 *
	 * A provider is free to throw any `\Throwable` here — the REST layer's own catch is
	 * deliberately broad — but this is the type the interface documents, so a caller that
	 * wants to distinguish a DEGRADED REQUEST from an outright bug in provider code (a
	 * `\TypeError`, a missing class) can catch this one specifically.
	 *
	 * @since 2.1.0
	 */
	class Location_Provider_Exception extends \RuntimeException {}

endif;
