'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const ROOT = path.join( __dirname, '..', '..' );
const logicSrc = fs.readFileSync( path.join( ROOT, 'assets/js/conditional-logic.js' ), 'utf8' );
const frontendSrc = fs.readFileSync( path.join( ROOT, 'assets/js/frontend.js' ), 'utf8' );

// Mirrors the markup produced by includes/class-renderer.php.
const FORM = `
<form id="lf-form-7" class="lf-form" method="post" data-lf-form="7">
	<div class="lf-field lf-field--select" data-lf-name="topic">
		<label for="lf-7-1-topic">Topic</label>
		<select id="lf-7-1-topic" name="lf[topic]"><option value="">— Select —</option><option value="sales">Sales</option><option value="support">Support</option></select>
	</div>
	<div class="lf-field lf-field--text lf-field--required" data-lf-name="order" data-lf-conditions='{"enabled":true,"action":"show","match":"all","rules":[{"field":"topic","operator":"is","value":"support"}]}' hidden>
		<label for="lf-7-1-order">Order</label>
		<input id="lf-7-1-order" name="lf[order]" required type="text" value="" disabled>
	</div>
	<fieldset class="lf-field lf-field--checkbox lf-fieldset" data-lf-name="extras">
		<legend>Extras</legend>
		<div class="lf-option"><input type="checkbox" id="lf-7-1-extras-0" name="lf[extras][]" value="a"> <label for="lf-7-1-extras-0">A</label></div>
		<div class="lf-option"><input type="checkbox" id="lf-7-1-extras-1" name="lf[extras][]" value="b"> <label for="lf-7-1-extras-1">B</label></div>
	</fieldset>
	<div class="lf-field lf-field--textarea" data-lf-name="why_b" data-lf-conditions='{"enabled":true,"action":"show","match":"all","rules":[{"field":"extras","operator":"is","value":"b"}]}' hidden>
		<label for="lf-7-1-why_b">Why B?</label>
		<textarea id="lf-7-1-why_b" name="lf[why_b]" disabled></textarea>
	</div>
	<div class="lf-field lf-field--email" data-lf-name="email">
		<label for="lf-7-1-email">Email</label>
		<input id="lf-7-1-email" name="lf[email]" type="email" value="" aria-describedby="lf-7-1-email-desc">
		<p class="lf-description" id="lf-7-1-email-desc">We reply here</p>
	</div>
	<div class="lf-hp" aria-hidden="true"><label for="lf-7-1-hp">Leave this field empty</label><input type="text" id="lf-7-1-hp" name="lf_hp" value="" tabindex="-1" autocomplete="off"></div>
	<input type="hidden" name="lf_form_id" value="7">
	<div class="lf-submit"><button type="submit" class="wp-element-button">Send</button></div>
</form>`;

function setup( fetchImpl, options = {} ) {
	const dom = new JSDOM( `<!doctype html><html><body><main>${ options.html || FORM }</main></body></html>`, {
		runScripts: 'outside-only',
		url: options.url || 'https://example.org/contact/',
	} );
	const { window } = dom;
	window.launchFormsConfig = { restUrl: 'https://example.org/wp-json/launch-forms/v1/forms/', i18n: { error: 'Oops' } };
	window.fetch = fetchImpl || ( () => Promise.reject( new Error( 'no fetch' ) ) );
	if ( options.beforeInit ) {
		options.beforeInit( window );
	}
	window.eval( logicSrc );
	window.eval( frontendSrc );
	window.launchForms.init(); // jsdom is still "loading" here; init is idempotent.
	return { window, document: window.document, form: window.document.querySelector( 'form' ) };
}

function change( window, el, value ) {
	if ( el.type === 'checkbox' ) {
		el.checked = value;
	} else {
		el.value = value;
	}
	el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 10 ) );

function jsonResponse( status, body ) {
	return Promise.resolve( { ok: status < 300, status, json: () => Promise.resolve( body ) } );
}

