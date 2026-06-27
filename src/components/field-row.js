/**
 * Woodev UI-kit — field anatomy row.
 *
 * Renders the shared field anatomy used across surfaces:
 *   [ label (+ required marker + tooltip icon) ] / [ control + description ]
 *
 * Default layout is vertical (label above control); a surface may override
 * `.woodev-field` to a horizontal grid (settings does). The tooltip uses the
 * native wp `Tooltip` (rendered in a portal via Popover) so long text is never
 * clipped at the viewport edge.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { Tooltip } from '@wordpress/components';
import { InfoIcon } from './icons';

/**
 * @param {Object}    props             component props.
 * @param {string}    [props.label]     field label.
 * @param {boolean}   [props.required]  show the required marker.
 * @param {string}    [props.tooltip]   tooltip text.
 * @param {string}    [props.description] help text under the control.
 * @param {*}         props.children    the control element(s).
 * @return {JSX.Element} the field row.
 */
export default function FieldRow( { label, required, tooltip, description, children } ) {
	return (
		<div className="woodev-field">
			{ label && (
				<div className="woodev-field__label">
					{ label }
					{ required && <span className="woodev-field__req">*</span> }
					{ tooltip && (
						<Tooltip text={ tooltip } placement="top">
							<span
								className="woodev-field__tip"
								tabIndex={ 0 }
								role="img"
								aria-label={ tooltip }
							>
								<InfoIcon />
							</span>
						</Tooltip>
					) }
				</div>
			) }
			<div className="woodev-field__control">
				{ children }
				{ description && (
					<div className="woodev-field__desc">{ description }</div>
				) }
			</div>
		</div>
	);
}
