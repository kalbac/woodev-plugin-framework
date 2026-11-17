/**
 * The «Инструменты» page: preview a tool on an account, confirm it, read the result. The account is picked only when the
 * plugin has two or more; a single account is chosen silently.
 *
 * Each tool is a card of the settings page's look: its title, its description and its button. The button shows the tool's
 * own label and still opens the preview first; nothing runs before the confirm.
 *
 * Every sentence comes from the server's `messages`; a running background run on the account is read again every few
 * seconds until it ends, and disables the tools that take the claim.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Card, CardBody, Notice, SelectControl, Spinner } from '@wordpress/components';
import { ProblemsLink } from '../../../components/help-link';
import FieldRow from '../../../components/field-row';
import type { PageViewProps } from '../../types';
import { errorLines } from '../mappings/api';
import { createToolsApi } from './api';
import { canConfirm, canPreview, chooseAccount, isBusy, needsAccountChoice } from './tool-state';
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
		return notice?.status === 'error' ? <Notice status="error" isDismissible={ false }>{ notice.lines.join( ' ' ) }<ProblemsLink status="error" /></Notice> : <Spinner />;
	}

	const { messages } = state;

	const openPreview = ( tool: ToolRow ) => {
		if ( accountId === null || ! canPreview( tool, accountId ) ) {
			return;
		}

		setPending( true );
		api.preview( tool.id, accountId )
			.then( setPreview )
			.catch( ( error ) => setNotice( { status: 'error', lines: errorLines( error, messages.loadFailed ) } ) )
			.finally( () => setPending( false ) );
	};

	const run = ( tool: ToolRow ) => {
		if ( accountId === null || ! canPreview( tool, accountId ) ) {
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
		<div className="woodev-settings woodev-tools-view">
			{ notice && (
				<Notice status={ notice.status } onRemove={ () => setNotice( null ) }>
					{ notice.lines.join( ' ' ) }
					<ProblemsLink status={ notice.status } />
				</Notice>
			) }
			{ isBusy( state.activity ) && <Notice status="warning" isDismissible={ false }>{ state.activity.text }<ProblemsLink status="warning" /></Notice> }
			{ needsAccountChoice( state.accounts ) && (
				<FieldRow label={ messages.accountLabel }>
					<SelectControl
						__next40pxDefaultSize
						label={ messages.accountLabel }
						hideLabelFromVision
						value={ accountId === null ? '' : String( accountId ) }
						options={ accountOptions }
						onChange={ ( value ) => {
							setAccountId( value === '' ? null : Number( value ) );
							setPreview( null );
						} }
					/>
				</FieldRow>
			) }
			{ accountId === null && <p>{ messages.chooseAccount }</p> }
			{ state.tools.length === 0 && <p>{ messages.noTools }</p> }
			{ state.tools.map( ( tool ) => (
				<Card key={ tool.id } className="woodev-settings__card woodev-tools-view__tool" data-tool={ tool.id }>
					<CardBody>
						<h3 className="woodev-group__title">{ tool.title }</h3>
						<p className="woodev-group__desc">{ tool.description }</p>
						{ tool.disabled && <Notice status="warning" isDismissible={ false }>{ tool.disabled_reason }</Notice> }
						<Button variant="secondary" disabled={ ! canPreview( tool, accountId ) || pending } onClick={ () => openPreview( tool ) }>
							{ tool.button }
						</Button>
						{ preview?.tool === tool.id && (
							<div className="woodev-group woodev-tools-view__preview">
								<p>{ preview.summary }</p>
								<div className="woodev-group__actions">
									<Button variant="primary" disabled={ ! canConfirm( preview, tool ) || pending } onClick={ () => run( tool ) }>
										{ pending ? messages.running : preview.confirm_label }
									</Button>
									<Button variant="tertiary" onClick={ () => setPreview( null ) }>
										{ messages.cancelButton }
									</Button>
								</div>
							</div>
						) }
					</CardBody>
				</Card>
			) ) }
		</div>
	);
}
