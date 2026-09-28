/**
 * What every wizard step receives (#969), and the seam later increments plug into.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import type { FieldErrors, PrefillOrder, WizardData, WizardStepId } from './types';

/** State updater in the `useState` style — a step patches from the latest state, never a stale copy. */
export type SetWizardData = ( update: ( data: WizardData ) => WizardData ) => void;

export interface StepProps {
	data: WizardData;
	setData: SetWizardData;
	/** Problems by request field path — client checks and the server's 422 alike. */
	errors: FieldErrors;
	/** `create` for a new order, `edit` when an existing one was loaded. */
	mode: 'create' | 'edit';
	/** The loaded order on edit (O14 reads its total and paid flag); `null` on create. */
	order: PrefillOrder | null;
	/**
	 * What the wizard's own totals (`orderTotals()`) came to for the order AS LOADED — the yardstick
	 * O14's «было X, стало Y» measures a change against, so the tax WooCommerce added to the saved
	 * total never reads as an edit. `null` on create, and until the order has loaded.
	 */
	baselineTotal?: number | null;
	/**
	 * Sends the order (`POST` / `PUT`). A 422 comes back as per-field errors and moves the
	 * wizard to the earliest step that has one. The button that calls it is step ⑤'s.
	 */
	submit: () => Promise<void>;
	/** A request is in flight. */
	busy: boolean;
	/** Jumps back to a step already passed — the summary's «Изменить» links (⑤). */
	goToStep: ( step: WizardStepId ) => void;
}

/** Renders one step's body — a host may replace any of the five through {@link StepRenderers}. */
export type StepRenderer = ( props: StepProps ) => ReactNode;

/** The renderers a host may override; anything absent uses the built-in step. */
export type StepRenderers = Partial<Record<WizardStepId, StepRenderer>>;
