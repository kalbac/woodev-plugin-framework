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

		$content .= $this->primary_action_link( $note['actions'] ?? [] );

		$this->notice_handler->add_admin_notice( $content, $rule->get_note_name() );
	}

	/**
	 * Renders the note's primary action as a trailing link. WC Admin Note actions
	 * are buttons the inbox draws; an admin notice has no action chrome, so the
	 * primary action (recommend link / deactivate link) is appended to the body
	 * as an anchor — otherwise the fallback would strip the only call to action.
	 *
	 * @since 2.0.2
	 *
	 * @param array<int,array<string,mixed>> $actions built note actions
	 */
	private function primary_action_link( array $actions ): string {

		foreach ( $actions as $action ) {

			$url   = (string) ( $action['url'] ?? '' );
			$label = (string) ( $action['label'] ?? '' );

			if ( ! empty( $action['primary'] ) && '' !== $url && '' !== $label ) {
				// The notice handler wraps the message in a single <p>; use <br>
				// (not a nested <p>) so the appended link stays valid markup.
				return sprintf(
					'<br><a href="%s">%s</a>',
					esc_url( $url ),
					esc_html( $label )
				);
			}
		}

		return '';
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
