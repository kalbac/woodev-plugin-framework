/**
 * Woodev UI-kit — progress-line step indicator (lifted from the setup wizard, #960).
 *
 * Reproduces the WooCommerce setup-wizard stepper, recolored to the woodev.ru
 * cyan: an <ol> of equal-width items, each a label over a 4px progress line with
 * a ::before dot (styled in `_stepper.scss`). Receives the full step list plus the
 * active index; a step before the active one reads as done, one after as upcoming.
 *
 * Shared by the setup wizard and the shipping orders page's order wizard (#710),
 * so it knows nothing about either: steps are `{ id, label, shortLabel? }`, navigation is a
 * callback. The dot's hollow centre takes the surface it sits on from the
 * `--woodev-stepper-dot-bg` custom property (see `_stepper.scss`).
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';

/** One step of the indicator. */
export interface StepperStep {
	/** Stable key — never the index, so a re-ordered list keeps its elements. */
	id: string | number;
	/** Full label — the accessible name and the hover title whenever `shortLabel` is shown instead. */
	label: string;
	/** Optional shorter text shown in the line in place of `label` (a long step list cannot fit full titles). */
	shortLabel?: string;
}

export interface StepperProps {
	/** Every step, in order (a terminal «finish» step included when the caller has one). */
	steps: StepperStep[];
	/** Index of the current step. */
	index: number;
	/** Called with a step's index when a non-current step label is clicked. */
	onNavigate?: ( index: number ) => void;
	/** When true, step buttons are non-clickable (e.g. while a save request is in flight). */
	disabled?: boolean;
	/**
	 * Per-step gate: return `false` for a step the user may not jump to (it renders as a
	 * plain label, like the current one). Absent = every other step is a button. The order
	 * wizard uses it for «back to a completed step, forward only through Далее» (#710 D1).
	 */
	canNavigate?: ( index: number ) => boolean;
}

/**
 * Step indicator.
 *
 * @param {StepperProps} props component props.
 * @return {JSX.Element} the step list.
 */
export default function Stepper( { steps, index, onNavigate, disabled, canNavigate }: StepperProps ) {
	return (
		<ol className="woodev-stepper">
			{ steps.map( ( step, i ) => {
				const state = i < index ? 'done' : ( i === index ? 'active' : 'upcoming' );
				// The state as TEXT for assistive tech — the CSS class alone says nothing to it (#1047).
				const status = 'done' === state
					? __( 'Шаг пройден', 'woodev-plugin-framework' )
					: ( 'active' === state
						? __( 'Текущий шаг', 'woodev-plugin-framework' )
						: __( 'Шаг ещё не пройден', 'woodev-plugin-framework' ) );

				// A short label replaces the visible text only: the full label stays the
				// accessible name (screen-reader text) and the hover title.
				const hasShort = !! step.shortLabel && step.shortLabel !== step.label;
				const text = hasShort
					? (
						<>
							<span aria-hidden="true">{ step.shortLabel }</span>
							<span className="screen-reader-text">{ step.label }</span>
						</>
					)
					: step.label;
				const title = hasShort ? step.label : undefined;

				// The current step — and any step `canNavigate` refuses — is a plain
				// (non-clickable) label; any other step is a button that navigates to it.
				const label = i === index || ( canNavigate && ! canNavigate( i ) )
					? <span className="woodev-stepper__label" title={ title }>{ text }</span>
					: (
						<button
							type="button"
							className="woodev-stepper__label"
							title={ title }
							disabled={ !! disabled }
							onClick={ () => onNavigate && onNavigate( i ) }
						>
							{ text }
						</button>
					);

				return (
					<li
						key={ step.id }
						className={ `is-${ state }` }
						aria-current={ 'active' === state ? 'step' : undefined }
					>
						{ label }
						{ /* A sibling of the label, not inside the button: it must not become the button's accessible name. */ }
						<span className="woodev-stepper__status">{ status }</span>
					</li>
				);
			} ) }
		</ol>
	);
}
