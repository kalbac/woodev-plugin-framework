/**
 * The dialog of one action. It shows, in order: how far the action reaches (the server's scope sentence — for an
 * account-wide action that is the first thing on screen), the input fields, the plan with its counts and reasons,
 * and finally the result with the real counts and every reason the server kept.
 *
 * Nothing here is sent by a render or an effect; the buttons call the flow. While a request is open the buttons are
 * disabled and the dialog cannot be dismissed, so a second submit cannot start.
 *
 * @package woodev-plugin-framework
 */

import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import type { TableHandle } from '../extensions';
import type { Messages } from '../types';
import { isOwnDownloadUrl } from './download';
import { FieldsForm } from './fields';
import { actionMessage, ACTION_MESSAGE_KEYS, type ActionMessageKey } from './messages';
import { Counts, Groups, Warnings } from './report';
import { STYLES } from './styles';
import { needsPreview, type ActionFlow, type FlowState } from './use-action-flow';
import type { ApplyResponse, ApplyStatus } from './types';

const STATUS_KEYS: Record< ApplyStatus, ActionMessageKey > = {
	done: ACTION_MESSAGE_KEYS.statusDone,
	partial: ACTION_MESSAGE_KEYS.statusPartial,
	queued: ACTION_MESSAGE_KEYS.statusQueued,
	failed: ACTION_MESSAGE_KEYS.statusFailed,
};

export interface ActionDialogProps {
	flow: ActionFlow;
	table: TableHandle;
}

function Busy( { text }: { text: string } ) {
	return (
		<div role="status" aria-busy="true" style={ STYLES.busy }>
			<Spinner />
			<span>{ text }</span>
		</div>
	);
}

function ResultView( { result, messages }: { result: ApplyResponse; messages: Messages } ) {
	const download = result.download;
	const allowed = download !== undefined && isOwnDownloadUrl( download.url, download.origins );

	return (
		<div className="woodev-records__result" data-status={ result.status }>
			<p role="status">
				<strong>{ actionMessage( messages, STATUS_KEYS[ result.status ] ) }</strong>{ ' ' }
				{ /* Only the apply's own sentence: the plan's summary promised what the plan saw, not what happened. */ }
				{ result.messages.summary ?? '' }
			</p>
			<Counts
				messages={ messages }
				entries={ [
					{ key: ACTION_MESSAGE_KEYS.requested, value: result.requested },
					{ key: ACTION_MESSAGE_KEYS.accepted, value: result.accepted },
					{ key: ACTION_MESSAGE_KEYS.skipped, value: result.skipped },
					{ key: ACTION_MESSAGE_KEYS.failed, value: result.failed },
				] }
			/>
			<Groups groups={ result.groups } messages={ messages } />
			{ download && allowed && (
				<p>
					<a href={ download.url } download={ download.filename === '' ? undefined : download.filename }>
						{ actionMessage( messages, ACTION_MESSAGE_KEYS.download ) || download.filename }
					</a>
				</p>
			) }
			{ download && ! allowed && <p role="alert">{ actionMessage( messages, ACTION_MESSAGE_KEYS.downloadBlocked ) }</p> }
		</div>
	);
}

function PlanView( { state, messages }: { state: FlowState; messages: Messages } ) {
	const plan = state.plan;
	if ( ! plan ) {
		return null;
	}

	return (
		<div className="woodev-records__plan">
			{ plan.messages.summary && <p role="status">{ plan.messages.summary }</p> }
			<Counts
				messages={ messages }
				entries={ [
					{ key: ACTION_MESSAGE_KEYS.requested, value: plan.requested },
					{ key: ACTION_MESSAGE_KEYS.eligible, value: plan.eligible },
					{ key: ACTION_MESSAGE_KEYS.skipped, value: plan.skipped },
				] }
			/>
			<Warnings warnings={ plan.warnings } messages={ messages } />
			<Groups groups={ plan.groups } messages={ messages } />
		</div>
	);
}

function FailureView( { state, messages }: { state: FlowState; messages: Messages } ) {
	const failure = state.failure;
	if ( ! failure ) {
		return null;
	}

	// No answer from an apply: it may have run. The table was read again; say so, and do not offer to send it again.
	const text =
		failure.kind === 'unknown'
			? actionMessage( messages, ACTION_MESSAGE_KEYS.recovery )
			: failure.message || actionMessage( messages, ACTION_MESSAGE_KEYS.error );

	return (
		<Notice status={ failure.kind === 'conflict' ? 'warning' : 'error' } isDismissible={ false }>
			{ text }
		</Notice>
	);
}

