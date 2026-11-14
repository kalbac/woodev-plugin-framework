/**
 * The page's decisions about what it may offer, kept apart from the markup so they can be tested without a DOM.
 *
 * @package woodev-plugin-framework
 */

import type { ToolAccount, ToolActivity, ToolPreview, ToolRow } from './types';

/**
 * The account to show: the one chosen before while it is still on the list, else the first one, else none.
 *
 * @param accounts Accounts the server offered.
 * @param current  The account chosen before, or null.
 */
export function chooseAccount( accounts: ToolAccount[], current: number | null ): number | null {
	if ( current !== null && accounts.some( ( account ) => account.id === current ) ) {
		return current;
	}

	return accounts.length > 0 ? accounts[ 0 ].id : null;
}

/**
 * Whether the page asks the merchant to choose an account: only when there is more than one. With a single account the
 * tools simply run on it; with none the page shows its empty state.
 *
 * @param accounts Accounts the server offered.
 */
export function needsAccountChoice( accounts: ToolAccount[] ): boolean {
	return accounts.length > 1;
}

/**
 * A tool's preview button: the tool is not disabled (a disabled tool shows its reason only) and an account is chosen.
 *
 * @param tool      The tool.
 * @param accountId The chosen account, or null.
 */
export function canPreview( tool: ToolRow, accountId: number | null ): boolean {
	return ! tool.disabled && accountId !== null;
}

/**
 * The confirm button of an open preview: the preview is for this tool, the tool is not disabled, and the server says it can run.
 *
 * @param preview The open preview, or null.
 * @param tool    The tool the preview belongs to, if it is on the page.
 */
export function canConfirm( preview: ToolPreview | null, tool: ToolRow | undefined ): boolean {
	return Boolean( preview && tool && preview.tool === tool.id && ! tool.disabled && preview.can_run );
}

/**
 * Whether a background run still holds the account, so the page should read the state again later.
 *
 * @param activity The account line, or null before the first read.
 */
export function isBusy( activity: ToolActivity | null ): boolean {
	return activity?.active === true;
}
