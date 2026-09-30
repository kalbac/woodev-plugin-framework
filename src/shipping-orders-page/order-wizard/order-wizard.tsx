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
 * **Modes.** `create` opens empty; `edit` (an `orderId`) loads the prefill first — the
 * «Редактировать» row action that opens it is #972. An edit of a PAID order warns on ⑤ when the
 * total changes (O14, `paidTotalChange()`), measured against the total the loaded order came to.
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
import { checkPickupPoint, loadOrderPrefill, saveOrder, toRequestError } from './api';
import type { WizardRequestError } from './api';
import { useAddressPolicy } from './address-policy';
import StepAddress from './step-address';
import StepCustomer from './step-customer';
import StepDelivery from './step-delivery';
import StepItems from './step-items';
import StepPayment from './step-payment';
import { pickedPointId } from './delivery-state';
import { isPickupRuntimeAvailable } from './pickup-session';
import type { PickupWizardConfig } from './pickup-session';
import type { SetWizardData, StepRenderers } from './step-props';
import { WIZARD_STEPS } from './types';
import type { FieldErrors, PrefillOrder, SaveResult, WizardData, WizardStepId } from './types';
import { errorsOfStep, firstStepWithErrors, groupServerErrors, validateAll, validateStep } from './validation';
import { orderTotals } from './payment-state';
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

/** What a typed-code check is made of (the model is `step-payment.tsx`'s `checkKey`): the route, the code, the tariff, the weight, the method, the destination. */
function typedPointCheckKey( data: WizardData, restRoot: string, pointId: string ): string {
	const { rest } = data;

	return JSON.stringify( [ restRoot, pointId, rest.shipping_line?.rate_id ?? '', rest.pickup_check?.provider ?? '', rest.pickup_check?.weight ?? null, rest.payment_method, data.settlementRecord ] );
}

type Phase = 'loading' | 'ready' | 'failed';

export type HeartbeatData = Record<string, unknown>;

const FALLBACK_ORDER_LOCK_HEARTBEAT_KEY = 'woodev-refresh-order-lock';

export type HeartbeatJquery = {
	on: ( event: string, handler: ( event: unknown, data: HeartbeatData ) => void ) => void;
	off: ( event: string ) => void;
};

export type HeartbeatJqueryFactory = ( element: Document ) => HeartbeatJquery;

