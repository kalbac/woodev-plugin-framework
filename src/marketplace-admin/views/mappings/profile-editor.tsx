/**
 * The editor of one export profile: sources, fallback sizes, units, VAT, currency and an optional price rule.
 *
 * @package woodev-plugin-framework
 */

import { Fragment, useEffect, useState } from '@wordpress/element';
import { Button, FormToggle, SelectControl, Spinner, TextControl } from '@wordpress/components';
import FieldRow from '../../../components/field-row';
import { errorLines, type MappingsApi } from './api';
import { FormActions, FormCard, Group, NestedRow } from './form-parts';
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
	const [ loadState, setLoadState ] = useState< 'loading' | 'loaded' | 'failed' >( profileId === null ? 'loaded' : 'loading' );
	const [ reload, setReload ] = useState( 0 );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	/** The name the profile has in the shop: the title does not follow what is being typed. */
	const [ savedName, setSavedName ] = useState( '' );

	useEffect( () => {
		setNotice( null );
		setConfirming( false );

		if ( profileId === null ) {
			setValues( emptyValues( state ) );
			setLoadState( 'loaded' );
			return undefined;
		}

		let current = true;
		setLoadState( 'loading' );
		api.profile( profileId )
			.then( ( detail ) => {
				if ( current ) {
					setValues( { ...detail.values, name: detail.name } );
					setLoadState( 'loaded' );
				}
			} )
			.catch( ( error ) => {
				if ( current ) {
					setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } );
					setLoadState( 'failed' );
				}
			} );

		return () => {
			current = false;
		};
		// The state object is rebuilt on every reload; only the profile being opened should restart this.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ api, profileId, reload ] );

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
			setSavedName( text( 'name' ) );
			onSaved( result.id );
		} );

	const remove = () =>
		act( async () => {
			await api.deleteProfile( profileId as number );
			onDeleted();
		} );

	if ( loadState === 'loading' ) {
		return <Spinner />;
	}

	const listed = state.profiles.find( ( profile ) => profile.id === profileId )?.name;
	const title = profileId === null ? messages.newProfile : `«${ savedName || listed || messages.profileName }»`;

	// An existing profile that could not be read is not edited: a blank form saved over it would reset its settings.
	if ( loadState === 'failed' ) {
		return (
			<FormCard title={ title } className="woodev-profile-editor">
				<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
				<Button __next40pxDefaultSize variant="secondary" onClick={ () => setReload( reload + 1 ) }>
					{ messages.retry }
				</Button>
			</FormCard>
		);
	}

	const input = ( key: string, label: string ) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			hideLabelFromVision
			key={ key }
			label={ label }
			value={ text( key ) }
			onChange={ ( value ) => set( key, value ) }
		/>
	);
	const dropdown = ( key: string, label: string, options: Option[], blank?: string ) => (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			hideLabelFromVision
			key={ key }
			label={ label }
			value={ text( key ) }
			options={ blank === undefined ? options : [ { value: '', label: blank }, ...options ] }
			onChange={ ( value ) => set( key, value ) }
		/>
	);
	const fieldRow = ( key: string, label: string ) => (
		<FieldRow label={ label }>{ input( key, label ) }</FieldRow>
	);
	const selectRow = ( key: string, label: string, options: Option[] ) => (
		<FieldRow label={ label }>{ dropdown( key, label, options ) }</FieldRow>
	);

	const fixedLabel = `${ messages.currencyFixed }: ${ text( 'currency_code' ) }`;
	const currencyOptions: Option[] = [
		{ value: 'account', label: messages.currencyAccount },
		{ value: 'rate', label: messages.currencyRate },
		...( form.currency_field === null ? [] : [ { value: 'field', label: form.currency_field } ] ),
		...( text( 'currency_kind' ) === 'fixed' ? [ { value: 'fixed', label: fixedLabel } ] : [] ),
	];

	return (
		<FormCard title={ title } className="woodev-profile-editor">
			<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
			{ fieldRow( 'name', messages.profileName ) }

			<Group title={ messages.sourcesHeading }>
				{ form.sources.map( ( source ) => (
					<Fragment key={ source.setting }>
						{ selectRow( source.setting, source.title, source.kinds ) }
						{ text( source.setting ) === source.custom && (
							<NestedRow>{ fieldRow( `${ source.setting }_key`, messages.customFieldKey ) }</NestedRow>
						) }
					</Fragment>
				) ) }
			</Group>

			<Group title={ messages.fallbacksHeading } description={ messages.fallbacksHelp }>
				<FieldRow label={ messages.fallbackDimensions }>
					<div className="woodev-mappings-size">
						{ input( 'fallback_width', messages.fallbackWidth ) }
						<span aria-hidden="true">×</span>
						{ input( 'fallback_height', messages.fallbackHeight ) }
						<span aria-hidden="true">×</span>
						{ input( 'fallback_depth', messages.fallbackDepth ) }
						{ dropdown( 'unit_dimension', messages.unitDimension, form.dimension_units, messages.unitDefaultDimension ) }
					</div>
				</FieldRow>
				<FieldRow label={ messages.fallbackWeight }>
					<div className="woodev-mappings-size">
						{ input( 'fallback_weight', messages.fallbackWeight ) }
						{ dropdown( 'unit_weight', messages.unitWeight, form.weight_units, messages.unitDefaultWeight ) }
					</div>
				</FieldRow>
			</Group>

			<Group title={ messages.groupPrice }>
				{ selectRow( 'vat_rate', messages.vatRate, form.vat_rates ) }
				{ selectRow( 'currency_kind', messages.currencyHeading, currencyOptions ) }
				{ text( 'currency_kind' ) === 'rate' && <NestedRow>{ fieldRow( 'currency_rate', messages.currencyRateInput ) }</NestedRow> }
				{ text( 'currency_kind' ) === 'field' && <NestedRow>{ fieldRow( 'currency_field', messages.currencyFieldKey ) }</NestedRow> }
				<FieldRow label={ messages.priceOverride }>
					<FormToggle
						checked={ values.price_override === true }
						aria-label={ messages.priceOverride }
						onChange={ ( event ) => set( 'price_override', event.target.checked ) }
					/>
				</FieldRow>
				{ values.price_override === true && (
					<>
						{ selectRow( 'price_base', messages.priceBase, form.price.base ) }
						{ selectRow( 'price_markup_kind', messages.priceMarkupKind, form.price.markup_kind ) }
						{ fieldRow( 'price_markup', messages.priceMarkup ) }
						{ selectRow( 'price_rounding', messages.priceRounding, form.price.rounding ) }
						{ selectRow( 'price_old_price_rule', messages.priceOldPrice, form.price.old_price_rule ) }
						{ fieldRow( 'price_min_percent', messages.priceMinPercent ) }
					</>
				) }
			</Group>

			<FormActions
				messages={ messages }
				busy={ busy }
				onSave={ save }
				onDelete={ profileId === null ? undefined : remove }
				confirming={ confirming }
				onConfirming={ setConfirming }
			/>
		</FormCard>
	);
}
