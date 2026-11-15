/**
 * The pieces both forms are built from, in the settings page's layout: a white card with a title, titled groups inside
 * it, and one row of buttons at the bottom.
 *
 * Markup and class names are the settings page's own (`.woodev-settings__card`, `.woodev-group`); the stylesheet comes
 * with the settings bundle, which every marketplace page already loads. `GroupCard` is not imported: it pulls the
 * settings REST client into this bundle.
 *
 * @package woodev-plugin-framework
 */

import type { ReactNode } from 'react';
import { useId } from '@wordpress/element';
import { Button, Card, CardBody } from '@wordpress/components';
import type { Messages } from './types';

/** A white card with the form's title as its first line. */
export function FormCard( { title, className, children }: { title: string; className?: string; children: ReactNode } ) {
	return (
		<Card className={ `woodev-settings__card woodev-mappings-card${ className ? ` ${ className }` : '' }` }>
			<CardBody>
				<h3 className="woodev-mappings-card__title">{ title }</h3>
				{ children }
			</CardBody>
		</Card>
	);
}

/** A titled block of related rows inside a card. */
export function Group( { title, description, children }: { title: string; description?: string; children: ReactNode } ) {
	const titleId = useId();

	return (
		<div className="woodev-group" role="group" aria-labelledby={ titleId }>
			<h4 className="woodev-group__title" id={ titleId }>
				{ title }
			</h4>
			{ description && <p className="woodev-group__desc">{ description }</p> }
			{ children }
		</div>
	);
}

/** A row that belongs to the row above it (the field a «custom field» choice asks for). */
export function NestedRow( { children }: { children: ReactNode } ) {
	return <div className="woodev-mappings-nested">{ children }</div>;
}

interface ActionsProps {
	messages: Messages;
	busy: boolean;
	/** The save button stays off while something it depends on is still loading. */
	saveDisabled?: boolean;
	onSave: () => void;
	/** Only a saved record can be copied or deleted. */
	onCopy?: () => void;
	onDelete?: () => void;
	confirming: boolean;
	onConfirming: ( confirming: boolean ) => void;
}

/** Save and copy on the left; delete, with its second confirming click, at the right end. */
export function FormActions( { messages, busy, saveDisabled = false, onSave, onCopy, onDelete, confirming, onConfirming }: ActionsProps ) {
	return (
		<div className="woodev-settings__actions woodev-mappings-actions">
			<Button __next40pxDefaultSize variant="primary" onClick={ onSave } disabled={ busy || saveDisabled } isBusy={ busy }>
				{ busy ? messages.saving : messages.save }
			</Button>
			{ onCopy && (
				<Button __next40pxDefaultSize variant="secondary" onClick={ onCopy } disabled={ busy }>
					{ messages.copy }
				</Button>
			) }
			{ onDelete && (
				<div className="woodev-mappings-actions__end">
					{ ! confirming && (
						<Button __next40pxDefaultSize variant="tertiary" isDestructive onClick={ () => onConfirming( true ) } disabled={ busy }>
							{ messages.delete }
						</Button>
					) }
					{ confirming && (
						<>
							<Button __next40pxDefaultSize variant="primary" isDestructive onClick={ onDelete } disabled={ busy }>
								{ messages.confirmDelete }
							</Button>
							<Button __next40pxDefaultSize variant="tertiary" onClick={ () => onConfirming( false ) }>
								{ messages.cancel }
							</Button>
						</>
					) }
				</div>
			) }
		</div>
	);
}