test( 'conditional field toggles visibility and disabled state', () => {
	const { window, document } = setup();
	const wrapper = document.querySelector( '[data-lf-name="order"]' );
	const input = document.getElementById( 'lf-7-1-order' );
	assert.equal( wrapper.hidden, true );
	assert.equal( input.disabled, true );

	change( window, document.getElementById( 'lf-7-1-topic' ), 'support' );
	assert.equal( wrapper.hidden, false );
	assert.equal( input.disabled, false );

	change( window, document.getElementById( 'lf-7-1-topic' ), 'sales' );
	assert.equal( wrapper.hidden, true );
	assert.equal( input.disabled, true, 'Hidden fields are disabled so they are not validated or submitted.' );
} );

test( 'checkbox groups are collected as arrays', () => {
	const { window, document, form } = setup();
	assert.deepEqual( JSON.parse( JSON.stringify( window.launchForms.collectValues( form ).extras ) ), [] );
	change( window, document.getElementById( 'lf-7-1-extras-1' ), true );
	assert.deepEqual( JSON.parse( JSON.stringify( window.launchForms.collectValues( form ).extras ) ), [ 'b' ] );
	assert.equal( document.querySelector( '[data-lf-name="why_b"]' ).hidden, false );
} );

test( 'successful AJAX submission replaces the form with the message', async () => {
	let request;
	const { window, document, form } = setup( ( url, init ) => {
		request = { url, init };
		return jsonResponse( 200, { success: true, message: 'Thanks', message_html: '<p>Thanks!</p>', hide_form: true, redirect: '' } );
	} );
	form.querySelector( '#lf-7-1-email' ).value = 'a@example.com';
	form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await flush();

	assert.equal( request.url, 'https://example.org/wp-json/launch-forms/v1/forms/7/submit' );
	assert.equal( request.init.method, 'POST' );
	assert.equal( request.init.body.get( 'lf[email]' ), 'a@example.com' );
	assert.equal( request.init.body.get( 'lf[order]' ), null, 'Disabled hidden field not sent.' );
	assert.equal( document.querySelector( 'form' ), null );
	const message = document.getElementById( 'lf-form-7' );
	assert.equal( message.getAttribute( 'role' ), 'status' );
	assert.equal( message.innerHTML, '<p>Thanks!</p>' );
} );

test( 'success without hiding resets the form and shows a message', async () => {
	const { window, document, form } = setup( () => jsonResponse( 200, { success: true, message: 'Thanks', message_html: '', hide_form: false } ) );
	form.querySelector( '#lf-7-1-email' ).value = 'a@example.com';
	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	await flush();
	assert.ok( document.querySelector( 'form' ) );
	assert.equal( form.querySelector( '#lf-7-1-email' ).value, '' );
	assert.equal( form.querySelector( '.lf-message--success' ).textContent, 'Thanks' );
} );

test( 'validation errors are shown accessibly and cleared on resubmit', async () => {
	let calls = 0;
	const { window, document, form } = setup( () => {
		calls++;
		return jsonResponse( 422, {
			success: false,
			message: 'Please correct the errors below.',
			errors: calls === 1 ? { email: 'Please enter a valid email address.', extras: 'Pick one.' } : {},
		} );
	} );
	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	await flush();

	const alert = form.querySelector( '.lf-message--error' );
	assert.equal( alert.getAttribute( 'role' ), 'alert' );
	assert.equal( alert.textContent, 'Please correct the errors below.' );

	const email = document.getElementById( 'lf-7-1-email' );
	assert.equal( email.getAttribute( 'aria-invalid' ), 'true' );
	assert.equal( email.getAttribute( 'aria-describedby' ), 'lf-7-1-email-desc lf-7-1-email-error' );
	assert.equal( document.getElementById( 'lf-7-1-email-error' ).textContent, 'Please enter a valid email address.' );
	assert.equal( document.activeElement, email, 'Focus moves to the first invalid field.' );

	const group = document.querySelector( '[data-lf-name="extras"]' );
	assert.equal( group.getAttribute( 'aria-invalid' ), 'true' );
	assert.ok( document.getElementById( 'lf-7-1-extras-error' ) );

	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	await flush();
	assert.equal( form.querySelectorAll( '.lf-error' ).length, 0 );
	assert.equal( email.hasAttribute( 'aria-invalid' ), false );
	assert.equal( email.getAttribute( 'aria-describedby' ), 'lf-7-1-email-desc' );
	assert.equal( form.querySelectorAll( '.lf-message' ).length, 1 );
} );

