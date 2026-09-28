/**
 * The admin order wizard's shell (#710 D1, increment I4b of card #969): a `Modal` with the
 * shared {@link Stepper}, the step body, and «Назад» / «Далее» / «Отмена».
 *
 * **Navigation (D1, operator O6).** The step indicator jumps BACK to a step already passed;
 * FORWARD is only through «Далее», which checks the current step first. A step the manager has
 * not reached is not clickable — the stepper's `canNavigate` seam (added for this).
 *
 * **Validation.** The server validates a whole payload, on create / update only (I3 has no
 * validate-only route), so «Далее» runs the client-side mirror of its rules for the step
 * (`validation.ts`), and a 422 from the final request is routed per field to the earliest step
 * that owns an error — shown on the very control, with the server's own sentence.
 *
 * **Steps.** All five are built here — ④ «Доставка» is I5a (#970), ⑤ «Оплата» I5b (#971); a host
 * may still replace any of them through `renderers`. ⑤ owns the submit button
 * (`StepProps.submit`); before it sends, every step is checked once more, and the first one with
 * a problem is shown.
 *
 * **Modes.** `create` opens empty; `edit` (an `orderId`) loads the prefill first — the row
 * action that opens it is I6.
 *
 * **Closing** with unsaved input asks first (C3); nothing is persisted as a draft.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import Stepper from '../../components/stepper';
import { getWizardContext } from '../rest';
import { loadOrderPrefill, saveOrder, toRequestError } from './api';
import type { WizardRequestError } from './api';
import StepAddress from './step-address';
import StepCustomer from './step-customer';
import StepDelivery from './step-delivery';
import StepItems from './step-items';
import StepPayment from './step-payment';
import type { SetWizardData, StepRenderers } from './step-props';
import { WIZARD_STEPS } from './types';
import type { FieldErrors, PrefillOrder, SaveResult, WizardData, WizardStepId } from './types';
import { errorsOfStep, firstStepWithErrors, groupServerErrors, validateAll, validateStep } from './validation';
import { buildPayload, emptyWizardData, isDirty, prefillToData } from './wizard-data';

export interface OrderWizardProps {
	/** The order to edit; absent = create a new one. */
	orderId?: number | null;
	/** Called when the wizard should disappear (cancelled, confirmed, or saved). */
	onClose: () => void;
	/** Called once with the saved order, right before `onClose`. */
	onSaved: ( result: SaveResult, mode: 'create' | 'edit' ) => void;
	/** Overrides for any step; an absent one uses the built-in. */
	renderers?: StepRenderers;
}

/** Step labels — the stepper's and the placeholders' titles. */
function stepLabels(): Record<WizardStepId, string> {
	return {
		customer: __( 'Покупатель', 'woodev-plugin-framework' ),
		address: __( 'Адрес', 'woodev-plugin-framework' ),
		items: __( 'Товары', 'woodev-plugin-framework' ),
		delivery: __( 'Доставка', 'woodev-plugin-framework' ),
		payment: __( 'Оплата', 'woodev-plugin-framework' ),
	};
}

type Phase = 'loading' | 'ready' | 'failed';

