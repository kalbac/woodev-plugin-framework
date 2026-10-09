/**
 * Renders one settings section's fields via the shared ControlField.
 *
 * The section title is shown by the sub-tab nav, so it is not repeated here.
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import CarrierBoxesTable from '../components/carrier-boxes-table';
import ControlField from '../components/control-field';
import ConnectionBlock from './connection-block';
import ToolsBlock from './tools-block';
import GroupCard, { arrangeFields } from './group-card';
import { isFieldVisible } from '../components/validate';
import { RawHTML } from '@wordpress/element';

export default function SectionView( { providerId, section, tabFields, values, conditionValues, onFieldChange, onFieldRevert, showErrors, serverErrors } ) {
	if ( ! section ) {
		return null;
	}

	if ( section.is_connection ) {
		return (
			<ConnectionBlock
				providerId={ providerId }
				section={ section }
				values={ values }
				conditionValues={ conditionValues || values }
				onFieldChange={ onFieldChange }
				onFieldRevert={ onFieldRevert }
			/>
		);
	}

	if ( section.is_tools ) {
		return <ToolsBlock providerId={ providerId } section={ section } />;
	}

	const effectiveValues = conditionValues || Object.fromEntries(
		Object.entries( section.fields ).map( ( [ id, field ] ) => [ id, values[ id ] ?? field.value ] )
	);
	const groups = section.groups || [];
	const actions = section.actions || [];
	const groupedFieldIds = new Set( groups.flatMap( ( group ) => group.fields ) );
	const isVisible = ( settingId ) => isFieldVisible(
		section.fields[ settingId ],
		section.fields[ settingId ].box_preset ? effectiveValues : ( conditionValues || values )
	);
	// A preset belongs to its group's card when a group names it; an ungrouped preset stays in the shared
	// table below the fields, exactly as before.
	const visibleIds = Object.keys( section.fields ).filter( ( settingId ) =>
		( ! section.fields[ settingId ].box_preset || groupedFieldIds.has( settingId ) ) && isVisible( settingId )
	);
	const pickPresets = ( ids ) => Object.fromEntries(
		ids.filter( ( settingId ) => section.fields[ settingId ].box_preset ).map( ( settingId ) => [ settingId, section.fields[ settingId ] ] )
	);
	const visibleBoxFields = pickPresets( Object.keys( section.fields ).filter( ( settingId ) => ! groupedFieldIds.has( settingId ) && isVisible( settingId ) ) );
	const renderBoxTable = ( boxFields ) => (
		<CarrierBoxesTable fields={ boxFields } values={ values } onFieldChange={ onFieldChange } serverErrors={ serverErrors } />
	);

	const renderField = ( settingId ) => section.fields[ settingId ].box_preset ? null : (
		<ControlField
			key={ settingId }
			settingId={ settingId }
			schema={ { ...section.fields[ settingId ], serverError: ( serverErrors || {} )[ settingId ] } }
			value={ values[ settingId ] ?? section.fields[ settingId ].value }
			conditionValues={ conditionValues || values }
			providerMismatchBaseline={ tabFields && tabFields.active_provider && tabFields.active_provider.value }
			onChange={ ( next ) => onFieldChange( settingId, next ) }
			hasEdit={ Object.prototype.hasOwnProperty.call( values, settingId ) }
			onRevert={ () => onFieldRevert( settingId ) }
			showErrors={ showErrors }
		/>
	);

	// A group's actions are rendered inside its card; whatever no group took stays in the shared block below.
	const groupedActionIds = new Set( groups.flatMap( ( group ) => group.actions ) );
	const ungroupedActions = actions.filter( ( action ) => ! groupedActionIds.has( action.id ) );

	return (
		<div className="woodev-settings__section">
			{ section.description && (
				<div className="woodev-settings__section-desc"><RawHTML>{ section.description }</RawHTML></div>
			) }
			{ arrangeFields( visibleIds, groups ).map( ( item ) => {
				if ( 'field' === item.kind ) {
					return renderField( item.id );
				}
				const groupActions = actions.filter( ( action ) => item.group.actions.includes( action.id ) );
				if ( 0 === item.fieldIds.length && 0 === groupActions.length ) {
					return null; // every member is hidden by show_if — no empty card.
				}
				return (
					<GroupCard key={ `group:${ item.group.id }` } providerId={ providerId } group={ item.group } actions={ groupActions }>
						{ item.fieldIds.map( renderField ) }
						{ renderBoxTable( pickPresets( item.fieldIds ) ) }
					</GroupCard>
				);
			} ) }
			{ renderBoxTable( visibleBoxFields ) }
			{ /* Buttons under the fields (Settings_Section::with_actions()) — «Обновить статусы сейчас» and alike. */ }
			{ ungroupedActions.length > 0 && (
				<ToolsBlock providerId={ providerId } section={ { tools: ungroupedActions } } />
			) }
		</div>
	);
}
