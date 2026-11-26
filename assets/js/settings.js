/**
 * Forms → Settings: show the SMTP details only while SMTP is on, and suggest the usual port
 * for the chosen encryption.
 */
( function () {
	// Only show the SMTP details while "Use Custom SMTP" is ticked.
	var toggle = document.getElementById( 'lif-smtp-enabled' );
	var details = document.getElementById( 'lif-smtp-details' );
	if ( toggle && details ) {
		toggle.addEventListener( 'change', function () {
			details.hidden = ! toggle.checked;
		} );
	}

	// Switch to the usual port when the encryption changes, unless a custom port was typed.
	var enc = document.getElementById( 'lif-smtp-encryption' );
	var port = document.getElementById( 'lif-smtp-port' );
	var ports = { tls: '587', ssl: '465', none: '25' };
	if ( enc && port ) {
		enc.addEventListener( 'change', function () {
			if ( [ '587', '465', '25', '' ].indexOf( port.value ) !== -1 ) {
				port.value = ports[ enc.value ];
			}
		} );
	}

	// Select the whole Zoho redirect URI or monitoring key when it's focused, ready to copy.
	[ 'lif-zoho-redirect', 'lif-monitor-key' ].forEach( function ( id ) {
		var input = document.getElementById( id );
		if ( input ) {
			input.addEventListener( 'focus', function () {
				input.select();
			} );
		}
	} );
} )();
