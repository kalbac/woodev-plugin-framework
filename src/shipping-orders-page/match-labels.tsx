/**
 * The «всем / любым» labels of the advanced-filters header (#941).
 *
 * The header reads «Заказы соответствуют {{select /}} условиям» (`./filters`), and the
 * `select` is WooCommerce's own «All / Any» control. «Соответствуют … условиям» governs the
 * DATIVE, so the nominative «Все / Любое» from the WooCommerce language pack reads wrong
 * («Заказы соответствуют Все условиям», operator, 27.09.2026, #843 acceptance).
 *
 * ⚠ WHY THE LABELS ARE REWRITTEN IN THE DOM AND NOT THROUGH A LABEL PROP OR A GETTEXT FILTER.
 * `AdvancedFilters` has no prop for them. Its source
 * (`packages/js/components/src/advanced-filters/index.tsx`) holds them in a MODULE-LEVEL
 * constant, `const matches = [ { value: 'all', label: __( 'All', 'woocommerce' ) }, … ]`,
 * and the shipped bundle does the same (measured in WooCommerce 11.0.1's
 * `assets/client/admin/components/index.js`: `_t=[{value:"all",label:(0,m.__)("All",…)},…]`).
 * That constant is evaluated ONCE, when the `wc-components` script executes — before this
 * bundle exists — so an `i18n.gettext_woocommerce` filter registered from here arrives too
 * late to change it, and one registered earlier would have to be injected from PHP into every
 * `wc-admin` screen (Analytics' own «Заказы» filter reads the same constant).
 *
 * Rewriting the two `<option>` nodes inside our own container has neither problem: it is
 * scoped to this page by construction, matches on the option's `value` (`all` / `any` —
 * language-independent, unlike the translated text), and degrades to WooCommerce's own label
 * if the markup ever changes. React never fights it — the option list is a constant, so its
 * props never change and it has no reason to rewrite the text — but the SELECT can be
 * re-created (the block is unmounted and remounted by the display-mode toggle), hence the
 * observer rather than a one-shot effect.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

/** `AdvancedFilters`' own class on its «All / Any» `SelectControl` (`getTitle()`). */
export const MATCH_SELECT_SELECTOR = '.woocommerce-filters-advanced__title-select';

/** The two option values WooCommerce's `matches` constant uses — the query's `match` value. */
export type MatchValue = 'all' | 'any';

/**
 * Our dative labels, keyed by the option `value` they replace.
 *
 * Russian msgids: an admin string (AGENTS.md → Conventions, «Translatable strings»), the
 * same rule as the header msgid beside them in `./filters`.
 */
export function getMatchLabels(): Record< MatchValue, string > {
	return {
		all: __( 'всем', 'woodev-plugin-framework' ),
		any: __( 'любым', 'woodev-plugin-framework' ),
	};
}

/**
 * Sets the text of the «All / Any» options under `root`. Idempotent — it writes only when the
 * text differs, so the mutation it makes cannot re-trigger the observer that calls it.
 */
export function relabelMatchOptions(
	root: ParentNode,
	labels: Record< MatchValue, string > = getMatchLabels()
): void {
	root.querySelectorAll< HTMLOptionElement >( `${ MATCH_SELECT_SELECTOR } option` ).forEach(
		( option ) => {
			if ( ! Object.prototype.hasOwnProperty.call( labels, option.value ) ) {
				return;
			}

			const label = labels[ option.value as MatchValue ];

			if ( option.textContent !== label ) {
				option.textContent = label;
			}
		}
	);
}

/**
 * Wraps `AdvancedFilters` and keeps its «All / Any» options in the dative.
 *
 * A plain block wrapper: `.woocommerce-filters-advanced` sizes itself (`style.scss`), and
 * nothing selects on its parent.
 */
export function MatchLabelScope( { children }: { children: ReactNode } ) {
	const ref = useRef< HTMLDivElement >( null );

	useEffect( () => {
		const root = ref.current;

		if ( ! root ) {
			return undefined;
		}

		const labels = getMatchLabels();

		relabelMatchOptions( root, labels );

		if ( 'undefined' === typeof MutationObserver ) {
			return undefined;
		}

		const observer = new MutationObserver( () => relabelMatchOptions( root, labels ) );

		observer.observe( root, { childList: true, subtree: true } );

		return () => observer.disconnect();
	}, [] );

	return <div ref={ ref }>{ children }</div>;
}
