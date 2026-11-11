/**
 * What the activity part draws: the account line and health notice above the table, and a row's lock and processes
 * inside its actions cell. Every sentence is PHP's; the views add structure only.
 *
 * - The lock reason is visible text, not colour.
 * - A process with no measurable total gets an indeterminate indicator (`<progress>` without a value) and PHP's own
 *   text — never a percentage computed from elapsed time.
 * - Several processes are listed side by side; one never hides another.
 *
 * @package woodev-plugin-framework
 */

import type { TableHandle } from '../extensions';
import { healthNotice } from './messages';
import type { Ownership } from './ownership';
import { useActivityState, type ActivityState } from './state';
import { ACTIVITY_STYLES } from './styles';
import type { ActivityProcess, RowActivity } from './types';
import { useActivityLoop } from './use-activity-loop';

function ProcessLine( { process }: { process: ActivityProcess } ) {
	const measured = process.total !== null && process.completed !== null;

	return (
		<li className="woodev-records__process" data-state={ process.state } style={ ACTIVITY_STYLES.process }>
			<span>{ process.label }</span>
			{ ( process.state === 'queued' || process.state === 'running' || process.state === 'waiting' ) && (
				<progress
					aria-label={ process.label }
					style={ ACTIVITY_STYLES.meter }
					// Without both counts the attribute is absent: the browser draws an indeterminate indicator.
					value={ measured ? process.completed ?? undefined : undefined }
					max={ measured ? process.total ?? undefined : undefined }
				/>
			) }
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

	return (
		<>
			{ health.account?.active && health.account.text !== '' && (
				<p role="status" className="woodev-records__account-activity" style={ ACTIVITY_STYLES.account }>
					{ health.account.text }
				</p>
			) }
			{ notice !== '' && (
				<p
					role="status"
					className="woodev-records__activity-notice"
					data-stale={ health.stale }
					style={ ACTIVITY_STYLES.notice }
				>
					{ notice }
				</p>
			) }
		</>
	);
}
