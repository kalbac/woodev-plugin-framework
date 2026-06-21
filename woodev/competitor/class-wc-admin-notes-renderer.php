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
	 * Whether WC Admin Notes are available — the gotcha-correct gate
	 * (class_exists( Note::class ), NOT the always-true is_enhanced_admin_available()).
	 * Owning this check here keeps the WooCommerce reference out of the engine.
	 *
	 * @since 2.0.2
	 */
	public static function is_available(): bool {
		return class_exists( Note::class );
	}

	/**
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload
	 */
	public function render( Competitor_Rule $rule, array $note ): void {

		if ( ! self::is_available() ) {
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

			// Create once (spec §5): if our per-plugin note already exists, the WC
			// inbox owns it from here (dismissal, etc.) — do not re-save on every
			// admin load, and never force-resurface.
			if ( Woodev_Notes_Helper::get_note_with_name( $this->note_name( $rule ) ) ) {
				return;
			}

			$wc_note = new Note();
			$wc_note->set_name( $this->note_name( $rule ) );
			$wc_note->set_title( $note['title'] );
			$wc_note->set_content( $note['content'] );
			$wc_note->set_source( $this->source );
			$wc_note->set_type( $this->map_type( (string) $note['type'] ) );
			$wc_note->set_layout( $note['layout'] );

			if ( ! empty( $note['image'] ) ) {
				$wc_note->set_layout( 'thumbnail' );
				$wc_note->set_image( $note['image'] );
			}

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

		if ( Woodev_Notes_Helper::note_with_name_exists( $this->note_name( $rule ) ) ) {
			Notes::delete_notes_with_name( $this->note_name( $rule ) );
		}
	}

	/**
	 * The plugin-namespaced WC note name. WC Admin Notes are a single global
	 * table keyed by name, so the rule's generic name is prefixed with this
	 * plugin's source — otherwise two Woodev plugins flagging the same competitor
	 * would update/delete each other's note.
	 *
	 * @since 2.0.2
	 */
	private function note_name( Competitor_Rule $rule ): string {
		return $this->source . '-' . $rule->get_note_name();
	}

	/**
	 * Maps a neutral engine note type to the WC Admin Note constant.
	 *
	 * @since 2.0.2
	 *
	 * @param string $type neutral type (Competitor_Notification_Handler::TYPE_*)
	 */
	private function map_type( string $type ): string {
		return Competitor_Notification_Handler::TYPE_ERROR === $type
			? Note::E_WC_ADMIN_NOTE_ERROR
			: Note::E_WC_ADMIN_NOTE_UPDATE;
	}
}