test( 'redirect on success', async () => {
	const { window, form } = setup( () => jsonResponse( 200, { success: true, redirect: 'https://example.org/thanks/' } ) );
	let assigned = null;
	window.launchForms.navigate = ( url ) => {
		assigned = url;
	};
	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	await flush();
	assert.equal( assigned, 'https://example.org/thanks/' );
	assert.equal( form.querySelector( '.lf-message--error' ), null );
} );

test( 'network failure shows a generic error and re-enables the button', async () => {
	const { window, form } = setup( () => Promise.reject( new Error( 'offline' ) ) );
	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	await flush();
	assert.equal( form.querySelector( '.lf-message--error' ).textContent, 'Oops' );
	assert.equal( form.querySelector( 'button' ).disabled, false );
	assert.equal( form.getAttribute( 'aria-busy' ), 'false' );
} );

test( 'without fetch the form submits natively', () => {
	const { window, form } = setup();
	window.fetch = undefined;
	const event = new window.Event( 'submit', { cancelable: true } );
	form.dispatchEvent( event );
	assert.equal( event.defaultPrevented, false );
} );

test( 'logged-in nonce header is sent when present', async () => {
	let headers;
	const { window, form } = setup( ( url, init ) => {
		headers = init.headers;
		return jsonResponse( 200, { success: true, hide_form: true } );
	} );
	window.launchFormsConfig.nonce = 'abc123';
	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	await flush();
	assert.equal( headers[ 'X-WP-Nonce' ], 'abc123' );
} );

/* Analytics events */

function withEvent( form, name ) {
	form.setAttribute( 'data-lf-event', name );
	form.setAttribute( 'data-lf-event-form', 'Contact us' );
}

const submitOk = ( window, form ) => {
	form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
	return flush();
};

test( 'analytics: gtag receives the event with form_id and form_name on success', async () => {
	const { window, form } = setup( () => jsonResponse( 200, { success: true, message: 'Thanks', hide_form: true } ) );
	withEvent( form, 'contact_form_submit' );
	const calls = [];
	window.gtag = ( ...args ) => calls.push( args );
	await submitOk( window, form );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ][ 0 ], 'event' );
	assert.equal( calls[ 0 ][ 1 ], 'contact_form_submit' );
	assert.equal( calls[ 0 ][ 2 ].form_id, '7' );
	assert.equal( calls[ 0 ][ 2 ].form_name, 'Contact us' );
} );

test( 'analytics: Google Tag Manager dataLayer is used when gtag is absent', async () => {
	const { window, form } = setup( () => jsonResponse( 200, { success: true, message: 'Thanks' } ) );
	withEvent( form, 'generate_lead' );
	window.dataLayer = [];
	await submitOk( window, form );
	assert.equal( window.dataLayer.length, 1 );
	assert.equal( window.dataLayer[ 0 ].event, 'generate_lead' );
	assert.equal( window.dataLayer[ 0 ].form_id, '7' );
} );

test( 'analytics: nothing is sent when the form has no event or the submission fails', async () => {
	const ok = setup( () => jsonResponse( 200, { success: true, message: 'Thanks' } ) );
	const calls = [];
	ok.window.gtag = ( ...args ) => calls.push( args );
	await submitOk( ok.window, ok.form );
	assert.equal( calls.length, 0, 'No event configured.' );

	const bad = setup( () => jsonResponse( 422, { success: false, message: 'Fix', errors: { email: 'Bad' } } ) );
	withEvent( bad.form, 'contact_form_submit' );
	bad.window.gtag = ( ...args ) => calls.push( args );
	await submitOk( bad.window, bad.form );
	assert.equal( calls.length, 0, 'Failed submissions are not counted.' );
} );

test( 'analytics: a redirect waits for the event to be sent', async () => {
	const { window, form } = setup( () => jsonResponse( 200, { success: true, redirect: 'https://example.org/thanks/' } ) );
	withEvent( form, 'contact_form_submit' );
	let callback = null;
	window.gtag = ( type, name, params ) => {
		callback = params.event_callback;
	};
	let assigned = null;
	window.launchForms.navigate = ( url ) => {
		assigned = url;
	};
	await submitOk( window, form );
	assert.equal( assigned, null, 'Not yet: GA has not confirmed.' );
	callback();
	assert.equal( assigned, 'https://example.org/thanks/' );
	callback();
	assert.equal( assigned, 'https://example.org/thanks/', 'Navigates only once.' );
} );

