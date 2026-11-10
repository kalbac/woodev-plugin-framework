/**
 * The editor of one export profile: sources, fallback sizes, units, VAT, currency and an optional price rule.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { Button, SelectControl, Spinner, TextControl, ToggleControl } from '@wordpress/components';
import { errorLines, type MappingsApi } from './api';
import { Notices, type NoticeState } from './notices';
import type { MappingsState, Option, ProfileValues } from './types';

interface Props {
	api: MappingsApi;
	state: MappingsState;
	/** The profile being edited; null for a new one. */
	profileId: number | null;
	onSaved: ( id: number ) => void;
	onDeleted: () => void;
}

/** The form of a profile that does not exist yet: the server's defaults. */
function emptyValues( state: MappingsState ): ProfileValues {
	const values: ProfileValues = {
		name: '',
		fallback_width: '',
		fallback_height: '',
		fallback_depth: '',
		fallback_weight: '',
		unit_weight: '',
		unit_dimension: '',
		vat_rate: '',
		currency_kind: 'account',
		currency_rate: '',
		currency_field: '',
		price_override: false,
		price_base: state.profile_form.price.base[ 0 ]?.value ?? '',
		price_markup_kind: state.profile_form.price.markup_kind[ 0 ]?.value ?? '',
		price_markup: '',
		price_rounding: state.profile_form.price.rounding[ 0 ]?.value ?? '',
		price_old_price_rule: state.profile_form.price.old_price_rule[ 0 ]?.value ?? '',
		price_min_percent: '',
	};

	state.profile_form.sources.forEach( ( source ) => {
		values[ source.setting ] = source.default;
		values[ `${ source.setting }_key` ] = '';
	} );

	return values;
}

/** What the server reads: everything the form shows except the read-only currency code. */
function toBody( values: ProfileValues ): Record< string, unknown > {
	const { currency_code: ignored, ...body } = values;

	return body;
}

