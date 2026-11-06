/** One records-table row, with cells keyed by the declared column id. */
export type RecordRow = Record<string, unknown>;

/** Reads the stored JSON row list; malformed or non-list values have no rows. */
export function parseRecordRows( value: unknown ): RecordRow[] {
	if ( 'string' !== typeof value || '' === value.trim() ) {
		return [];
	}

	try {
		const rows: unknown = JSON.parse( value );
		const isRow = ( row: unknown ): row is RecordRow => !! row && 'object' === typeof row && ! Array.isArray( row );

		return Array.isArray( rows ) && rows.every( isRow ) ? rows : [];
	} catch ( error ) {
		return [];
	}
}
