jQuery( document ).ready( function( $ ) {

	if ( $( '.hmbkp_running' ).size() ) {
		hmbkpRedirectOnBackupComplete();
	}

	if ( $( '.hmbkp_estimated-size .calculate' ).size() ) {
		$.get( ajaxurl, { 'action' : 'hmbkp_calculate' },
		    function( data ) {
		    	$( '.hmbkp_estimated-size .calculate' ).fadeOut( function() {
		    		$( this ).empty().append( data );
		    	} ).fadeIn();
		    }
		);
	}

	$.get( ajaxurl, { 'action' : 'hmbkp_cron_test' },
	    function( data ) {
	    	if ( data != 1 ) {
		    	$( '.wrap > h2' ).after( data );
		    }
	    }
	);

	$( '#hmbkp_backup' ).click( function( e ) {

		ajaxRequest = $.get( ajaxurl, { 'action' : 'hmbkp_backup' } );

		setTimeout( function() {

			ajaxRequest.abort();

			hmbkpRedirectOnBackupComplete();

		}, 50 );

		e.preventDefault();

	} );

	$( '.hmbkp-settings-toggle' ).click( function( e ) {

		$( '#hmbkp-settings' ).toggle();

		e.preventDefault();

	} );

	if ( typeof( screenMeta ) != 'undefined' ) {
		$( '.hmbkp-show-help-tab' ).click( screenMeta.toggleEvent );
	}

	if ( window.location.hash == '#hmbkp-settings' ){
		$( '#hmbkp-settings' ).show();
	}


} );

function hmbkpRedirectOnBackupComplete() {

	jQuery.get( ajaxurl, { 'action' : 'hmbkp_is_in_progress' },

		function( data ) {

			if ( data == 0 ) {

				location.reload( true );

			} else {

				setTimeout( 'hmbkpRedirectOnBackupComplete();', 1000 );

				jQuery( '#hmbkp_backup' ).addClass( 'hmbkp_running' ).text( data );

			}
		}
	);

}