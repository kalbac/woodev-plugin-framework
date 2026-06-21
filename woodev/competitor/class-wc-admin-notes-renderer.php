<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Notes_Helper;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Exception;

/**
 * WooCommerce Admin Notes renderer.
 *
 * Creates or updates a WC inbox Note for an active-competitor rule, and deletes
 * it when the competitor is gone. Selected by the engine only when
 * class_exists( Note::class ) — the gotcha-correct gate (NOT the always-true
 * Woodev_Plugin_Compatibility::is_enhanced_admin_available()). Mirrors the v1
 * add_note() build logic minus the actioned→unactioned forced re-surface.
 *
 * @since 2.0.2
 */
final class WC_Admin_Notes_Renderer implements Competitor_Notice_Renderer {

	/** @var string the owning plugin's dasherized id, used as the note source */
	private string $source;

	/**
	 * @since 2.0.2
	 *
	 * @param string $source note source (plugin id dasherized)
	 */
	public function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload
	 */
	public function render( Competitor_Rule $rule, array $note ): void {

		if ( ! class_exists( Note::class ) ) {
			return;
		}

		$note = wp_parse_args(
			$note,
			[
				'title'   => '',
				'content' => '',
				'type'    => Note::E_WC_ADMIN_NOTE_UPDATE,
				'layout'  => 'plain',
				'image'   => '',
				'actions' => [],
			]
		);

		try {

			$wc_note = Woodev_Notes_Helper::get_note_with_name( $rule->get_note_name() );

			if ( ! $wc_note ) {
				$wc_note = new Note();
				$wc_note->set_name( $rule->get_note_name() );
				$wc_note->set_title( $note['title'] );
				$wc_note->set_content( $note['content'] );
				$wc_note->set_source( $this->source );
				$wc_note->set_type( $note['type'] );
				$wc_note->set_layout( $note['layout'] );
			}

			if ( ! empty( $note['image'] ) ) {
				$wc_note->set_layout( 'thumbnail' );
				$wc_note->set_image( $note['image'] );
			}

			$wc_note->set_actions( [] );

			foreach ( (array) $note['actions'] as $action ) {

				$action = wp_parse_args(
					$action,
					[
						'name'          => '',
						'label'         => '',
						'url'           => '',
						'status'        => Note::E_WC_ADMIN_NOTE_ACTIONED,
						'primary'       => false,
						'actioned_text' => '',
					]
				);

				$wc_note->add_action(
					$action['name'],
					$action['label'],
					$action['url'],
					$action['status'],
					$action['primary'],
					$action['actioned_text']
				);
			}

			$wc_note->save();

		} catch ( Exception $e ) {
			// Swallow — a failed note must never fatal an admin page load.
			unset( $e );
		}
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule the rule whose note should be removed
	 */
	public function delete( Competitor_Rule $rule ): void {

		if ( ! class_exists( Notes::class ) ) {
			return;
		}

		if ( Woodev_Notes_Helper::note_with_name_exists( $rule->get_note_name() ) ) {
			Notes::delete_notes_with_name( $rule->get_note_name() );
		}
	}
}
