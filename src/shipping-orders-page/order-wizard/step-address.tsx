/**
 * Step ② «Адрес» (#710 D1): country → region → settlement → street / house / flat → postcode.
 *
 * Every field is typed by hand and none of them needs the location provider: the two
 * search buttons (settlement, street) are an aid on top — they fill the fields from the
 * provider's record (postcode included) and never replace them. That is deliberate: a shop
 * with no provider configured, or a country the provider does not cover, must still be able
 * to place an order.
 *
 * The picker is the shared {@link LocationPicker} (#960); it is handed the `woodev/v1` root
 * and calls only the stateless public `/location/suggest` — never `/location/select` or
 * `/location/forget`, which write the calling ADMIN's own location store (#710 D1).
 *
 * The region is a WooCommerce STATE CODE, which is what the order (and the payload
 * validator) stores: a select over the bootstrap's region list when the country has one,
 * free text otherwise. A picked settlement fills it only when its region name matches exactly
 * one code ({@see matchState}).
 *
 * The checkout's own field policy (`Checkout_Field_Policy`, #985) decides which fields are required
 * (marked with «*», checked on «Далее» by `validateAddress()`) and which are not shown at all — a
 * field the merchant removed, or one hidden for a pickup tariff. The policy arrives from the server
 * (`useAddressPolicy()`); before it does, the step asks for what every carrier needs — a country and
 * a city — and shows every field. The country is always shown: a manager may place an order for any.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import LocationPicker from '../../components/location-picker';
import type { LocationFill, LocationSuggestion } from '../../components/location-picker';
import { getWizardContext } from '../rest';
import { ruleOf } from './address-policy';
import { SelectField, TextField, errorsFor } from './fields';
import type { StepProps } from './step-props';
import { matchState } from './wizard-data';

export default function StepAddress( { data, setData, errors, addressPolicy }: StepProps ) {
	const { apiRoot, nonce, wizard } = getWizardContext();
	const countries = wizard.countries || {};
	const states = wizard.states || {};
	const { shipping, settlementKey } = data;
	const regions = states[ shipping.country ];
	const rules = {
		state: ruleOf( addressPolicy, 'state' ),
		city: ruleOf( addressPolicy, 'city' ),
		address_1: ruleOf( addressPolicy, 'address_1' ),
		address_2: ruleOf( addressPolicy, 'address_2' ),
		postcode: ruleOf( addressPolicy, 'postcode' ),
	};

	const setShipping = ( patch: Partial<typeof shipping> ) =>
		setData( ( d ) => ( { ...d, shipping: { ...d.shipping, ...patch } } ) );

	const countryOptions = Object.keys( countries ).length
		? [
				{ value: '', label: __( '— выберите страну —', 'woodev-plugin-framework' ) },
				...Object.entries( countries )
					.map( ( [ value, label ] ) => ( { value, label } ) )
					.sort( ( a, b ) => a.label.localeCompare( b.label, 'ru' ) ),
		  ]
		: null;

	/** Applies what a picked record fills; a field the record does not carry is left as typed. */
	const onPick = ( suggestion: LocationSuggestion, fill: LocationFill ) => {
		setData( ( d ) => {
			const patch: Partial<typeof d.shipping> = {};

			if ( fill.settlement ) {
				patch.city = fill.settlement;
			}

			if ( fill.region ) {
				const code = matchState( states[ d.shipping.country ], fill.region );

				if ( code ) {
					patch.state = code;
				} else if ( ! states[ d.shipping.country ] ) {
					patch.state = fill.region;
				}
			}

			if ( fill.address ) {
				patch.address_1 = fill.address;
			}

			if ( fill.postcode ) {
				patch.postcode = fill.postcode;
			}

			return {
				...d,
				shipping: { ...d.shipping, ...patch },
				settlementKey: 'settlement' === suggestion.level ? suggestion.record.key : d.settlementKey,
				// Kept whole for step ④: the rates and pickup routes take the settlement's own record.
				settlementRecord: 'settlement' === suggestion.level ? ( suggestion.record as unknown as Record<string, unknown> ) : d.settlementRecord,
			};
		} );
	};

	const pickerProps = { value: null, country: shipping.country, restRoot: apiRoot, nonce, onChange: onPick };

	return (
		<div className="woodev-order-wizard__step">
			<h3 className="woodev-order-wizard__step-title">{ __( 'Куда доставить', 'woodev-plugin-framework' ) }</h3>

			<div className="woodev-order-wizard__grid">
				{ countryOptions ? (
					<SelectField
						label={ __( 'Страна', 'woodev-plugin-framework' ) }
						value={ shipping.country }
						options={ countryOptions }
						required
						errors={ errorsFor( errors, 'shipping.country', 'billing.country' ) }
						onChange={ ( country ) =>
							setData( ( d ) => ( {
								...d,
								shipping: { ...d.shipping, country, state: '' },
								// A settlement belongs to its country: another country starts the search over.
								settlementKey: '',
								settlementRecord: null,
							} ) )
						}
					/>
				) : (
					<TextField
						label={ __( 'Страна (код, например RU)', 'woodev-plugin-framework' ) }
						value={ shipping.country }
						required
						errors={ errorsFor( errors, 'shipping.country', 'billing.country' ) }
						onChange={ ( country ) => setShipping( { country: country.toUpperCase() } ) }
					/>
				) }

				{ ! rules.state.hidden &&
					( regions ? (
						<SelectField
							label={ __( 'Регион', 'woodev-plugin-framework' ) }
							value={ shipping.state }
							options={ [
								{ value: '', label: __( '— выберите регион —', 'woodev-plugin-framework' ) },
								...Object.entries( regions ).map( ( [ value, label ] ) => ( { value, label } ) ),
							] }
							required={ rules.state.required }
							errors={ errorsFor( errors, 'shipping.state', 'billing.state' ) }
							onChange={ ( state ) => setShipping( { state } ) }
						/>
					) : (
						<TextField
							label={ __( 'Регион', 'woodev-plugin-framework' ) }
							value={ shipping.state }
							required={ rules.state.required }
							errors={ errorsFor( errors, 'shipping.state', 'billing.state' ) }
							onChange={ ( state ) => setShipping( { state } ) }
						/>
					) ) }
			</div>

			<div className="woodev-order-wizard__with-picker">
				<TextField
					label={ __( 'Город или населённый пункт', 'woodev-plugin-framework' ) }
					value={ shipping.city }
					required={ rules.city.required }
					errors={ errorsFor( errors, 'shipping.city', 'billing.city' ) }
					onChange={ ( city ) =>
						setData( ( d ) => ( { ...d, shipping: { ...d.shipping, city }, settlementKey: '', settlementRecord: null } ) )
					}
				/>
				<LocationPicker
					{ ...pickerProps }
					level="settlement"
					disabled={ '' === shipping.country }
					placeholder={ __( 'Найти населённый пункт…', 'woodev-plugin-framework' ) }
				/>
			</div>

			{ ! rules.address_1.hidden && (
				<div className="woodev-order-wizard__with-picker">
					<TextField
						label={ __( 'Улица, дом', 'woodev-plugin-framework' ) }
						value={ shipping.address_1 }
						required={ rules.address_1.required }
						errors={ errorsFor( errors, 'shipping.address_1', 'billing.address_1' ) }
						onChange={ ( address_1 ) => setShipping( { address_1 } ) }
					/>
					<LocationPicker
						{ ...pickerProps }
						level="address"
						within={ settlementKey }
						disabled={ '' === shipping.country }
						placeholder={ __( 'Найти адрес…', 'woodev-plugin-framework' ) }
					/>
				</div>
			) }

			{ ( ! rules.address_2.hidden || ! rules.postcode.hidden ) && (
				<div className="woodev-order-wizard__grid">
					{ ! rules.address_2.hidden && (
						<TextField
							label={ __( 'Квартира, офис', 'woodev-plugin-framework' ) }
							value={ shipping.address_2 }
							required={ rules.address_2.required }
							errors={ errorsFor( errors, 'shipping.address_2', 'billing.address_2' ) }
							onChange={ ( address_2 ) => setShipping( { address_2 } ) }
						/>
					) }
					{ ! rules.postcode.hidden && (
						<TextField
							label={ __( 'Индекс', 'woodev-plugin-framework' ) }
							value={ shipping.postcode }
							required={ rules.postcode.required }
							errors={ errorsFor( errors, 'shipping.postcode', 'billing.postcode' ) }
							onChange={ ( postcode ) => setShipping( { postcode } ) }
						/>
					) }
				</div>
			) }
		</div>
	);
}
