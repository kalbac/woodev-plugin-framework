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
	 * Sends the order (`POST` / `PUT`). A 422 comes back as per-field errors and moves the
	 * wizard to the earliest step that has one. The buttons that call it are steps ⑤'s (I5b).
	 */
	submit: () => Promise<void>;
	/** A request is in flight. */
	busy: boolean;
}

/** Renders one step's body. Steps ④ and ⑤ are replaced through this seam by I5a / I5b. */
export type StepRenderer = ( props: StepProps ) => ReactNode;

/** The renderers a host may override; anything absent uses the built-in step. */
export type StepRenderers = Partial<Record<WizardStepId, StepRenderer>>;
