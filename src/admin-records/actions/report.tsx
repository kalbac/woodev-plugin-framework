/**
 * The server's report: counts, warnings and the reasons behind every skipped or failed row.
 *
 * Every sentence is PHP's. A group is told apart by its heading and its place in the list, not by colour alone.
 *
 * @package woodev-plugin-framework
 */

import type { Messages } from '../types';
import { isLinkUrl } from './download';
import { actionMessage, ACTION_MESSAGE_KEYS, type ActionMessageKey } from './messages';
import { STYLES } from './styles';
import type { GroupKind, ReportGroup } from './types';

const GROUP_HEADINGS: Record< GroupKind, ActionMessageKey > = {
	skipped: ACTION_MESSAGE_KEYS.groupSkipped,
	failed: ACTION_MESSAGE_KEYS.groupFailed,
	warning: ACTION_MESSAGE_KEYS.groupWarning,
};

export interface CountEntry {
	key: ActionMessageKey;
	value: number;
}

/** Labelled numbers. A count with no PHP label still shows its number. */
export function Counts( { entries, messages }: { entries: CountEntry[]; messages: Messages } ) {
	return (
		<dl className="woodev-records__counts" style={ STYLES.counts }>
			{ entries.map( ( { key, value } ) => (
				<div key={ key } style={ STYLES.count }>
					<dt>{ actionMessage( messages, key ) }</dt>
					<dd style={ STYLES.countValue }>{ value }</dd>
				</div>
			) ) }
		</dl>
	);
}

function Group( { group, messages }: { group: ReportGroup; messages: Messages } ) {
	const heading = actionMessage( messages, GROUP_HEADINGS[ group.kind ] );

	return (
		<li className="woodev-records__group" data-kind={ group.kind } style={ STYLES.group }>
			{ heading !== '' && <strong style={ STYLES.groupHeading }>{ heading }: </strong> }
			<span>{ group.reason }</span> <span>({ group.count })</span>
			{ group.fix_url !== '' && isLinkUrl( group.fix_url ) && (
				<>
					{ ' ' }
					<a href={ group.fix_url } target="_blank" rel="noopener noreferrer">
						{ group.fix_label }
					</a>
				</>
			) }
			{ group.examples.length > 0 && <span style={ STYLES.examples }> { group.examples.join( ', ' ) }</span> }
		</li>
	);
}

export function Groups( { groups, messages }: { groups: ReportGroup[]; messages: Messages } ) {
	if ( groups.length === 0 ) {
		return null;
	}

	return (
		<ul className="woodev-records__groups" style={ STYLES.groups }>
			{ groups.map( ( group, index ) => (
				<Group key={ `${ group.kind }-${ index }` } group={ group } messages={ messages } />
			) ) }
		</ul>
	);
}

export function Warnings( { warnings, messages }: { warnings: string[]; messages: Messages } ) {
	if ( warnings.length === 0 ) {
		return null;
	}

	const heading = actionMessage( messages, ACTION_MESSAGE_KEYS.warnings );

	return (
		<div className="woodev-records__warnings">
			{ heading !== '' && <strong>{ heading }</strong> }
			<ul style={ STYLES.groups }>
				{ warnings.map( ( warning, index ) => (
					<li key={ index }>{ warning }</li>
				) ) }
			</ul>
		</div>
	);
}