test( 'analytics: blocked analytics delays a redirect by at most about a second', async () => {
	const { window, form } = setup( () => jsonResponse( 200, { success: true, redirect: 'https://example.org/thanks/' } ) );
	withEvent( form, 'contact_form_submit' );
	window.gtag = () => {}; // Stub left behind by an ad blocker: never calls back.
	let assigned = null;
	window.launchForms.navigate = ( url ) => {
		assigned = url;
	};
	await submitOk( window, form );
	assert.equal( assigned, null );
	await new Promise( ( resolve ) => setTimeout( resolve, 1300 ) );
	assert.equal( assigned, 'https://example.org/thanks/' );
} );

/* REST API blocked for visitors (security plugins, "logged-in users only" snippets, firewalls) */

function blockedSetup( status, body ) {
	const native = [];
	const ctx = setup( () =>
		Promise.resolve( {
			ok: false,
			status,
			json: () => ( body === undefined ? Promise.reject( new SyntaxError( 'Not JSON' ) ) : Promise.resolve( body ) ),
		} )
	);
	ctx.window.launchForms.submitNatively = ( form ) => native.push( form );
	return { ...ctx, native };
}

test( 'blocked REST request falls back to a normal submit', async () => {
	const { window, form, native } = blockedSetup( 401, { code: 'rest_not_logged_in', message: 'You are not currently logged in.', data: { status: 401 } } );
	withEvent( form, 'contact_form_submit' );
	const calls = [];
	window.gtag = ( ...args ) => calls.push( args );
	await submitOk( window, form );
	assert.deepEqual( native, [ form ], 'Submitted the ordinary way.' );
	assert.equal( form.querySelector( '.lf-message' ), null, 'The REST error is not shown.' );
	assert.equal( form.getAttribute( 'aria-busy' ), 'true', 'Stays busy while the page submits.' );
	assert.equal( calls.length, 0, 'No analytics event yet: the submission has not been processed.' );
} );

test( 'a firewall page (not JSON) also falls back', async () => {
	const { window, form, native } = blockedSetup( 403 );
	await submitOk( window, form );
	assert.equal( native.length, 1 );
} );

test( 'Launch Forms errors are shown, not retried', async () => {
	const missing = blockedSetup( 404, { code: 'lf_form_not_found', message: 'Form not found.', data: { status: 404 } } );
	await submitOk( missing.window, missing.form );
	assert.equal( missing.native.length, 0 );
	assert.equal( missing.form.querySelector( '.lf-message--error' ).textContent, 'Form not found.' );

	const limited = blockedSetup( 403, { success: false, message: 'Too many submissions.', errors: {} } );
	await submitOk( limited.window, limited.form );
	assert.equal( limited.native.length, 0 );
	assert.equal( limited.form.querySelector( '.lf-message--error' ).textContent, 'Too many submissions.' );

	const server = blockedSetup( 500 );
	await submitOk( server.window, server.form );
	assert.equal( server.native.length, 0, 'A server error may have happened after processing, so never resubmit.' );
	assert.equal( server.form.querySelector( '.lf-message--error' ).textContent, 'Oops' );
} );

test( 'analytics: the event is sent on the page shown after a normal submit', () => {
	const calls = [];
	const { window, document } = setup( null, {
		url: 'https://example.org/contact/?lf_sent=7&lf_msg=abc&utm_source=x#lf-form-7',
		html: '<div id="lf-form-7" class="lf-message lf-message--success" role="status" data-lf-sent data-lf-form="7" data-lf-event="contact_form_submit" data-lf-event-form="Contact us"><p>Thanks!</p></div>',
		beforeInit: ( w ) => {
			w.gtag = ( ...args ) => calls.push( args );
		},
	} );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ][ 1 ], 'contact_form_submit' );
	assert.equal( calls[ 0 ][ 2 ].form_id, '7' );
	assert.equal( calls[ 0 ][ 2 ].form_name, 'Contact us' );
	assert.equal( window.location.href, 'https://example.org/contact/?utm_source=x#lf-form-7', 'A reload does not count it again.' );
	window.launchForms.init( document );
	assert.equal( calls.length, 1, 'Sent once.' );
} );
