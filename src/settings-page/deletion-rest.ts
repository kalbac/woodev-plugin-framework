/**
 * REST client of the account deletion route (`…/accounts/{id}/deletion`, handed over as the account card's `delete_url`).
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import { getBootstrap } from './bootstrap';

/** One line of the preview: what would be deleted and how many. */
export interface DeletionCount {
	key: string;
	label: string;
	count: number;
}

/** What deleting the account would remove, what stays, and the warnings that apply. */
export interface DeletionPreview {
	counts: DeletionCount[];
	kept: string;
	warnings: string[];
}

/** How far a deletion has got. `notes` arrive with `done`. */
export interface DeletionProgress {
	state: 'running' | 'failed' | 'done';
	step: string;
	removed: Record< string, number >;
	message: string;
	notes?: string[];
}

/** The state of an account's deletion as the server describes it. */
export interface DeletionState {
	deleted: boolean;
	can_delete: boolean;
	refusal: string;
	preview: DeletionPreview | null;
	deletion: DeletionProgress | null;
}

/**
 * Reads the state: whether the account may be deleted, the preview and the progress. Changes nothing.
 *
 * @param url The account's deletion route.
 */
export function fetchDeletion( url: string ): Promise< DeletionState > {
	const { nonce } = getBootstrap();

	return apiFetch( { url, method: 'GET', headers: { 'X-WP-Nonce': nonce || '' } } ) as Promise< DeletionState >;
}

/**
 * Starts the deletion. The server checks the capability, the nonce, that the account is switched off and that `confirm` is its
 * identifier.
 *
 * @param url     The account's deletion route.
 * @param confirm The identifier the merchant typed.
 */
export function startDeletion( url: string, confirm: string ): Promise< DeletionState > {
	const { nonce } = getBootstrap();

	return apiFetch( { url, method: 'POST', headers: { 'X-WP-Nonce': nonce || '' }, data: { confirm } } ) as Promise< DeletionState >;
}
