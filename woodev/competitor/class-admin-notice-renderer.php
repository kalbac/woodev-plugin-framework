<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

/**
 * Fallback renderer: a dismissible WP admin notice.
 *
 * Used when WooCommerce Admin Notes are unavailable. Delegates to the plugin's
 * Woodev_Admin_Notice_Handler, keyed by the rule's note name so the notice is
 * not re-shown once dismissed. delete() is a no-op: the handler only renders
 * notices added during the current request, so simply not adding it (when the
 * competitor is gone) is the removal.
 *
 * @since 2.0.2
 */
final class Admin_Notice_Renderer implements Competitor_Notice_Renderer {

	/** @var \Woodev_Admin_Notice_Handler the plugin's notice handler */
	private $notice_handler;

	/**
	 * @since 2.0.2
	 *
	 * @param \Woodev_Admin_Notice_Handler $notice_handler the plugin's notice handler
	 */
	public function __construct( $notice_handler ) {
		$this->notice_handler = $notice_handler;
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload
	 */
	public function render( Competitor_Rule $rule, array $note ): void {

		$content = (string) ( $note['content'] ?? '' );

		if ( '' === $content ) {
			return;
		}

		$this->notice_handler->add_admin_notice( $content, $rule->get_note_name() );
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule the rule whose note should be removed
	 */
	public function delete( Competitor_Rule $rule ): void {
		// No-op: dismissible notices are only rendered when re-added during a
		// request; ceasing to add it is the removal.
	}
}
