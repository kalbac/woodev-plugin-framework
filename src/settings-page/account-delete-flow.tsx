/**
 * Deleting an account: preview → confirm by typing the identifier → progress → done.
 *
 * The server owns the rules and the copy (`Account_Deletion`): this dialog reads the preview, sends the confirmation and polls the
 * progress of the background job. Closing the dialog never stops the job; a reopened one picks the progress up again.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner, TextControl } from '@wordpress/components';
import type { AccountCardSchema } from './accounts-view';
import { fetchDeletion, startDeletion, type DeletionProgress, type DeletionState } from './deletion-rest';

/** How often the progress is read while the job runs, in milliseconds. */
const POLL_MS = 2000;

type Phase = 'loading' | 'preview' | 'starting' | 'progress' | 'done' | 'error';

/**
 * Which screen a state belongs to.
 *
 * @param {DeletionState} state what the server said.
 * @return {Phase} the screen.
 */
export function phaseOf( state: DeletionState ): Phase {
	if ( state.deleted ) {
		return 'done';
	}

	if ( state.deletion && 'running' === state.deletion.state ) {
		return 'progress';
	}

	return state.can_delete ? 'preview' : 'error';
}

/**
 * Whether what the merchant typed confirms the deletion: exactly the account's identifier.
 *
 * @param {string} typed      the input.
 * @param {string} identifier the account's identifier.
 * @return {boolean} true when they match.
 */
export function confirms( typed: string, identifier: string ): boolean {
	return '' !== identifier && typed.trim() === identifier;
}

function messageOf( error: unknown ): string {
	const message = ( error as { message?: unknown } | null )?.message;

	return typeof message === 'string' && '' !== message ? message : __( 'Не удалось выполнить запрос. Повторите позже.', 'woodev-plugin-framework' );
}

function Removed( { progress }: { progress: DeletionProgress } ) {
	const rows = Object.entries( progress.removed ).filter( ( [ , count ] ) => count > 0 );

	if ( 0 === rows.length ) {
		return null;
	}

	return (
		<p className="woodev-account-delete__removed">
			{ __( 'Удалено строк:', 'woodev-plugin-framework' ) } { rows.reduce( ( sum, [ , count ] ) => sum + count, 0 ) }
		</p>
	);
}

interface AccountDeleteFlowProps {
	card: AccountCardSchema;
	/** Called when the dialog is closed, whatever the phase: the page reloads the cards. */
	onClose: () => void;
}

/**
 * The deletion dialog of one account.
 *
 * @param {AccountDeleteFlowProps} props the props.
 */
