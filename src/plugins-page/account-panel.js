/**
 * Account-connection scaffold (Phase A). Renders a «Подключить аккаунт» CTA
 * describing the upcoming feature; the button is disabled until the feature
 * flag (window.woodevExtensions.accountEnabled) is on. The live OAuth handshake
 * (Phase B) is added once the woodev-account-connector plugin ships.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement required by classic JSX runtime.
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The account-connection panel (scaffold).
 *
 * @param {Object}  props         Props.
 * @param {boolean} props.enabled Whether the connect action is enabled (feature flag).
 * @return {JSX.Element} The panel.
 */
export default function AccountPanel( { enabled } ) {
	return (
		<div className="woodev-extensions__account">
			<span className="woodev-extensions__account-text">
				{ __( 'Подключите аккаунт woodev.ru, чтобы видеть купленные плагины.', 'woodev-plugin-framework' ) }
			</span>
			<button type="button" className="button" disabled={ ! enabled }>
				{ __( 'Подключить аккаунт', 'woodev-plugin-framework' ) }
			</button>
		</div>
	);
}
