/**
 * Account-connection menu (Phase B). Both states are a trigger button that opens a
 * dropdown. Disconnected (#6): «Подключить аккаунт» trigger → dropdown with the
 * connect link + a my-account link. Connected (#9): avatar + display name trigger →
 * dropdown with my-account + disconnect (POSTs the REST route, then reloads to the
 * disconnected state). Renders nothing when the feature flag is off.
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

	const connected = !! account.connected;

	return (
		<div className={ 'woodev-extensions__account' + ( connected ? ' is-connected' : '' ) }>
			{ ! connected ? (
				<span className="woodev-extensions__account-text">
					{ __(
						'Подключите аккаунт woodev.ru, чтобы видеть купленные плагины.',
						'woodev-plugin-framework'
					) }
				</span>
			) : null }

			<div className="woodev-account-menu">
				<button
					type="button"
					className={
						'woodev-account-menu__trigger' +
						( connected ? '' : ' woodev-account-menu__trigger--cta' )
					}
					aria-expanded={ open }
					aria-haspopup="true"
					onClick={ () => setOpen( ( v ) => ! v ) }
				>
					{ connected ? (
						<Fragment>
							{ account.avatar ? (
								<img
									className="woodev-account-menu__avatar"
									src={ account.avatar }
									alt=""
								/>
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
						</Fragment>
					) : (
						<span className="woodev-account-menu__name">
							{ __( 'Подключить аккаунт', 'woodev-plugin-framework' ) }
						</span>
					) }
					<span className="woodev-account-menu__caret" aria-hidden="true">
						▾
					</span>
				</button>

				{ open ? (
					<div className="woodev-account-menu__dropdown">
						{ ! connected ? (
							<Fragment>
								<a className="woodev-account-menu__item" href={ account.connectUrl }>
									{ __( 'Подключить аккаунт', 'woodev-plugin-framework' ) }
								</a>
								<a
									className="woodev-account-menu__item"
									href={ myAccount }
									target="_blank"
									rel="noreferrer"
								>
									{ __( 'Личный кабинет на woodev.ru', 'woodev-plugin-framework' ) }
								</a>
							</Fragment>
						) : (
							<Fragment>
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
							</Fragment>
						) }
					</div>
				) : null }
			</div>
		</div>
	);
}
