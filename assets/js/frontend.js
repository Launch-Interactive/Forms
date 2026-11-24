/**
 * Launch Interactive Forms front end: live conditional logic and AJAX submission.
 * Forms work without this script (plain POST + server-side conditional logic).
 */
( function () {
	'use strict';

	var config = window.liformsConfig || {};
	var logic = window.liformsConditional;

	function fieldName( inputName ) {
		var match = /^lif\[([a-z0-9_]+)\]/.exec( inputName || '' );
		return match ? match[ 1 ] : null;
	}

	/** Collect current values keyed by field name (ignores visibility). */
	function collectValues( form ) {
		var values = {};
		Array.prototype.forEach.call( form.elements, function ( el ) {
			var name = fieldName( el.name );
			if ( ! name ) {
				return;
			}
			var multi = /\[\]$/.test( el.name );
			if ( multi && ! Array.isArray( values[ name ] ) ) {
				values[ name ] = [];
			}
			var value;
			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				if ( ! el.checked ) {
					if ( ! multi && values[ name ] === undefined ) {
						values[ name ] = '';
					}
					return;
				}
				value = el.value;
			} else if ( el.type === 'file' ) {
				value = el.files && el.files.length ? el.files[ 0 ].name : '';
			} else if ( el.tagName === 'SELECT' && el.multiple ) {
				value = Array.prototype.filter
					.call( el.options, function ( o ) {
						return o.selected;
					} )
					.map( function ( o ) {
						return o.value;
					} );
				values[ name ] = value;
				return;
			} else {
				value = el.value;
			}
			if ( multi ) {
				values[ name ].push( value );
			} else {
				values[ name ] = value;
			}
		} );
		return values;
	}

	function fieldWrappers( form ) {
		return Array.prototype.slice.call( form.querySelectorAll( '[data-lif-name]' ) );
	}

	/** Show/hide fields; hidden fields are disabled so they are neither validated nor sent. */
	function applyConditions( form ) {
		if ( ! logic ) {
			return;
		}
		var wrappers = fieldWrappers( form );
		var defs = wrappers.map( function ( wrapper ) {
			var conditions = null;
			try {
				conditions = JSON.parse( wrapper.getAttribute( 'data-lif-conditions' ) || 'null' );
			} catch ( e ) {}
			return { name: wrapper.getAttribute( 'data-lif-name' ), conditions: conditions };
		} );
		var visible = logic.visibility( defs, collectValues( form ) );
		wrappers.forEach( function ( wrapper ) {
			var show = visible[ wrapper.getAttribute( 'data-lif-name' ) ] !== false;
			wrapper.hidden = ! show;
			if ( wrapper.tagName === 'FIELDSET' ) {
				wrapper.disabled = ! show;
			}
			Array.prototype.forEach.call(
				wrapper.querySelectorAll( 'input, select, textarea' ),
				function ( el ) {
					el.disabled = ! show;
				}
			);
		} );
	}

	function clearErrors( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '.lif-error, .lif-message' ), function ( el ) {
			el.parentNode.removeChild( el );
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '[aria-invalid]' ), function ( el ) {
			el.removeAttribute( 'aria-invalid' );
			var describedBy = ( el.getAttribute( 'aria-describedby' ) || '' )
				.split( ' ' )
				.filter( function ( id ) {
					return id && ! /-error$/.test( id );
				} )
				.join( ' ' );
			if ( describedBy ) {
				el.setAttribute( 'aria-describedby', describedBy );
			} else {
				el.removeAttribute( 'aria-describedby' );
			}
		} );
		Array.prototype.forEach.call( form.querySelectorAll( '.lif-field--error' ), function ( el ) {
			el.classList.remove( 'lif-field--error' );
		} );
	}

	function showMessage( form, text, type, isHtml ) {
		var box = document.createElement( 'div' );
		box.className = 'lif-message lif-message--' + type;
		box.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		box.setAttribute( 'tabindex', '-1' );
		if ( isHtml ) {
			box.innerHTML = text; // Server-sanitised (wp_kses_post) success message.
		} else {
			box.textContent = text;
		}
		form.insertBefore( box, form.firstChild );
		return box;
	}

	function showFieldErrors( form, errors ) {
		var first = null;
		Object.keys( errors ).forEach( function ( name ) {
			var wrapper = form.querySelector( '[data-lif-name="' + name + '"]' );
			if ( ! wrapper ) {
				return;
			}
			wrapper.classList.add( 'lif-field--error' );
			// A captcha's only control is its hidden token box, which can't take focus.
			var control = wrapper.classList.contains( 'lif-field--captcha' ) ? null : wrapper.querySelector( 'input, select, textarea' );
			var isGroup = wrapper.tagName === 'FIELDSET';
			// Option inputs are "<prefix>-<name>-<index>"; the group's base id drops the index.
			var base = control && control.id ? ( isGroup ? control.id.replace( /-\d+$/, '' ) : control.id ) : form.id + '-' + name;
			var id = base + '-error';
			var p = document.createElement( 'p' );
			p.className = 'lif-error';
			p.id = id;
			p.textContent = errors[ name ];
			wrapper.appendChild( p );
			var target = isGroup ? wrapper : control;
			if ( target ) {
				target.setAttribute( 'aria-invalid', 'true' );
				var describedBy = target.getAttribute( 'aria-describedby' );
				target.setAttribute( 'aria-describedby', ( describedBy ? describedBy + ' ' : '' ) + id );
			}
			if ( ! first ) {
				first = control;
			}
		} );
		return first;
	}

	function resetCaptcha( form ) {
		if ( window.turnstile && form.querySelector( '.cf-turnstile' ) ) {
			try {
				window.turnstile.reset( form.querySelector( '.cf-turnstile' ) );
			} catch ( e ) {}
		}
		if ( window.grecaptcha && form.querySelector( '.g-recaptcha' ) && window.grecaptcha.reset ) {
			try {
				window.grecaptcha.reset();
			} catch ( e ) {}
		}
	}

	/** reCAPTCHA v3: fetch a fresh token right before sending. */
	function withRecaptchaV3( form ) {
		var input = form.querySelector( '.lif-recaptcha-v3' );
		if ( ! input || ! window.grecaptcha || ! window.grecaptcha.execute ) {
			return Promise.resolve();
		}
		return new Promise( function ( resolve ) {
			window.grecaptcha.ready( function () {
				window.grecaptcha
					.execute( input.getAttribute( 'data-sitekey' ), { action: input.getAttribute( 'data-action' ) } )
					.then( function ( token ) {
						input.value = token;
						resolve();
					}, resolve );
			} );
		} );
	}

	function setBusy( form, busy ) {
		var button = form.querySelector( '[type="submit"]' );
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( button ) {
			button.disabled = busy;
		}
	}

	/**
	 * Send the form's analytics event, if it has one, to gtag.js or Google Tag Manager.
	 * Calls `done` once the event is sent (or after a short wait), so a redirect doesn't
	 * cut it off.
	 */
	function track( form, done ) {
		var finished = false;
		var finish = function () {
			if ( ! finished ) {
				finished = true;
				done();
			}
		};
		var name = form.getAttribute( 'data-lif-event' );
		var params = {
			form_id: form.getAttribute( 'data-lif-form' ),
			form_name: form.getAttribute( 'data-lif-event-form' ) || '',
		};
		var sent = false;
		try {
			if ( name && typeof window.gtag === 'function' ) {
				window.gtag( 'event', name, Object.assign( {}, params, { event_callback: finish, event_timeout: 1000 } ) );
				sent = true;
			} else if ( name && window.dataLayer && typeof window.dataLayer.push === 'function' ) {
				window.dataLayer.push( Object.assign( { event: name }, params, { eventCallback: finish, eventTimeout: 1000 } ) );
				sent = true;
			}
		} catch ( e ) {
			sent = false;
		}
		if ( sent ) {
			window.setTimeout( finish, 1200 ); // Blocked or slow analytics never hold the visitor up for long.
		} else {
			finish();
		}
	}

	var BLOCKED = {};

	/**
	 * Whether a REST response was refused before it reached the plugin. The plugin's own
	 * replies always say whether the submission succeeded, and its errors use `liforms_` codes.
	 */
	function blocked( status, data ) {
		if ( [ 401, 403, 404, 405 ].indexOf( status ) === -1 ) {
			return false;
		}
		if ( data && typeof data === 'object' && ( typeof data.success === 'boolean' || /^liforms_/.test( String( data.code || '' ) ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * After a no-JavaScript-style submission, the page reloads with ?lif_sent=ID and the success
	 * message carries the form's analytics event: send it, then tidy the address so a reload
	 * doesn't count the submission again.
	 */
	function trackSent( root ) {
		var sent = ( root || document ).querySelector( '.lif-message[data-lif-sent][data-lif-event]' );
		if ( ! sent ) {
			return;
		}
		sent.removeAttribute( 'data-lif-sent' );
		track( sent, function () {} );
		try {
			var url = new window.URL( window.location.href );
			url.searchParams.delete( 'lif_sent' );
			url.searchParams.delete( 'lif_msg' );
			window.history.replaceState( window.history.state, '', url.toString() );
		} catch ( e ) {}
	}

	function submit( form, event ) {
		if ( ! window.fetch || ! window.FormData || ! config.restUrl ) {
			return; // Fall back to a normal POST.
		}
		event.preventDefault();
		if ( form.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		clearErrors( form );
		setBusy( form, true );

		withRecaptchaV3( form )
			.then( function () {
				var headers = {};
				if ( config.nonce ) {
					headers[ 'X-WP-Nonce' ] = config.nonce;
				}
				return window.fetch( config.restUrl + form.getAttribute( 'data-lif-form' ) + '/submit', {
					method: 'POST',
					body: new window.FormData( form ),
					credentials: 'same-origin',
					headers: headers,
				} );
			} )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return null;
					} )
					.then( function ( data ) {
						return blocked( response.status, data ) ? BLOCKED : data || { success: false, message: config.i18n && config.i18n.error };
					} );
			} )
			.then( function ( data ) {
				if ( data === BLOCKED ) {
					// The site, a security plugin or a firewall refused the REST request before it
					// reached the plugin (e.g. "REST API for logged-in users only"). The form works
					// without JavaScript, so send it the ordinary way. The captcha token is unused.
					window.liforms.submitNatively( form );
					return;
				}
				setBusy( form, false );
				if ( data && data.success ) {
					if ( data.redirect ) {
						track( form, function () {
							window.liforms.navigate( data.redirect );
						} );
						return;
					}
					track( form, function () {} );
					form.dispatchEvent( new window.CustomEvent( 'liforms:success', { bubbles: true, detail: data } ) );
					if ( data.hide_form ) {
						var box = document.createElement( 'div' );
						box.id = form.id;
						box.className = 'lif-message lif-message--success';
						box.setAttribute( 'role', 'status' );
						box.setAttribute( 'tabindex', '-1' );
						box.innerHTML = data.message_html || '';
						form.parentNode.replaceChild( box, form );
						box.focus();
					} else {
						form.reset();
						applyConditions( form );
						resetCaptcha( form );
						showMessage( form, data.message_html || data.message, 'success', !! data.message_html ).focus();
					}
					return;
				}
				resetCaptcha( form );
				var message = ( data && data.message ) || ( config.i18n && config.i18n.error ) || 'Error';
				var box2 = showMessage( form, message, 'error', false );
				var first = showFieldErrors( form, ( data && data.errors ) || {} );
				( first || box2 ).focus();
				form.dispatchEvent( new window.CustomEvent( 'liforms:error', { bubbles: true, detail: data } ) );
			} )
			.catch( function () {
				setBusy( form, false );
				resetCaptcha( form );
				showMessage( form, ( config.i18n && config.i18n.error ) || 'Error', 'error', false ).focus();
			} );
	}

	function init( root ) {
		trackSent( root );
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( 'form.lif-form' ), function ( form ) {
			if ( form.__liforms ) {
				return;
			}
			form.__liforms = true;
			applyConditions( form );
			form.addEventListener( 'input', function () {
				applyConditions( form );
			} );
			form.addEventListener( 'change', function () {
				applyConditions( form );
			} );
			form.addEventListener( 'submit', function ( event ) {
				submit( form, event );
			} );
		} );
	}

	window.liforms = window.liforms || {};
	window.liforms.init = init;
	window.liforms.applyConditions = applyConditions;
	window.liforms.collectValues = collectValues;
	window.liforms.navigate = function ( url ) {
		window.location.assign( url );
	};
	window.liforms.submitNatively = function ( form ) {
		window.HTMLFormElement.prototype.submit.call( form ); // Skips the submit event, so no loop.
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}
} )();
