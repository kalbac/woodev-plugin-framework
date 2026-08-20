/**
 * Woodev UI-kit — shared tooltip-on-info-icon affordance.
 *
 * Extracted out of `FieldRow` so the checkbox/toggle branch of `ControlField`
 * (which renders its own label+description markup instead of going through
 * `FieldRow`) can show the same tooltip affordance the other controls show,
 * without duplicating the `Tooltip` + `InfoIcon` markup.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { Tooltip } from '@wordpress/components';
import { InfoIcon } from './icons';

/**
 * @param {Object} props      component props.
 * @param {string} [props.text] tooltip text; nothing is rendered when empty.
 * @return {JSX.Element|null} the tooltip affordance, or null when there is no text.
 */
export default function FieldTip( { text } ) {
	if ( ! text ) {
		return null;
	}

	return (
		<Tooltip text={ text } placement="top">
			<span
				className="woodev-field__tip"
				tabIndex={ 0 }
				role="img"
				aria-label={ text }
			>
				<InfoIcon />
			</span>
		</Tooltip>
	);
}
