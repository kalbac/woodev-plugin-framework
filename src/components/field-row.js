/**
 * Woodev UI-kit — field anatomy row.
 *
 * Renders the shared field anatomy used across surfaces:
 *   [ label (+ required marker + tooltip icon) ] / [ control + description + error ]
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

import { RawHTML } from '@wordpress/element';
import FieldTip from './field-tip';

/**
 * `description` renders through `RawHTML` (issue #373 — the operator's own rule is
 * `desc_tip` for plain text, `description` specifically for a clickable link, e.g.
 * `'<a href="…">личном кабинете</a>'`) rather than as a plain text child: React
 * escapes markup in a text child, so an `<a>` tag would previously show up as
 * literal `&lt;a href…&gt;` text instead of a link (verified against this file
 * before the fix). `description` is always a hardcoded, developer-authored
 * translatable string built with `__()` at a settings-field registration call
 * site — never runtime or user-submitted data — matching the same "trusted,
 * plugin-authored HTML" justification `step-view.js` already uses for its own
 * `dangerouslySetInnerHTML` of `step.content`.
 *
 * @param {Object}    props                  component props.
 * @param {string}    [props.label]          field label.
 * @param {boolean}   [props.required]       show the required marker.
 * @param {string}    [props.tooltip]        tooltip text.
 * @param {string}    [props.description]    help text under the control (what the option does),
 *                                            may contain a trusted `<a>` link.
 * @param {string}    [props.disabledReason] why the control is currently disabled — rendered
 *                                            as a separate, visually distinguishable note from
 *                                            `description` (a state note, not documentation).
 * @param {string}    [props.error]          validation error message (red, under control).
 * @param {*}         props.children         the control element(s).
 * @return {JSX.Element} the field row.
 */
export default function FieldRow( { label, required, tooltip, description, disabledReason, error, children } ) {
	return (
		<div className={ `woodev-field${ error ? ' woodev-field--error' : '' }` }>
			{ label && (
				<div className="woodev-field__label">
					{ label }
					{ required && (
						<abbr className="woodev-field__req" title="Обязательное поле">
							*
						</abbr>
					) }
					<FieldTip text={ tooltip } />
				</div>
			) }
			<div className="woodev-field__control">
				{ children }
				{ description && (
					<div className="woodev-field__desc"><RawHTML>{ description }</RawHTML></div>
				) }
				{ disabledReason && (
					<div className="woodev-field__disabled-reason">{ disabledReason }</div>
				) }
				{ error && (
					<div className="woodev-field__error" aria-live="polite" aria-atomic="true">
						{ error }
					</div>
				) }
			</div>
		</div>
	);
}
