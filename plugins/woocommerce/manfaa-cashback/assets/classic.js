/* Manfaa code panel on the classic (shortcode) cart and checkout. */
( function ( $ ) {
	'use strict';

	var cfg = window.manfaaCashback || {};

	function clean( value ) {
		return String( value || '' ).replace( /\D+/g, '' ).slice( 0, 6 );
	}

	function refreshTotals() {
		if ( $( 'form.woocommerce-checkout' ).length ) {
			$( document.body ).trigger( 'update_checkout' );
		} else if ( $( 'form.woocommerce-cart' ).length ) {
			$( document.body ).trigger( 'wc_update_cart' );
		}
	}

	function store( code, $panel ) {
		return $.post( cfg.ajaxUrl, { action: 'manfaa_set_code', nonce: cfg.ajaxNonce, code: code } ).always( refreshTotals );
	}

	function say( $panel, text, status ) {
		$panel.find( '[data-manfaa-message]' ).text( text );
		$panel.attr( 'class', 'manfaa-panel manfaa-panel--' + ( status || 'idle' ) );
	}

	function apply( $panel ) {
		var $input = $panel.find( '[data-manfaa-input]' );
		var code = clean( $input.val() );
		$input.val( code );

		if ( code === '' ) {
			store( '', $panel );
			say( $panel, cfg.i18n.cleared, 'idle' );
			return;
		}

		if ( code.length !== 6 ) {
			return;
		}

		if ( ! cfg.lookup ) {
			store( code, $panel );
			say( $panel, '', 'stored' );
			return;
		}

		say( $panel, cfg.i18n.checking, 'checking' );

		$.ajax( {
			url: cfg.lookupUrl,
			method: 'POST',
			contentType: 'application/json',
			headers: { 'X-Manfaa-Nonce': cfg.lookupNonce },
			data: JSON.stringify( { code: code } ),
		} ).done( function ( answer ) {
			refreshTotals();
			say( $panel, answer.message || '', answer.valid === true ? 'valid' : answer.valid === false ? 'invalid' : 'stored' );
		} ).fail( function () {
			store( code, $panel );
			say( $panel, '', 'stored' );
		} );
	}

	$( document ).on( 'click', '[data-manfaa-apply]', function () {
		apply( $( this ).closest( '[data-manfaa-panel]' ) );
	} );

	$( document ).on( 'keydown', '[data-manfaa-input]', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			apply( $( this ).closest( '[data-manfaa-panel]' ) );
		}
	} );

	$( document ).on( 'input', '[data-manfaa-input]', function () {
		var $input = $( this );
		var code = clean( $input.val() );
		$input.val( code );

		if ( code.length === 6 ) {
			apply( $input.closest( '[data-manfaa-panel]' ) );
		}
	} );

	// The checkout form submits the session's code; this hidden field lets the
	// classic validation hook see a freshly typed one too.
	$( document ).on( 'checkout_place_order', function () {
		var $input = $( '[data-manfaa-input]' ).first();
		if ( $input.length ) {
			var $form = $( 'form.woocommerce-checkout' );
			$form.find( 'input[name=manfaa_code]' ).remove();
			$form.append( $( '<input type="hidden" name="manfaa_code">' ).val( $input.val() ) );
		}
		return true;
	} );
} )( window.jQuery );
