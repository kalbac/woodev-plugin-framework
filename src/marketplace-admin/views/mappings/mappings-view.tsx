/**
 * The «Сопоставление» page: two tabs (mappings, profiles), each a list beside its editor.
 *
 * @package woodev-plugin-framework
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Spinner, TabPanel } from '@wordpress/components';
import type { PageViewProps } from '../../types';
import { createApi, errorLines, type MappingsApi } from './api';
import { MappingEditor } from './mapping-editor';
import { Notices, type NoticeState } from './notices';
import { ProfileEditor } from './profile-editor';
import type { MappingsState } from './types';

/** `null` is a new record; a number is a saved one; `undefined` is nothing chosen yet. */
type Selection = number | null | undefined;

const SIDEBAR_WIDTH = 320;

export function MappingsView( { menu }: PageViewProps ) {
	const api = useMemo( () => createApi( menu.pluginId ), [ menu.pluginId ] );
	const [ state, setState ] = useState< MappingsState | null >( null );
	const [ failure, setFailure ] = useState< NoticeState | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ mapping, setMapping ] = useState< Selection >( undefined );
	const [ profile, setProfile ] = useState< Selection >( undefined );

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
	const done = ( sentence: string, extra: string[] = [] ) => {
		setNotice( { status: 'success', lines: [ sentence, ...extra ] } );
		load();
	};

	const tabs = [
		{ name: 'mappings', title: messages.tabMappings },
		{ name: 'profiles', title: messages.tabProfiles },
	];

	return (
		<div className="woodev-mappings-view" style={ { paddingRight: 20 } }>
			<Notices notice={ notice } onDismiss={ () => setNotice( null ) } />
			<TabPanel tabs={ tabs } onSelect={ () => setNotice( null ) }>
				{ ( tab ) =>
					tab.name === 'mappings' ? (
						<MappingsTab
							api={ api }
							state={ state }
							selection={ mapping }
							onSelect={ setMapping }
							onSaved={ ( id, notices ) => {
								setMapping( id );
								done( messages.savedMapping, notices );
							} }
							onCopied={ ( id ) => {
								setMapping( id );
								done( messages.copiedMapping );
							} }
							onDeleted={ () => {
								setMapping( undefined );
								done( messages.deletedMapping );
							} }
						/>
					) : (
						<ProfilesTab
							api={ api }
							state={ state }
							selection={ profile }
							onSelect={ setProfile }
							onSaved={ ( id ) => {
								setProfile( id );
								done( messages.savedProfile );
							} }
							onDeleted={ () => {
								setProfile( undefined );
								done( messages.deletedProfile );
							} }
						/>
					)
				}
			</TabPanel>
		</div>
	);
}

interface MappingsTabProps {
	api: MappingsApi;
	state: MappingsState;
	selection: Selection;
	onSelect: ( selection: Selection ) => void;
	onSaved: ( id: number, notices: string[] ) => void;
	onCopied: ( id: number ) => void;
	onDeleted: () => void;
}

function MappingsTab( { api, state, selection, onSelect, onSaved, onCopied, onDeleted }: MappingsTabProps ) {
	const { messages, mappings } = state;

	return (
		<div style={ { display: 'flex', gap: 24, alignItems: 'flex-start', marginTop: 16 } }>
			<div style={ { width: SIDEBAR_WIDTH, flexShrink: 0 } }>
				<Button variant="primary" onClick={ () => onSelect( null ) }>
					{ messages.addMapping }
				</Button>
				{ mappings.length === 0 && <p className="description">{ messages.emptyMappings }</p> }
				<ul>
					{ mappings.map( ( item ) => (
						<li key={ item.id }>
							<Button variant="link" aria-current={ selection === item.id } onClick={ () => onSelect( item.id ) }>
								{ item.name }
							</Button>
							<div className="description">
								{ `${ item.category_label || messages.noCategory } · ${ messages.columnProducts }: ${ item.products }` }
							</div>
						</li>
					) ) }
				</ul>
			</div>
			<div style={ { flex: 1, minWidth: 0 } }>
				{ selection === undefined ? (
					<p className="description">{ messages.chooseMapping }</p>
				) : (
					<MappingEditor
						key={ selection ?? 'new' }
						api={ api }
						state={ state }
						mappingId={ selection }
						onSaved={ onSaved }
						onCopied={ onCopied }
						onDeleted={ onDeleted }
					/>
				) }
			</div>
		</div>
	);
}

interface ProfilesTabProps {
	api: MappingsApi;
	state: MappingsState;
	selection: Selection;
	onSelect: ( selection: Selection ) => void;
	onSaved: ( id: number ) => void;
	onDeleted: () => void;
}

function ProfilesTab( { api, state, selection, onSelect, onSaved, onDeleted }: ProfilesTabProps ) {
	const { messages, profiles } = state;

	return (
		<div style={ { display: 'flex', gap: 24, alignItems: 'flex-start', marginTop: 16 } }>
			<div style={ { width: SIDEBAR_WIDTH, flexShrink: 0 } }>
				<Button variant="primary" onClick={ () => onSelect( null ) }>
					{ messages.addProfile }
				</Button>
				{ profiles.length === 0 && <p className="description">{ messages.emptyProfiles }</p> }
				<ul>
					{ profiles.map( ( item ) => (
						<li key={ item.id }>
							<Button variant="link" aria-current={ selection === item.id } onClick={ () => onSelect( item.id ) }>
								{ item.name }
							</Button>
							<div className="description">{ `${ messages.columnMappings }: ${ item.mappings }` }</div>
						</li>
					) ) }
				</ul>
			</div>
			<div style={ { flex: 1, minWidth: 0 } }>
				{ selection === undefined ? (
					<p className="description">{ messages.chooseProfile }</p>
				) : (
					<ProfileEditor
						key={ selection ?? 'new' }
						api={ api }
						state={ state }
						profileId={ selection }
						onSaved={ onSaved }
						onDeleted={ onDeleted }
					/>
				) }
			</div>
		</div>
	);
}
