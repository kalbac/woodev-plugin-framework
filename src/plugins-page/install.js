/**
 * Install or update button for an owned plugin. Reflects the per-plugin
 * install lifecycle: idle → installing → done / error.
 *
 * @package
 */

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime.
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The install control for a single plugin.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.state     'idle' | 'installing' | 'done' | 'error'.
 * @param {boolean}  props.isUpdate  Whether this replaces an installed plugin.
 * @param {Function} props.onInstall Click handler (no args).
 * @return {JSX.Element} The control.
 */
export default function InstallButton( {
	state = 'idle',
	isUpdate = false,
	onInstall,
} ) {
	if ( 'done' === state ) {
		return (
			<span className="woodev-install woodev-install--done">
				<span className="woodev-install__done-label">
					{ isUpdate
						? __( 'Обновлено', 'woodev-plugin-framework' )
						: __( 'Установлено', 'woodev-plugin-framework' ) }
				</span>
				{ ! isUpdate ? (
					<a className="woodev-install__activate" href="plugins.php">
						{ __(
							'Активировать в «Плагинах»',
							'woodev-plugin-framework'
						) }
					</a>
				) : null }
			</span>
		);
	}

	const installing = 'installing' === state;
	const failed = 'error' === state;

	let label;
	if ( installing ) {
		label = isUpdate
			? __( 'Обновление…', 'woodev-plugin-framework' )
			: __( 'Установка…', 'woodev-plugin-framework' );
	} else if ( failed ) {
		label = __( 'Ошибка, повторить', 'woodev-plugin-framework' );
	} else {
		label = isUpdate
			? __( 'Обновить', 'woodev-plugin-framework' )
			: __( 'Установить', 'woodev-plugin-framework' );
	}

	return (
		<button
			type="button"
			className={
				'woodev-install woodev-install__button' +
				( failed ? ' woodev-install__button--error' : '' )
			}
			onClick={ onInstall }
			disabled={ installing }
			aria-busy={ installing }
		>
			{ label }
		</button>
	);
}

/**
 * A full-cover loading overlay with a centered spinner, shown over a card/row
 * while its plugin is installing. The host element must be position:relative.
 *
 * @param {Object}  props        Props.
 * @param {boolean} props.active Whether to render the overlay.
 * @return {JSX.Element|null} The overlay, or null.
 */
export function InstallOverlay( { active } ) {
	if ( ! active ) {
		return null;
	}

	return (
		<div
			className="woodev-install-overlay"
			role="status"
			aria-live="polite"
			aria-label={ __( 'Установка…', 'woodev-plugin-framework' ) }
		>
			<span className="woodev-install-overlay__spinner" aria-hidden="true" />
		</div>
	);
}
