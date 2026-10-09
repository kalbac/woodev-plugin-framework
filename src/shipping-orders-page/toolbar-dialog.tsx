/**
 * The dialog behind a carrier's toolbar button (s164) — one thing run for several orders («Вызвать курьера»).
 *
 * The carrier declares it server-side (`Toolbar_Actions`) as TABS: one `form` tab (the declared fields plus the orders
 * multi-select, submitted once; the server answers one result per order) and any number of `list` tabs (rows the
 * carrier supplies, each with optional buttons such as «Отменить»). The dialog is fetched when it opens, and replaced
 * by the refreshed one every answer carries, so the list always shows what the last run changed.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Modal, Notice, Spinner, TabPanel } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { FieldControl, useFieldValues } from './action-input-modal';
import { reconcileValues, toPayload } from './action-input';
import { fetchToolbarDialog, performToolbarRowAction, submitToolbarAction } from './rest';
import type {
	OrderActionFieldError,
	ToolbarActionButton,
	ToolbarDialog as ToolbarDialogData,
	ToolbarFormTab,
	ToolbarListTab,
	ToolbarRowAction,
	ToolbarSubmitResult,
} from './rest';

interface RestError {
	message?: string;
	code?: string;
	data?: { errors?: OrderActionFieldError[] };
}

interface ToolbarDialogProps {
	button: ToolbarActionButton;
	onClose: () => void;
	/** Something changed at the carrier: the page refetches its rows and buttons. */
	onChanged: () => void;
}

/** The generic confirmation for a row button that declared none. */
export function rowConfirmQuestion( action: ToolbarRowAction ): string {
	return (
		action.confirm ||
		sprintf(
			/* translators: %s: button label, e.g. "Отменить". */
			__( 'Вы уверены, что хотите выполнить «%s»?', 'woodev-plugin-framework' ),
			action.label
		)
	);
}

