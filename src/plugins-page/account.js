/**
 * Account-connection menu (Phase B). Disconnected (#6): a «Подключить аккаунт»
 * CTA linking to the server-side connect-init URL, plus a my-account link.
 * Connected (#9): avatar + display name with a dropdown to my-account and a
 * disconnect action (POSTs the REST route, then reloads to the disconnected
 * state). Renders nothing when the feature flag is off.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- createElement/Fragment required by classic JSX runtime.
import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * The account menu.
 *
 * @param {Object}  props         Props.
 * @param {boolean} props.enabled Feature flag (window.woodevExtensions.accountEnabled).
 * @param {Object}  props.account { connected, name, email, avatar, url, connectUrl, myAccountUrl }.
 * @return {JSX.Element|null} The menu, or null when disabled.
 */
export default function AccountMenu( { enabled, account } ) {
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	if ( ! enabled || ! account ) {
		return null;
	}

	const myAccount =
		account.myAccountUrl || ( account.url ? account.url + '/my-account/' : '#' );

	const disconnect = () => {
		setBusy( true );
		apiFetch( { path: '/woodev/v1/account/disconnect', method: 'POST' } )
			.then( () => window.location.reload() )
			.catch( () => setBusy( false ) );
	};

	// ---- Disconnected (#6) --------------------------------------------------
	if ( ! account.connected ) {
		return (
			<div className="woodev-extensions__account">
				<span className="woodev-extensions__account-text">
					{ __(
						'Подключите аккаунт woodev.ru, чтобы видеть купленные плагины.',
						'woodev-plugin-framework'
					) }
				</span>
				<div className="woodev-account-menu">
					<a className="button button-primary" href={ account.connectUrl }>
						{ __( 'Подключить аккаунт', 'woodev-plugin-framework' ) }
					</a>
					<a
						className="woodev-account-menu__link"
						href={ myAccount }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Личный кабинет на woodev.ru', 'woodev-plugin-framework' ) }
					</a>
				</div>
			</div>
		);
	}

	// ---- Connected (#9) -----------------------------------------------------
	return (
		<div className="woodev-extensions__account is-connected">
			<div className="woodev-account-menu">
				<button
					type="button"
					className="woodev-account-menu__trigger"
					aria-expanded={ open }
					onClick={ () => setOpen( ( v ) => ! v ) }
				>
					{ account.avatar ? (
						<img className="woodev-account-menu__avatar" src={ account.avatar } alt="" />
					) : (
						<span
							className="woodev-account-menu__avatar woodev-account-menu__avatar--placeholder"
							aria-hidden="true"
						>
							{ ( account.name || '?' ).trim().charAt( 0 ).toUpperCase() }
						</span>
					) }
					<span className="woodev-account-menu__name">
						{ account.name || account.email }
					</span>
					<span className="woodev-account-menu__caret" aria-hidden="true">
						▾
					</span>
				</button>

				{ open ? (
					<div className="woodev-account-menu__dropdown">
						<a
							className="woodev-account-menu__item"
							href={ myAccount }
							target="_blank"
							rel="noreferrer"
						>
							{ __( 'Личный кабинет', 'woodev-plugin-framework' ) }
						</a>
						<button
							type="button"
							className="woodev-account-menu__item woodev-account-menu__item--danger"
							onClick={ disconnect }
							disabled={ busy }
						>
							{ busy
								? __( 'Отключение…', 'woodev-plugin-framework' )
								: __( 'Отключить аккаунт', 'woodev-plugin-framework' ) }
						</button>
					</div>
				) : null }
			</div>
		</div>
	);
}
