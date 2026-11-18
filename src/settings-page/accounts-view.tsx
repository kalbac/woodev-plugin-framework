/**
 * The «Подключение» tab of a marketplace plugin: one card per connected account, and the way to add another.
 *
 * Sections that carry a `card` (`Settings_Section::with_card()`) are drawn here instead of as sub-tabs. A card shows the
 * account's title, identifier and stored status with «Редактировать» / «Удалить»; opening it renders the
 * section's own connection block (fields and «Проверить подключение») through `renderSection`, so saving and testing are the
 * settings page's, unchanged. With no account the tab is one big «Добавить аккаунт» button that opens the form.
 *
 * @package woodev-plugin-framework
 */

import type { KeyboardEvent, ReactNode } from 'react';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import AccountDeleteFlow from './account-delete-flow';

/** The stored connection status of an account section (`Settings_Section` status). */
export interface SectionStatus {
	success?: boolean;
	message?: string;
}

/** `Settings_Section::with_card()` as the registry serialises it. */
export interface AccountCardSchema {
	title: string;
	identifier_label: string;
	identifier: string;
	enabled: boolean;
	/** The setting that switches the account (the form's «Аккаунт включён»); the page saves it with the form. */
	enabled_field: string;
	/** Why «Удалить» is unavailable (an enabled account must be switched off first); empty when it is available. */
	delete_hint?: string;
	/** The account's deletion route; absent when the screen does not offer deleting. */
	delete_url?: string;
	/** State of a deletion already under way (`running` / `failed`), or empty. */
	deletion?: string;
	/** True for the block that adds a new account instead of showing a saved one. */
	new?: boolean;
}

/** The slice of a section the accounts and stacked views read. */
export interface AccountSection {
	id: string;
	label?: string;
	subtitle?: string;
	stacked?: boolean;
	card?: AccountCardSchema;
	status?: SectionStatus | null;
}

/** The slice of a tab schema the accounts and stacked views read. */
export interface AccountsTab {
	id: string;
	sections?: AccountSection[];
}

/** A section known to carry a card. */
type CardSection = AccountSection & { card: AccountCardSchema };

/** The text of an account card's status line and whether it is good news. */
export interface CardStatus {
	ok: boolean;
	text: string;
}

/**
 * Whether a tab is the accounts tab: some of its sections are drawn as cards.
 *
 * @param {AccountsTab} tab the tab schema.
 * @return {boolean} true when it has card sections.
 */
export function isAccountsTab( tab: AccountsTab ): boolean {
	return ( tab.sections || [] ).some( ( section ) => !! section.card );
}

/**
 * Splits an accounts tab into the saved accounts' sections and the block that adds a new one.
 *
 * @param {AccountsTab} tab the tab schema.
 * @return {{accounts: CardSection[], adder: CardSection|null}} the saved accounts' sections and the add block.
 */
export function splitAccountSections( tab: AccountsTab ): { accounts: CardSection[]; adder: CardSection | null } {
	const cards = ( tab.sections || [] ).filter( ( section ): section is CardSection => !! section.card );

	return {
		accounts: cards.filter( ( section ) => ! section.card.new ),
		adder: cards.find( ( section ) => section.card.new ) || null,
	};
}

/**
 * The status line of a saved account card.
 *
 * @param {CardSection} section the account's connection section.
 * @return {CardStatus} the text and whether it is good news.
 */
export function cardStatus( section: CardSection ): CardStatus {
	if ( ! section.card.enabled ) {
		return { ok: false, text: __( 'Выключен', 'woodev-plugin-framework' ) };
	}

	const status = section.status;
	if ( ! status || ! status.message ) {
		return { ok: false, text: __( 'Подключение не проверялось', 'woodev-plugin-framework' ) };
	}

	return { ok: !! status.success, text: status.message };
}

interface AccountCardProps {
	section: CardSection;
	open: boolean;
	onToggleOpen: () => void;
	onDelete: () => void;
	children?: ReactNode;
}

/**
 * Whether «Удалить» can be pressed: the account is switched off and the screen offers deleting.
 *
 * @param {AccountCardSchema} card the account's card.
 * @return {boolean} true when it can.
 */
export function canDelete( card: AccountCardSchema ): boolean {
	return ! card.enabled && !! card.delete_url;
}

