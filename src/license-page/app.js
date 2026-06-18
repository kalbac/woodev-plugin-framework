/**
 * License Page App — root component.
 *
 * Renders the introductory description paragraph and the adaptive card grid
 * that maps each registered plugin to a LicenseCard component.
 *
 * Classic JSX runtime: { createElement, Fragment } MUST be imported here.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- classic JSX runtime pragma.
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import LicenseCard from './license-card';

/**
 * App — renders the intro paragraph and the card grid.
 *
 * @param {Object}   props         Component props.
 * @param {Array}    props.plugins Array of get_state() objects from window.woodevLicenses.plugins.
 *
 * @return {WPElement} Rendered component.
 */
export default function App( { plugins } ) {
	return (
		<Fragment>
			<div className="woodev-licenses-intro" role="note">
				<span className="dashicons dashicons-info woodev-licenses-intro__icon" aria-hidden="true" />
				<p className="woodev-licenses-intro__text">
					{ __(
						'Для использования плагинов Woodev укажите и активируйте действующий лицензионный ключ. Ключ был отправлен на вашу электронную почту после покупки. Также его можно найти на ',
						'woodev-plugin-framework'
					) }
					<a
						href="https://woodev.ru/my-account"
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'странице вашего аккаунта', 'woodev-plugin-framework' ) }
					</a>
					{ __( '.', 'woodev-plugin-framework' ) }
				</p>
			</div>

			<div className="woodev-licenses-grid">
				{ plugins.map( ( initialState ) => (
					<LicenseCard
						key={ initialState.plugin_id }
						initialState={ initialState }
					/>
				) ) }
			</div>
		</Fragment>
	);
}
