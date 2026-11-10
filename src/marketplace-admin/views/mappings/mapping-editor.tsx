/**
 * The editor of one mapping: name, shop categories, marketplace category, profile and the attribute table.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { Button, CheckboxControl, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { errorLines, type MappingsApi } from './api';
import { AttributeTable } from './attribute-table';
import { CategoryPicker } from './category-picker';
import { CheckReport } from './check-report';
import { Notices, type NoticeState } from './notices';
import type { AttributeRow, MappingCategory, MappingsState } from './types';

interface Props {
	api: MappingsApi;
	state: MappingsState;
	/** The mapping being edited; null for a new one. */
	mappingId: number | null;
	onSaved: ( id: number, notices: string[] ) => void;
	onCopied: ( id: number ) => void;
	onDeleted: () => void;
}

interface Draft {
	name: string;
	terms: number[];
	profileId: string;
	category: MappingCategory | null;
	/** Null until the table of the chosen category has loaded; a save then sends no table. */
	attributes: AttributeRow[] | null;
}

const EMPTY: Draft = { name: '', terms: [], profileId: '', category: null, attributes: [] };

/** The body the server reads; rows with no source are left out. */
function toBody( draft: Draft ): Record< string, unknown > {
	const body: Record< string, unknown > = {
		name: draft.name,
		terms: draft.terms,
		category: draft.category?.key ?? '',
		profile_id: draft.profileId === '' ? null : Number( draft.profileId ),
	};

	if ( draft.attributes !== null ) {
		body.attributes = Object.fromEntries(
			draft.attributes
				.filter( ( row ) => row.source.source !== '' )
				.map( ( row ) => [ row.id, row.source ] )
		);
	}

	return body;
}

export function MappingEditor( { api, state, mappingId, onSaved, onCopied, onDeleted }: Props ) {
	const { messages } = state;
	const [ draft, setDraft ] = useState< Draft >( EMPTY );
	const [ loading, setLoading ] = useState( mappingId !== null );
	const [ loadingTable, setLoadingTable ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ filter, setFilter ] = useState( '' );

	useEffect( () => {
		setNotice( null );
		setConfirming( false );

		if ( mappingId === null ) {
			setDraft( EMPTY );
			setLoading( false );
			return undefined;
		}

		let current = true;
		setLoading( true );
		api.mapping( mappingId )
			.then( ( detail ) => {
				if ( ! current ) {
					return;
				}
				setDraft( {
					name: detail.name,
					terms: detail.terms,
					profileId: detail.profile_id === null ? '' : String( detail.profile_id ),
					category: detail.category,
					attributes: detail.attributes,
				} );
				setLoading( false );
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
	}, [ api, mappingId, messages.requestFailed ] );

	const chooseCategory = ( category: MappingCategory | null ) => {
		setDraft( ( previous ) => ( { ...previous, category, attributes: category ? null : [] } ) );

		if ( ! category ) {
			return;
		}

		setLoadingTable( true );
		api.fields( category.key, mappingId )
			.then( ( response ) => setDraft( ( previous ) => ( { ...previous, attributes: response.attributes } ) ) )
			.catch( ( error ) => setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } ) )
			.finally( () => setLoadingTable( false ) );
	};

	const toggleTerm = ( id: number, on: boolean ) =>
		setDraft( ( previous ) => ( {
			...previous,
			terms: on ? [ ...previous.terms, id ] : previous.terms.filter( ( term ) => term !== id ),
		} ) );

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
			const result = await api.saveMapping( mappingId, toBody( draft ) );
			onSaved( result.id, result.notices );
		} );

	const remove = () =>
		act( async () => {
			await api.deleteMapping( mappingId as number );
			onDeleted();
		} );

	const copy = () =>
		act( async () => {
			const result = await api.copyMapping( mappingId as number );
			onCopied( result.id );
		} );

	if ( loading ) {
		return <Spinner />;
	}

	const needle = filter.trim().toLowerCase();
	const categories = state.shop_categories.filter( ( category ) => needle === '' || category.label.toLowerCase().includes( needle ) );

	return (
		<div className="woodev-mapping-editor">
			<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />

			<TextControl __next40pxDefaultSize
				label={ messages.mappingName }
				value={ draft.name }
				onChange={ ( name ) => setDraft( { ...draft, name } ) }
			/>

			<fieldset>
				<legend>{ messages.shopCategories }</legend>
				<p className="description">{ messages.shopCategoriesHelp }</p>
				<TextControl __next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ messages.shopCategories }
					hideLabelFromVision
					value={ filter }
					onChange={ setFilter }
				/>
				<div style={ { maxHeight: 220, overflowY: 'auto' } }>
					{ categories.map( ( category ) => {
						const heldByOther = category.mapping_id !== null && category.mapping_id !== mappingId;
						const label = heldByOther ? `${ category.label } — ${ messages.shopCategoryTaken }: ${ category.mapping_name }` : category.label;

						return (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ category.id }
								label={ label }
								checked={ draft.terms.includes( category.id ) }
								disabled={ heldByOther }
								onChange={ ( on ) => toggleTerm( category.id, on ) }
							/>
						);
					} ) }
				</div>
			</fieldset>

			<fieldset>
				<legend>{ messages.marketplaceCategory }</legend>
				<CategoryPicker api={ api } messages={ messages } value={ draft.category } onChange={ chooseCategory } />
			</fieldset>

			<SelectControl __next40pxDefaultSize
				label={ messages.profile }
				value={ draft.profileId }
				options={ [
					{ value: '', label: messages.profileNone },
					...state.profiles.map( ( profile ) => ( { value: String( profile.id ), label: profile.name } ) ),
				] }
				onChange={ ( profileId ) => setDraft( { ...draft, profileId } ) }
			/>

			<h3>{ messages.attributes }</h3>
			{ loadingTable && <p>{ messages.attributesLoading }</p> }
			{ ! loadingTable && draft.category === null && <p className="description">{ messages.attributesEmpty }</p> }
			{ ! loadingTable && draft.category !== null && draft.attributes !== null && draft.attributes.length > 0 && (
				<AttributeTable
					api={ api }
					messages={ messages }
					categoryKey={ draft.category.key }
					productFields={ state.product_fields }
					rows={ draft.attributes }
					onChange={ ( attributes ) => setDraft( { ...draft, attributes } ) }
				/>
			) }

			<p style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
				<Button variant="primary" onClick={ save } disabled={ busy || loadingTable } isBusy={ busy }>
					{ busy ? messages.saving : messages.save }
				</Button>
				{ mappingId !== null && (
					<Button variant="secondary" onClick={ copy } disabled={ busy }>
						{ messages.copy }
					</Button>
				) }
				{ mappingId !== null && ! confirming && (
					<Button variant="tertiary" isDestructive onClick={ () => setConfirming( true ) } disabled={ busy }>
						{ messages.delete }
					</Button>
				) }
				{ mappingId !== null && confirming && (
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

			{ mappingId !== null && <CheckReport api={ api } messages={ messages } mappingId={ mappingId } /> }
		</div>
	);
}
