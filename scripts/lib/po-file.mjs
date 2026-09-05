/**
 * Minimal but correct gettext `.po` reader/writer.
 *
 * Handles what the s100 gotcha (`comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts`)
 * found broken in a naive parser: multi-line strings, escaped quotes, and `msgctxt` (including a
 * `msgctxt` that itself spans multiple lines). Entries are returned in file order so callers that
 * need to report a location can pair an entry back to its `references`.
 *
 * @package woodev-plugin-framework
 */

import { readFileSync } from 'node:fs';

/** Un-escapes a single C-style quoted gettext string body (the text between the outer quotes). */
function unescapePoString( raw ) {
	let out = '';
	for ( let i = 0; i < raw.length; i++ ) {
		const c = raw[ i ];
		if ( c === '\\' && i + 1 < raw.length ) {
			const next = raw[ ++i ];
			if ( next === 'n' ) out += '\n';
			else if ( next === 't' ) out += '\t';
			else if ( next === 'r' ) out += '\r';
			else if ( next === '"' ) out += '"';
			else if ( next === '\\' ) out += '\\';
			else out += next;
		} else {
			out += c;
		}
	}
	return out;
}

/** Escapes a string body for writing back into a `.po`/`.mo` literal. */
export function escapePoString( str ) {
	return str
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\t/g, '\\t' )
		.replace( /\r/g, '\\r' )
		.replace( /\n/g, '\\n' );
}

/**
 * Reads every `"..."` quoted-string line starting at `lines[i]`, concatenating multi-line
 * continuations (`msgid "a"` then `"b"` on the next line means the value is `ab`).
 * Returns `{ value, next }` where `next` is the index of the first line NOT consumed.
 */
function readQuotedRun( lines, i ) {
	let value = '';
	let first = true;
	while ( i < lines.length ) {
		const line = lines[ i ];
		const m = first
			? /^\s*\w[\w[\]]*\s+"((?:[^"\\]|\\.)*)"\s*$/.exec( line ) // keyword "..."
			: /^\s*"((?:[^"\\]|\\.)*)"\s*$/.exec( line ); // bare continuation "..."
		if ( ! m ) break;
		value += unescapePoString( m[ 1 ] );
		i++;
		first = false;
	}
	return { value, next: i };
}

/**
 * Parses a `.po` file into `{ header, entries }`.
 *
 * `header` is the raw msgstr body of the first (msgid "") entry, split into key/value pairs.
 * Each entry: `{ msgctxt, msgid, msgidPlural, msgstr, msgstrPlural, references, flags, lineNo }`.
 * `lineNo` is the 1-based source line of the entry's `msgid` keyword, for error reporting.
 */
export function parsePo( path ) {
	const text = readFileSync( path, 'utf8' );
	const lines = text.split( /\r\n|\n/ );

	const entries = [];
	let i = 0;

	while ( i < lines.length ) {
		// Skip blank lines.
		if ( lines[ i ].trim() === '' ) {
			i++;
			continue;
		}

		const entryStart = i;
		let flags = [];
		const references = [];

		// Comment block preceding the entry.
		while ( i < lines.length && lines[ i ].startsWith( '#' ) ) {
			const c = lines[ i ];
			if ( c.startsWith( '#,' ) ) {
				flags = c.slice( 2 ).split( ',' ).map( ( s ) => s.trim() );
			} else if ( c.startsWith( '#:' ) ) {
				references.push( c.slice( 2 ).trim() );
			}
			i++;
		}

		if ( i >= lines.length || lines[ i ].trim() === '' ) {
			// Comment-only block with nothing following (shouldn't happen in a well-formed .po).
			continue;
		}

		let msgctxt = null;
		if ( /^\s*msgctxt\s+"/.test( lines[ i ] ) ) {
			const r = readQuotedRun( lines, i );
			msgctxt = r.value;
			i = r.next;
		}

		if ( ! /^\s*msgid\s+"/.test( lines[ i ] ) ) {
			// Not a recognizable entry start — skip forward defensively.
			i = Math.max( i + 1, entryStart + 1 );
			continue;
		}
		const msgidRun = readQuotedRun( lines, i );
		const msgid = msgidRun.value;
		i = msgidRun.next;

		let msgidPlural = null;
		if ( /^\s*msgid_plural\s+"/.test( lines[ i ] ) ) {
			const r = readQuotedRun( lines, i );
			msgidPlural = r.value;
			i = r.next;
		}

		let msgstr = null;
		const msgstrPlural = [];
		while ( i < lines.length && /^\s*msgstr(\[\d+\])?\s+"/.test( lines[ i ] ) ) {
			const idxMatch = /^\s*msgstr\[(\d+)\]/.exec( lines[ i ] );
			const r = readQuotedRun( lines, i );
			if ( idxMatch ) {
				msgstrPlural[ Number( idxMatch[ 1 ] ) ] = r.value;
			} else {
				msgstr = r.value;
			}
			i = r.next;
		}

		entries.push( {
			msgctxt,
			msgid,
			msgidPlural,
			msgstr,
			msgstrPlural: msgstrPlural.length ? msgstrPlural : null,
			references,
			flags,
			lineNo: entryStart + 1,
		} );
	}

	const headerEntry = entries.shift();
	const header = {};
	if ( headerEntry && headerEntry.msgstr ) {
		for ( const line of headerEntry.msgstr.split( '\n' ) ) {
			const m = /^([^:]+):\s?(.*)$/.exec( line );
			if ( m ) header[ m[ 1 ] ] = m[ 2 ];
		}
	}

	return { header, headerRaw: headerEntry ? headerEntry.msgstr : '', entries };
}

/** The gettext key an entry is stored under: `msgctxt + "\x04" + msgid`, or bare `msgid`. */
export function poKey( entry ) {
	return entry.msgctxt ? `${ entry.msgctxt }\x04${ entry.msgid }` : entry.msgid;
}
