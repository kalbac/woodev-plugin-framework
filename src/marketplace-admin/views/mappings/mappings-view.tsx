/**
 * The «Сопоставление и профили» page: two tabs (mappings, profiles). Each shows its list; opening a record, or adding one,
 * replaces the list with the record's form, and «← Все …» brings the list back. Where the page is lives in the URL
 * ({@see ./route}).
 *
 * The page looks like the settings: folder tabs, white cards, the settings stylesheet (`.woodev-settings`).
 *
 * @package woodev-plugin-framework
 */

import './style.scss';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { FolderTabs } from '../../../components/folder-tabs';
import type { PageViewProps } from '../../types';
import { createApi, errorLines } from './api';
import { MappingEditor } from './mapping-editor';
import { MappingsList, ProfilesList } from './mapping-lists';
import { Notices, type NoticeState } from './notices';
import { ProfileEditor } from './profile-editor';
import { selectionArgument, useRoute, type Selection } from './route';
import type { AttributeCountsById, MappingsState } from './types';

export function MappingsView( { page, menu, query }: PageViewProps ) {
	const api = useMemo( () => createApi( menu.pluginId ), [ menu.pluginId ] );
	const route = useRoute( query );
	const [ state, setState ] = useState< MappingsState | null >( null );
	const [ failure, setFailure ] = useState< NoticeState | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ counts, setCounts ] = useState< AttributeCountsById | null >( null );

	const load = useCallback( () => {
		api.state()
			.then( setState )
			.catch( ( error ) => setFailure( { status: 'error', lines: errorLines( error, '' ) } ) );
	}, [ api ] );

	useEffect( load, [ load ] );

	// The «Атрибуты» column follows the list: the first paint asks the marketplace for no fields. A failed read leaves the column
	// with its dashes; the list itself is fine without it.
	useEffect( () => {
		if ( ! state ) {
			return undefined;
		}

		let current = true;
		api.attributeCounts()
			.then( ( answer ) => current && setCounts( Array.isArray( answer.counts ) ? {} : answer.counts ) )
			.catch( () => undefined );

		return () => {
			current = false;
		};
	}, [ api, state ] );

	if ( failure ) {
		return <Notices notice={ failure } onDismiss={ () => setFailure( null ) } />;
	}

	if ( ! state ) {
		return <Spinner />;
	}

	const { messages } = state;
	const { tab, mapping, profile, go } = route;
	const done = ( sentence: string, extra: string[] = [] ) => {
		setNotice( { status: 'success', lines: [ sentence, ...extra ] } );
		load();
	};
	/** A move the merchant made by hand: what the last action said is no longer news. */
	const open = ( patch: Record< string, string | undefined > ) => {
		setNotice( null );
		go( patch );
	};

	const pickMapping = ( selection: Selection ) => open( { mapping: selectionArgument( selection ) } );
	const pickProfile = ( selection: Selection ) => open( { profile: selectionArgument( selection ) } );
	/** A profile linked from a mapping's row: its form, on the other tab. */
	const openProfile = ( id: number ) => open( { tab: 'profiles', mapping: undefined, profile: String( id ) } );
	/** A delete asked in the list: the page shows what came of it, and the list is read again. */
	const remove = async ( request: () => Promise< unknown >, sentence: string ) => {
		try {
			await request();
			done( sentence );
		} catch ( error ) {
			setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } );
		}
	};
	const listing = tab === 'mappings' ? mapping === undefined : profile === undefined;

	return (
		<div className="woodev-mappings-view woodev-settings">
			<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
			<FolderTabs
				label={ page.title }
				tabs={ [
					{ id: 'mappings', label: messages.tabMappings },
					{ id: 'profiles', label: messages.tabProfiles },
				] }
				active={ tab }
				// The other tab starts from its list, whatever was open here.
				onSelect={ ( id ) => open( { tab: id === 'profiles' ? 'profiles' : undefined, mapping: undefined, profile: undefined } ) }
			/>
			{ listing && (
				<p className="woodev-mappings-description">
					{ tab === 'mappings' ? messages.descriptionMappings : messages.descriptionProfiles }
				</p>
			) }
			<div className="woodev-mappings-body">
				{ tab === 'mappings' ? (
					mapping === undefined ? (
						<MappingsList
							state={ state }
							counts={ counts }
							onOpen={ pickMapping }
							onOpenProfile={ openProfile }
							onDelete={ ( id ) => remove( () => api.deleteMapping( id ), messages.deletedMapping ) }
						/>
					) : (
						<>
							<Back label={ messages.backToMappings } onClick={ () => pickMapping( undefined ) } />
							<MappingEditor
								key={ mapping ?? 'new' }
								api={ api }
								state={ state }
								mappingId={ mapping }
								onSaved={ ( id, notices ) => {
									// A new record's «new» entry is replaced, so Back from the saved form is the list; a saved one stays put.
									if ( id !== mapping ) {
										go( { mapping: String( id ) }, mapping === null );
									}
									done( messages.savedMapping, notices );
								} }
								onCopied={ ( id ) => {
									go( { mapping: String( id ) } );
									done( messages.copiedMapping );
								} }
								onDeleted={ () => {
									go( { mapping: undefined }, true );
									done( messages.deletedMapping );
								} }
							/>
						</>
					)
				) : profile === undefined ? (
					<ProfilesList
						state={ state }
						onOpen={ pickProfile }
						onDelete={ ( id ) => remove( () => api.deleteProfile( id ), messages.deletedProfile ) }
					/>
				) : (
					<>
						<Back label={ messages.backToProfiles } onClick={ () => pickProfile( undefined ) } />
						<ProfileEditor
							key={ profile ?? 'new' }
							api={ api }
							state={ state }
							profileId={ profile }
							onSaved={ ( id ) => {
								if ( id !== profile ) {
									go( { profile: String( id ) }, profile === null );
								}
								done( messages.savedProfile );
							} }
							onDeleted={ () => {
								go( { profile: undefined }, true );
								done( messages.deletedProfile );
							} }
						/>
					</>
				) }
			</div>
		</div>
	);
}

function Back( { label, onClick }: { label: string; onClick: () => void } ) {
	return (
		<p className="woodev-mappings-back">
			<Button variant="link" onClick={ onClick }>
				{ label }
			</Button>
		</p>
	);
}
