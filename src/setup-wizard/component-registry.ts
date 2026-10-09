/**
 * Setup Wizard — looks up a plugin-supplied step component (D4).
 *
 * @package woodev-plugin-framework
 */

import type { SetupWizardComponentRef } from './types';

/**
 * Finds the component a step declared in `window.woodevSetupWizardComponents`.
 *
 * @param {SetupWizardComponentRef|null|undefined} ref the step's `component` descriptor.
 * @return {unknown} a React component (function, class, memo/forwardRef object), or null when
 *                   the script did not publish it — the caller shows an error state.
 */
export function resolveStepComponent( ref: SetupWizardComponentRef | null | undefined ): unknown {
	if ( ! ref ) {
		return null;
	}

	const registry = window.woodevSetupWizardComponents;
	const candidate = registry && registry[ ref.handle ] ? registry[ ref.handle ][ ref.export ] : undefined;

	return 'function' === typeof candidate || ( 'object' === typeof candidate && null !== candidate )
		? candidate
		: null;
}
