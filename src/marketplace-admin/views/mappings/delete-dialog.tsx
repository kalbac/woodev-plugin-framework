/**
 * «Удалить» of a mapping or a profile, asked in the marketplace admin's one dialog ({@link WoodevDialog}).
 *
 * A profile that mappings still choose cannot be deleted (the server refuses it): the dialog says so and lists those mappings
 * instead of offering a button that can only fail.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { WoodevDialog } from '../../../components/woodev-dialog';
import type { Messages } from './types';

/** The record the merchant asked to delete. */
export interface DeleteTarget {
	id: number;
	name: string;
	/** The names of the mappings that choose the profile; empty for a mapping and for a profile nothing uses. */
	usedIn: string[];
}

interface Props {
	/** `null` while no dialog is open. */
	target: DeleteTarget | null;
	messages: Messages;
	kind: 'mapping' | 'profile';
	/** Deletes the record; resolves when the page has shown the outcome, whichever it was. */
	onDelete: ( id: number ) => Promise< void >;
	onClose: () => void;
}

/** A message with `{name}` filled in. */
function named( text: string, name: string ): string {
	return text.split( '{name}' ).join( name );
}

export function DeleteDialog( { target, messages, kind, onDelete, onClose }: Props ) {
	const [ busy, setBusy ] = useState( false );

	if ( target === null ) {
		return null;
	}

	const inUse = target.usedIn.length > 0;
	const title = inUse
		? named( messages.profileInUseTitle, target.name )
		: messages[ kind === 'mapping' ? 'deleteMappingTitle' : 'deleteProfileTitle' ];
	const text = named( messages[ kind === 'mapping' ? 'deleteMappingText' : 'deleteProfileText' ], target.name );

	const confirm = async () => {
		setBusy( true );
		await onDelete( target.id );
		setBusy( false );
		onClose();
	};

	return (
		<WoodevDialog
			title={ title }
			size="small"
			dismissible={ ! busy }
			onRequestClose={ onClose }
			footer={
				inUse ? (
					<Button __next40pxDefaultSize variant="secondary" onClick={ onClose }>
						{ messages.close }
					</Button>
				) : (
					<>
						<Button __next40pxDefaultSize variant="secondary" onClick={ onClose } disabled={ busy }>
							{ messages.cancel }
						</Button>
						<Button __next40pxDefaultSize variant="primary" isDestructive isBusy={ busy } onClick={ confirm } disabled={ busy }>
							{ messages.confirmDelete }
						</Button>
					</>
				)
			}
		>
			<p>{ inUse ? messages.profileInUseText : text }</p>
			{ inUse && (
				<ul className="woodev-mappings-delete__used">
					{ target.usedIn.map( ( name, index ) => (
						<li key={ `${ index }-${ name }` }>{ name }</li>
					) ) }
				</ul>
			) }
		</WoodevDialog>
	);
}
