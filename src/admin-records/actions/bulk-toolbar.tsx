/**
 * The bulk toolbar: one button per bulk action PHP declared for this user. A selection action waits for a
 * selection; an account-wide action does not need one, and its dialog says how far it reaches before anything runs.
 *
 * @package woodev-plugin-framework
 */

import { Button } from '@wordpress/components';
import type { SelectionHandle, TableHandle } from '../extensions';
import { STYLES } from './styles';
import type { ActionFlow } from './use-action-flow';

export interface BulkToolbarProps {
	flow: ActionFlow;
	selection: SelectionHandle;
	table: TableHandle;
}

export function BulkToolbar( { flow, selection, table }: BulkToolbarProps ) {
	// A link is plain navigation; it has no bulk form.
	const actions = table.definition.bulk_actions.filter( ( action ) => action.interaction !== 'link' );
	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<div className="woodev-records__bulk" role="toolbar" style={ STYLES.bulk }>
			{ actions.map( ( action ) => {
				const idle = action.scope === 'selection' && selection.selectedIds.length === 0;

				return (
					<Button
						key={ action.id }
						variant="secondary"
						isDestructive={ action.tone === 'danger' }
						disabled={ idle }
						accessibleWhenDisabled
						data-action={ action.id }
						onClick={ () =>
							flow.begin(
								action,
								action.scope === 'account' ? [] : selection.selectedIds,
								'',
								table,
								{ onApplied: selection.clear }
							)
						}
					>
						{ action.label }
					</Button>
				);
			} ) }
		</div>
	);
}
