/**
 * Per-row ownership: who may write a row right now.
 *
 * Same technique as the shipping orders page (`rowOwners` and `fetchGeneration` in its `app.tsx`), applied to a
 * table that has several writers — the list read, a row or bulk action, and the activity refresh. An operation
 * that is about to write rows (a submitted action, say) {@link Ownership.claim claims} them and
 * {@link Ownership.release releases} them when its own response has landed. Both bump the row's version.
 *
 * The refresh loop remembers each row's version when it sends a read, and applies the answer only to rows that are
 * not claimed and whose version has not moved: a response that was already in flight when somebody else wrote the
 * row is dropped for that row, so an old observation never overwrites newer state.
 *
 * A claim is a browser-side guard only. It never stands for the server's durable lock, and releasing it does not
 * unlock anything.
 *
 * @package woodev-plugin-framework
 */

/** Opaque: compared by identity only. */
export type ClaimToken = object;

export interface Ownership {
	/** Marks the rows as being written by one operation; returns the token that releases them. */
	claim: ( ids: string[] ) => ClaimToken;
	/** Frees the rows `token` still owns; rows a newer operation claimed meanwhile stay claimed. */
	release: ( ids: string[], token: ClaimToken ) => void;
	/** Records that rows were written without a claim (for example a list read replaced them). */
	touch: ( ids: string[] ) => void;
	isClaimed: ( id: string ) => boolean;
	/** The row's write counter. */
	version: ( id: string ) => number;
	/** The versions of several rows, for a read that is about to start. */
	snapshot: ( ids: string[] ) => Map< string, number >;
	/** Whether a read that started at `snapshot` may still write this row. */
	mayWrite: ( id: string, snapshot: Map< string, number > ) => boolean;
}

export function createOwnership(): Ownership {
	const owners = new Map< string, ClaimToken >();
	const versions = new Map< string, number >();
	const bump = ( id: string ) => versions.set( id, ( versions.get( id ) ?? 0 ) + 1 );
	const version = ( id: string ) => versions.get( id ) ?? 0;

	return {
		claim: ( ids ) => {
			const token: ClaimToken = {};

			ids.forEach( ( id ) => {
				owners.set( id, token );
				bump( id );
			} );

			return token;
		},
		release: ( ids, token ) => {
			ids.filter( ( id ) => owners.get( id ) === token ).forEach( ( id ) => {
				owners.delete( id );
				// What the operation wrote is newer than any read that started before it finished.
				bump( id );
			} );
		},
		touch: ( ids ) => ids.forEach( bump ),
		isClaimed: ( id ) => owners.has( id ),
		version,
		snapshot: ( ids ) => new Map( ids.map( ( id ) => [ id, version( id ) ] ) ),
		mayWrite: ( id, snapshot ) => ! owners.has( id ) && version( id ) === ( snapshot.get( id ) ?? version( id ) ),
	};
}
