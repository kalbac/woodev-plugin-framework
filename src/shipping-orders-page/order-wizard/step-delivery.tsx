/**
 * Step ④ «Доставка» (#710 D1 / D2 / D3, O8 / O10 / O12, increment I5a of card #970): the
 * carriers' tariffs for what steps ①–③ built, the pickup list + map INSIDE the step for a
 * pickup tariff, and the delivery price the manager may change.
 *
 * **Tariffs.** Asked from `POST /shipping/orders/rates` (I2a) whenever the package or the
 * destination changes — the request is built from the wizard state, so the step re-asks on
 * arrival (the manager may have edited ②/③ since) and never otherwise. The answer is grouped by
 * carrier and only ever holds the shop's carriers' tariffs (O12); a carrier with nothing for this
 * package is shown with a line saying so rather than dropped.
 *
 * **A chosen tariff that stops being offered** (the address or the package changed) is dropped
 * with a notice — it never rides into the order silently. One that is still offered keeps the
 * manager's own price (O8): a re-quote never overwrites a typed price.
 *
 * **Price (O8).** Starts at the carrier's own and is a plain number field; the carrier's price
 * stays visible beside it with «вернуть цену тарифа». The number sent is the FINAL one
 * (`shipping_line.cost`), the rate's `meta` travels with it.
 *
 * **Pickup point (O10, D3).** A pickup tariff shows the point list and map in this step — the
 * framework's own picker over the admin points routes, told the order's weight, the payment
 * method (once ⑤ has one) and the destination record explicitly. It blocks «Далее» until a point
 * is chosen (checkout parity, spec A2). Where the picker cannot run (no picker for this carrier,
 * a script that did not load) the manager types the point's code instead.
 *
 * **Carrier fields (O13, D7).** A carrier declares its own export fields in PHP (declared value, package
 * type, extra services…), per tariff; the rates answer carries the definitions and this step draws them
 * under the chosen tariff with the SAME `ControlField` the settings page uses — the plugin ships no JS.
 * An untouched field shows (and is sent as) the carrier's own default, so what is held in the state is only
 * what the manager set; «Далее» checks them by the settings page's rules, and the server re-checks against
 * the same declaration (a problem it alone can see arrives on `carrier_fields.{id}`).
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import type { ComponentType } from 'react';
import ControlField from '../../components/control-field';
import { getWizardContext } from '../rest';
import { fetchRates, toRequestError } from './api';
import type { WizardRequestError } from './api';
import {
	applyRates,
	buildRatesRequest,
	chooseRate,
	carrierFieldValue,
	chosenRateId,
	decodeEntities,
	effectiveCarrierValues,
	findRate,
	isChosenRateGone,
	isCostOverridden,
	pickedPointId,
	pickupContext,
	ratesRequestKey,
	resetDeliveryCost,
	setCarrierField,
	setDeliveryCost,
	setPickupPoint,
	visibleCarrierFields,
	withPickupCheck,
} from './delivery-state';
import { FieldErrorList, TextField, errorsFor } from './fields';
import PickupMap from './pickup-map';
import { isPickupRuntimeAvailable } from './pickup-session';
import type { PickupPoint, PickupWizardConfig } from './pickup-session';
import type { StepProps } from './step-props';
import type { CarrierField, FieldErrors, RateGroup, RateOption, RatesResponse } from './types';
import { formatMoney } from './wizard-data';

type Phase = 'idle' | 'loading' | 'ready' | 'failed';

interface RatesState {
	phase: Phase;
	response: RatesResponse | null;
	error: string;
}

/** «Название — адрес» of the chosen point, decoded for display; '' when nothing is known beyond the id. */
function describePoint( point: Record<string, unknown> | null ): string {
	if ( ! point ) {
		return '';
	}

	const name = 'string' === typeof point.name ? decodeEntities( point.name ) : '';
	const address = 'string' === typeof point.address ? decodeEntities( point.address ) : '';

	return [ name, address ].filter( Boolean ).join( ' — ' );
}

interface RateRowProps {
	rate: RateOption;
	checked: boolean;
	symbol: string;
	onChoose: () => void;
}

