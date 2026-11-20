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
import { RecordsList, type RouteArguments } from './mapping-lists';
import { Notices, type NoticeState } from './notices';
import { ProfileEditor } from './profile-editor';
import { selectionArgument, useRoute, type Selection } from './route';
import type { MappingsState } from './types';

/** The arguments of the page's own route; every other one in the URL belongs to the list on screen (its page, sort and size). */
const ROUTE_ARGUMENTS = [ 'page', 'path', 'tab', 'mapping', 'profile' ];

/** The patch that drops the left list's page, sort and size from the URL. */
function clearedListArguments( query: Record< string, string > ): Record< string, undefined > {
	return Object.fromEntries(
		Object.keys( query )
			.filter( ( key ) => ! ROUTE_ARGUMENTS.includes( key ) )
			.map( ( key ) => [ key, undefined ] )
	);
}

export function MappingsView( { page, menu, query }: PageViewProps ) {
	const api = useMemo( () => createApi( menu.pluginId ), [ menu.pluginId ] );
	const route = useRoute( query );
	const [ state, setState ] = useState< MappingsState | null >( null );
	const [ failure, setFailure ] = useState< NoticeState | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );

	const load = useCallback( () => {
		api.state()
			.then( setState )
			.catch( ( error ) => setFailure( { status: 'error', lines: errorLines( error, '' ) } ) );
	}, [ api ] );

	useEffect( load, [ load ] );

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
	/** A link of a list (a name, «Редактировать», the profile of a mapping): the form it points at, which may be on the other tab. */
	const follow = ( route: RouteArguments ) => open( route );
	/** A delete asked in the list: the page shows what came of it, and the list is read again. */
	const remove = async ( request: () => Promise< unknown >, sentence: string ) => {
		try {
			await request();
			done( sentence );
		} catch ( error ) {
			setNotice( { status: 'error', lines: errorLines( error, messages.requestFailed ) } );
		}
	};

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
				// The other tab starts from its list — from its first page, in its own order — whatever was open here.
				onSelect={ ( id ) => open( { ...clearedListArguments( query ), tab: id === 'profiles' ? 'profiles' : undefined, mapping: undefined, profile: undefined } ) }
			/>
			<div className="woodev-mappings-body">
				{ tab === 'mappings' ? (
					mapping === undefined ? (
						<RecordsList
							key="mapping"
							kind="mapping"
							owner={ menu.pluginId }
							query={ query }
							state={ state }
							onFollow={ follow }
							onOpen={ pickMapping }
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
					<RecordsList
						key="profile"
						kind="profile"
						owner={ menu.pluginId }
						query={ query }
						state={ state }
						onFollow={ follow }
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
