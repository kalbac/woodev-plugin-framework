/**
 * The editor of one mapping: name, shop categories, marketplace category, profile and the attribute table.
 *
 * @package woodev-plugin-framework
 */

import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, SelectControl, Spinner, TextControl } from '@wordpress/components';
import FieldRow from '../../../components/field-row';
import SelectField from '../../../components/select-field';
import { errorLines, type MappingsApi } from './api';
import { AttributeTable } from './attribute-table';
import { CategoryPicker } from './category-picker';
import { CheckReport } from './check-report';
import { FormActions, FormCard, Group, Inset } from './form-parts';
import { Notices, type NoticeState } from './notices';
import type { AttributeRow, MappingCategory, MappingsState, ShopCategory } from './types';

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

/** How the read of an existing mapping went: only a loaded record may be edited and saved. */
type LoadState = 'loading' | 'loaded' | 'failed';

const EMPTY: Draft = { name: '', terms: [], profileId: '', category: null, attributes: [] };

/** A category another mapping holds: one category belongs to one mapping, so it cannot be ticked here. */
function isHeld( category: ShopCategory, mappingId: number | null ): boolean {
	return category.mapping_id !== null && category.mapping_id !== mappingId;
}

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
		// The table travels with the category it was loaded for; the server refuses it for any other.
		body.attributes_category = draft.category?.key ?? '';
	}

	return body;
}

export function MappingEditor( { api, state, mappingId, onSaved, onCopied, onDeleted }: Props ) {
	const { messages } = state;
	const [ draft, setDraft ] = useState< Draft >( EMPTY );
	const [ loadState, setLoadState ] = useState< LoadState >( mappingId === null ? 'loaded' : 'loading' );
	const [ reload, setReload ] = useState( 0 );
	/** Counts category choices; a field response of an earlier choice is ignored. */
	const fieldsRun = useRef( 0 );
	const [ loadingTable, setLoadingTable ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	/** The name the mapping has in the shop: the title does not follow what is being typed. */
	const [ savedName, setSavedName ] = useState( '' );

	useEffect( () => {
		setNotice( null );
		setConfirming( false );

		fieldsRun.current += 1;
		setLoadingTable( false );

		if ( mappingId === null ) {
			setDraft( EMPTY );
			setLoadState( 'loaded' );
			return undefined;
		}

		let current = true;
		setLoadState( 'loading' );
		api.mapping( mappingId )
			.then( ( detail ) => {
				if ( ! current ) {
					return;
				}
				setSavedName( detail.name );
				setDraft( {
					name: detail.name,
					terms: detail.terms,
					profileId: detail.profile_id === null ? '' : String( detail.profile_id ),
					category: detail.category,
					attributes: detail.attributes,
				} );
				setLoadState( 'loaded' );
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
	}, [ api, mappingId, messages.requestFailed, reload ] );

	// A response that arrives after the editor is gone must not touch it.
	useEffect( () => () => {
		fieldsRun.current += 1;
	}, [] );

	const chooseCategory = ( category: MappingCategory | null ) => {
		fieldsRun.current += 1;
		const run = fieldsRun.current;
		const isLatest = () => run === fieldsRun.current;

		setDraft( ( previous ) => ( { ...previous, category, attributes: category ? null : [] } ) );
		setLoadingTable( category !== null );

		if ( ! category ) {
			return;
		}

		api.fields( category.key, mappingId )
			.then( ( response ) => {
				if ( isLatest() ) {
					setDraft( ( previous ) => ( { ...previous, attributes: response.attributes } ) );
				}
			} )
			.catch( ( error ) => {
				if ( isLatest() ) {
					setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } );
				}
			} )
			.finally( () => {
				if ( isLatest() ) {
					setLoadingTable( false );
				}
			} );
	};

	/** The picker reports the whole choice; a held category in it is dropped, so ticking one changes nothing. */
	const setTerms = ( chosen: string[] ) => {
		const held = new Set( state.shop_categories.filter( ( category ) => isHeld( category, mappingId ) ).map( ( category ) => category.id ) );

		setDraft( ( previous ) => ( { ...previous, terms: chosen.map( Number ).filter( ( id ) => ! held.has( id ) ) } ) );
	};

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
			setSavedName( draft.name );
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

	if ( loadState === 'loading' ) {
		return <Spinner />;
	}

	const listed = state.mappings.find( ( mapping ) => mapping.id === mappingId )?.name;
	const title = mappingId === null ? messages.newMapping : `«${ savedName || listed || messages.mappingName }»`;

	// An existing mapping that could not be read is not edited: a blank form saved over it would erase it.
	if ( loadState === 'failed' ) {
		return (
			<FormCard title={ title } className="woodev-mapping-editor">
				<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
				<Button __next40pxDefaultSize variant="secondary" onClick={ () => setReload( reload + 1 ) }>
					{ messages.retry }
				</Button>
			</FormCard>
		);
	}

	// Saving waits for the table of the category chosen now.
	const tableReady = draft.category === null || draft.attributes !== null;

	const categoryOptions = state.shop_categories.map( ( category ) => ( {
		value: String( category.id ),
		label: isHeld( category, mappingId ) ? `${ category.label } — ${ messages.shopCategoryTaken }: ${ category.mapping_name }` : category.label,
	} ) );

	return (
		<>
			<FormCard title={ title } className="woodev-mapping-editor">
				<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />

				<Inset>
					<FieldRow label={ messages.mappingName }>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ messages.mappingName }
							hideLabelFromVision
							value={ draft.name }
							onChange={ ( name ) => setDraft( { ...draft, name } ) }
						/>
					</FieldRow>
				</Inset>

				<Group title={ messages.groupCategories }>
					<FieldRow label={ messages.shopCategories } description={ messages.shopCategoriesHelp }>
						<SelectField multi placeholder={ __( 'Не выбрано', 'woodev-plugin-framework' ) } value={ draft.terms.map( String ) } options={ categoryOptions } onChange={ setTerms } />
					</FieldRow>
					<FieldRow label={ messages.marketplaceCategory }>
						<CategoryPicker api={ api } messages={ messages } value={ draft.category } onChange={ chooseCategory } />
					</FieldRow>
					<FieldRow label={ messages.profile }>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ messages.profile }
							hideLabelFromVision
							value={ draft.profileId }
							options={ [
								{ value: '', label: messages.profileNone },
								...state.profiles.map( ( profile ) => ( { value: String( profile.id ), label: profile.name } ) ),
							] }
							onChange={ ( profileId ) => setDraft( { ...draft, profileId } ) }
						/>
					</FieldRow>
				</Group>

				<Group title={ messages.attributes }>
					{ loadingTable && <p className="woodev-group__desc">{ messages.attributesLoading }</p> }
					{ ! loadingTable && draft.category === null && <p className="woodev-group__desc">{ messages.attributesEmpty }</p> }
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
				</Group>

				<FormActions
					messages={ messages }
					busy={ busy }
					saveDisabled={ loadingTable || ! tableReady }
					onSave={ save }
					onCopy={ mappingId === null ? undefined : copy }
					onDelete={ mappingId === null ? undefined : remove }
					confirming={ confirming }
					onConfirming={ setConfirming }
				/>
			</FormCard>

			{ mappingId !== null && <CheckReport api={ api } messages={ messages } mappingId={ mappingId } /> }
		</>
	);
}
