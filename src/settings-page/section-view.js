/**
 * Renders one settings section's fields via the shared ControlField.
 *
 * The section title is shown by the sub-tab nav, so it is not repeated here.
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import ControlField from '../components/control-field';
import ConnectionBlock from './connection-block';
import ToolsBlock from './tools-block';
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

	return (
		<div className="woodev-settings__section">
			{ section.description && (
				<div className="woodev-settings__section-desc"><RawHTML>{ section.description }</RawHTML></div>
			) }
			{ Object.keys( section.fields )
				.filter( ( settingId ) =>
					isFieldVisible( section.fields[ settingId ], conditionValues || values )
				)
				.map( ( settingId ) => (
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
				) ) }
			{ /* Buttons under the fields (Settings_Section::with_actions()) — «Обновить статусы сейчас» and alike. */ }
			{ section.actions && section.actions.length > 0 && (
				<ToolsBlock providerId={ providerId } section={ { tools: section.actions } } />
			) }
		</div>
	);
}
