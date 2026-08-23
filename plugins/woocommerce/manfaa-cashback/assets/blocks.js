/**
 * Manfaa code + estimate — inner block for the Cart and Checkout Blocks.
 *
 * Written against the globals WooCommerce and WordPress already load
 * (wc.blocksCheckout, wc.blocksComponents, wp.element, wp.i18n), so the
 * plugin ships no build step and no node_modules. `force: true` puts the
 * block on the page without the merchant editing it.
 */
( function () {
	'use strict';

	var checkout = window.wc && window.wc.blocksCheckout;
	var components = window.wc && window.wc.blocksComponents;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var __ = window.wp.i18n.__;
	var sprintf = window.wp.i18n.sprintf;

	if ( ! checkout || ! checkout.registerCheckoutBlock ) {
		return;
	}

	var settings = ( window.wc.wcSettings && window.wc.wcSettings.getSetting( 'manfaa_data', {} ) ) || {};

	function clean( value ) {
		var digits = String( value || '' ).replace( /\D+/g, '' ).slice( 0, 6 );
		return digits;
	}

	function lookup( code ) {
		return window.fetch( settings.lookupUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Manfaa-Nonce': settings.lookupNonce },
			body: JSON.stringify( { code: code } ),
		} ).then( function ( r ) { return r.json(); } );
	}

	// The Cart block hands inner blocks a `cart` prop; the Checkout block
	// does NOT, so on the checkout step props.cart is undefined and the
	// field rendered blank even with a code in the session. Read the cart
	// from the shared wc/store/cart data store, which is populated in both
	// contexts, and fall back to the prop where it exists.
	var useSelect = window.wp.data && window.wp.data.useSelect;

	function useCart( props ) {
		var fromStore = useSelect
			? useSelect( function ( select ) {
					var store = select( 'wc/store/cart' );
					return store ? store.getCartData() : null;
			  }, [] )
			: null;
		return ( fromStore && fromStore.extensions ) ? fromStore : ( props.cart || {} );
	}

	function Panel( props ) {
		var cart = useCart( props );
		var ext = ( cart.extensions && cart.extensions.manfaa ) || {};
		var stored = ext.code || '';
		var estimate = ext.estimate || { available: false };
		var label = ext.label || __( 'Manfaa code', 'manfaa-cashback' );

		var state = useState( { value: stored, status: stored ? 'stored' : 'idle', message: '' } );
		var s = state[ 0 ];
		var setS = state[ 1 ];
		var timer = useRef( null );
		var lastSent = useRef( stored );

		// A code stored in the session (a returning buyer) shows as applied.
		useEffect( function () {
			if ( stored && stored !== lastSent.current ) {
				lastSent.current = stored;
				setS( { value: stored, status: 'stored', message: '' } );
			}
		}, [ stored ] );

		function apply( code ) {
			lastSent.current = code;
			checkout.extensionCartUpdate( { namespace: 'manfaa', data: { code: code } } );
		}

		function onChange( event ) {
			var code = clean( event.target.value );
			setS( { value: code, status: code.length === 6 ? 'checking' : 'idle', message: '' } );

			if ( timer.current ) {
				window.clearTimeout( timer.current );
			}

			if ( code.length !== 6 ) {
				if ( code === '' && lastSent.current ) {
					apply( '' );
				}
				return;
			}

			timer.current = window.setTimeout( function () {
				if ( ! ext.lookup ) {
					apply( code );
					setS( { value: code, status: 'stored', message: '' } );
					return;
				}

				lookup( code ).then( function ( answer ) {
					// The lookup route stored the code in the session itself;
					// the cart update makes the Blocks re-read the estimate.
					apply( code );
					setS( {
						value: code,
						status: answer.valid === true ? 'valid' : answer.valid === false ? 'invalid' : 'stored',
						message: answer.message || '',
					} );
				} ).catch( function () {
					apply( code );
					setS( { value: code, status: 'stored', message: __( "We'll check this code when your order is placed.", 'manfaa-cashback' ) } );
				} );
			}, 350 );
		}

		var hint;

		if ( s.status === 'checking' ) {
			hint = __( 'Checking…', 'manfaa-cashback' );
		} else if ( s.message ) {
			hint = s.message;
		} else if ( s.status === 'stored' ) {
			hint = __( 'Manfaa code applied.', 'manfaa-cashback' );
		} else {
			hint = __( 'Enter the code from your Manfaa app to earn cashback on this order.', 'manfaa-cashback' );
		}

		var estimateRow = null;

		if ( estimate.available && components && components.TotalsItem ) {
			var text = estimate.shortfall_laari > 0
				? sprintf( __( 'Add MVR %s more to earn cashback', 'manfaa-cashback' ), estimate.shortfall_mvr )
				: 'MVR ' + estimate.estimate_mvr;

			estimateRow = el( components.TotalsItem, {
				className: 'manfaa-estimate-row',
				label: estimate.wording || __( 'Estimated Manfaa cashback', 'manfaa-cashback' ),
				value: el( 'span', null, text ),
			} );
		}

		return el(
			'div',
			{ className: 'manfaa-panel manfaa-panel--block manfaa-panel--' + s.status, dir: settings.isRtl ? 'rtl' : 'ltr' },
			el( 'label', { className: 'manfaa-panel__label', htmlFor: 'manfaa-code' }, label ),
			el( 'div', { className: 'manfaa-panel__row' },
				el( 'input', {
					id: 'manfaa-code',
					className: 'manfaa-panel__input',
					type: 'text',
					inputMode: 'numeric',
					autoComplete: 'off',
					maxLength: 6,
					pattern: '[0-9]*',
					placeholder: __( '6-digit code', 'manfaa-cashback' ),
					value: s.value,
					onChange: onChange,
					'aria-describedby': 'manfaa-code-hint',
				} ),
				s.status === 'valid' ? el( 'span', { className: 'manfaa-panel__tick', 'aria-hidden': true }, '✓' ) : null
			),
			el( 'p', { id: 'manfaa-code-hint', className: 'manfaa-panel__hint', 'aria-live': 'polite' }, hint ),
			estimateRow
		);
	}

	var metadata = {
		name: 'manfaa/panel',
		parent: [ 'woocommerce/cart-order-summary-block', 'woocommerce/checkout-order-summary-block' ],
		attributes: { lock: { type: 'object', default: { remove: true, move: true } } },
	};

	checkout.registerCheckoutBlock( { metadata: metadata, component: Panel, force: true } );

	// Editor: a placeholder so the block is visible and locked in place.
	if ( window.wp.blocks && window.wp.blockEditor && window.wp.blocks.registerBlockType && ! window.wp.blocks.getBlockType( 'manfaa/panel' ) ) {
		window.wp.blocks.registerBlockType( 'manfaa/panel', {
			title: __( 'Manfaa code', 'manfaa-cashback' ),
			category: 'woocommerce',
			parent: metadata.parent,
			attributes: metadata.attributes,
			supports: { html: false, multiple: false, inserter: false },
			edit: function () {
				return el( 'div', window.wp.blockEditor.useBlockProps( { className: 'manfaa-panel manfaa-panel--editor' } ),
					el( 'label', { className: 'manfaa-panel__label' }, __( 'Manfaa code', 'manfaa-cashback' ) ),
					el( 'div', { className: 'manfaa-panel__row' }, el( 'input', { className: 'manfaa-panel__input', type: 'text', placeholder: '482917', disabled: true } ) ),
					el( 'p', { className: 'manfaa-panel__hint' }, __( 'Buyers enter their Manfaa code here. Configure it under Manfaa Cashback.', 'manfaa-cashback' ) )
				);
			},
			save: function () {
				return el( 'div', window.wp.blockEditor.useBlockProps.save() );
			},
		} );
	}
} )();
