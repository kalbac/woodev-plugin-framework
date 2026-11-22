/**
 * What the activity part draws: the account line and health notice above the table, and a row's lock and processes
 * inside its actions cell. Every sentence is PHP's; the views add structure only.
 *
 * - The lock reason is visible text, not colour.
 * - A process is PHP's label and text, never a percentage computed from elapsed time. A row with a process in flight
 *   is marked busy by the table itself (`table-model`), not by this view (#1937).
 * - Several processes are listed side by side; one never hides another.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import { ProblemsLink } from '../../components/help-link';
import type { TableHandle } from '../extensions';
import { healthNotice } from './messages';
import type { Ownership } from './ownership';
import { accountBlockedReason, useActivityState, type ActivityState } from './state';
import { ACTIVITY_STYLES } from './styles';
import type { ActivityProcess, RowActivity } from './types';
import { useActivityLoop } from './use-activity-loop';

/** A process's label and PHP's own text; its progress is the busy row's bar, not a bar of its own. */
function ProcessLine( { process }: { process: ActivityProcess } ) {
	return (
		<li className="woodev-records__process" data-state={ process.state } style={ ACTIVITY_STYLES.process }>
			<span>{ process.label }</span>
			{ process.text !== '' && <span>{ process.text }</span> }
		</li>
	);
}

interface RowActivityViewProps {
	activity: RowActivity | undefined;
	/** The lock sentence is already on screen beside a blocked action button; saying it twice is noise. */
	reasonShown: boolean;
}

/** A row's lock sentence and its processes; nothing at all for a row with no activity. */
export function RowActivityView( { activity, reasonShown }: RowActivityViewProps ) {
	if ( ! activity || ( ! activity.locked && activity.reason === '' && activity.processes.length === 0 ) ) {
		return null;
	}

	return (
		<span className="woodev-records__activity" data-locked={ activity.locked } style={ ACTIVITY_STYLES.row }>
			{ activity.reason !== '' && ! reasonShown && (
				<span className="woodev-records__lock" style={ ACTIVITY_STYLES.lock }>
					{ activity.reason }
				</span>
			) }
			{ activity.processes.length > 0 && (
				<ul style={ ACTIVITY_STYLES.processes }>
					{ activity.processes.map( ( process ) => (
						<ProcessLine key={ process.id } process={ process } />
					) ) }
				</ul>
			) }
		</span>
	);
}

interface ActivityLoopProps {
	table: TableHandle;
	state: ActivityState;
	ownership: Ownership;
}

/** Runs the refresh loop and shows the account line and the stale/failed notice. */
export function ActivityLoop( { table, state, ownership }: ActivityLoopProps ) {
	useActivityLoop( table, state, ownership );
	const health = useActivityState( state );
	const notice = healthNotice( table.messages, health );

	const account = health.account;
	const buttons = account?.actions ?? [];
	// A finished run keeps its line for a while with a «Подробнее» button, so buttons alone also earn the line.
	const showLine = account !== null && account.text !== '' && ( account.active || buttons.length > 0 );

	return (
		<>
			{ account && showLine && (
				<div className="woodev-records__account-line" style={ ACTIVITY_STYLES.accountLine }>
					<p role="status" className="woodev-records__account-activity" style={ ACTIVITY_STYLES.account }>
						{ account.text }
					</p>
					{ table.runAccountAction &&
						buttons.map( ( entry ) => (
							<Button
								key={ entry.action }
								variant="secondary"
								data-action={ entry.action }
								disabled={ accountBlockedReason( health, entry.action ) !== '' }
								accessibleWhenDisabled
								onClick={ () => table.runAccountAction?.( entry.action, entry.payload ) }
							>
								{ entry.label }
							</Button>
						) ) }
				</div>
			) }
			{ notice !== '' && (
				<>
					<p
						role="status"
						className="woodev-records__activity-notice"
						data-stale={ health.stale }
						style={ ACTIVITY_STYLES.notice }
					>
						{ notice }
					</p>
					<ProblemsLink status="warning" />
				</>
			) }
		</>
	);
}