/** Which of the two forward buttons a stage shows: ask for the plan, or run the action. */
function forwardButton( state: FlowState ): 'preview' | 'confirm' | null {
	const { stage, action } = state;

	if ( stage === 'planning' || ( stage === 'input' && needsPreview( action ) ) ) {
		return 'preview';
	}

	return stage === 'input' || stage === 'previewed' || stage === 'applying' ? 'confirm' : null;
}

function Footer( { flow, table, messages }: ActionDialogProps & { messages: Messages } ) {
	const state = flow.state as FlowState;
	const { action, stage, plan } = state;
	const busy = stage === 'planning' || stage === 'applying';
	const forward = forwardButton( state );
	const confirmLabel = plan?.confirm_label || action.confirm_label || action.label;
	const cancel = actionMessage( messages, ACTION_MESSAGE_KEYS.cancel ) || action.label;
	const close = actionMessage( messages, ACTION_MESSAGE_KEYS.close ) || cancel;

	if ( stage === 'done' || ( stage === 'failed' && state.failedAt === 'apply' ) ) {
		return (
			<Button variant="primary" onClick={ flow.close }>
				{ close }
			</Button>
		);
	}

	return (
		<>
			<Button variant="tertiary" disabled={ stage === 'applying' } accessibleWhenDisabled onClick={ flow.close }>
				{ cancel }
			</Button>
			{ stage === 'failed' && (
				<Button variant="secondary" onClick={ () => void flow.preview( table ) }>
					{ actionMessage( messages, ACTION_MESSAGE_KEYS.retry ) || confirmLabel }
				</Button>
			) }
			{ forward === 'preview' && (
				<Button variant="primary" disabled={ busy } accessibleWhenDisabled onClick={ () => void flow.preview( table ) }>
					{ actionMessage( messages, ACTION_MESSAGE_KEYS.preview ) || confirmLabel }
				</Button>
			) }
			{ forward === 'confirm' && (
				<Button
					variant="primary"
					isDestructive={ action.tone === 'danger' }
					disabled={ busy || ( stage === 'previewed' && plan?.can_apply !== true ) }
					accessibleWhenDisabled
					isBusy={ stage === 'applying' }
					onClick={ () => void flow.confirm( table ) }
				>
					{ confirmLabel }
				</Button>
			) }
		</>
	);
}

export function ActionDialog( { flow, table }: ActionDialogProps ) {
	const state = flow.state;
	const account = table.context.account_id;

	// Every render says which table scope is on screen, so an answer from another one is not applied to it.
	useEffect( () => flow.observe( table ), [ flow, table ] );

	// The ids and fields belong to the account the flow started in.
	useEffect( () => {
		if ( state && state.stage !== 'applying' && state.account !== account ) {
			flow.close();
		}
	}, [ state, account, flow ] );

	if ( ! state ) {
		return null;
	}

	const { action, stage, plan, result } = state;
	const messages: Messages = { ...table.messages, ...( plan?.messages ?? {} ), ...( result?.messages ?? {} ) };
	const scope = plan?.scope.sentence || action.scope_sentence;
	const showForm = stage === 'input' || stage === 'planning' || stage === 'previewed' || ( stage === 'applying' && action.fields.length > 0 );

	return (
		<Modal
			title={ action.label }
			onRequestClose={ flow.close }
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ stage !== 'applying' }
			isDismissible={ stage !== 'applying' }
		>
			<div className="woodev-records__dialog" data-action={ action.id } data-stage={ stage } aria-busy={ stage === 'planning' || stage === 'applying' }>
				{ scope !== '' && (
					<p className="woodev-records__scope" data-scope={ action.scope } style={ STYLES.scope }>
						{ scope }
					</p>
				) }
				{ showForm && (
					<FieldsForm
						table={ table }
						messages={ messages }
						action={ action.id }
						rowId={ state.rowId }
						fields={ action.fields }
						values={ state.payload }
						errors={ state.fieldErrors }
						disabled={ stage === 'planning' || stage === 'applying' }
						onChange={ flow.edit }
					/>
				) }
				{ stage === 'input' && state.failure?.kind === 'fields' && <FailureView state={ state } messages={ messages } /> }
				{ stage === 'planning' && <Busy text={ actionMessage( messages, ACTION_MESSAGE_KEYS.planning ) } /> }
				{ stage === 'previewed' && <PlanView state={ state } messages={ messages } /> }
				{ stage === 'applying' && <Busy text={ actionMessage( messages, ACTION_MESSAGE_KEYS.applying ) } /> }
				{ stage === 'done' && result && <ResultView result={ result } messages={ messages } /> }
				{ stage === 'failed' && <FailureView state={ state } messages={ messages } /> }
				<div className="woodev-records__dialog-footer" style={ STYLES.footer }>
					<Footer flow={ flow } table={ table } messages={ messages } />
				</div>
			</div>
		</Modal>
	);
}
