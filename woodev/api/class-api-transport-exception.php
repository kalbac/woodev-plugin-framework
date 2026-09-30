<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_API_Transport_Exception' ) ) :

	/**
	 * The request may or may not have reached the remote service.
	 *
	 * Thrown by {@see Woodev_API_Base::handle_response()} when the HTTP transport itself failed
	 * (timeout, connection refused, DNS — a `WP_Error`), or when the server answered with a 5xx
	 * status or no usable status at all. In every one of these the server MAY have acted on the
	 * request before the answer was lost, so a caller that must not repeat a side effect (an order
	 * export that would create a second carrier order, #945) treats it as «unknown», not as a
	 * refusal. A subclass of {@see Woodev_API_Exception}: every existing `catch` still matches.
	 *
	 * @since 2.0.2
	 */
	class Woodev_API_Transport_Exception extends Woodev_API_Exception {}

endif;
