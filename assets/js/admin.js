/**
 * Mounts the builder on the Forms → Edit screen. The JSON config is kept in a hidden
 * textarea that is saved with the normal "Publish / Update" button.
 */
( function ( wp ) {
	'use strict';

	var builder = window.liformsBuilder;
	var textarea = document.getElementById( 'liforms-config' );
	var mount = document.getElementById( 'liforms-builder-root' );
	if ( ! builder || ! textarea || ! mount ) {
		return;
	}

	var el = wp.element.createElement;
	var dirty = false;

	function App() {
		var state = wp.element.useState( function () {
			return builder.parseConfig( textarea.value, builder.data.defaults );
		} );
		return el( builder.Builder, {
			config: state[ 0 ],
			onChange: function ( next ) {
				state[ 1 ]( next );
				textarea.value = JSON.stringify( next );
				dirty = true;
			},
		} );
	}

	if ( wp.element.createRoot ) {
		wp.element.createRoot( mount ).render( el( App ) );
	} else {
		wp.element.render( el( App ), mount );
	}

	var postForm = document.getElementById( 'post' );
	if ( postForm ) {
		postForm.addEventListener( 'submit', function () {
			dirty = false;
		} );
	}
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
} )( window.wp );
