/**
 * Notice lines of the mappings view.
 *
 * @package woodev-plugin-framework
 */

import { Notice } from '@wordpress/components';
import { ProblemsLink } from '../../../components/help-link';

export interface NoticeState {
	status: 'success' | 'error';
	lines: string[];
}

export function Notices( { notice, onDismiss }: { notice: NoticeState | null; onDismiss: () => void } ) {
	if ( ! notice || notice.lines.length === 0 ) {
		return null;
	}

	return (
		<Notice status={ notice.status } onRemove={ onDismiss }>
			{ notice.lines.map( ( line ) => (
				<p key={ line }>{ line }</p>
			) ) }
			<ProblemsLink status={ notice.status } />
		</Notice>
	);
}
