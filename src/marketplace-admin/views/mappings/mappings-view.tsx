/**
 * The «Сопоставление» page: two tabs (mappings, profiles). Each shows its list; opening a record, or adding one,
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
import type { MappingsState } from './types';

export function MappingsView( { menu, query }: PageViewProps ) {
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

	return (
		<div className="woodev-mappings-view woodev-settings">
			<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
			<FolderTabs
				tabs={ [
					{ id: 'mappings', label: messages.tabMappings },
					{ id: 'profiles', label: messages.tabProfiles },
				] }
				active={ tab }
				// The other tab starts from its list, whatever was open here.
				onSelect={ ( id ) => open( { tab: id === 'profiles' ? 'profiles' : undefined, mapping: undefined, profile: undefined } ) }
			/>
			<div className="woodev-mappings-body">
				{ tab === 'mappings' ? (
					mapping === undefined ? (
						<MappingsList state={ state } onOpen={ pickMapping } />
					) : (
						<>
							<Back label={ messages.backToMappings } onClick={ () => pickMapping( undefined ) } />
							<MappingEditor
								key={ mapping ?? 'new' }
								api={ api }
								state={ state }
								mappingId={ mapping }
								onSaved={ ( id, notices ) => {
									go( { mapping: String( id ) } );
									done( messages.savedMapping, notices );
								} }
								onCopied={ ( id ) => {
									go( { mapping: String( id ) } );
									done( messages.copiedMapping );
								} }
								onDeleted={ () => {
									go( { mapping: undefined } );
									done( messages.deletedMapping );
								} }
							/>
						</>
					)
				) : profile === undefined ? (
					<ProfilesList state={ state } onOpen={ pickProfile } />
				) : (
					<>
						<Back label={ messages.backToProfiles } onClick={ () => pickProfile( undefined ) } />
						<ProfileEditor
							key={ profile ?? 'new' }
							api={ api }
							state={ state }
							profileId={ profile }
							onSaved={ ( id ) => {
								go( { profile: String( id ) } );
								done( messages.savedProfile );
							} }
							onDeleted={ () => {
								go( { profile: undefined } );
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
