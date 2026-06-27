/**
 * Renders one settings section's fields via the shared ControlField.
 *
 * The section title is shown by the sub-tab nav, so it is not repeated here.
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import ControlField from '../components/control-field';

export default function SectionView( { section, values, onFieldChange } ) {
	if ( ! section ) {
		return null;
	}

	return (
		<div className="woodev-settings__section">
			{ Object.keys( section.fields ).map( ( settingId ) => (
				<ControlField
					key={ settingId }
					schema={ section.fields[ settingId ] }
					value={ values[ settingId ] ?? section.fields[ settingId ].value }
					onChange={ ( next ) => onFieldChange( settingId, next ) }
				/>
			) ) }
		</div>
	);
}
