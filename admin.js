/**
 * CampTix Admin JavaScript
 */
window.camptix = window.camptix || { models: {}, views: {} };

(function($){

	camptix.template_options = {
		evaluate:    /<#([\s\S]+?)#>/g,
		interpolate: /\{\{\{([\s\S]+?)\}\}\}/g,
		escape:      /\{\{([^\}]+?)\}\}(?!\})/g,
		variable:    'data'
	};

	var Question = Backbone.Model.extend({
		defaults: {
			post_id: 0,
			type: 'text',
			question: '',
			values: '',
			required: false,
			menu_order: 0,
			json: ''
		}
	});

	var QuestionView = Backbone.View.extend({

		className: 'tix-item tix-item-sortable',

		events: {
			'click a.tix-item-delete': 'clear'
		},

		initialize: function() {
			this.model.bind( 'change', this.render, this );
			this.model.bind( 'destroy', this.remove, this );
		},

		render: function() {
			// Update the hidden input.
			this.model.set( { json: '' }, { silent: true } );
			this.model.set( { json: JSON.stringify( this.model.toJSON() ) }, { silent: true } );

			this.$el.toggleClass( 'tix-item-required', !! this.model.get( 'required' ) );
			this.$el.data( 'tix-cid', this.model.cid );

			this.template = _.template( $( '#camptix-tmpl-question' ).html(), null, camptix.template_options );
			this.$el.html( this.template( this.model.toJSON() ) );
			return this;
		},

		clear: function(e) {
			if ( ! confirm( 'Are you sure you want to remove this question?' ) )
				return false;

			this.model.destroy();
			$( '.tix-ui-sortable' ).trigger( 'sortupdate' );
			return false;
		}
	});

	var Questions = Backbone.Collection.extend({
		model: Question
	});

	var QuestionsView = Backbone.View.extend({
		initialize: function() {
			this.collection.bind( 'add', this.addOne, this );
		},

		render: function() {
			return this;
		},

		addOne: function( item ) {
			var view = new QuestionView( { model: item } );
			$( '#tix-questions-container' ).append( view.render().el );
		}
	});

	camptix.models.Question = Question;
	camptix.questions = new Questions();

	camptix.questions.on( 'add', function( item ) {
		item.set( 'menu_order', camptix.questions.length );
	} );

	camptix.views.QuestionsView = new QuestionsView({ collection: camptix.questions });

	camptix.questions.on( 'add remove', function() {
		$( '.tix-existing-question' ).each( function() {
			var question_id = $( this ).data( 'tix-question-id' );
			var cb = $( this ).find( '.tix-existing-checkbox' );
			var found = camptix.questions.where( { post_id: parseInt( question_id, 10 ) } );

			$( cb ).prop( 'disabled', found.length > 0 );
			$( this ).toggleClass( 'tix-disabled', found.length > 0 );
		} );
	} );

	$(document).ready(function(){
		$( ".tix-date-field" ).datepicker({
			dateFormat: 'yy-mm-dd',
			firstDay: 1
		});

		// Show or hide the refunds date field in Setup > Beta.
		$('#tix-refunds-enabled-radios input').change(function() {
			if ( $(this).val() > 0 )
				$('#tix-refunds-date').show();
			else
				$('#tix-refunds-date').hide();
		});

		// Clicking on a notify shortcode in Tools > Notify inserts it into the email body.
		$('.tix-notify-shortcode').click(function() {
			var shortcode = $(this).find('code').text();
			$('#tix-notify-body').val( $('#tix-notify-body' ).val() + ' ' + shortcode );
			return false;
		});

		// Ticket availability range in tickets and coupons metabox.
		var tix_availability_dates = $( "#tix-date-from, #tix-date-to" ).datepicker({
			dateFormat: 'yy-mm-dd',
			firstDay: 1,
			onSelect: function( selectedDate ) {
				var option = this.id == "tix-date-from" ? "minDate" : "maxDate",
					instance = $( this ).data( "datepicker" ),
					date = $.datepicker.parseDate(
						instance.settings.dateFormat ||
						$.datepicker._defaults.dateFormat,
						selectedDate, instance.settings );
				tix_availability_dates.not( this ).datepicker( "option", option, date );
			}
		});

		// Coupon applies to all/none links.
		$( '#tix-applies-to-all' ).click( function(e) {
			$( '.tix-applies-to-checkbox' ).prop( 'checked', true );
			e.preventDefault();
			return false;
		});
		$( '#tix-applies-to-none' ).click( function(e) {
			$( '.tix-applies-to-checkbox' ).prop( 'checked', false );
			e.preventDefault();
			return false;
		});

		$( '.tix-ui-sortable' ).sortable( {
			items: '.tix-item-sortable',
			handle: '.tix-item-sort-handle',
			placeholder: 'tix-item-highlight'
		} );

		$( '.tix-ui-sortable' ).on( 'sortupdate', function( e, ui ) {
			var items = $( '.tix-ui-sortable .tix-item-sortable' );
			for ( var i = 0; i < items.length; i++ ) {
				var cid = $( items[i] ).data( 'tix-cid' );
				var model = camptix.questions.getByCid( cid );
				model.set( 'menu_order', i + 1 );
			}
		} );

		$( '#tix-add-question-new' ).click( function() {
			$( '#tix-add-question-action' ).hide();
			$( '#tix-add-question-new-form' ).show();
			$( '#tix-add-question-type' ).change();
			return false;
		} );

		// Show/hide the values input for certain question types.
		$( '#tix-add-question-type' ).change(function() {
			var value = $( this ).val();
			var $row = $( '.tix-add-question-values-row' );

			if ( value.match( /radio|checkbox|select/ ) )
				$( $row ).show();
			else
				$( $row ).hide();
		});

		$( '#tix-add-question-new-form-cancel' ).click(function() {
			$( '#tix-add-question-action' ).show();
			$( '#tix-add-question-new-form' ).hide();
			return false;
		});

		$( '#tix-add-question-existing' ).click(function() {
			$( '#tix-add-question-action' ).hide();
			$( '#tix-add-question-existing-form' ).show();
			return false;
		});

		$( '#tix-add-question-existing-form-cancel' ).click(function() {
			$( '#tix-add-question-action' ).show();
			$( '#tix-add-question-existing-form' ).hide();
			return false;
		});

		$( '#tix-add-question-submit' ).click(function() {
			var question = new camptix.models.Question();

			$('#tix-add-question-new-form').find('input,select').each(function() {
				var attr = $(this).data('model-attribute');
				var attr_type = $(this).data('model-attribute-type');

				if ( ! attr )
					return;

				var value = $(this).val();

				// Special treatment for checkboxes.
				if ( 'checkbox' == attr_type )
					value = $(this).prop('checked');

				question.set( attr, value, { silent: true } );
			});

			camptix.questions.add( question );

			// Clear form
			$('#tix-add-question-new-form input[type="text"], #tix-add-question-new-form select').val('');
			$('#tix-add-question-new-form input[type="checkbox"]').attr('checked',false);
			$('#tix-add-question-type').change();
			return false;
		});

		$( '#tix-add-question-existing-form-add' ).click(function() {

			$( '.tix-existing-checkbox:checked' ).each( function( index, checkbox ) {
				var parent = $( checkbox ).parent();
				var question = new camptix.models.Question();

				$( parent ).find( 'input' ).each( function() {
					var attr = $(this).data( 'model-attribute' );
					if ( ! attr )
						return;

					question.set( attr, $( this ).val(), { silent: true } );
				} );

				// Make sure post_id and required are correct types, not integers.
				question.set( {
					post_id: parseInt( question.get( 'post_id' ), 10 ),
					required: !! parseInt( question.get( 'required' ), 10 )
				}, { silent: true } );

				var found = camptix.questions.where( { post_id: parseInt( question.get( 'post_id' ), 10 ) } );

				// Don't add duplicate existing questions.
				if ( 0 === found.length )
					camptix.questions.add( question );

				$( checkbox ).prop( 'checked', false );
			});

			return false;
		});
	});
}(jQuery));