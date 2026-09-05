#!/usr/bin/env node
/**
 * Signature-compatibility probe (card #767).
 *
 * Why this exists: repointing a plugin class at a stricter v2 framework base fatals at PHP
 * CLASS-DECLARATION time — before any WordPress bootstrap, before any test runs, before a single
 * request is served. A green unit suite cannot see this (mocks never build the real class
 * hierarchy — see docs-internal/gotchas/a-stricter-base-class-fatals-on-signatures.md), and a
 * human review that greps for TYPE mismatches walks straight past a `final` method (the single
 * most expensive miss in the manual pass that produced this card).
 *
 * This script reads PHP SOURCE ONLY — no WordPress, no Composer autoload, no class_exists(). That
 * is the point: it must work before the plugin is capable of loading at all. It is a hand-rolled,
 * good-enough PHP class/method scanner (comment/string stripped, brace-depth aware), not a real
 * PHP parser — it is scoped to what this repo's plugin classes actually look like, not to the full
 * PHP grammar.
 *
 * Run: node scripts/signature-probe.mjs --pair "subjectFile:SubjectClass=baseFile:BaseClass" [...]
 *      npm run probe:signature -- --pair "..."
 *
 * With no --pair given, runs the edostavka-vs-Shipping_* triple this card was built to reproduce.
 */

import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname, resolve, relative, extname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );

const DEFAULT_ROOTS = [ 'woodev', 'plugins-reference' ];

const DEFAULT_PAIRS = [
	'plugins-reference/woocommerce-edostavka/woocommerce-edostavka.php:WC_Edostavka_Shipping=woodev/shipping-method/class-shipping-plugin.php:Shipping_Plugin',
	'plugins-reference/woocommerce-edostavka/includes/class-wc-edostavka-shipping-method.php:WD_Edostavka_Shipping=woodev/shipping-method/class-shipping-method.php:Shipping_Method',
	'plugins-reference/woocommerce-edostavka/includes/class-wc-edostavka-integration.php:WC_Edostavka_Integration=woodev/shipping-method/settings/class-shipping-integration.php:Shipping_Integration',
];

/* ------------------------------------------------------------------ *
 * 1. Strip comments and string literals, preserving offsets.
 *
 * Everything below scans this "clean" source, never the raw file — a docblock that says
 * "this abstract method" or a translated string containing "{" must never be mistaken for code.
 * ------------------------------------------------------------------ */

