/**
 * Setup Wizard — error boundary around a plugin-supplied step component (D4).
 *
 * The component is the plugin's code: when it throws while rendering, the step shows an
 * honest error instead of taking the whole wizard down to a blank page.
 *
 * @package woodev-plugin-framework
 */

import { Component, createElement } from '@wordpress/element';
import type { ReactNode } from 'react';

interface BoundaryProps {
	children?: ReactNode;
	/** Rendered instead of the children after a render error. */
	fallback: ReactNode;
}

interface BoundaryState {
	failed: boolean;
}

export default class StepComponentBoundary extends Component<BoundaryProps, BoundaryState> {
	state: BoundaryState = { failed: false };

	static getDerivedStateFromError(): BoundaryState {
		return { failed: true };
	}

	componentDidCatch( error: unknown ): void {
		if ( window.console ) {
			window.console.error( 'woodev setup: a step component failed to render', error );
		}
	}

	render() {
		return this.state.failed ? createElement( 'div', null, this.props.fallback ) : this.props.children;
	}
}