function RateRow( { rate, checked, symbol, onChoose }: RateRowProps ) {
	return (
		<label className={ `woodev-order-wizard__rate${ checked ? ' is-chosen' : '' }` }>
			<input type="radio" name="woodev-order-wizard-rate" value={ rate.id } checked={ checked } onChange={ onChoose } />
			<span className="woodev-order-wizard__rate-body">
				<span className="woodev-order-wizard__rate-name">
					{ rate.label }
					{ rate.is_pickup && <span className="woodev-order-wizard__rate-badge">{ __( 'Пункт выдачи', 'woodev-plugin-framework' ) }</span> }
				</span>
				{ ( rate.delivery_time || rate.description ) && (
					<span className="woodev-order-wizard__rate-hint">{ [ rate.delivery_time, rate.description ].filter( Boolean ).join( ' · ' ) }</span>
				) }
			</span>
			<span className="woodev-order-wizard__rate-cost">{ formatMoney( rate.cost, symbol ) }</span>
		</label>
	);
}

function RateGroupList( { group, chosen, symbol, onChoose }: { group: RateGroup; chosen: string; symbol: string; onChoose: ( rate: RateOption ) => void } ) {
	return (
		<fieldset className="woodev-order-wizard__rate-group">
			<legend className="woodev-order-wizard__rate-group-title">{ group.label }</legend>
			{ 0 === group.rates.length ? (
				<p className="woodev-order-wizard__muted">{ __( 'Для этого адреса и товаров тарифов нет.', 'woodev-plugin-framework' ) }</p>
			) : (
				group.rates.map( ( rate ) => (
					<RateRow key={ rate.id } rate={ rate } checked={ chosen === rate.id } symbol={ symbol } onChoose={ () => onChoose( rate ) } />
				) )
			) }
		</fieldset>
	);
}

/**
 * `ControlField` is plain JS whose JSDoc says it returns an `Object`, which TypeScript refuses as a JSX
 * element: name the props the wizard passes and treat it as the component it is.
 */
interface ControlFieldProps {
	schema: Record<string, unknown>;
	value: unknown;
	onChange: ( value: unknown ) => void;
	showErrors: boolean;
	settingId?: string;
	conditionValues?: Record<string, unknown>;
}

const SettingsControl = ControlField as unknown as ComponentType<ControlFieldProps>;

interface CarrierFieldsProps {
	schema: CarrierField[];
	values: Record<string, unknown>;
	errors: FieldErrors;
	onChange: ( id: string, value: unknown ) => void;
}

/** The carrier's own fields for the chosen tariff (D7), drawn by the settings page's control. */
function CarrierFields( { schema, values, errors, onChange }: CarrierFieldsProps ) {
	const visible = visibleCarrierFields( schema, values );

	if ( 0 === visible.length ) {
		return null;
	}

	const effective = effectiveCarrierValues( schema, values );
	// «Далее» (or the server) reported something on these fields: show every problem at once, not after a blur.
	const reveal = visible.some( ( field ) => errorsFor( errors, `carrier_fields.${ field.id }` ).length > 0 );

	return (
		<div className="woodev-order-wizard__carrier-fields">
			<h4 className="woodev-order-wizard__pickup-title">{ __( 'Данные для перевозчика', 'woodev-plugin-framework' ) }</h4>
			{ visible.map( ( field ) => (
				<SettingsControl
					key={ field.id }
					settingId={ field.id }
					schema={ { ...field, serverError: errorsFor( errors, `carrier_fields.${ field.id }` )[ 0 ] } }
					value={ carrierFieldValue( field, values ) }
					conditionValues={ effective }
					showErrors={ reveal }
					onChange={ ( value: unknown ) => onChange( field.id, value ) }
				/>
			) ) }
		</div>
	);
}