export default function AccountDeleteFlow( { card, onClose }: AccountDeleteFlowProps ) {
	const url = card.delete_url || '';
	const [ phase, setPhase ] = useState< Phase >( 'loading' );
	const [ state, setState ] = useState< DeletionState | null >( null );
	const [ typed, setTyped ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const apply = ( next: DeletionState ) => {
		setState( next );
		setPhase( phaseOf( next ) );
	};

	// Read the state once when the dialog opens.
	useEffect( () => {
		let live = true;

		fetchDeletion( url )
			.then( ( next ) => live && apply( next ) )
			.catch( ( err ) => {
				if ( live ) {
					setError( messageOf( err ) );
					setPhase( 'error' );
				}
			} );

		return () => {
			live = false;
		};
	}, [ url ] ); // eslint-disable-line react-hooks/exhaustive-deps -- `apply` only sets state.

	// While the job runs, read its progress until it ends.
	useEffect( () => {
		if ( 'progress' !== phase ) {
			return undefined;
		}

		let live = true;
		const timer = window.setTimeout( () => {
			fetchDeletion( url )
				.then( ( next ) => live && apply( next ) )
				.catch( ( err ) => {
					if ( live ) {
						setError( messageOf( err ) );
						// A new state object runs the effect again: the next read is the retry.
						setState( ( previous ) => ( previous ? { ...previous } : previous ) );
					}
				} );
		}, POLL_MS );

		return () => {
			live = false;
			window.clearTimeout( timer );
		};
	}, [ phase, state, url ] ); // eslint-disable-line react-hooks/exhaustive-deps -- `apply` only sets state.

	const start = () => {
		setError( '' );
		setPhase( 'starting' );
		startDeletion( url, typed )
			.then( apply )
			.catch( ( err ) => {
				setError( messageOf( err ) );
				setPhase( 'preview' );
			} );
	};

	const busy = 'starting' === phase;

	return (
		<Modal
			title={ __( 'Удаление аккаунта', 'woodev-plugin-framework' ) }
			onRequestClose={ busy ? () => undefined : onClose }
			shouldCloseOnClickOutside={ false }
			className="woodev-account-delete"
		>
			{ 'loading' === phase && (
				<p role="status" aria-busy="true">
					<Spinner /> { __( 'Загрузка…', 'woodev-plugin-framework' ) }
				</p>
			) }

			{ 'error' === phase && (
				<>
					<Notice status="warning" isDismissible={ false }>
						{ error || ( state && state.refusal ) || __( 'Аккаунт сейчас нельзя удалить.', 'woodev-plugin-framework' ) }
					</Notice>
					<div className="woodev-account-delete__actions">
						<Button variant="secondary" onClick={ onClose }>{ __( 'Закрыть', 'woodev-plugin-framework' ) }</Button>
					</div>
				</>
			) }

			{ ( 'preview' === phase || 'starting' === phase ) && state && state.preview && (
				<>
					<p>
						{ __( 'Аккаунт', 'woodev-plugin-framework' ) } <strong>{ card.title }</strong> ({ card.identifier_label }: { card.identifier }) { __( 'будет удалён вместе со своими данными в плагине. Это нельзя отменить.', 'woodev-plugin-framework' ) }
					</p>
					<ul className="woodev-account-delete__counts">
						{ state.preview.counts.map( ( row ) => (
							<li key={ row.key }>{ row.label }: <strong>{ row.count }</strong></li>
						) ) }
					</ul>
					<p>{ state.preview.kept }</p>
					{ state.preview.warnings.map( ( warning ) => (
						<Notice key={ warning } status="warning" isDismissible={ false }>{ warning }</Notice>
					) ) }
					{ state.deletion && 'failed' === state.deletion.state && (
						<Notice status="error" isDismissible={ false }>{ state.deletion.message }</Notice>
					) }
					{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
					<TextControl
						label={ `${ __( 'Чтобы подтвердить, введите', 'woodev-plugin-framework' ) } ${ card.identifier_label }` }
						help={ card.identifier }
						value={ typed }
						disabled={ busy }
						onChange={ setTyped }
						autoComplete="off"
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<div className="woodev-account-delete__actions">
						<Button variant="secondary" disabled={ busy } onClick={ onClose }>{ __( 'Отмена', 'woodev-plugin-framework' ) }</Button>
						<Button variant="primary" isDestructive isBusy={ busy } disabled={ busy || ! confirms( typed, card.identifier ) } onClick={ start }>
							{ __( 'Удалить аккаунт', 'woodev-plugin-framework' ) }
						</Button>
					</div>
				</>
			) }

			{ 'progress' === phase && state && state.deletion && (
				<>
					<p role="status" aria-busy="true">
						<Spinner /> { __( 'Удаляем данные аккаунта. Можно закрыть окно: удаление продолжится в фоне.', 'woodev-plugin-framework' ) }
					</p>
					<Removed progress={ state.deletion } />
					{ error && <Notice status="warning" isDismissible={ false }>{ error }</Notice> }
					<div className="woodev-account-delete__actions">
						<Button variant="secondary" onClick={ onClose }>{ __( 'Закрыть', 'woodev-plugin-framework' ) }</Button>
					</div>
				</>
			) }

			{ 'done' === phase && (
				<>
					<Notice status="success" isDismissible={ false }>{ __( 'Аккаунт удалён.', 'woodev-plugin-framework' ) }</Notice>
					{ ( ( state && state.deletion && state.deletion.notes ) || [] ).map( ( note ) => (
						<Notice key={ note } status="warning" isDismissible={ false }>{ note }</Notice>
					) ) }
					<div className="woodev-account-delete__actions">
						<Button variant="primary" onClick={ onClose }>{ __( 'Готово', 'woodev-plugin-framework' ) }</Button>
					</div>
				</>
			) }
		</Modal>
	);
}