function stripCommentsAndStrings( src ) {
	let out = '';
	let i = 0;
	const n = src.length;

	while ( i < n ) {
		const c = src[ i ];
		const c2 = src[ i + 1 ];

		if ( '/' === c && '/' === c2 ) {
			while ( i < n && '\n' !== src[ i ] ) {
				out += ' ';
				i++;
			}
			continue;
		}

		if ( '#' === c && '[' !== c2 ) {
			while ( i < n && '\n' !== src[ i ] ) {
				out += ' ';
				i++;
			}
			continue;
		}

		if ( '/' === c && '*' === c2 ) {
			out += '  ';
			i += 2;
			while ( i < n && ! ( '*' === src[ i ] && '/' === src[ i + 1 ] ) ) {
				out += '\n' === src[ i ] ? '\n' : ' ';
				i++;
			}
			out += '  ';
			i += 2;
			continue;
		}

		if ( '\'' === c || '"' === c ) {
			const quote = c;
			out += ' ';
			i++;
			while ( i < n && src[ i ] !== quote ) {
				if ( '\\' === src[ i ] && i + 1 < n ) {
					out += '\n' === src[ i ] ? '\n' : ' ';
					out += '\n' === src[ i + 1 ] ? '\n' : ' ';
					i += 2;
					continue;
				}
				out += '\n' === src[ i ] ? '\n' : ' ';
				i++;
			}
			out += ' ';
			i++;
			continue;
		}

		// Heredoc/nowdoc: <<<TAG ... TAG; — blank the body, keep line count.
		if ( '<' === c && '<' === src[ i + 1 ] && '<' === src[ i + 2 ] ) {
			const m = /^<<<[ \t]*(['"]?)([A-Za-z_][A-Za-z0-9_]*)\1/.exec( src.slice( i ) );
			if ( m ) {
				const tag = m[ 2 ];
				out += ' '.repeat( m[ 0 ].length );
				i += m[ 0 ].length;
				const endRe = new RegExp( `\\n[ \\t]*${ tag }\\b` );
				const rest = src.slice( i );
				const endMatch = endRe.exec( rest );
				const endIdx = endMatch ? endMatch.index : rest.length;
				for ( let k = 0; k < endIdx; k++ ) {
					out += '\n' === rest[ k ] ? '\n' : ' ';
				}
				i += endIdx;
				continue;
			}
		}

		out += c;
		i++;
	}

	return out;
}

/* ------------------------------------------------------------------ *
 * 2. namespace / use resolution
 * ------------------------------------------------------------------ */

function parseNamespaceAndUses( clean ) {
	const nsMatch = /\bnamespace\s+([A-Za-z_\\][\w\\]*)\s*;/.exec( clean );
	const namespace = nsMatch ? nsMatch[ 1 ] : '';

	const uses = new Map(); // lowercased alias -> FQCN (no leading backslash)
	const useRe = /\buse\s+([A-Za-z_\\][\w\\]*)(?:\s+as\s+([A-Za-z_]\w*))?\s*;/g;
	let m;
	while ( ( m = useRe.exec( clean ) ) ) {
		const fqcn = m[ 1 ].replace( /^\\/, '' );
		const alias = m[ 2 ] || fqcn.split( '\\' ).pop();
		uses.set( alias.toLowerCase(), fqcn );
	}

	return { namespace, uses };
}

/** Resolves a class-name token as it appears in source to a best-effort FQCN (no leading `\`). */
function resolveTypeName( raw, ctx ) {
	if ( ! raw ) {
		return raw;
	}

	if ( raw.startsWith( '\\' ) ) {
		return raw.slice( 1 );
	}

	const segments = raw.split( '\\' );
	const first = segments[ 0 ].toLowerCase();

	if ( ctx.uses.has( first ) ) {
		const base = ctx.uses.get( first );
		return segments.length > 1 ? `${ base }\\${ segments.slice( 1 ).join( '\\' ) }` : base;
	}

	// scalar / builtin pseudo-types are never namespace-qualified
	const builtins = new Set( [
		'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array', 'object',
		'mixed', 'void', 'never', 'null', 'false', 'true', 'callable', 'iterable', 'self', 'static', 'parent',
	] );
	if ( builtins.has( raw.toLowerCase() ) ) {
		return raw.toLowerCase();
	}

	return ctx.namespace ? `${ ctx.namespace }\\${ raw }` : raw;
}

/* ------------------------------------------------------------------ *
 * 3. Class + method extraction
 * ------------------------------------------------------------------ */

function findMatchingBrace( clean, openIdx ) {
	let depth = 0;
	for ( let i = openIdx; i < clean.length; i++ ) {
		if ( '{' === clean[ i ] ) {
			depth++;
		} else if ( '}' === clean[ i ] ) {
			depth--;
			if ( 0 === depth ) {
				return i;
			}
		}
	}
	return clean.length - 1;
}

function findMatchingParen( clean, openIdx ) {
	let depth = 0;
	for ( let i = openIdx; i < clean.length; i++ ) {
		if ( '(' === clean[ i ] ) {
			depth++;
		} else if ( ')' === clean[ i ] ) {
			depth--;
			if ( 0 === depth ) {
				return i;
			}
		}
	}
	return clean.length - 1;
}

/** Splits a raw PHP parameter list into individual parameter descriptors. */
function parseParams( raw ) {
	const parts = [];
	let depth = 0;
	let cur = '';
	for ( const ch of raw ) {
		if ( '(' === ch || '[' === ch || '{' === ch ) {
			depth++;
		}
		if ( ')' === ch || ']' === ch || '}' === ch ) {
			depth--;
		}
		if ( ',' === ch && 0 === depth ) {
			parts.push( cur );
			cur = '';
			continue;
		}
		cur += ch;
	}
	if ( cur.trim() ) {
		parts.push( cur );
	}

	return parts
		.map( ( p ) => p.trim() )
		.filter( Boolean )
		.map( ( p ) => {
			const hasDefault = /=/.test( p.split( '=' )[ 0 ] ) ? false : p.includes( '=' );
			const [ beforeEq, ...rest ] = p.split( '=' );
			const defaultValue = rest.length ? rest.join( '=' ).trim() : null;
			const variadic = /\.\.\./.test( beforeEq );
			const byRef = /&\s*\$/.test( beforeEq );
			const nameMatch = /\$(\w+)/.exec( beforeEq );
			const name = nameMatch ? nameMatch[ 1 ] : '?';
			let typePart = beforeEq
				.replace( /\.\.\./, '' )
				.replace( /&\s*\$\w+/, '' )
				.replace( /\$\w+/, '' )
				.trim();
			const nullable = typePart.startsWith( '?' );
			if ( nullable ) {
				typePart = typePart.slice( 1 ).trim();
			}

			return {
				name,
				rawType: typePart || null,
				nullable,
				hasDefault: null !== defaultValue,
				defaultValue,
				variadic,
				byRef,
			};
		} );
}

const MODIFIER_WORDS = [ 'abstract', 'final', 'public', 'protected', 'private', 'static' ];

function findMethods( clean, ctx ) {
	const methods = [];
	const funcRe = /\bfunction\b/g;
	let pos = 0;

	while ( true ) {
		funcRe.lastIndex = pos;
		const m = funcRe.exec( clean );
		if ( ! m ) {
			break;
		}

		const funcIdx = m.index;

		// modifiers: the statement head since the last ; { } before "function"
		let start = funcIdx;
		while ( start > 0 && ! ';{}'.includes( clean[ start - 1 ] ) ) {
			start--;
		}
		const head = clean.slice( start, funcIdx );
		const modifiers = ( head.match( /\b(?:abstract|final|public|protected|private|static)\b/g ) || [] )
			.map( ( w ) => w.toLowerCase() );

		const afterFuncIdx = funcRe.lastIndex;
		const nameMatch = /^\s*&?\s*(\w+)\s*\(/.exec( clean.slice( afterFuncIdx ) );

		if ( ! nameMatch ) {
			// not a method declaration (shouldn't normally happen once comments/strings are stripped)
			pos = afterFuncIdx;
			continue;
		}

		const name = nameMatch[ 1 ];
		const parenOpen = afterFuncIdx + nameMatch[ 0 ].length - 1;
		const parenClose = findMatchingParen( clean, parenOpen );
		const rawParams = clean.slice( parenOpen + 1, parenClose );

		let j = parenClose + 1;
		const rtMatch = /^\s*:\s*([^{;]+?)\s*(?=\{|;)/.exec( clean.slice( j ) );
		let rawReturnType = null;
		if ( rtMatch ) {
			rawReturnType = rtMatch[ 1 ].trim();
			j += rtMatch[ 0 ].length;
		}

		while ( j < clean.length && /\s/.test( clean[ j ] ) ) {
			j++;
		}

		let bodyEnd;
		let hasBody;
		if ( '{' === clean[ j ] ) {
			bodyEnd = findMatchingBrace( clean, j );
			hasBody = true;
		} else {
			// ';' (interface/abstract declaration) — also the fallback if something odd happened
			bodyEnd = j;
			hasBody = false;
		}

		const visibility = modifiers.includes( 'private' )
			? 'private'
			: modifiers.includes( 'protected' )
				? 'protected'
				: 'public';

		let returnType = null;
		if ( rawReturnType ) {
			let t = rawReturnType.trim();
			const nullable = t.startsWith( '?' );
			if ( nullable ) {
				t = t.slice( 1 ).trim();
			}
			returnType = {
				raw: rawReturnType,
				nullable,
				segments: t.split( /\s*\|\s*/ ).map( ( s ) => resolveTypeName( s, ctx ) ),
			};
		}

		methods.push( {
			name,
			nameLower: name.toLowerCase(),
			modifiers,
			visibility,
			static: modifiers.includes( 'static' ),
			abstract: modifiers.includes( 'abstract' ) || ! hasBody,
			hasBody,
			params: parseParams( rawParams ).map( ( p ) => ( {
				...p,
				resolvedType: p.rawType
					? p.rawType.split( /\s*\|\s*/ ).map( ( s ) => resolveTypeName( s, ctx ) )
					: null,
			} ) ),
			returnType,
			rawSignature: `function ${ name }( ${ rawParams.trim() } )${ rawReturnType ? `: ${ rawReturnType }` : '' }`,
		} );

		pos = bodyEnd + 1;
	}

	return methods;
}

function findClasses( raw ) {
	const clean = stripCommentsAndStrings( raw );
	const ctx = parseNamespaceAndUses( clean );
	const classes = [];

	const classRe = /\b(abstract\s+)?(?:final\s+)?class\s+(\w+)\s*(?:extends\s+([A-Za-z_\\][\w\\]*))?\s*(?:implements\s+[^{]+)?\{/g;
	let m;
	while ( ( m = classRe.exec( clean ) ) ) {
		const isAbstract = Boolean( m[ 1 ] );
		const name = m[ 2 ];
		const extendsRaw = m[ 3 ] || null;
		const braceOpen = classRe.lastIndex - 1;
		const braceClose = findMatchingBrace( clean, braceOpen );
		const body = clean.slice( braceOpen + 1, braceClose );

		classes.push( {
			name,
			fqcn: ctx.namespace ? `${ ctx.namespace }\\${ name }` : name,
			isAbstract,
			extends: extendsRaw ? resolveTypeName( extendsRaw, ctx ) : null,
			extendsRaw,
			namespace: ctx.namespace,
			methods: findMethods( body, ctx ),
		} );

		classRe.lastIndex = braceClose + 1;
	}

	return classes;
}

/* ------------------------------------------------------------------ *
 * 4. Project-wide FQCN index (for auto-walking the base chain)
 * ------------------------------------------------------------------ */

function walkPhpFiles( dir, out ) {
	let entries;
	try {
		entries = readdirSync( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const entry of entries ) {
		const full = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			walkPhpFiles( full, out );
		} else if ( '.php' === extname( entry.name ) ) {
			out.push( full );
		}
	}
}

function buildIndex( roots ) {
	const files = [];
	for ( const root of roots ) {
		walkPhpFiles( resolve( ROOT, root ), files );
	}

	// fqcn (lowercased) -> { file, cls }
	const index = new Map();

	for ( const file of files ) {
		let raw;
		try {
			raw = readFileSync( file, 'utf8' );
		} catch {
			continue;
		}
		if ( ! raw.includes( 'class' ) ) {
			continue;
		}
		let classes;
		try {
			classes = findClasses( raw );
		} catch {
			continue;
		}
		for ( const cls of classes ) {
			const key = cls.fqcn.toLowerCase();
			if ( ! index.has( key ) ) {
				index.set( key, { file, cls } );
			}
		}
	}

	return index;
}

/* ------------------------------------------------------------------ *
 * 5. Resolve a single (file, className) into its class descriptor,
 *    and walk the ancestor chain via the project index.
 * ------------------------------------------------------------------ */

function loadClassFromFile( file, className ) {
	const absPath = resolve( ROOT, file );
	const raw = readFileSync( absPath, 'utf8' );
	const classes = findClasses( raw );
	const cls = classes.find( ( c ) => c.name === className );
	if ( ! cls ) {
		throw new Error( `Class ${ className } not found in ${ file }` );
	}
	return { file: relative( ROOT, absPath ).replace( /\\/g, '/' ), cls };
}

function buildAncestorChain( startFile, startClassName, index ) {
	const chain = [];
	let current = loadClassFromFile( startFile, startClassName );
	const seen = new Set();

	while ( current ) {
		if ( seen.has( current.cls.fqcn.toLowerCase() ) ) {
			break; // defensive: cyclical extends, should never happen
		}
		seen.add( current.cls.fqcn.toLowerCase() );
		chain.push( current );

		if ( ! current.cls.extends ) {
			break;
		}

		const nextKey = current.cls.extends.toLowerCase();
		const found = index.get( nextKey );
		if ( ! found ) {
			chain.push( {
				file: null,
				cls: null,
				externalStop: current.cls.extends,
			} );
			break;
		}
		current = found;
	}

	return chain;
}

/* ------------------------------------------------------------------ *
 * 6. Comparison
 * ------------------------------------------------------------------ */

const VIS_RANK = { private: 1, protected: 2, public: 3 };

function typeKey( segments ) {
	return segments.map( ( s ) => s.toLowerCase() ).sort().join( '|' );
}

function returnTypesCompatible( baseType, subjectType ) {
	if ( ! baseType ) {
		return true; // base declared nothing: subject is free to do anything
	}
	if ( ! subjectType ) {
		return false; // base declared a type, subject dropped it entirely — always fatal
	}
	if ( typeKey( baseType.segments ) !== typeKey( subjectType.segments ) ) {
		return false;
	}
	if ( baseType.nullable && ! subjectType.nullable ) {
		return true; // covariant narrowing: dropping nullability is fine
	}
	if ( ! baseType.nullable && subjectType.nullable ) {
		return false; // widening: adding nullability where the base had none
	}
	return true;
}

function paramSignature( params ) {
	return params
		.map( ( p ) => {
			const type = p.resolvedType ? `${ p.nullable ? '?' : '' }${ p.resolvedType.join( '|' ).toLowerCase() }` : '';
			const def = p.hasDefault ? '=default' : '';
			const variadic = p.variadic ? '...' : '';
			return `${ type }${ variadic }${ def }`;
		} )
		.join( ',' );
}

/**
 * Compares one subject method against its nearest base declaration.
 * Returns { fatal: {rule, detail}|null, shadowed: bool, paramDivergence: string|null }.
 */
function compareMethod( subjectMethod, declEntry ) {
	const { method: decl, className: declClassName } = declEntry;

	if ( 'private' === decl.visibility ) {
		return {
			fatal: null,
			shadowed: 'private' !== subjectMethod.visibility,
			shadowedIn: declClassName,
			paramDivergence: null,
		};
	}

	if ( decl.modifiers.includes( 'final' ) ) {
		return {
			fatal: {
				rule: 'final-override',
				detail: `${ declClassName }::${ decl.name }() is declared final; the override cannot exist`,
			},
			shadowed: false,
			paramDivergence: null,
		};
	}

	if ( VIS_RANK[ subjectMethod.visibility ] < VIS_RANK[ decl.visibility ] ) {
		return {
			fatal: {
				rule: 'visibility-narrowed',
				detail: `narrows ${ declClassName }::${ decl.name }() from ${ decl.visibility } to ${ subjectMethod.visibility }`,
			},
			shadowed: false,
			paramDivergence: null,
		};
	}

	if ( subjectMethod.static !== decl.static ) {
		return {
			fatal: {
				rule: 'static-mismatch',
				detail: `${ declClassName }::${ decl.name }() is ${ decl.static ? 'static' : 'non-static' }, override is ${ subjectMethod.static ? 'static' : 'non-static' }`,
			},
			shadowed: false,
			paramDivergence: null,
		};
	}

	if ( ! returnTypesCompatible( decl.returnType, subjectMethod.returnType ) ) {
		const baseRt = decl.returnType ? `${ decl.returnType.nullable ? '?' : '' }${ decl.returnType.segments.join( '|' ) }` : '(none)';
		const subRt = subjectMethod.returnType ? `${ subjectMethod.returnType.nullable ? '?' : '' }${ subjectMethod.returnType.segments.join( '|' ) }` : '(none)';
		return {
			fatal: {
				rule: 'incompatible-return-type',
				detail: `${ declClassName }::${ decl.name }(): ${ baseRt } vs override: ${ subRt }`,
			},
			shadowed: false,
			paramDivergence: null,
		};
	}

	let paramDivergence = null;
	if ( paramSignature( decl.params ) !== paramSignature( subjectMethod.params ) ) {
		paramDivergence = `${ declClassName }::${ decl.rawSignature } vs override: ${ subjectMethod.rawSignature }`;
	}

	return { fatal: null, shadowed: false, paramDivergence };
}

function probePair( subjectFile, subjectClass, baseFile, baseClass, index ) {
	const subject = loadClassFromFile( subjectFile, subjectClass );
	const chain = buildAncestorChain( baseFile, baseClass, index );
	const resolvedChain = chain.filter( ( c ) => c.cls );
	const externalStop = chain.find( ( c ) => c.externalStop )?.externalStop || null;

	// nearest-ancestor-wins declaration lookup, and track which link in the chain
	// provides a CONCRETE (non-abstract) implementation for the "unimplemented
	// abstracts" pass below.
	const nearestByName = new Map(); // nameLower -> { method, className }
	const concreteByName = new Set(); // nameLower with a concrete impl somewhere in the chain
	const abstractDecls = []; // { method, className } for abstracts not later made concrete

	for ( const link of resolvedChain ) {
		for ( const method of link.cls.methods ) {
			if ( ! nearestByName.has( method.nameLower ) ) {
				nearestByName.set( method.nameLower, { method, className: link.cls.name } );
			}
			if ( method.abstract ) {
				if ( ! concreteByName.has( method.nameLower ) ) {
					abstractDecls.push( { method, className: link.cls.name } );
				}
			} else {
				concreteByName.add( method.nameLower );
			}
		}
	}

	const unimplementedAbstracts = [];
	const seenAbstractNames = new Set();
	for ( const { method, className } of abstractDecls ) {
		if ( seenAbstractNames.has( method.nameLower ) ) {
			continue;
		}
		seenAbstractNames.add( method.nameLower );
		if ( concreteByName.has( method.nameLower ) ) {
			continue; // satisfied somewhere else in the framework chain itself
		}
		const implementedBySubject = subject.cls.methods.some( ( m ) => m.nameLower === method.nameLower );
		if ( ! implementedBySubject ) {
			unimplementedAbstracts.push( { name: method.name, declaredIn: className, signature: method.rawSignature } );
		}
	}

	const fatals = [];
	const shadowed = [];
	const paramDivergences = [];

	for ( const method of subject.cls.methods ) {
		if ( '__construct' === method.name ) {
			continue; // PHP does not enforce LSP compatibility on constructors
		}
		const declEntry = nearestByName.get( method.nameLower );
		if ( ! declEntry ) {
			continue; // plugin-only method, not part of the base chain
		}
		const result = compareMethod( method, declEntry );
		if ( result.fatal ) {
			fatals.push( { method: method.name, subjectClass: subject.cls.name, ...result.fatal } );
		}
		if ( result.shadowed ) {
			shadowed.push( {
				method: method.name,
				subjectClass: subject.cls.name,
				declaredIn: result.shadowedIn,
			} );
		}
		if ( result.paramDivergence ) {
			paramDivergences.push( { method: method.name, subjectClass: subject.cls.name, detail: result.paramDivergence } );
		}
	}

	return {
		subjectFile: subject.file,
		subjectClass: subject.cls.name,
		baseFile: relative( ROOT, resolve( ROOT, baseFile ) ).replace( /\\/g, '/' ),
		baseClass,
		chainNames: resolvedChain.map( ( c ) => c.cls.name ),
		externalStop,
		fatals,
		unimplementedAbstracts,
		shadowed,
		paramDivergences,
	};
}

/* ------------------------------------------------------------------ *
 * 7. CLI + report
 * ------------------------------------------------------------------ */

function parseArgs( argv ) {
	const pairs = [];
	const roots = [];
	for ( let i = 0; i < argv.length; i++ ) {
		if ( '--pair' === argv[ i ] ) {
			pairs.push( argv[ ++i ] );
		} else if ( '--root' === argv[ i ] ) {
			roots.push( argv[ ++i ] );
		}
	}
	return { pairs: pairs.length ? pairs : DEFAULT_PAIRS, roots: roots.length ? roots : DEFAULT_ROOTS };
}

/**
 * Splits "file:Class" on the LAST colon, not the first — a Windows absolute path
 * ("C:\...\File.php") has its own colon at index 1, which a naive `split(':')` collides with.
 */
function splitFileAndClass( spec, wholeSpecForError ) {
	const idx = spec.lastIndexOf( ':' );
	if ( -1 === idx ) {
		throw new Error( `Malformed --pair "${ wholeSpecForError }". Expected "file:Class=baseFile:BaseClass".` );
	}
	return [ spec.slice( 0, idx ), spec.slice( idx + 1 ) ];
}

function parsePairSpec( spec ) {
	const eqIdx = spec.lastIndexOf( '=' );
	if ( -1 === eqIdx ) {
		throw new Error( `Malformed --pair "${ spec }". Expected "file:Class=baseFile:BaseClass".` );
	}
	const subjectSpec = spec.slice( 0, eqIdx );
	const baseSpec = spec.slice( eqIdx + 1 );
	const [ subjectFile, subjectClass ] = splitFileAndClass( subjectSpec, spec );
	const [ baseFile, baseClass ] = splitFileAndClass( baseSpec, spec );
	if ( ! subjectFile || ! subjectClass || ! baseFile || ! baseClass ) {
		throw new Error( `Malformed --pair "${ spec }". Expected "file:Class=baseFile:BaseClass".` );
	}
	return { subjectFile, subjectClass, baseFile, baseClass };
}

function printReport( results ) {
	let totalFatals = 0;
	let totalAbstracts = 0;

	for ( const r of results ) {
		console.log( `\n=== ${ r.subjectClass } (${ r.subjectFile }) vs ${ r.baseClass } chain ===` );
		console.log( `  chain: ${ r.chainNames.join( ' -> ' ) }${ r.externalStop ? ` -> ${ r.externalStop } (external, source not available — stopped)` : '' }` );

		console.log( `\n  FATALS (${ r.fatals.length })` );
		for ( const f of r.fatals ) {
			console.log( `    ✗ [${ f.rule }] ${ r.subjectClass }::${ f.method }() — ${ f.detail }` );
		}

		console.log( `\n  UNIMPLEMENTED ABSTRACTS (${ r.unimplementedAbstracts.length })` );
		for ( const a of r.unimplementedAbstracts ) {
			console.log( `    ✗ ${ a.declaredIn }::${ a.signature } — no implementation anywhere in ${ r.subjectClass }` );
		}

		console.log( `\n  PARAMETER DIVERGENCE (${ r.paramDivergences.length }, not fatal)` );
		for ( const p of r.paramDivergences ) {
			console.log( `    ~ ${ r.subjectClass }::${ p.method }() — ${ p.detail }` );
		}

		if ( r.shadowed.length ) {
			console.log( `\n  SHADOWED BASE-PRIVATE METHODS (${ r.shadowed.length }, not fatal — dead code)` );
			for ( const s of r.shadowed ) {
				console.log( `    ! ${ r.subjectClass }::${ s.method }() has the same name as ${ s.declaredIn }::${ s.method }() (private) — the base calls its OWN private method internally; ${ r.subjectClass }'s override never runs via that path` );
			}
		}

		totalFatals += r.fatals.length;
		totalAbstracts += r.unimplementedAbstracts.length;
	}

	console.log( `\n=== TOTAL: ${ totalFatals } fatal(s), ${ totalAbstracts } unimplemented abstract(s) across ${ results.length } pair(s) ===` );

	return { totalFatals, totalAbstracts };
}

function main() {
	const { pairs, roots } = parseArgs( process.argv.slice( 2 ) );
	const index = buildIndex( roots );

	const results = pairs.map( ( spec ) => {
		const { subjectFile, subjectClass, baseFile, baseClass } = parsePairSpec( spec );
		return probePair( subjectFile, subjectClass, baseFile, baseClass, index );
	} );

	printReport( results );
}

const isMain = process.argv[ 1 ] && import.meta.url === pathToFileURL( process.argv[ 1 ] ).href;
if ( isMain ) {
	main();
}

export {
	stripCommentsAndStrings,
	parseNamespaceAndUses,
	resolveTypeName,
	findClasses,
	findMethods,
	parseParams,
	buildIndex,
	buildAncestorChain,
	loadClassFromFile,
	probePair,
	returnTypesCompatible,
};