export default function StepDelivery( { data, setData, errors }: StepProps ) {
	const { nonce, wizard } = getWizardContext();
	const symbol = wizard.currency?.symbol || '';
	const [ rates, setRates ] = useState<RatesState>( { phase: 'idle', response: null, error: '' } );
	const [ attempt, setAttempt ] = useState( 0 );
	const [ gone, setGone ] = useState( false );

	// The effect below reads the state as it is when the answer LANDS, not when it was asked.
	const latest = useRef( data );
	latest.current = data;

	const request = buildRatesRequest( data );
	const requestKey = ratesRequestKey( request );

	useEffect( () => {
		const body = buildRatesRequest( latest.current );

		if ( null === body ) {
			setRates( { phase: 'idle', response: null, error: '' } );

			return undefined;
		}

		let cancelled = false;
		let settled = false;

		setRates( ( current ) => ( { ...current, phase: 'loading', error: '' } ) );
		setData( ( d ) => ( d.rest.rates_pending ? d : { ...d, rest: { ...d.rest, rates_pending: true } } ) );

		fetchRates( body )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}

				settled = true;
				setGone( isChosenRateGone( latest.current, response ) );
				setData( ( d ) => {
					const reconciled = applyRates( d, response );

					return { ...reconciled, rest: { ...reconciled.rest, rates_pending: false } };
				} );
				setRates( { phase: 'ready', response, error: '' } );
			} )
			.catch( ( raw: unknown ) => {
				if ( cancelled ) {
					return;
				}

				settled = true;

				const error: WizardRequestError = toRequestError( raw );

				// No answer to wait for any more: the manager decides with the line they have (or retries).
				setData( ( d ) => ( d.rest.rates_pending ? { ...d, rest: { ...d.rest, rates_pending: false } } : d ) );
				setRates( { phase: 'failed', response: null, error: error.message } );
			} );

		return () => {
			cancelled = true;

			// Leaving (or asking again) mid-flight abandons this answer; the next visit re-asks before
			// anything passes, so the marker must not stay up. Once it has landed there is nothing to clear.
			if ( ! settled ) {
				setData( ( d ) => ( d.rest.rates_pending ? { ...d, rest: { ...d.rest, rates_pending: false } } : d ) );
			}
		};
		// Asked again only when the request itself changes, or the manager presses «Повторить».
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ requestKey, attempt ] );

	const { rest } = data;
	const line = rest.shipping_line;
	const chosen = chosenRateId( rest );
	const response = rates.response;
	const found = findRate( response, chosen );
	const overridden = isCostOverridden( rest );

	const pickupConfig = found ? ( wizard.pickup?.[ found.group.id ] as PickupWizardConfig | undefined ) : undefined;
	const canDrawPicker = isPickupRuntimeAvailable( pickupConfig );
	const pointId = pickedPointId( rest );
	const noRates = 'ready' === rates.phase && !! response && response.needs_shipping && response.providers.every( ( group ) => 0 === group.rates.length );

	// Step ⑤ asks the points route about the chosen point once a payment method is picked; it needs to
	// know whose route and which weight this package had (D3).
	const checkProvider = found ? found.group.id : '';
	const checkWeight = response ? response.weight : 0;

	useEffect( () => {
		if ( '' !== checkProvider ) {
			setData( ( d ) => withPickupCheck( d, checkProvider, checkWeight ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ checkProvider, checkWeight ] );

	const onPickPoint = ( point: PickupPoint ) =>
		setData( ( d ) =>
			setPickupPoint( d, {
				id: String( point.id ),
				name: 'string' === typeof point.name ? point.name : '',
				address: 'string' === typeof point.address ? point.address : 'string' === typeof point.short_address ? point.short_address : '',
			} )
		);

	return (
		<div className="woodev-order-wizard__step">
			<h3 className="woodev-order-wizard__step-title">{ __( 'Как доставить', 'woodev-plugin-framework' ) }</h3>

			{ 'loading' === rates.phase && (
				<div className="woodev-order-wizard__loading">
					<Spinner />
					<span>{ __( 'Считаем тарифы…', 'woodev-plugin-framework' ) }</span>
				</div>
			) }

			{ 'failed' === rates.phase && (
				<Notice status="error" isDismissible={ false }>
					{ rates.error }{ ' ' }
					<Button variant="link" onClick={ () => setAttempt( ( n ) => n + 1 ) }>
						{ __( 'Повторить', 'woodev-plugin-framework' ) }
					</Button>
				</Notice>
			) }

			{ 'idle' === rates.phase && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Чтобы посчитать доставку, укажите на предыдущих шагах страну и товары.', 'woodev-plugin-framework' ) }
				</Notice>
			) }

			{ gone && (
				<Notice status="warning" isDismissible onRemove={ () => setGone( false ) }>
					{ __( 'Выбранный тариф больше не подходит для этого адреса и товаров — выберите другой.', 'woodev-plugin-framework' ) }
				</Notice>
			) }

			{ 'ready' === rates.phase && response && ! response.needs_shipping && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'В заказе нет товаров, которым нужна доставка.', 'woodev-plugin-framework' ) }
				</Notice>
			) }

			{ noRates && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Перевозчики не предложили тарифов. Проверьте адрес и товары на предыдущих шагах.', 'woodev-plugin-framework' ) }
				</Notice>
			) }

			{ 'ready' === rates.phase && response && response.needs_shipping && (
				<div className="woodev-order-wizard__rates">
					{ response.providers.map( ( group ) => (
						<RateGroupList
							key={ group.id }
							group={ group }
							chosen={ chosen }
							symbol={ symbol }
							onChoose={ ( rate ) => {
								setGone( false );
								setData( ( d ) => chooseRate( d, rate ) );
							} }
						/>
					) ) }
				</div>
			) }

			<FieldErrorList messages={ errorsFor( errors, 'shipping_line', 'shipping_line.method_id' ) } />

			{ line && (
				<div className="woodev-order-wizard__delivery-price">
					<TextField
						label={ __( 'Стоимость доставки', 'woodev-plugin-framework' ) }
						type="number"
						min="0"
						step="0.01"
						value={ String( line.cost ?? '' ) }
						errors={ errorsFor( errors, 'shipping_line.cost' ) }
						onChange={ ( cost ) => setData( ( d ) => setDeliveryCost( d, cost ) ) }
					/>
					{ '' !== rest.rate_cost && (
						<p className="woodev-order-wizard__hint">
							{ sprintf(
								/* translators: %s: the carrier's own price, e.g. "250,00 ₽". */
								__( 'Тариф перевозчика: %s.', 'woodev-plugin-framework' ),
								formatMoney( Number( rest.rate_cost ), symbol )
							) }{ ' ' }
							{ overridden && (
								<>
									<strong>{ __( 'Цена изменена.', 'woodev-plugin-framework' ) }</strong>{ ' ' }
									<Button variant="link" onClick={ () => setData( ( d ) => resetDeliveryCost( d ) ) }>
										{ __( 'Вернуть цену тарифа', 'woodev-plugin-framework' ) }
									</Button>
								</>
							) }
						</p>
					) }
				</div>
			) }

			{ line && rest.rate_is_pickup && (
				<div className="woodev-order-wizard__pickup">
					<h4 className="woodev-order-wizard__pickup-title">{ __( 'Пункт выдачи', 'woodev-plugin-framework' ) }</h4>

					<p className="woodev-order-wizard__pickup-chosen">
						{ pointId ? (
							<>
								{ __( 'Выбран:', 'woodev-plugin-framework' ) }{ ' ' }
								<strong>{ describePoint( rest.pickup_point ) || pointId }</strong>
							</>
						) : (
							__( 'Пункт ещё не выбран — выберите его в списке или на карте.', 'woodev-plugin-framework' )
						) }
					</p>
					<FieldErrorList messages={ errorsFor( errors, 'pickup_point.id', 'pickup_point' ) } />

					{ found && pickupConfig && canDrawPicker && response && (
						<PickupMap
							config={ pickupConfig }
							context={ pickupContext( data, response.weight ) }
							locality={ data.shipping.city }
							localityKey={ 'string' === typeof data.settlementRecord?.key ? data.settlementRecord.key : '' }
							nonce={ nonce }
							selectedId={ pointId }
							onSelect={ onPickPoint }
						/>
					) }

					{ found && ! canDrawPicker && (
						<>
							<p className="woodev-order-wizard__muted">
								{ __( 'Карта пунктов выдачи для этого перевозчика недоступна — укажите код пункта вручную.', 'woodev-plugin-framework' ) }
							</p>
							<TextField
								label={ __( 'Код пункта выдачи', 'woodev-plugin-framework' ) }
								value={ pointId }
								onChange={ ( id ) => setData( ( d ) => setPickupPoint( d, { id: id.trim() } ) ) }
							/>
						</>
					) }
				</div>
			) }

			{ line && (
				<CarrierFields
					schema={ rest.carrier_schema }
					values={ rest.carrier_fields }
					errors={ errors }
					onChange={ ( id, value ) => setData( ( d ) => setCarrierField( d, id, value ) ) }
				/>
			) }
		</div>
	);
}
