/**
 * Install button for an owned-but-not-installed plugin. Reflects the per-plugin
 * install lifecycle: idle → installing → done / error. The plugin is installed
 * inactive (the REST route does not activate), so the «done» state points the
 * user at the Plugins page to activate it.
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
 * @param {Function} props.onInstall Click handler (no args).
 * @return {JSX.Element} The control.
 */
export default function InstallButton( { state = 'idle', onInstall } ) {
	if ( 'done' === state ) {
		return (
			<span className="woodev-install woodev-install--done">
				<span className="woodev-install__done-label">
					{ __( 'Установлено', 'woodev-plugin-framework' ) }
				</span>
				<a className="woodev-install__activate" href="plugins.php">
					{ __(
						'Активировать в «Плагинах»',
						'woodev-plugin-framework'
					) }
				</a>
			</span>
		);
	}

	const installing = 'installing' === state;
	const failed = 'error' === state;

	const label = installing
		? __( 'Установка…', 'woodev-plugin-framework' )
		: failed
		? __( 'Ошибка, повторить', 'woodev-plugin-framework' )
		: __( 'Установить', 'woodev-plugin-framework' );

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
