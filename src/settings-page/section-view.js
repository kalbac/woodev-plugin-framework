/**
 * Renders one settings tab: its sections, each field via the shared ControlField.
 *
 * Classic JSX runtime: createElement / Fragment used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment } from '@wordpress/element';
import ControlField from '../components/control-field';

export default function SectionView( { tab, values, onFieldChange } ) {
	return createElement(
		Fragment,
		null,
		tab.sections.map( ( section ) =>
			createElement(
				'div',
				{ key: section.id, className: 'woodev-settings__section' },
				section.label &&
					createElement(
						'h3',
						{ className: 'woodev-settings__section-title' },
						section.label
					),
				Object.keys( section.fields ).map( ( settingId ) =>
					createElement( ControlField, {
						key: settingId,
						schema: section.fields[ settingId ],
						value:
							values[ settingId ] ?? section.fields[ settingId ].value,
						onChange: ( next ) => onFieldChange( settingId, next ),
					} )
				)
			)
		)
	);
}