export function ProfileEditor( { api, state, profileId, onSaved, onDeleted }: Props ) {
	const { messages, profile_form: form } = state;
	const [ values, setValues ] = useState< ProfileValues >( () => emptyValues( state ) );
	const [ loading, setLoading ] = useState( profileId !== null );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );

	useEffect( () => {
		setNotice( null );
		setConfirming( false );

		if ( profileId === null ) {
			setValues( emptyValues( state ) );
			setLoading( false );
			return undefined;
		}

		let current = true;
		setLoading( true );
		api.profile( profileId )
			.then( ( detail ) => {
				if ( current ) {
					setValues( { ...detail.values, name: detail.name } );
					setLoading( false );
				}
			} )
			.catch( ( error ) => {
				if ( current ) {
					setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } );
					setLoading( false );
				}
			} );

		return () => {
			current = false;
		};
		// The state object is rebuilt on every reload; only the profile being opened should restart this.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ api, profileId ] );

	const set = ( key: string, value: string | boolean ) =>
		setValues( ( previous ) => ( { ...previous, [ key ]: value } ) );
	const text = ( key: string ) => String( values[ key ] ?? '' );

	const act = async ( work: () => Promise< void > ) => {
		setBusy( true );
		setNotice( null );
		try {
			await work();
		} catch ( error ) {
			setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } );
		}
		setBusy( false );
	};

	const save = () =>
		act( async () => {
			const result = await api.saveProfile( profileId, toBody( values ) );
			onSaved( result.id );
		} );

	const remove = () =>
		act( async () => {
			await api.deleteProfile( profileId as number );
			onDeleted();
		} );

	if ( loading ) {
		return <Spinner />;
	}

	const field = ( key: string, label: string ) => (
		<TextControl
			__next40pxDefaultSize
			key={ key }
			label={ label }
			value={ text( key ) }
			onChange={ ( value ) => set( key, value ) }
		/>
	);
	const select = ( key: string, label: string, options: Option[], blank?: string ) => (
		<SelectControl __next40pxDefaultSize
			key={ key }
			label={ label }
			value={ text( key ) }
			options={ blank === undefined ? options : [ { value: '', label: blank }, ...options ] }
			onChange={ ( value ) => set( key, value ) }
		/>
	);

	const fixedLabel = `${ messages.currencyFixed }: ${ text( 'currency_code' ) }`;
	const currencyOptions: Option[] = [
		{ value: 'account', label: messages.currencyAccount },
		{ value: 'rate', label: messages.currencyRate },
		...( form.currency_field === null ? [] : [ { value: 'field', label: form.currency_field } ] ),
		...( text( 'currency_kind' ) === 'fixed' ? [ { value: 'fixed', label: fixedLabel } ] : [] ),
	];

	return (
		<div className="woodev-profile-editor">
			<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
			{ field( 'name', messages.profileName ) }

			<fieldset>
				<legend>{ messages.sourcesHeading }</legend>
				{ form.sources.map( ( source ) => (
					<div key={ source.setting }>
						{ select( source.setting, source.title, source.kinds ) }
						{ text( source.setting ) === source.custom &&
							field( `${ source.setting }_key`, messages.customFieldKey ) }
					</div>
				) ) }
			</fieldset>

			<fieldset>
				<legend>{ messages.fallbacksHeading }</legend>
				<p className="description">{ messages.fallbacksHelp }</p>
				{ field( 'fallback_width', messages.fallbackWidth ) }
				{ field( 'fallback_height', messages.fallbackHeight ) }
				{ field( 'fallback_depth', messages.fallbackDepth ) }
				{ field( 'fallback_weight', messages.fallbackWeight ) }
				{ select( 'unit_dimension', messages.unitDimension, form.dimension_units, messages.unitDefault ) }
				{ select( 'unit_weight', messages.unitWeight, form.weight_units, messages.unitDefault ) }
			</fieldset>

			{ select( 'vat_rate', messages.vatRate, form.vat_rates ) }

			<fieldset>
				<legend>{ messages.currencyHeading }</legend>
				{ select( 'currency_kind', messages.currencyHeading, currencyOptions ) }
				{ text( 'currency_kind' ) === 'rate' && field( 'currency_rate', messages.currencyRateInput ) }
				{ text( 'currency_kind' ) === 'field' && field( 'currency_field', messages.currencyFieldKey ) }
			</fieldset>

			<ToggleControl
				label={ messages.priceOverride }
				checked={ values.price_override === true }
				onChange={ ( on ) => set( 'price_override', on ) }
			/>
			{ values.price_override === true && (
				<fieldset>
					{ select( 'price_base', messages.priceBase, form.price.base ) }
					{ select( 'price_markup_kind', messages.priceMarkupKind, form.price.markup_kind ) }
					{ field( 'price_markup', messages.priceMarkup ) }
					{ select( 'price_rounding', messages.priceRounding, form.price.rounding ) }
					{ select( 'price_old_price_rule', messages.priceOldPrice, form.price.old_price_rule ) }
					{ field( 'price_min_percent', messages.priceMinPercent ) }
				</fieldset>
			) }

			<p style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
				<Button variant="primary" onClick={ save } disabled={ busy } isBusy={ busy }>
					{ busy ? messages.saving : messages.save }
				</Button>
				{ profileId !== null && ! confirming && (
					<Button variant="tertiary" isDestructive onClick={ () => setConfirming( true ) } disabled={ busy }>
						{ messages.delete }
					</Button>
				) }
				{ profileId !== null && confirming && (
					<>
						<Button variant="primary" isDestructive onClick={ remove } disabled={ busy }>
							{ messages.confirmDelete }
						</Button>
						<Button variant="tertiary" onClick={ () => setConfirming( false ) }>
							{ messages.cancel }
						</Button>
					</>
				) }
			</p>
		</div>
	);
}
