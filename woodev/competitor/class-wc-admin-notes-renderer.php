<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

use Woodev_Notes_Helper;
use Automattic\WooCommerce\Admin\Notes\{Note, Notes};
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
				'actions' => [],
			]
		);

		try {

			$existing_note = Woodev_Notes_Helper::get_note_with_name( $rule->get_note_name() );
			$changed       = false;

			if ( $existing_note ) {
				// Keep actioned and snoozed notes as the merchant left them.
				if ( Note::E_WC_ADMIN_NOTE_UNACTIONED !== $existing_note->get_status() ) {
					return;
				}

				$wc_note = $existing_note;
			} else {
				$wc_note = new Note();
				$wc_note->set_name( $rule->get_note_name() );
				$wc_note->set_source( $this->source );
				$changed = true;
			}

			$changed = $changed
				|| $wc_note->get_title() !== $note['title']
				|| $wc_note->get_content() !== $note['content']
				|| $wc_note->get_type() !== $this->map_type( (string) $note['type'] );

			$wc_note->set_title( $note['title'] );
			$wc_note->set_content( $note['content'] );
			$wc_note->set_type( $this->map_type( (string) $note['type'] ) );
			$changed = $this->update_actions( $wc_note, (array) $note['actions'] ) || $changed;

			if ( $changed ) {
				$wc_note->save();
			}
		} catch ( Exception $e ) {
			// Swallow — a failed note must never fatal an admin page load.
			unset( $e );
		}
	}

	/**
	 * Replaces note actions only when their visible and behavioral fields differ.
	 *
	 * @since 2.0.2
	 *
	 * @param Note             $wc_note existing or new note
	 * @param array<int,mixed> $actions desired note actions
	 */
	private function update_actions( Note $wc_note, array $actions ): bool {

		$desired_actions = [];

		foreach ( $actions as $action ) {
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

			$desired_actions[] = [
				'name'          => $action['name'],
				'label'         => $action['label'],
				'url'           => $action['url'],
				'status'        => $action['status'],
				'primary'       => $action['primary'],
				'actioned_text' => $action['actioned_text'],
			];
		}

		$current_actions = [];
		foreach ( $wc_note->get_actions() as $action ) {
			$current_actions[] = [
				'name'          => $action->name,
				'label'         => $action->label,
				'url'           => $action->query,
				'status'        => $action->status,
				'primary'       => $action->is_primary,
				'actioned_text' => $action->actioned_text,
			];
		}

		if ( $desired_actions === $current_actions ) {
			return false;
		}

		$wc_note->clear_actions();
		foreach ( $desired_actions as $action ) {
			$wc_note->add_action(
				$action['name'],
				$action['label'],
				$action['url'],
				$action['status'],
				$action['primary'],
				$action['actioned_text']
			);
		}

		return true;
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