export default function OrderWizard( { orderId = null, onClose, onSaved, renderers = {} }: OrderWizardProps ) {
	const editing = null !== orderId;
	const mode = editing ? 'edit' : 'create';
	const { wizard } = getWizardContext();
	const countries = wizard.countries || {};
	const states = wizard.states || {};
	const statuses = wizard.orderStatuses;

	const [ phase, setPhase ] = useState<Phase>( editing ? 'loading' : 'ready' );
	const [ loadError, setLoadError ] = useState( '' );
	const initial = useRef<WizardData>( emptyWizardData( wizard.defaultCountry || '' ) );
	const [ data, setDataState ] = useState<WizardData>( initial.current );
	const [ order, setOrder ] = useState<PrefillOrder | null>( null );
	const [ errors, setErrors ] = useState<FieldErrors>( {} );
	const [ index, setIndex ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ confirmingClose, setConfirmingClose ] = useState( false );
	const saved = useRef( false );

	// Edit: the prefill is the wizard's starting — and «unchanged» — state.
	useEffect( () => {
		if ( ! editing ) {
			return undefined;
		}

		let cancelled = false;

		loadOrderPrefill( orderId as number )
			.then( ( prefill ) => {
				if ( cancelled ) {
					return;
				}
				initial.current = prefillToData( prefill );
				setDataState( initial.current );
				setOrder( prefill.order );
				setPhase( 'ready' );
			} )
			.catch( ( error: WizardRequestError ) => {
				if ( cancelled ) {
					return;
				}
				setLoadError( error.message );
				setPhase( 'failed' );
			} );

		return () => {
			cancelled = true;
		};
	}, [ editing, orderId ] );

	const setData: SetWizardData = ( update ) => setDataState( ( current ) => update( current ) );

	const ids = WIZARD_STEPS;
	const stepId = ids[ index ];
	const labels = stepLabels();
	const isLast = index === ids.length - 1;

	// While a step shows problems, fixing a field takes its problem away (the client-checkable
	// ones; a server-only one clears with the edit and comes back from the next request if real).
	// Only an EDIT counts: a step that looks things up on arrival (④ asks the tariffs) changes
	// derived bookkeeping in the state, and that must not wipe the server's 422 the wizard has
	// just routed to it (`isDirty()` ignores exactly that bookkeeping).
	const settled = useRef<WizardData>( data );

	useEffect( () => {
		const before = settled.current;

		settled.current = data;

		if ( ! isDirty( before, data ) || 0 === Object.keys( errorsOfStep( errors, stepId ) ).length ) {
			return;
		}

		const fresh = validateStep( index, data, countries, states, statuses );

		setErrors( ( current ) => ( { ...withoutStep( current, stepId ), ...fresh } ) );
		// Only an edit re-checks; the errors themselves changing must not loop.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ data ] );

	const dirty = useMemo( () => isDirty( initial.current, data ), [ data, phase ] );

	const requestClose = () => {
		if ( busy ) {
			return;
		}

		if ( dirty && ! saved.current ) {
			setConfirmingClose( true );

			return;
		}

		onClose();
	};

	const goNext = () => {
		const found = validateStep( index, data, countries, states, statuses );

		setErrors( ( current ) => ( { ...withoutStep( current, stepId ), ...found } ) );

		if ( 0 === Object.keys( found ).length ) {
			setSubmitError( '' );
			setIndex( Math.min( index + 1, ids.length - 1 ) );
		}
	};

	const submit = async (): Promise<void> => {
		if ( busy ) {
			return;
		}

		// The last look: every step once more. The manager passed each on the way here, but a step's data
		// can move behind its back (a tariff dropping out, the tariffs still being asked).
		const problems = validateAll( data, countries, states, statuses );
		const first = firstStepWithErrors( problems );

		if ( first >= 0 ) {
			setErrors( problems );
			setIndex( first );
			setSubmitError( __( 'Проверьте отмеченные поля — заказ ещё не создан.', 'woodev-plugin-framework' ) );

			return;
		}

		setBusy( true );
		setSubmitError( '' );

		try {
			const result = await saveOrder( editing ? ( orderId as number ) : null, buildPayload( data ) );

			saved.current = true;
			setBusy( false );
			onSaved( result, mode );
			onClose();
		} catch ( raw ) {
			const error = toRequestError( raw );
			const grouped = groupServerErrors( error.errors );
			const target = firstStepWithErrors( grouped );

			setBusy( false );
			setSubmitError( error.message );

			if ( target >= 0 ) {
				setErrors( grouped );
				setIndex( target );
			}
		}
	};

	const goToStep = ( step: WizardStepId ) => setIndex( Math.max( 0, ids.indexOf( step ) ) );
	const props = { data, setData, errors, mode, order, submit, busy, goToStep } as const;
	const body = ( () => {
		const custom = renderers[ stepId ];

		if ( custom ) {
			return custom( props );
		}

		switch ( stepId ) {
			case 'customer':
				return <StepCustomer { ...props } />;
			case 'address':
				return <StepAddress { ...props } />;
			case 'items':
				return <StepItems { ...props } />;
			case 'delivery':
				return <StepDelivery { ...props } />;
			default:
				return <StepPayment { ...props } />;
		}
	} )();

	const title =
		editing && order
			? sprintf(
					/* translators: %s: order number, e.g. "301". */
					__( 'Редактировать заказ №%s', 'woodev-plugin-framework' ),
					order.number
			  )
			: editing
				? __( 'Редактировать заказ', 'woodev-plugin-framework' )
				: __( 'Создать заказ', 'woodev-plugin-framework' );

	return (
		<Modal
			title={ title }
			className="woodev-order-wizard"
			size="large"
			shouldCloseOnClickOutside={ false }
			onRequestClose={ requestClose }
		>
			{ 'loading' === phase && (
				<div className="woodev-order-wizard__loading">
					<Spinner />
					<span>{ __( 'Загружаем заказ…', 'woodev-plugin-framework' ) }</span>
				</div>
			) }

			{ 'failed' === phase && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ loadError }
					</Notice>
					<div className="woodev-order-wizard__footer">
						<Button variant="secondary" onClick={ onClose }>
							{ __( 'Закрыть', 'woodev-plugin-framework' ) }
						</Button>
					</div>
				</>
			) }

			{ 'ready' === phase && (
				<>
					<Stepper
						steps={ ids.map( ( id ) => ( { id, label: labels[ id ] } ) ) }
						index={ index }
						disabled={ busy }
						canNavigate={ ( i ) => i < index }
						onNavigate={ ( i ) => setIndex( i ) }
					/>

					{ submitError && (
						<Notice status="error" isDismissible={ false }>
							{ submitError }
						</Notice>
					) }

					<div className="woodev-order-wizard__body">{ body }</div>

					{ confirmingClose ? (
						<div className="woodev-order-wizard__footer woodev-order-wizard__confirm" role="alert">
							<span>{ __( 'Закрыть окно? Введённые данные не сохранятся.', 'woodev-plugin-framework' ) }</span>
							<Button variant="tertiary" onClick={ () => setConfirmingClose( false ) }>
								{ __( 'Продолжить оформление', 'woodev-plugin-framework' ) }
							</Button>
							<Button variant="secondary" isDestructive onClick={ onClose }>
								{ __( 'Закрыть без сохранения', 'woodev-plugin-framework' ) }
							</Button>
						</div>
					) : (
						<div className="woodev-order-wizard__footer">
							<Button variant="tertiary" onClick={ requestClose } disabled={ busy }>
								{ __( 'Отмена', 'woodev-plugin-framework' ) }
							</Button>
							<span className="woodev-order-wizard__footer-spacer" />
							{ index > 0 && (
								<Button variant="secondary" onClick={ () => setIndex( index - 1 ) } disabled={ busy }>
									{ __( 'Назад', 'woodev-plugin-framework' ) }
								</Button>
							) }
							{ ! isLast && (
								<Button variant="primary" onClick={ goNext } disabled={ busy }>
									{ __( 'Далее', 'woodev-plugin-framework' ) }
								</Button>
							) }
						</div>
					) }
				</>
			) }
		</Modal>
	);
}

/** The errors with every field of `step` removed. */
function withoutStep( errors: FieldErrors, step: WizardStepId ): FieldErrors {
	const owned = errorsOfStep( errors, step );
	const rest: FieldErrors = {};

	for ( const field of Object.keys( errors ) ) {
		if ( ! ( field in owned ) ) {
			rest[ field ] = errors[ field ];
		}
	}

	return rest;
}
