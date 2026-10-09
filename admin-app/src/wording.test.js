import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const ROOT = path.resolve( 'admin-app/src' );

/**
 * Code-only places that may still hold the old name. Each entry is a path
 * under admin-app/src and the reason it is allowed. Empty for now.
 */
const ALLOWED = {};

/**
 * @param {string} dir Folder to read.
 * @return {string[]} Every app source file under it, tests left out.
 */
function sourceFiles( dir ) {
	return readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return sourceFiles( full );
		}
		return entry.name.endsWith( '.js' ) &&
			! entry.name.endsWith( '.test.js' )
			? [ full ]
			: [];
	} );
}

/**
 * Drops comments and keeps strings, so a docblock that names the feature in
 * code terms does not count. Walks the text once and tracks quotes.
 *
 * @param {string} code Source.
 * @return {string} The source without comments.
 */
export function withoutComments( code ) {
	let out = '';
	let quote = '';
	for ( let i = 0; i < code.length; i++ ) {
		const char = code[ i ];
		if ( quote ) {
			out += char;
			if ( '\\' === char ) {
				out += code[ ++i ] || '';
			} else if ( char === quote ) {
				quote = '';
			}
			continue;
		}
		if ( '/' === char && '/' === code[ i + 1 ] ) {
			const end = code.indexOf( '\n', i );
			i = -1 === end ? code.length : end - 1;
			continue;
		}
		if ( '/' === char && '*' === code[ i + 1 ] ) {
			const end = code.indexOf( '*/', i + 2 );
			i = -1 === end ? code.length : end + 1;
			continue;
		}
		if ( '"' === char || "'" === char || '`' === char ) {
			quote = char;
		}
		out += char;
	}
	return out;
}

describe( 'person-facing wording', () => {
	it( 'drops comments but keeps strings', () => {
		expect(
			withoutComments( "// Support access\nconst a = 'x'; /* old */ 'b'" )
		).toBe( "\nconst a = 'x';  'b'" );
	} );

	it( 'never says Support access in the app, outside comments', () => {
		const found = sourceFiles( ROOT )
			.filter( ( file ) => ! ( path.relative( ROOT, file ) in ALLOWED ) )
			.filter( ( file ) =>
				withoutComments( readFileSync( file, 'utf8' ) )
					.toLowerCase()
					.includes( 'support access' )
			)
			.map( ( file ) => path.relative( ROOT, file ) );

		expect( found ).toEqual( [] );
	} );
} );
