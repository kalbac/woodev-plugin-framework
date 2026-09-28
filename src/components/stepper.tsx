/**
 * Woodev UI-kit — progress-line step indicator (lifted from the setup wizard, #960).
 *
 * Reproduces the WooCommerce setup-wizard stepper, recolored to the woodev.ru
 * cyan: an <ol> of equal-width items, each a label over a 4px progress line with
 * a ::before dot (styled in `_stepper.scss`). Receives the full step list plus the
 * active index; a step before the active one reads as done, one after as upcoming.
 *
 * Shared by the setup wizard and the shipping orders page's order wizard (#710),
 * so it knows nothing about either: steps are `{ id, label }`, navigation is a
 * callback. The dot's hollow centre takes the surface it sits on from the
 * `--woodev-stepper-dot-bg` custom property (see `_stepper.scss`).
 *
 * @package woodev-plugin-framework
 */

/** One step of the indicator. */
export interface StepperStep {
	/** Stable key — never the index, so a re-ordered list keeps its elements. */
	id: string | number;
	/** Visible label. */
	label: string;
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
}

/**
 * Step indicator.
 *
 * @param {StepperProps} props component props.
 * @return {JSX.Element} the step list.
 */
export default function Stepper( { steps, index, onNavigate, disabled }: StepperProps ) {
	return (
		<ol className="woodev-stepper">
			{ steps.map( ( step, i ) => {
				const state = i < index ? 'done' : ( i === index ? 'active' : 'upcoming' );

				// The current step is a plain (non-clickable) label; any other step is a
				// button that navigates to it.
				const label = i === index
					? <span className="woodev-stepper__label">{ step.label }</span>
					: (
						<button
							type="button"
							className="woodev-stepper__label"
							disabled={ !! disabled }
							onClick={ () => onNavigate && onNavigate( i ) }
						>
							{ step.label }
						</button>
					);

				return (
					<li key={ step.id } className={ `is-${ state }` }>
						{ label }
					</li>
				);
			} ) }
		</ol>
	);
}
