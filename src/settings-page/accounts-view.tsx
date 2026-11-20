/**
 * The «Подключение» tab of a marketplace plugin: ONE white wrapper card holding a card per connected account, with the way to add
 * another in the wrapper's header.
 *
 * Sections that carry a `card` (`Settings_Section::with_card()`) are drawn here instead of as sub-tabs. A card shows the on/off
 * switch, the account's title, «Client-Id · description», the stored connection status and «Редактировать» / «Удалить»; the
 * switch saves at once (`onToggleEnabled`). Opening a card renders the section's own connection block (fields and «Проверить
 * подключение») through `renderSection`, so saving and testing are the settings page's, unchanged. With no account the wrapper
 * says to add the first one.
 *
 * @package woodev-plugin-framework
 */

import type { KeyboardEvent, ReactNode } from 'react';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, CardBody, FormToggle } from '@wordpress/components';
import AccountDeleteFlow from './account-delete-flow';
import TabDescription from './tab-description';

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
	/** The account's own note that tells it apart from the others (optional, may be empty). */
	description?: string;
	/** Why «Удалить» is unavailable (the screen does not offer deleting); empty when it is available. */
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
	/** What the tab is for, as escaped HTML from PHP; absent for a tab that says nothing. */
	description?: string;
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
	/** True while the switch is being saved. */
	switching: boolean;
	onToggleOpen: () => void;
	onToggleEnabled?: ( enabled: boolean ) => void;
	onDelete: () => void;
	children?: ReactNode;
}

/**
 * Whether «Удалить» can be pressed: the screen offers deleting. An enabled account can be deleted too: the dialog says that it is
 * switched off first.
 *
 * @param {AccountCardSchema} card the account's card.
 * @return {boolean} true when it can.
 */
export function canDelete( card: AccountCardSchema ): boolean {
	return !! card.delete_url;
}

/**
 * The muted line under an account's name: «Client-Id: 4180680 · Одежда и обувь».
 *
 * @param {AccountCardSchema} card the account's card.
 * @return {string} the line.
 */
export function identifierLine( card: AccountCardSchema ): string {
	const identifier = `${ card.identifier_label }: ${ card.identifier }`;

	return card.description ? `${ identifier } · ${ card.description }` : identifier;
}

function AccountCard( { section, open, switching, onToggleOpen, onToggleEnabled, onDelete, children }: AccountCardProps ) {
	const card = section.card;
	const status = cardStatus( section );
	// An open form shows the stored connection message under «Проверить подключение»: the header does not say it a second time.
	const statusShownBelow = open && card.enabled && !! section.status?.message;
	// The reason «Удалить» is not offered, as visible text: a disabled button shows no tooltip of its own.
	const deleteHint = ! canDelete( card ) && card.delete_hint ? card.delete_hint : '';

	return (
		<div className={ 'woodev-account-card' + ( open ? ' is-open' : '' ) + ( card.enabled ? '' : ' is-disabled' ) }>
			<div className="woodev-account-card__head">
				<FormToggle
					className="woodev-account-card__toggle"
					checked={ card.enabled }
					disabled={ ! onToggleEnabled || switching }
					onChange={ () => onToggleEnabled && onToggleEnabled( ! card.enabled ) }
					aria-label={ __( 'Аккаунт включён', 'woodev-plugin-framework' ) }
				/>
				<div className="woodev-account-card__main">
					<strong className="woodev-account-card__title">{ card.title }</strong>
					<span className="woodev-account-card__identifier">{ identifierLine( card ) }</span>
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
	/** Saves an account's on/off switch at once and resolves when the cards are up to date; absent: the switch is read-only. */
	onToggleEnabled?: ( section: AccountSection, enabled: boolean ) => Promise< unknown >;
}

/**
 * The accounts tab: a white wrapper with the tab's description and «+ Добавить аккаунт» in its header, a card per saved account
 * and the form of a new one.
 *
 * @param {AccountsViewProps} props the view props.
 */
export default function AccountsView( { tab, renderSection, onAccountsChanged, onToggleEnabled }: AccountsViewProps ) {
	const { accounts, adder } = splitAccountSections( tab );
	const [ openId, setOpenId ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const [ deletingId, setDeletingId ] = useState( '' );
	const [ switchingId, setSwitchingId ] = useState( '' );
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

	// The switch waits for the save; a failed save leaves it where it was (the page already said why).
	const toggleEnabled = ( section: CardSection, enabled: boolean ) => {
		if ( ! onToggleEnabled ) {
			return;
		}

		setSwitchingId( section.id );
		onToggleEnabled( section, enabled )
			.catch( () => {} )
			.then( () => setSwitchingId( '' ) );
	};

	return (
		<Card className="woodev-settings__card woodev-accounts" onKeyDown={ closeOnEscape }>
			<CardBody>
				<div className="woodev-accounts__header">
					<TabDescription tab={ tab } />
					{ adder && ! adding && (
						<Button
							variant={ 0 === accounts.length ? 'primary' : 'secondary' }
							className="woodev-accounts__add-button"
							onClick={ () => {
								// Two forms never stack: adding an account collapses the open edit form.
								setOpenId( '' );
								setAdding( true );
							} }
						>
							<span aria-hidden="true">+ </span>
							{ __( 'Добавить аккаунт', 'woodev-plugin-framework' ) }
						</Button>
					) }
				</div>
				{ 0 === accounts.length && ! adding && (
					<p className="woodev-accounts__empty">
						{ __( 'Пока нет ни одного аккаунта. Добавьте первый, чтобы плагин начал работать.', 'woodev-plugin-framework' ) }
					</p>
				) }
				{ accounts.map( ( section ) => (
					<AccountCard
						key={ section.id }
						section={ section }
						open={ openId === section.id }
						switching={ switchingId === section.id }
						onToggleOpen={ () => {
							// Two forms never stack: opening an edit puts the new-account form away.
							setAdding( false );
							setOpenId( openId === section.id ? '' : section.id );
						} }
						onToggleEnabled={ onToggleEnabled ? ( enabled ) => toggleEnabled( section, enabled ) : undefined }
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
				{ adder && adding && (
					<div className="woodev-accounts__form">
						<h3 className="woodev-accounts__form-title">{ __( 'Новый аккаунт', 'woodev-plugin-framework' ) }</h3>
						{ renderSection( tab, adder.id ) }
					</div>
				) }
			</CardBody>
		</Card>
	);
}
