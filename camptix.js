/**
 * CampTix Javascript
 *
 * Hopefully runs during wp_footer.
 */
(function($){
	var tix = $( '#tix' );
	$( tix ).addClass( 'tix-js' );

	if ( $( tix ).hasClass( 'tix-has-dynamic-receipts' ) ) {
		refresh_receipt_emails = function() {
			var fields = $('.tix-field-email');
			var html = '';
			var previously_checked = $('[name="tix_receipt_email_js"]:checked').val();
			var checked = false;

			for ( var i = 0; i < fields.length; i++ ) {
				var value = fields[i].value;
				if ( value.length < 1 ) continue;

				var field = $('<div><label><input type="radio" name="tix_receipt_email_js" /> <span>container</span></label><br /></div>');
				$(field).find('span').text(value);
				$(field).find('input').attr('value', value);

				if ( previously_checked != undefined && previously_checked == value && ! checked )
					checked = $(field).find('input').attr('checked','checked');

				html += $(field).html();
			}

			if ( html.length < 1 )
				html = '<label>' + camptix_l10n.enterEmail + '</label>';

			if ( html == $('#tix-receipt-emails-list').html() )
				return;

			$('#tix-receipt-emails-list').html(html);

			previously_checked = $('[name="tix_receipt_email_js"]:checked').val();
			if ( previously_checked == undefined || previously_checked.length < 1 )
				$('#tix-receipt-emails-list input:first').attr('checked','checked');
		};

		$('.tix-field-email').change(refresh_receipt_emails);
		$('.tix-field-email').keyup(refresh_receipt_emails);
		$(document).ready(refresh_receipt_emails);
	}

	// Hide unknown attendee fields when reloading the page
	$( document ).ready( function() {
		tix.find( 'input.unknown-attendee' ).each( hide_input_rows_for_unknown_attendee );
	} );

	// Hide unknown attendee fields when checkbox is clicked
	tix.find( 'input.unknown-attendee' ).change( hide_input_rows_for_unknown_attendee );

	/**
	 * Hide the input fields for unknown attendees
	 */
	function hide_input_rows_for_unknown_attendee() {
		// Select core input rows. There aren't any question rows because those are removed by filter_unconfirmed_attendees_questions().
		var input_rows = $( this ).parents( 'table' ).find( 'tr.tix-row-first-name, tr.tix-row-last-name, tr.tix-row-email' );

		if ( this.checked ) {
			input_rows.each( function() {
				$( this ).addClass( 'tix-hidden' );
			} );
		} else {
			input_rows.each( function() {
				$( this ).removeClass( 'tix-hidden' );
			} );
		}
	}
}(jQuery));