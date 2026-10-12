<?php

namespace Woodev\Framework\Competitor;

defined( 'ABSPATH' ) || exit;

/**
 * Renders (or removes) a competitor notice for a single rule.
 *
 * Implementations are platform-specific: WC Admin Notes when WooCommerce admin
 * is present, a dismissible admin notice as fallback. The engine selects one
 * and passes a fully-built note payload.
 *
 * @since 2.0.2
 */
interface Competitor_Notice_Renderer {

	/**
	 * Creates or updates the notice for a rule whose competitor is active.
	 *
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule     $rule the rule being rendered
	 * @param array<string,mixed> $note built note payload: title, content, type, image, actions
	 */
	public function render( Competitor_Rule $rule, array $note ): void;

	/**
	 * Removes the notice for a rule whose competitor is no longer active.
	 *
	 * @since 2.0.2
	 *
	 * @param Competitor_Rule $rule the rule whose note should be deleted
	 */
	public function delete( Competitor_Rule $rule ): void;
}
