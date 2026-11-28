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
 * (`shipping_line.cost`), the rate's `meta` travels with it. In a delivery-only edit (#2010) the price is not the
 * order's: it is the shop's own cost of the delivery, so it is shown, never typed, and follows every fresh quote.
 * It is the carrier's price, not the customer's ({@link atCarrierCost}), and — there being no step ⑤ — the step asks
 * the final quote for the chosen point and the order's payment method itself ({@link buildFinalQuoteRequest}).
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
	applyReprice,
	atCarrierCost,
	buildFinalQuoteRequest,
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
	setFinalQuote,
	setPickupPoint,
	visibleCarrierFields,
	withPickupCheck,
} from './delivery-state';
import { FieldErrorList, TextField, errorsFor } from './fields';
import PickupMap from './pickup-map';
import { isPickupRuntimeAvailable, pointAddressLine } from './pickup-session';
import type { PickupPoint, PickupWizardConfig } from './pickup-session';
import type { StepProps } from './step-props';
import type { CarrierField, FieldErrors, RateGroup, RateOption, RatesResponse, WizardData } from './types';
import { formatMoney } from './wizard-data';

type Phase = 'idle' | 'loading' | 'ready' | 'failed';

/** How long a delivery-only edit waits before its final quote — a point code typed by hand changes with every key. */
const FINAL_QUOTE_DELAY_MS = 400;

interface RatesState {
	phase: Phase;
	response: RatesResponse | null;
	error: string;
}

