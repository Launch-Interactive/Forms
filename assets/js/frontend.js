/**
 * Launch Forms front end: live conditional logic and AJAX submission.
 * Forms work without this script (plain POST + server-side conditional logic).
 */
( function () {
	'use strict';

	var config = window.launchFormsConfig || {};
	var logic = window.launchFormsConditional;

	function fieldName( inputName ) {
		var match = /^lf\[([a-z0-9_]+)\]/.exec( inputName || '' );
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
		return Array.prototype.slice.call( form.querySelectorAll( '[data-lf-name]' ) );
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
				conditions = JSON.parse( wrapper.getAttribute( 'data-lf-conditions' ) || 'null' );
			} catch ( e ) {}
			return { name: wrapper.getAttribute( 'data-lf-name' ), conditions: conditions };
		} );
		var visible = logic.visibility( defs, collectValues( form ) );
		wrappers.forEach( function ( wrapper ) {
			var show = visible[ wrapper.getAttribute( 'data-lf-name' ) ] !== false;
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
		Array.prototype.forEach.call( form.querySelectorAll( '.lf-error, .lf-message' ), function ( el ) {
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
		Array.prototype.forEach.call( form.querySelectorAll( '.lf-field--error' ), function ( el ) {
			el.classList.remove( 'lf-field--error' );
		} );
	}

	function showMessage( form, text, type, isHtml ) {
		var box = document.createElement( 'div' );
		box.className = 'lf-message lf-message--' + type;
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
			var wrapper = form.querySelector( '[data-lf-name="' + name + '"]' );
			if ( ! wrapper ) {
				return;
			}
			wrapper.classList.add( 'lf-field--error' );
			// A captcha's only control is its hidden token box, which can't take focus.
			var control = wrapper.classList.contains( 'lf-field--captcha' ) ? null : wrapper.querySelector( 'input, select, textarea' );
			var isGroup = wrapper.tagName === 'FIELDSET';
			// Option inputs are "<prefix>-<name>-<index>"; the group's base id drops the index.
			var base = control && control.id ? ( isGroup ? control.id.replace( /-\d+$/, '' ) : control.id ) : form.id + '-' + name;
			var id = base + '-error';
			var p = document.createElement( 'p' );
			p.className = 'lf-error';
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
		var input = form.querySelector( '.lf-recaptcha-v3' );
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
				return window.fetch( config.restUrl + form.getAttribute( 'data-lf-form' ) + '/submit', {
					method: 'POST',
					body: new window.FormData( form ),
					credentials: 'same-origin',
					headers: headers,
				} );
			} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, message: config.i18n && config.i18n.error };
				} );
			} )
			.then( function ( data ) {
				setBusy( form, false );
				if ( data && data.success ) {
					if ( data.redirect ) {
						window.launchForms.navigate( data.redirect );
						return;
					}
					form.dispatchEvent( new window.CustomEvent( 'launch-forms:success', { bubbles: true, detail: data } ) );
					if ( data.hide_form ) {
						var box = document.createElement( 'div' );
						box.id = form.id;
						box.className = 'lf-message lf-message--success';
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
				form.dispatchEvent( new window.CustomEvent( 'launch-forms:error', { bubbles: true, detail: data } ) );
			} )
			.catch( function () {
				setBusy( form, false );
				resetCaptcha( form );
				showMessage( form, ( config.i18n && config.i18n.error ) || 'Error', 'error', false ).focus();
			} );
	}

	function init( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( 'form.lf-form' ), function ( form ) {
			if ( form.__launchForms ) {
				return;
			}
			form.__launchForms = true;
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

	window.launchForms = window.launchForms || {};
	window.launchForms.init = init;
	window.launchForms.applyConditions = applyConditions;
	window.launchForms.collectValues = collectValues;
	window.launchForms.navigate = function ( url ) {
		window.location.assign( url );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}
} )();
