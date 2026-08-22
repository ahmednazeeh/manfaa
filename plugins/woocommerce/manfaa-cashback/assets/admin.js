/* Show the category map only when "Per category" is selected. */
( function ( $ ) {
	function toggle() {
		var per = $( 'input[name$="[pricing_mode]"]:checked' ).val() === 'per_category';
		$( '[data-manfaa-map]' ).toggle( per );
	}
	$( document ).on( 'change', 'input[name$="[pricing_mode]"]', toggle );
	$( toggle );
} )( window.jQuery );