/** A locality comparison that ignores only spelling noise a carrier must not turn into a new destination. */
function normalizedLocality( value: string ): string {
	return value.trim().replace( /\s+/g, ' ' ).toLocaleLowerCase();
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

interface RateGroupListProps {
	group: RateGroup;
	chosen: string;
	/** The price the chosen tariff was settled at, when that is not the one in this list (a delivery-only edit's final quote). */
	chosenCost?: number;
	symbol: string;
	onChoose: ( rate: RateOption ) => void;
}

function RateGroupList( { group, chosen, chosenCost, symbol, onChoose }: RateGroupListProps ) {
	return (
		<fieldset className="woodev-order-wizard__rate-group">
			<legend className="woodev-order-wizard__rate-group-title">{ group.label }</legend>
			{ 0 === group.rates.length ? (
				<p className="woodev-order-wizard__muted">{ __( 'Для этого адреса и товаров тарифов нет.', 'woodev-plugin-framework' ) }</p>
			) : (
				group.rates.map( ( rate ) => (
					<RateRow
						key={ rate.id }
						rate={ chosen === rate.id && undefined !== chosenCost ? { ...rate, cost: chosenCost } : rate }
						checked={ chosen === rate.id }
						symbol={ symbol }
						onChoose={ () => onChoose( rate ) }
					/>
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

export default function StepDelivery( { data, setData, errors, delivery }: StepProps ) {
	const { nonce, wizard } = getWizardContext();
	const symbol = wizard.currency?.symbol || '';
	const [ rates, setRates ] = useState<RatesState>( { phase: 'idle', response: null, error: '' } );
	const [ attempt, setAttempt ] = useState( 0 );
	const [ gone, setGone ] = useState( false );

	// The effect below reads the state as it is when the answer LANDS, not when it was asked.
	const latest = useRef( data );
	latest.current = data;
	// Replacing the address is a consequence of selecting a point from the current quote, not a
	// manager edit of the destination. Do not ask that same quote again and let its answer erase
	// the selection which caused the substitution.
	const ignoredRequestKey = useRef( '' );

	const request = buildRatesRequest( data );
	const requestKey = ratesRequestKey( request );

	useEffect( () => {
		if ( ignoredRequestKey.current === requestKey ) {
			ignoredRequestKey.current = '';

			return undefined;
		}

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
			.then( ( answer ) => {
				if ( cancelled ) {
					return;
				}

				const response = delivery ? atCarrierCost( answer ) : answer;

				settled = true;
				setGone( isChosenRateGone( latest.current, response ) );
				setData( ( d ) => {
					// A delivery-only edit has no price of the manager's to keep: the cost is what the carrier asks now.
					const applied = applyRates( d, response );
					const reconciled = delivery ? resetDeliveryCost( applied ) : applied;

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

	const [ quoteError, setQuoteError ] = useState( '' );
	const [ quoteAttempt, setQuoteAttempt ] = useState( 0 );
	const finalRequest = delivery ? buildFinalQuoteRequest( data, wizard.paymentMethods ) : null;
	const finalKey = ratesRequestKey( finalRequest );

	// Runs after every answer of the tariffs above: that answer prices the tariff for no particular point, this one settles it.
	useEffect( () => {
		if ( ! delivery ) {
			return undefined;
		}

		setQuoteError( '' );

		if ( 'ready' !== rates.phase || null === finalRequest ) {
			// Without the tariffs themselves there is no cost to save either.
			setData( ( d ) => setFinalQuote( d, 'failed' === rates.phase ? 'failed' : '' ) );

			return undefined;
		}

		let cancelled = false;

		const fail = ( message: string ) => {
			setQuoteError( message );
			setData( ( d ) => setFinalQuote( d, 'failed' ) );
		};

		setData( ( d ) => setFinalQuote( d, 'pending' ) );

		const timer = setTimeout( () => {
			fetchRates( finalRequest )
				.then( ( answer ) => {
					if ( cancelled ) {
						return;
					}

					const response = atCarrierCost( answer );

					if ( 'failed' === applyReprice( latest.current, response ).outcome ) {
						fail( __( 'Перевозчик не посчитал доставку для этого заказа.', 'woodev-plugin-framework' ) );

						return;
					}

					setData( ( d ) => setFinalQuote( resetDeliveryCost( applyReprice( d, response ).data ), '' ) );
				} )
				.catch( ( raw: unknown ) => {
					if ( ! cancelled ) {
						fail( toRequestError( raw ).message );
					}
				} );
		}, FINAL_QUOTE_DELAY_MS );

		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
		// `finalKey` is the request; the object identities are not the question.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ finalKey, rates.phase, rates.response, quoteAttempt ] );

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

	// m1: a 422 on `carrier_fields.*` / `fields.*` routes here, but the controls only draw a field that is on
	// screen — an edit whose rates request failed keeps `carrier_schema` empty. Whatever no control shows is
	// listed, so the manager never gets a jump to ④ with no reason on it.
	const drawnFields = line ? visibleCarrierFields( rest.carrier_schema, rest.carrier_fields ).map( ( field ) => `carrier_fields.${ field.id }` ) : [];
	const strayErrors = Array.from(
		new Set(
			Object.entries( errors )
				.filter( ( [ field ] ) => ( field.startsWith( 'carrier_fields' ) || field.startsWith( 'fields' ) ) && ! drawnFields.includes( field ) )
				.flatMap( ( [ , messages ] ) => messages )
		)
	);

	const replaceAddressFromPoint = ( current: WizardData, point: PickupPoint ): WizardData => {
		const selected = setPickupPoint( current, {
			id: String( point.id ),
			name: 'string' === typeof point.name ? point.name : '',
			address: 'string' === typeof point.address ? point.address : 'string' === typeof point.short_address ? point.short_address : '',
		} );

		if ( ! pickupConfig?.replaceAddress?.enabled ) {
			return selected;
		}

		const address = decodeEntities( pointAddressLine( point ) );
		const locality = 'string' === typeof point.locality ? decodeEntities( point.locality ) : '';
		const postcode = 'string' === typeof point.postal_code ? decodeEntities( point.postal_code ) : '';
		const cityChanged = '' !== locality && normalizedLocality( locality ) !== normalizedLocality( selected.shipping.city );

		return {
			...selected,
			shipping: {
				...selected.shipping,
				address_1: address,
				// A point without a locality does not make the existing settlement unknown.
				city: cityChanged ? locality : selected.shipping.city,
				postcode,
			},
			// The confirmed settlement record survives the pick, as it does on the checkout
			// (location-cascade.js handlePickupAddressReplacing, #339 — re-seeding, not
			// suppressing): a point may stand in a neighbouring settlement, and the record is what
			// addresses the picker and step ⑤'s point check for a settlement-keyed carrier.
		};
	};

	const onPickPoint = ( point: PickupPoint ) => {
		const replaced = replaceAddressFromPoint( latest.current, point );
		const replacedRequestKey = ratesRequestKey( buildRatesRequest( replaced ) );

		if ( pickupConfig?.replaceAddress?.enabled && replacedRequestKey !== requestKey ) {
			ignoredRequestKey.current = replacedRequestKey;
		}

		setData( ( current ) => replaceAddressFromPoint( current, point ) );
	};

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
					{ delivery
						? __( 'Тарифы не посчитать: проверьте страну доставки и то, что товары заказа есть в магазине.', 'woodev-plugin-framework' )
						: __( 'Чтобы посчитать доставку, укажите на предыдущих шагах страну и товары.', 'woodev-plugin-framework' ) }
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
							chosenCost={ delivery && line && ! rest.final_quote ? Number( line.cost ) : undefined }
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

			{ line && delivery && (
				<div className="woodev-order-wizard__delivery-price">
					{ 'pending' === rest.final_quote && (
						<div className="woodev-order-wizard__loading">
							<Spinner />
							<span>{ __( 'Уточняем стоимость доставки…', 'woodev-plugin-framework' ) }</span>
						</div>
					) }
					{ ! rest.final_quote && (
						<p className="woodev-order-wizard__hint">
							{ sprintf(
								/* translators: %s: the carrier's price of the delivery, e.g. "250,00 ₽". */
								__( 'Ваш расход на доставку: %s. Сумма заказа не изменится.', 'woodev-plugin-framework' ),
								formatMoney( Number( line.cost ), symbol )
							) }
						</p>
					) }
					{ quoteError && (
						<Notice status="error" isDismissible={ false }>
							{ quoteError }{ ' ' }
							<Button variant="link" onClick={ () => setQuoteAttempt( ( n ) => n + 1 ) }>
								{ __( 'Повторить', 'woodev-plugin-framework' ) }
							</Button>
						</Notice>
					) }
					<FieldErrorList messages={ errorsFor( errors, 'shipping_line.cost' ) } />
				</div>
			) }

			{ line && ! delivery && (
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

			<FieldErrorList messages={ strayErrors } />
		</div>
	);
}
