/**
 * `POST R/activity` — a local, read-only batch read of the rows on screen. The server never claims a job, calls a
 * marketplace or queues work for it, so repeating or aborting a read is always safe.
 *
 * @package woodev-plugin-framework
 */

import apiFetch from '@wordpress/api-fetch';
import type { RecordsApi } from '../api';
import type { RecordsContext } from '../types';
import type { ActivityResponse } from './types';

/**
 * @param api     The table's REST client.
 * @param context The context the server resolved.
 * @param ids     Row ids, at most the table's `id_limit`.
 * @param signal  Aborts a read the loop no longer wants.
 */
export function readActivity(
	api: RecordsApi,
	context: RecordsContext,
	ids: string[],
	signal?: AbortSignal
): Promise< ActivityResponse > {
	return apiFetch< ActivityResponse >( {
		path: `${ api.path }/activity`,
		method: 'POST',
		data: { context, ids },
		signal,
	} );
}
