/**
 * Notice lines of the mappings view.
 *
 * @package woodev-plugin-framework
 */

import { Notice } from '@wordpress/components';
import HelpLink, { type HelpLinkData } from '../../../components/help-link';

export interface NoticeState {
	status: 'success' | 'error';
	lines: string[];
	/** The «Частые проблемы» link of the article the note belongs to, resolved by PHP (`Help_Links::resolve()`). */
	help?: HelpLinkData;
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
			<HelpLink help={ notice.help } />
		</Notice>
	);
}