export function ToolbarDialog( { button, onClose, onChanged }: ToolbarDialogProps ) {
	const [ dialog, setDialog ] = useState<ToolbarDialogData | null>( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ serverErrors, setServerErrors ] = useState<OrderActionFieldError[]>( [] );
	const [ message, setMessage ] = useState( '' );
	const [ run, setRun ] = useState<ToolbarSubmitResult | null>( null );
	const [ confirming, setConfirming ] = useState<{ tab: ToolbarListTab; rowId: string; action: ToolbarRowAction } | null>( null );
	const [ busyRow, setBusyRow ] = useState( '' );
	const [ listError, setListError ] = useState( '' );
	const mounted = useRef( true );
	// ONE mutation at a time across the form and the list tabs: a second request would race the first on the same
	// address, and whichever answer arrived last would replace the dialog with an older snapshot. The ref closes the
	// gap between a click and the re-render that disables the controls.
	const inFlight = useRef( false );
	const locked = busy || '' !== busyRow;

	const form = dialog ? ( dialog.tabs.find( ( tab ) => 'form' === tab.type ) as ToolbarFormTab | undefined ) : undefined;
	const fields = form ? form.fields : [];
	const { values, setValues, shown, change, check } = useFieldValues( fields, serverErrors );

	useEffect( () => {
		mounted.current = true;

		fetchToolbarDialog( button.id )
			.then( ( res ) => {
				if ( mounted.current ) {
					applyDialog( res.dialog, 'open' );
				}
			} )
			.catch( ( err: RestError ) => {
				if ( mounted.current ) {
					setLoadError( ( err && err.message ) || __( 'Не удалось открыть окно.', 'woodev-plugin-framework' ) );
				}
			} );

		return () => {
			mounted.current = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ button.id ] );

	/** Takes a (re)fetched dialog in; the typed values survive, the offered orders are reconciled. */
	const applyDialog = ( next: ToolbarDialogData | null, source: 'open' | 'run' | 'row' ) => {
		if ( ! next ) {
			// Nothing left to offer. After a run the merchant still gets to read what it did, so the dialog stays
			// open on the per-order summary (with its Close button) instead of vanishing with the results.
			if ( 'run' === source ) {
				setDialog( null );
				return;
			}

			onClose();
			return;
		}

		const nextForm = next.tabs.find( ( tab ) => 'form' === tab.type ) as ToolbarFormTab | undefined;

		setDialog( next );
		setValues( ( current ) => reconcileValues( nextForm ? nextForm.fields : [], 'open' === source ? {} : current ) );
	};

	const submit = ( event: { preventDefault: () => void } ) => {
		event.preventDefault();

		if ( ! form || inFlight.current || locked || ! check() ) {
			return;
		}

		inFlight.current = true;
		setBusy( true );
		setMessage( '' );
		setServerErrors( [] );
		setRun( null );

		submitToolbarAction( button.id, toPayload( fields, values ) )
			.then( ( res ) => {
				inFlight.current = false;

				if ( ! mounted.current ) {
					return;
				}

				setBusy( false );
				setRun( res );
				onChanged();

				if ( res.messages.success ) {
					dispatch( noticesStore ).createSuccessNotice( res.messages.success, { type: 'snackbar' } );
				}
				if ( res.messages.error ) {
					dispatch( noticesStore ).createErrorNotice( res.messages.error, { type: 'snackbar' } );
				}

				// The dialog stays open on every outcome: the per-order summary (a carrier's «Заявка № …» notes
				// included) is the merchant's receipt, and closing it with the dialog would throw it away.
				applyDialog( res.dialog, 'run' );
			} )
			.catch( ( err: RestError ) => {
				inFlight.current = false;

				if ( ! mounted.current ) {
					return;
				}

				setBusy( false );

				if ( err && 'woodev_shipping_orders_invalid_payload' === err.code ) {
					setServerErrors( err.data?.errors ?? [] );
					return;
				}

				setMessage( ( err && err.message ) || __( 'Не удалось выполнить действие.', 'woodev-plugin-framework' ) );
			} );
	};

	const runRowAction = ( tab: ToolbarListTab, rowId: string, action: ToolbarRowAction ) => {
		setConfirming( null );

		if ( inFlight.current || locked ) {
			return;
		}

		inFlight.current = true;
		setListError( '' );
		setBusyRow( `${ rowId }:${ action.action }` );

		performToolbarRowAction( button.id, tab.id, rowId, action.action )
			.then( ( res ) => {
				inFlight.current = false;
				dispatch( noticesStore ).createSuccessNotice( res.message, { type: 'snackbar' } );
				onChanged();

				if ( mounted.current ) {
					setBusyRow( '' );
					applyDialog( res.dialog, 'row' );
				}
			} )
			.catch( ( err: RestError ) => {
				inFlight.current = false;
				const text = ( err && err.message ) || __( 'Не удалось выполнить действие.', 'woodev-plugin-framework' );

				dispatch( noticesStore ).createErrorNotice( text, { type: 'snackbar' } );

				if ( mounted.current ) {
					setBusyRow( '' );
					setListError( text );
				}
			} );
	};

	const onRowAction = ( tab: ToolbarListTab, rowId: string, action: ToolbarRowAction ) => {
		if ( locked ) {
			return;
		}

		if ( action.destructive ) {
			setConfirming( { tab, rowId, action } );
			return;
		}

		runRowAction( tab, rowId, action );
	};

	const renderForm = ( tab: ToolbarFormTab ) => (
		<form className="woodev-action-form woodev-toolbar-dialog__form" onSubmit={ submit } noValidate>
			{ tab.description && <p className="woodev-toolbar-dialog__description">{ tab.description }</p> }
			{ tab.fields.map( ( field ) => (
				<FieldControl
					key={ field.id }
					field={ field }
					value={ values[ field.id ] }
					error={ shown[ field.id ] }
					disabled={ locked }
					onChange={ ( value ) => change( field.id, value ) }
				/>
			) ) }
			{ message && (
				<Notice status="error" isDismissible={ false } className="woodev-action-form__message">
					{ message }
				</Notice>
			) }
			<div className="woodev-action-form__buttons">
				<Button variant="tertiary" onClick={ onClose } disabled={ locked }>
					{ __( 'Отмена', 'woodev-plugin-framework' ) }
				</Button>
				<Button variant="primary" type="submit" isBusy={ busy } disabled={ locked }>
					{ tab.submit_label || ( dialog ? dialog.title : button.label ) }
				</Button>
			</div>
		</form>
	);

	const renderList = ( tab: ToolbarListTab ) => (
		<div className="woodev-toolbar-dialog__list">
			{ listError && (
				<Notice status="error" isDismissible onRemove={ () => setListError( '' ) }>
					{ listError }
				</Notice>
			) }
			{ 0 === tab.rows.length ? (
				<p className="woodev-toolbar-dialog__empty">
					{ tab.empty || __( 'Пока ничего нет.', 'woodev-plugin-framework' ) }
				</p>
			) : (
				<table className="woodev-toolbar-dialog__table">
					<thead>
						<tr>
							{ tab.columns.map( ( column ) => (
								<th key={ column.id } scope="col">
									{ column.label }
								</th>
							) ) }
							<th scope="col">
								<span className="screen-reader-text">{ __( 'Действия', 'woodev-plugin-framework' ) }</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ tab.rows.map( ( row ) => (
							<tr key={ row.id }>
								{ tab.columns.map( ( column ) => (
									<td key={ column.id }>{ row.cells[ column.id ] }</td>
								) ) }
								<td className="woodev-toolbar-dialog__row-actions">
									{ row.actions.map( ( action ) => (
										<Button
											key={ action.action }
											variant="secondary"
											size="small"
											isDestructive={ action.destructive }
											label={ action.title || undefined }
											isBusy={ busyRow === `${ row.id }:${ action.action }` }
											disabled={ locked }
											onClick={ () => onRowAction( tab, row.id, action ) }
										>
											{ action.label }
										</Button>
									) ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);

	const renderTab = ( name: string ) => {
		const tab = dialog?.tabs.find( ( candidate ) => candidate.id === name );

		if ( ! tab ) {
			return null;
		}

		return 'form' === tab.type ? renderForm( tab ) : renderList( tab );
	};

	return (
		<>
			<Modal
				title={ dialog ? dialog.title : button.label }
				onRequestClose={ locked ? () => undefined : onClose }
				className="woodev-toolbar-dialog"
				size="medium"
			>
				{ loadError && (
					<>
						<Notice status="error" isDismissible={ false }>
							{ loadError }
						</Notice>
						<div className="woodev-action-form__buttons">
							<Button variant="tertiary" onClick={ onClose }>
								{ __( 'Закрыть', 'woodev-plugin-framework' ) }
							</Button>
						</div>
					</>
				) }
				{ ! loadError && ! dialog && ! run && (
					<div className="woodev-orders__loading">
						<Spinner />
					</div>
				) }
				{ dialog && dialog.description && <p className="woodev-toolbar-dialog__description">{ dialog.description }</p> }
				{ run && <RunSummary run={ run } onClose={ onClose } disabled={ locked } /> }
				{ dialog && 1 === dialog.tabs.length && renderTab( dialog.tabs[ 0 ].id ) }
				{ dialog && dialog.tabs.length > 1 && (
					<TabPanel
						className="woodev-toolbar-dialog__tabs"
						tabs={ dialog.tabs.map( ( tab ) => ( { name: tab.id, title: tab.label } ) ) }
					>
						{ ( tab ) => renderTab( tab.name ) }
					</TabPanel>
				) }
			</Modal>
			{ confirming && (
				<Modal
					title={ confirming.action.label }
					onRequestClose={ () => setConfirming( null ) }
					className="woodev-orders-actions__confirm"
					size="small"
				>
					<p>{ rowConfirmQuestion( confirming.action ) }</p>
					<div className="woodev-orders-actions__confirm-buttons">
						<Button variant="tertiary" onClick={ () => setConfirming( null ) }>
							{ __( 'Нет', 'woodev-plugin-framework' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => runRowAction( confirming.tab, confirming.rowId, confirming.action ) }
						>
							{ __( 'Да', 'woodev-plugin-framework' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

/** What the last run did, per order — for every outcome, a full success included — with an explicit way out. */
export function RunSummary( { run, onClose, disabled = false }: { run: ToolbarSubmitResult; onClose?: () => void; disabled?: boolean } ) {
	return (
		<div className="woodev-toolbar-dialog__summary">
			{ run.messages.success && (
				<Notice status="success" isDismissible={ false }>
					{ run.messages.success }
				</Notice>
			) }
			{ run.messages.error && (
				<Notice status="error" isDismissible={ false }>
					{ run.messages.error }
				</Notice>
			) }
			<ul className="woodev-toolbar-dialog__results">
				{ run.results.map( ( result ) => (
					<li key={ result.id } className={ result.ok ? 'is-ok' : 'is-failed' }>
						<strong>{ sprintf( __( 'Заказ %s', 'woodev-plugin-framework' ), result.order_number ) }</strong>
						{ result.message ? ` — ${ result.message }` : '' }
					</li>
				) ) }
			</ul>
			{ onClose && (
				<div className="woodev-toolbar-dialog__summary-close">
					<Button variant="secondary" onClick={ onClose } disabled={ disabled }>
						{ __( 'Закрыть', 'woodev-plugin-framework' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
