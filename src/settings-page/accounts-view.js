/**
 * The «Подключение» tab of a marketplace plugin: one card per connected account, and the way to add another.
 *
 * Sections that carry a `card` (`Settings_Section::with_card()`) are drawn here instead of as sub-tabs. A card shows the
 * account's title, identifier and stored status with «Редактировать» / «Выключить» / «Удалить»; opening it renders the
 * section's own connection block (fields and «Проверить подключение») through `renderSection`, so saving and testing are the
 * settings page's, unchanged. With no account the tab is one big «Добавить аккаунт» button that opens the form.
 *
 * @package woodev-plugin-framework
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Whether a tab is the accounts tab: some of its sections are drawn as cards.
 *
 * @param {Object} tab the tab schema.
 * @return {boolean} true when it has card sections.
 */
export function isAccountsTab( tab ) {
	return ( tab.sections || [] ).some( ( section ) => !! section.card );
}

/**
 * Splits an accounts tab into the saved accounts' sections and the block that adds a new one.
 *
 * @param {Object} tab the tab schema.
 * @return {{accounts: Object[], adder: ?Object}} the saved accounts' sections and the add block.
 */
export function splitAccountSections( tab ) {
	const cards = ( tab.sections || [] ).filter( ( section ) => !! section.card );

	return {
		accounts: cards.filter( ( section ) => ! section.card.new ),
		adder: cards.find( ( section ) => section.card.new ) || null,
	};
}

/**
 * The status line of a saved account card.
 *
 * @param {Object} section the account's connection section.
 * @return {{ok: boolean, text: string}} the text and whether it is good news.
 */
export function cardStatus( section ) {
	if ( ! section.card.enabled ) {
		return { ok: false, text: __( 'Выключен', 'woodev-plugin-framework' ) };
	}

	const status = section.status;
	if ( ! status || ! status.message ) {
		return { ok: false, text: __( 'Подключение не проверялось', 'woodev-plugin-framework' ) };
	}

	return { ok: !! status.success, text: status.message };
}

function AccountCard( { section, open, busy, onToggleOpen, onToggleEnabled, children } ) {
	const card = section.card;
	const status = cardStatus( section );

	return (
		<div className={ 'woodev-account-card' + ( open ? ' is-open' : '' ) + ( card.enabled ? '' : ' is-disabled' ) }>
			<div className="woodev-account-card__head">
				<div className="woodev-account-card__main">
					<strong className="woodev-account-card__title">{ card.title }</strong>
					<span className="woodev-account-card__identifier">
						{ card.identifier_label }: { card.identifier }
					</span>
					<span className={ `woodev-account-card__status is-${ status.ok ? 'ok' : 'error' }` }>
						{ status.text }
					</span>
				</div>
				<div className="woodev-account-card__actions">
					<Button variant="secondary" aria-expanded={ open } onClick={ onToggleOpen }>
						{ open ? __( 'Свернуть', 'woodev-plugin-framework' ) : __( 'Редактировать', 'woodev-plugin-framework' ) }
					</Button>
					<Button variant="secondary" isBusy={ busy } disabled={ busy } onClick={ onToggleEnabled }>
						{ card.enabled ? __( 'Выключить', 'woodev-plugin-framework' ) : __( 'Включить', 'woodev-plugin-framework' ) }
					</Button>
					{ /* A disabled button shows no tooltip of its own in every browser: the wrapper carries the hint. */ }
					<span title={ card.delete_hint }>
						<Button variant="secondary" isDestructive disabled title={ card.delete_hint }>
							{ __( 'Удалить', 'woodev-plugin-framework' ) }
						</Button>
					</span>
				</div>
			</div>
			{ open && <div className="woodev-account-card__body">{ children }</div> }
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.tab             the accounts tab.
 * @param {Function} props.renderSection   (tab, sectionId) => node — the connection block of one section.
 * @param {Function} props.onToggleEnabled (tab, section) => void — switches the account on or off and saves.
 * @param {string}   props.busySection     id of the section whose switch is being saved.
 */
export default function AccountsView( { tab, renderSection, onToggleEnabled, busySection = '' } ) {
	const { accounts, adder } = splitAccountSections( tab );
	const [ openId, setOpenId ] = useState( '' );
	const [ adding, setAdding ] = useState( false );

	// A saved new account turns into a card: the add form has done its job.
	useEffect( () => setAdding( false ), [ accounts.length ] );

	return (
		<div className="woodev-accounts">
			{ accounts.map( ( section ) => (
				<AccountCard
					key={ section.id }
					section={ section }
					open={ openId === section.id }
					busy={ busySection === section.id }
					onToggleOpen={ () => setOpenId( openId === section.id ? '' : section.id ) }
					onToggleEnabled={ () => onToggleEnabled( tab, section ) }
				>
					{ renderSection( tab, section.id ) }
				</AccountCard>
			) ) }
			{ adder && ! adding && (
				<div className={ 'woodev-accounts__add' + ( 0 === accounts.length ? ' is-first' : '' ) }>
					<Button
						variant={ 0 === accounts.length ? 'primary' : 'secondary' }
						className="woodev-accounts__add-button"
						onClick={ () => setAdding( true ) }
					>
						{ __( 'Добавить аккаунт', 'woodev-plugin-framework' ) }
					</Button>
				</div>
			) }
			{ adder && adding && (
				<div className="woodev-accounts__form">
					<h3 className="woodev-accounts__form-title">{ __( 'Новый аккаунт', 'woodev-plugin-framework' ) }</h3>
					{ renderSection( tab, adder.id ) }
				</div>
			) }
		</div>
	);
}
