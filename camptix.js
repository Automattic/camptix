/*
 * MDN Cookie Framework
 * https://developer.mozilla.org/en-US/docs/Web/API/document.cookie#A_little_framework.3A_a_complete_cookies_reader.2Fwriter_with_full_unicode_support
 * Updated: 2014-08-28
 */
var docCookies={getItem:function(e){return decodeURIComponent(document.cookie.replace(new RegExp("(?:(?:^|.*;)\\s*"+encodeURIComponent(e).replace(/[\-\.\+\*]/g,"\\$&")+"\\s*\\=\\s*([^;]*).*$)|^.*$"),"$1"))||null},setItem:function(e,o,n,t,c,r){if(!e||/^(?:expires|max\-age|path|domain|secure)$/i.test(e))return!1;var s="";if(n)switch(n.constructor){case Number:s=1/0===n?"; expires=Fri, 31 Dec 9999 23:59:59 GMT":"; max-age="+n;break;case String:s="; expires="+n;break;case Date:s="; expires="+n.toUTCString()}return document.cookie=encodeURIComponent(e)+"="+encodeURIComponent(o)+s+(c?"; domain="+c:"")+(t?"; path="+t:"")+(r?"; secure":""),!0},removeItem:function(e,o,n){return e&&this.hasItem(e)?(document.cookie=encodeURIComponent(e)+"=; expires=Thu, 01 Jan 1970 00:00:00 GMT"+(n?"; domain="+n:"")+(o?"; path="+o:""),!0):!1},hasItem:function(e){return new RegExp("(?:^|;\\s*)"+encodeURIComponent(e).replace(/[\-\.\+\*]/g,"\\$&")+"\\s*\\=").test(document.cookie)},keys:function(){for(var e=document.cookie.replace(/((?:^|\s*;)[^\=]+)(?=;|$)|^\s*|\s*(?:\=[^;]*)?(?:\1|$)/g,"").split(/\s*(?:\=[^;]*)?;\s*/),o=0;o<e.length;o++)e[o]=decodeURIComponent(e[o]);return e}};

/*
 * jQuery appear plugin
 *
 * Copyright (c) 2012 Andrey Sidorov
 * licensed under MIT license.
 *
 * https://github.com/morr/jquery.appear/
 *
 * Version: 0.3.6
 */
!function(e){function r(r){return e(r).filter(function(){return e(this).is(":appeared")})}function t(){a=!1;for(var e=0,t=i.length;t>e;e++){var n=r(i[e]);if(n.trigger("appear",[n]),p[e]){var o=p[e].not(n);o.trigger("disappear",[o])}p[e]=n}}function n(e){i.push(e),p.push()}var i=[],o=!1,a=!1,f={interval:250,force_process:!1},u=e(window),p=[];e.expr[":"].appeared=function(r){var t=e(r);if(!t.is(":visible"))return!1;var n=u.scrollLeft(),i=u.scrollTop(),o=t.offset(),a=o.left,f=o.top;return f+t.height()<i||f-(t.data("appear-top-offset")||0)>i+u.height()||a+t.width()<n||a-(t.data("appear-left-offset")||0)>n+u.width()?!1:!0},e.fn.extend({appear:function(r){var i=e.extend({},f,r||{}),u=this.selector||this;if(!o){var p=function(){a||(a=!0,setTimeout(t,i.interval))};e(window).scroll(p).resize(p),o=!0}return i.force_process&&setTimeout(t,i.interval),n(u),e(u)}}),e.extend({force_appear:function(){return o?(t(),!0):!1}})}(function(){return"undefined"!=typeof module?require("jquery"):jQuery}());

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

	/**
	 * Automatically prepend http:// to URL fields if the user didn't.
	 *
	 * Some browsers will reject input like "example.org" as invalid because it's missing the protocol. This
	 * confuses users who don't realize that the protocol is required.
	 */
	tix.find( 'input[type=url]' ).on( 'blur', function( event ) {
		var url = $( this ).val();

		if ( '' == url ) {
			return;
		}

		if ( url.match( '^https?:\/\/.*' ) === null ) {
			$( this ).val( 'http://' + url );
		}
	} );

	// Get a cookie object
	function tixGetCookie( name ) {
		var cookie = docCookies.getItem( name );

		if ( null == cookie ) {
			cookie = {};
		} else {
			cookie = $.parseJSON( cookie );
		}

		return cookie;
	}

	// Count unique visitors to [tickets] page
	// TODO: Refactor to use wpCookies instead of MDN Cookie Framework
	$( document ).ready( function() {
		if ( ! tix.length ) {
			return;
		}

		var cookie  = tixGetCookie( 'camptix_client_stats' ),
			ajaxURL = camptix_l10n.ajaxURL;

		// Do nothing if we've already counted them
		if ( cookie.hasOwnProperty( 'visited_tickets_form' ) ) {
			return;
		}

		// If it's their first visit, bump the counter on the server and set the client cookie
		cookie.visited_tickets_form = true;

		if ( window.location.href.indexOf( 'tix_reservation_token' ) > -1 ) {
			ajaxURL += window.location.search;
		}

		$.post(
			ajaxURL,
			{
				action:  'camptix_client_stats',
				command: 'increment',
				stat:    'tickets_form_unique_visitors'
			},

			function( response ) {
				if ( true != response.success ) {
					return;
				}

				docCookies.setItem(
					'camptix_client_stats',
					JSON.stringify( cookie ),
					60 * 60 * 24 * 365
				);
			}
		);
	} );

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

	/**
	 * Lazy load camptix_attendees avatars
	 *
	 * @uses jQuery appear plugin
	 * @uses Jetpack's jquery.spin
	 */
	var lazyLoad = {
		cache: {
			$document:  $( document ),
			$attendees: $( '#tix-attendees' )
		},

        /**
		 * Initialize placeholders to be lazy-loaded into avatars.
         */
		init: function() {
			// Dependencies
			if ( lazyLoad.cache.$attendees.length < 1 ||
				'undefined' === typeof $.fn.appear ||
				'undefined' === typeof wp ) {
				return;
			}

			var spinner = 'undefined' !== typeof $.fn.spin;

			lazyLoad.avatarTemplate = wp.template( 'tix-attendee-avatar' );

			lazyLoad.cache.$placeholders = lazyLoad.cache.$attendees.find( '.avatar-placeholder' );

			lazyLoad.cache.$placeholders.appear({ interval: 500 });

			lazyLoad.cache.$placeholders.one( 'appear', function(event) {
				var $placeholder = $( event.target );

				$placeholder.each(function() {
					if ( spinner ) {
						$( this ).spin( 'medium' );
					}
					lazyLoad.convertPlaceholder( $( this ) );
				});
			});

			// Trigger appear event for the placeholders in the initial viewport
			$.force_appear();
		},

        /**
		 * Replace a placeholder with an instance of the avatar template.
		 *
         * @param {Object} $placeholder
         */
		convertPlaceholder: function( $placeholder ) {
			var content = lazyLoad.avatarTemplate( $placeholder.data() );

			if ( content ) {
				$placeholder.after( content ).remove();
			}
		}
	};

	$( document ).ready( lazyLoad.init )

}(jQuery));