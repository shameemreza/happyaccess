/**
 * Runs the inline reCAPTCHA form script in jsdom for RecaptchaTest.
 *
 * Reads {"script": "...", "scenario": "stall"|"pass"|"fail"|"old"} on stdin
 * and prints what the form sent as JSON. Timers are held, not run, until the
 * test fires them, so nothing waits 8 real seconds.
 */
'use strict';

const { JSDOM } = require( 'jsdom' );

const page = `<form name="happyaccess-twostep" method="post" action="/wp-login.php">
	<input name="pwd" value="123456">
	<button type="submit" name="happyaccess_action" value="later">Later</button>
	<button type="submit" name="other" value="x">Other</button>
</form>`;

function fields( form, submitter ) {
	const list = Array.from( form.querySelectorAll( 'input' ) )
		.filter( ( input ) => input.name )
		.map( ( input ) => [ input.name, input.value ] );
	if ( submitter && submitter.name ) {
		list.push( [ submitter.name, submitter.value ] );
	}
	return list;
}

async function run( input ) {
	const { script, scenario } = JSON.parse( input );
	const dom = new JSDOM( page, { runScripts: 'outside-only' } );
	const w = dom.window;
	const out = { sent: [], timers: [] };
	const timers = [];

	w.setTimeout = ( fn, ms ) => {
		timers.push( { fn, ms, cleared: false } );
		return timers.length;
	};
	w.clearTimeout = ( id ) => {
		if ( timers[ id - 1 ] ) {
			timers[ id - 1 ].cleared = true;
		}
	};
	w.HTMLFormElement.prototype.submit = function () {
		out.sent.push( { how: 'submit', fields: fields( this, null ) } );
	};
	if ( 'old' === scenario ) {
		w.HTMLFormElement.prototype.requestSubmit = undefined;
	}

	const ready = 'stall' === scenario ? () => {} : ( callback ) => callback();
	w.grecaptcha = {
		ready,
		execute: () =>
			'fail' === scenario
				? Promise.reject( new Error( 'no' ) )
				: Promise.resolve( 'token-value' ),
	};

	w.eval( script );
	w.document.addEventListener( 'submit', ( event ) => {
		if ( event.defaultPrevented ) {
			return;
		}
		event.preventDefault();
		out.sent.push( {
			how: 'requestSubmit',
			fields: fields( event.target, event.submitter ),
		} );
	} );

	w.document.querySelector( 'button[name="happyaccess_action"]' ).click();
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	out.before = out.sent.length;
	out.timers = timers.filter( ( t ) => ! t.cleared ).map( ( t ) => t.ms );
	timers.filter( ( t ) => ! t.cleared ).forEach( ( t ) => t.fn() );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	process.stdout.write( JSON.stringify( out ) );
}

let input = '';
process.stdin.on( 'data', ( chunk ) => {
	input += chunk;
} );
process.stdin.on( 'end', () => {
	run( input ).catch( ( error ) => {
		process.stderr.write( String( error && error.stack ) );
		process.exit( 1 );
	} );
} );
