/**
 * The «Инструменты» page: pick an account, preview a tool, confirm it, read the result.
 *
 * Every sentence comes from the server's `messages`; a running background run on the account is read again every few
 * seconds until it ends, and disables the tools that take the claim.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import type { PageViewProps } from '../../types';
import { errorLines } from '../mappings/api';
import { createToolsApi } from './api';
import { canConfirm, canPreview, chooseAccount, isBusy } from './tool-state';
import type { ToolPreview, ToolRow, ToolsState } from './types';

const POLL_MS = 5000;

interface NoticeState {
	status: 'success' | 'error';
	lines: string[];
}

export function ToolsView( { menu }: PageViewProps ) {
	const api = useMemo( () => createToolsApi( menu.pluginId ), [ menu.pluginId ] );
	const [ state, setState ] = useState< ToolsState | null >( null );
	const [ accountId, setAccountId ] = useState< number | null >( null );
	const [ reloads, setReloads ] = useState( 0 );
	const [ preview, setPreview ] = useState< ToolPreview | null >( null );
	const [ notice, setNotice ] = useState< NoticeState | null >( null );
	const [ pending, setPending ] = useState( false );

	useEffect( () => {
		let cancelled = false;

		api.state( accountId )
			.then( ( next ) => {
				if ( cancelled ) {
					return;
				}
				setState( next );
				setAccountId( ( current ) => chooseAccount( next.accounts, current ) );
			} )
			.catch( ( error ) => {
				if ( ! cancelled ) {
					setNotice( { status: 'error', lines: errorLines( error, '' ) } );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ api, accountId, reloads ] );

	useEffect( () => {
		if ( ! isBusy( state?.activity ?? null ) ) {
			return undefined;
		}

		const timer = window.setTimeout( () => setReloads( ( count ) => count + 1 ), POLL_MS );

		return () => window.clearTimeout( timer );
	}, [ state ] );

	if ( ! state ) {
		return notice?.status === 'error' ? <Notice status="error" isDismissible={ false }>{ notice.lines.join( ' ' ) }</Notice> : <Spinner />;
	}

	const { messages } = state;

	const openPreview = ( tool: ToolRow ) => {
		if ( accountId === null ) {
			return;
		}

		setPending( true );
		api.preview( tool.id, accountId )
			.then( setPreview )
			.catch( ( error ) => setNotice( { status: 'error', lines: errorLines( error, messages.loadFailed ) } ) )
			.finally( () => setPending( false ) );
	};

	const run = ( tool: ToolRow ) => {
		if ( accountId === null ) {
			return;
		}

		setPending( true );
		api.run( tool.id, accountId )
			.then( ( result ) => {
				setNotice( { status: result.success ? 'success' : 'error', lines: [ result.message ] } );
				setPreview( null );
				setReloads( ( count ) => count + 1 );
			} )
			.catch( ( error ) => {
				setNotice( { status: 'error', lines: errorLines( error, messages.runFailed ) } );
				setReloads( ( count ) => count + 1 );
			} )
			.finally( () => setPending( false ) );
	};

	const accountOptions = state.accounts.map( ( account ) => ( { label: account.name, value: String( account.id ) } ) );

	return (
		<div className="woodev-tools-view" style={ { paddingRight: 20 } }>
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.lines.join( ' ' ) }
				</Notice>
			) }
			{ isBusy( state.activity ) && <Notice status="warning" isDismissible={ false }>{ state.activity.text }</Notice> }
			<SelectControl
				label={ messages.accountLabel }
				value={ accountId === null ? '' : String( accountId ) }
				options={ accountOptions.length > 0 ? accountOptions : [ { label: messages.chooseAccount, value: '' } ] }
				onChange={ ( value ) => {
					setAccountId( value === '' ? null : Number( value ) );
					setPreview( null );
				} }
			/>
			{ accountId === null && <p>{ messages.chooseAccount }</p> }
			{ state.tools.length === 0 && <p>{ messages.noTools }</p> }
			{ state.tools.map( ( tool ) => (
				<section key={ tool.id } className="woodev-tools-view__tool" data-tool={ tool.id }>
					<h3>{ tool.title }</h3>
					<p>{ tool.description }</p>
					{ tool.disabled && <Notice status="warning" isDismissible={ false }>{ tool.disabled_reason }</Notice> }
					<Button variant="secondary" disabled={ ! canPreview( tool, accountId ) || pending } onClick={ () => openPreview( tool ) }>
						{ messages.previewButton }
					</Button>
					{ preview?.tool === tool.id && (
						<div className="woodev-tools-view__preview">
							<p>{ preview.summary }</p>
							<Button variant="primary" disabled={ ! canConfirm( preview, tool ) || pending } onClick={ () => run( tool ) }>
								{ pending ? messages.running : preview.confirm_label }
							</Button>{ ' ' }
							<Button variant="tertiary" onClick={ () => setPreview( null ) }>
								{ messages.cancelButton }
							</Button>
						</div>
					) }
				</section>
			) ) }
		</div>
	);
}