export default function OrderWizard( { orderId = null, onClose, onSaved, renderers = {} }: OrderWizardProps ) {
	const editing = null !== orderId;
	const mode = editing ? 'edit' : 'create';
	const { wizard } = getWizardContext();
	const countries = wizard.countries || {};
	const states = wizard.states || {};
	const statuses = wizard.orderStatuses;
	const orderLockHeartbeatKey = wizard.editLockHeartbeatKey || FALLBACK_ORDER_LOCK_HEARTBEAT_KEY;

	const [ phase, setPhase ] = useState<Phase>( editing ? 'loading' : 'ready' );
	const [ loadError, setLoadError ] = useState( '' );
	const initial = useRef<WizardData>( emptyWizardData( wizard.defaultCountry || '' ) );
	const [ data, setDataState ] = useState<WizardData>( initial.current );
	const [ order, setOrder ] = useState<PrefillOrder | null>( null );
	// O14's yardstick: the wizard's own total for the order as loaded (see `StepProps.baselineTotal`).
	const [ baselineTotal, setBaselineTotal ] = useState<number | null>( null );
	const [ errors, setErrors ] = useState<FieldErrors>( {} );
	const [ index, setIndex ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ submitError, setSubmitError ] = useState( '' );
	const [ confirmingClose, setConfirmingClose ] = useState( false );
	const saved = useRef( false );
	// State disables the visible button after React renders; this ref closes the tiny gap
	// before that render, when two click events can otherwise both enter `submit()`.
	const submitting = useRef( false );
	// The latest render's state — what an `await` in `goNext` finds when it resumes (its closure holds the state it started with).
	const latest = useRef( { data, index } );

	latest.current = { data, index };

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
				setBaselineTotal( orderTotals( initial.current ).total );
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

	// The framework's datastore-aware heartbeat handler listens for this exact payload.
	// Its private key deliberately avoids WooCommerce's global lock refresher.
	// Namespaced handlers leave the page's heartbeat listeners untouched when the modal closes.
	useEffect( () => {
		if ( ! editing || 'ready' !== phase ) {
			return undefined;
		}

		const jquery = ( window as Window & { jQuery?: HeartbeatJqueryFactory } ).jQuery;

		if ( ! jquery ) {
			return undefined;
		}

		const documentHeartbeat = jquery( document );
		const send = ( event: unknown, data: HeartbeatData ) => {
			data[ orderLockHeartbeatKey ] = orderId as number;
		};
		const tick = ( event: unknown, data: HeartbeatData ) => {
			const response = data[ orderLockHeartbeatKey ] as { error?: { message?: unknown } } | undefined;
			const message = response?.error?.message;

			if ( 'string' === typeof message && message ) {
				setSubmitError( message );
			}
		};

		documentHeartbeat.on( 'heartbeat-send.woodevOrderWizard', send );
		documentHeartbeat.on( 'heartbeat-tick.woodevOrderWizard', tick );

		return () => {
			documentHeartbeat.off( 'heartbeat-send.woodevOrderWizard' );
			documentHeartbeat.off( 'heartbeat-tick.woodevOrderWizard' );
		};
	}, [ editing, orderId, orderLockHeartbeatKey, phase ] );

	const setData: SetWizardData = ( update ) => setDataState( ( current ) => update( current ) );

	const ids = WIZARD_STEPS;
	const stepId = ids[ index ];
	const labels = stepLabels();
	const isLast = index === ids.length - 1;
	// The checkout's rules for the delivery address (#985). Asked while ② is on screen — the country is picked there,
	// and the tariff kind (pickup or courier) is ④'s answer, so coming back to ② after ④ asks again if it changed.
	const addressPolicy = useAddressPolicy( data.shipping.country, data.rest.rate_is_pickup, 'address' === stepId );

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

		const fresh = validateStep( index, data, countries, states, statuses, addressPolicy );

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

	const goNext = async (): Promise<void> => {
		const found = validateStep( index, data, countries, states, statuses, addressPolicy );

		setErrors( ( current ) => ( { ...withoutStep( current, stepId ), ...found } ) );

		if ( Object.keys( found ).length > 0 ) {
			return;
		}

		// m6: a point code TYPED by hand (the picker could not run) has nobody's eye on it — ask the carrier's
		// points route before leaving ④. Only for a carrier that has such a route; a failed check never blocks.
		if ( 'delivery' === stepId ) {
			const config = data.rest.pickup_check ? ( wizard.pickup?.[ data.rest.pickup_check.provider ] as PickupWizardConfig | undefined ) : undefined;
			const pointId = pickedPointId( data.rest );

			if ( data.rest.rate_is_pickup && '' !== pointId && data.rest.pickup_check && config?.restRoot && ! isPickupRuntimeAvailable( config ) ) {
				const asked = typedPointCheckKey( data, config.restRoot, pointId );

				setBusy( true );

				const verdict = await checkPickupPoint( config.restRoot, pointId, data.rest.pickup_check.weight, data.rest.payment_method, data.settlementRecord );

				setBusy( false );

				// The code input stays editable while the check runs. An answer about a code (or a carrier, tariff, method, destination)
				// that is no longer the one on screen says nothing about what is there now: stay on ④, so «Далее» asks again about the
				// current one — never advance on the old answer, and never show its refusal against the new code.
				const now = latest.current;

				if ( now.index !== index || asked !== typedPointCheckKey( now.data, config.restRoot, pickedPointId( now.data.rest ) ) ) {
					return;
				}

				if ( verdict && ! verdict.allowed ) {
					const reason = verdict.reason.trim() || __( 'Этот пункт выдачи не подходит для заказа — проверьте код.', 'woodev-plugin-framework' );

					setErrors( ( current ) => ( { ...current, 'pickup_point.id': [ reason ] } ) );

					return;
				}
			}
		}

		setSubmitError( '' );
		setIndex( Math.min( index + 1, ids.length - 1 ) );
	};

	const submit = async (): Promise<void> => {
		if ( busy || submitting.current ) {
			return;
		}

		// The last look: every step once more. The manager passed each on the way here, but a step's data
		// can move behind its back (a tariff dropping out, the tariffs still being asked).
		const problems = validateAll( data, countries, states, statuses, addressPolicy );
		const first = firstStepWithErrors( problems );

		if ( first >= 0 ) {
			setErrors( problems );
			setIndex( first );
			setSubmitError(
				editing
					? __( 'Проверьте отмеченные поля — заказ не сохранён.', 'woodev-plugin-framework' )
					: __( 'Проверьте отмеченные поля — заказ ещё не создан.', 'woodev-plugin-framework' )
			);

			return;
		}

		submitting.current = true;
		setBusy( true );
		setSubmitError( '' );

		try {
			const result = await saveOrder( editing ? ( orderId as number ) : null, buildPayload( data ) );

			saved.current = true;
			submitting.current = false;
			setBusy( false );
			onSaved( result, mode );
			onClose();
		} catch ( raw ) {
			const error = toRequestError( raw );
			const grouped = groupServerErrors( error.errors );
			const target = firstStepWithErrors( grouped );

			submitting.current = false;
			setBusy( false );
			setSubmitError( error.message );

			if ( target >= 0 ) {
				setErrors( grouped );
				setIndex( target );
			}
		}
	};

	const goToStep = ( step: WizardStepId ) => setIndex( Math.max( 0, ids.indexOf( step ) ) );
	const props = { data, setData, errors, mode, order, baselineTotal, submit, busy, goToStep, addressPolicy } as const;
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
								<Button variant="primary" onClick={ () => void goNext() } disabled={ busy }>
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