function AccountCard( { section, open, onToggleOpen, onDelete, children }: AccountCardProps ) {
	const card = section.card;
	const status = cardStatus( section );
	// An open form shows the stored connection message under «Проверить подключение»: the header does not say it a second time.
	const statusShownBelow = open && card.enabled && !! section.status?.message;
	// The reason «Удалить» waits, as visible text: a disabled button shows no tooltip of its own.
	const deleteHint = ! canDelete( card ) && card.delete_hint ? card.delete_hint : '';

	return (
		<div className={ 'woodev-account-card' + ( open ? ' is-open' : '' ) + ( card.enabled ? '' : ' is-disabled' ) }>
			<div className="woodev-account-card__head">
				<div className="woodev-account-card__main">
					<strong className="woodev-account-card__title">{ card.title }</strong>
					<span className="woodev-account-card__identifier">
						{ card.identifier_label }: { card.identifier }
					</span>
					{ ! statusShownBelow && (
						<span className={ `woodev-account-card__status is-${ status.ok ? 'ok' : 'error' }` }>
							{ status.text }
						</span>
					) }
				</div>
				<div className="woodev-account-card__controls">
					<div className="woodev-account-card__actions">
						<Button variant="secondary" aria-expanded={ open } onClick={ onToggleOpen }>
							{ open ? __( 'Свернуть', 'woodev-plugin-framework' ) : __( 'Редактировать', 'woodev-plugin-framework' ) }
						</Button>
						<Button variant="secondary" isDestructive disabled={ ! canDelete( card ) } onClick={ onDelete }>
							{ 'running' === card.deletion ? __( 'Удаляется…', 'woodev-plugin-framework' ) : __( 'Удалить', 'woodev-plugin-framework' ) }
						</Button>
					</div>
					{ deleteHint && <p className="woodev-account-card__hint">{ deleteHint }</p> }
				</div>
			</div>
			{ open && <div className="woodev-account-card__body">{ children }</div> }
		</div>
	);
}

interface AccountsViewProps {
	/** The accounts tab. */
	tab: AccountsTab;
	/** The connection block of one section. */
	renderSection: ( tab: AccountsTab, sectionId: string ) => ReactNode;
	/** Reloads the cards after the deletion dialog closes (the account may be gone, or a deletion may be running). */
	onAccountsChanged?: () => void;
}

/**
 * The accounts tab: a card per saved account and the way to add another.
 *
 * @param {AccountsViewProps} props the view props.
 */
export default function AccountsView( { tab, renderSection, onAccountsChanged }: AccountsViewProps ) {
	const { accounts, adder } = splitAccountSections( tab );
	const [ openId, setOpenId ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const [ deletingId, setDeletingId ] = useState( '' );
	const deleting = accounts.find( ( section ) => section.id === deletingId );

	// A saved new account turns into a card: the add form has done its job.
	useEffect( () => setAdding( false ), [ accounts.length ] );

	// Esc closes the open form (an edit or a new account); the edits stay staged in the page, so nothing typed is lost.
	// The deletion dialog is a modal of its own and answers Esc itself.
	const closeOnEscape = ( event: KeyboardEvent< HTMLDivElement > ) => {
		if ( 'Escape' !== event.key || event.defaultPrevented || deletingId || ( ! openId && ! adding ) ) {
			return;
		}

		// Esc inside an open dropdown closes the dropdown, not the form behind it.
		if ( ( event.target as HTMLElement ).closest( '.components-popover, [aria-haspopup][aria-expanded="true"]' ) ) {
			return;
		}

		setOpenId( '' );
		setAdding( false );
	};

	return (
		<div className="woodev-accounts" onKeyDown={ closeOnEscape }>
			{ accounts.map( ( section ) => (
				<AccountCard
					key={ section.id }
					section={ section }
					open={ openId === section.id }
					onToggleOpen={ () => {
						// Two forms never stack: opening an edit puts the new-account form away.
						setAdding( false );
						setOpenId( openId === section.id ? '' : section.id );
					} }
					onDelete={ () => setDeletingId( section.id ) }
				>
					{ renderSection( tab, section.id ) }
				</AccountCard>
			) ) }
			{ deleting && (
				<AccountDeleteFlow
					card={ deleting.card }
					onClose={ () => {
						setDeletingId( '' );
						if ( onAccountsChanged ) {
							onAccountsChanged();
						}
					} }
				/>
			) }
			{ adder && ! adding && (
				<div className={ 'woodev-accounts__add' + ( 0 === accounts.length ? ' is-first' : '' ) }>
					<Button
						variant={ 0 === accounts.length ? 'primary' : 'secondary' }
						className="woodev-accounts__add-button"
						onClick={ () => {
							// Two forms never stack: adding an account collapses the open edit form.
							setOpenId( '' );
							setAdding( true );
						} }
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
