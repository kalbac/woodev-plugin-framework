/**
 * One titled card of a settings section: its fields, then its actions as buttons in a single row
 * with ONE shared result line and the group's notice shown once.
 *
 * `Settings_Group` on the PHP side names the members; `SectionView` decides where the card goes and hands
 * the already-rendered fields in as `children`, so validation, `show_if` and tooltips stay with ControlField.
 * Not to be confused with `.woodev-field__option-group` — the inner card of ONE toggle field.
 *
 * Pure helpers are exported for the unit tests.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { RawHTML, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { runTool } from './rest';

/** A group as the registry serialises it (`Settings_Page_Registry::build_groups()`). */
export interface GroupSchema {
	id: string;
	title: string;
	description: string;
	notice: string;
	fields: string[];
	actions: string[];
}

/** The slice of `Shipping_Tool::to_array()` a group button needs. */
export interface ActionSchema {
	id: string;
	button: string;
	disabled: boolean;
	status_text: string;
}

interface ToolResult {
	success: boolean;
	message: string;
}

/**
 * Splits a section's fields into the groups' cards and the ungrouped rest, keeping declaration order.
 *
 * A group sits where its first VISIBLE member is declared; a group with no visible field comes after every
 * ungrouped field. Pure — `SectionView` renders the result.
 *
 * @param {string[]}      visibleIds fields to render, in declaration order.
 * @param {GroupSchema[]} groups     the section's groups.
 * @return {Array<{ kind: 'field', id: string } | { kind: 'group', group: GroupSchema, fieldIds: string[] }>} render order.
 */
export function arrangeFields(
	visibleIds: string[],
	groups: GroupSchema[]
): Array< { kind: 'field'; id: string } | { kind: 'group'; group: GroupSchema; fieldIds: string[] } > {
	const groupOf: Record< string, GroupSchema > = {};
	groups.forEach( ( group ) => group.fields.forEach( ( id ) => {
		groupOf[ id ] = groupOf[ id ] || group;
	} ) );

	const placed = new Set< string >();
	const out: Array< { kind: 'field'; id: string } | { kind: 'group'; group: GroupSchema; fieldIds: string[] } > = [];

	visibleIds.forEach( ( id ) => {
		const group = groupOf[ id ];
		if ( ! group ) {
			out.push( { kind: 'field', id } );
			return;
		}
		if ( placed.has( group.id ) ) {
			return;
		}
		placed.add( group.id );
		out.push( { kind: 'group', group, fieldIds: visibleIds.filter( ( other ) => groupOf[ other ] === group ) } );
	} );

	groups.forEach( ( group ) => {
		if ( ! placed.has( group.id ) ) {
			out.push( { kind: 'group', group, fieldIds: [] } );
		}
	} );

	return out;
}

/**
 * The status lines to show once under a group's buttons: the group's own notice, then the distinct
 * `status_text` of its disabled actions (the reason they are disabled), without repeats.
 *
 * @param {GroupSchema}    group   the group.
 * @param {ActionSchema[]} actions the group's actions.
 * @return {string[]} lines, empty ones dropped.
 */
export function groupNotices( group: GroupSchema, actions: ActionSchema[] ): string[] {
	const lines = [ group.notice ];
	actions.forEach( ( action ) => {
		if ( action.disabled ) {
			lines.push( action.status_text );
		}
	} );

	return lines.filter( ( line, index ) => '' !== line && lines.indexOf( line ) === index );
}

interface GroupCardProps {
	providerId: string;
	group: GroupSchema;
	/** The group's actions, resolved from the section's flat list. */
	actions: ActionSchema[];
	/** The group's rendered fields. */
	children?: ReactNode;
	/** Runs one action; defaults to the REST tool route. The UI-kit gallery passes a stub. */
	onRun?: ( actionId: string ) => Promise< unknown >;
}

export default function GroupCard( { providerId, group, actions, children, onRun }: GroupCardProps ) {
	const [ busyId, setBusyId ] = useState< string >( '' );
	const [ result, setResult ] = useState< ToolResult | null >( null );
	const notices = groupNotices( group, actions );

	const run = ( action: ActionSchema ) => {
		// Busy + result-clear happen before the request starts, like ToolCard — a live call takes seconds.
		setBusyId( action.id );
		setResult( null );

		( onRun ? onRun( action.id ) : runTool( providerId, action.id, {} ) )
			.then( ( res: unknown ) => setResult( res as ToolResult ) )
			.catch( ( err: { message?: string } ) =>
				setResult( {
					success: false,
					message: ( err && err.message ) || __( 'Ошибка выполнения.', 'woodev-plugin-framework' ),
				} )
			)
			.finally( () => setBusyId( '' ) );
	};

	return (
		<div className="woodev-group">
			<h3 className="woodev-group__title">{ group.title }</h3>
			{ group.description && (
				<div className="woodev-group__desc"><RawHTML>{ group.description }</RawHTML></div>
			) }
			{ children }
			{ actions.length > 0 && (
				<div className="woodev-group__actions">
					{ actions.map( ( action ) => (
						<Button
							key={ action.id }
							variant="secondary"
							isBusy={ busyId === action.id }
							disabled={ '' !== busyId || action.disabled }
							onClick={ () => run( action ) }
						>
							{ action.button }
						</Button>
					) ) }
				</div>
			) }
			{ notices.map( ( line ) => (
				<p key={ line } className="woodev-group__notice">{ line }</p>
			) ) }
			{ result && (
				<div className={ `woodev-group__result is-${ result.success ? 'ok' : 'error' }` }>
					{ result.message }
				</div>
			) }
		</div>
	);
}
