/**
 * Renders one settings tab: its sections, each field via the WC-style FieldRow.
 *
 * Classic JSX runtime: createElement / Fragment used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment } from '@wordpress/element';
import FieldRow from './field-row';

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
						createElement( 'span', null, section.label )
					),
				createElement(
					'div',
					{ className: 'woodev-settings__rows' },
					Object.keys( section.fields ).map( ( settingId ) =>
						createElement( FieldRow, {
							key: settingId,
							schema: section.fields[ settingId ],
							value:
								values[ settingId ] ?? section.fields[ settingId ].value,
							onChange: ( next ) => onFieldChange( settingId, next ),
						} )
					)
				)
			)
		)
	);
}
