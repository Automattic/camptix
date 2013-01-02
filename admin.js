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
			order: 0,
			json: ''
		}
	});

	var QuestionView = Backbone.View.extend({
		events: {
			'click a.tix-item-delete': 'clear'
		},

		initialize: function() {
			this.model.bind( 'change', this.render, this );
			this.model.bind( 'destroy', this.remove, this );
		},

		render: function() {
			// Update the hidden input.
			this.model.set( 'json', '' );
			this.model.set( 'json', JSON.stringify( this.model.toJSON() ) );

			this.template = _.template( $( '#camptix-tmpl-question' ).html(), null, camptix.template_options );
			this.$el.html( this.template( this.model.toJSON() ) );
			return this;
		},

		clear: function(e) {
			if ( ! confirm( 'Are you sure you want to remove this question?' ) )
				return false;

			this.model.destroy();
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
			$('#tix-questions-container').append(view.render().el);
		}
	});

	camptix.models.Question = Question;
	camptix.questions = new Questions();
	camptix.views.QuestionsView = new QuestionsView({ collection: camptix.questions });

	$(document).ready(function() {
		$( ".tix-ui-sortable" ).sortable({
			items: ".tix-item-sortable",
			handle:'.tix-item-sort-handle',
			placeholder: "tix-item-highlight",
			update: function(e, ui) {
				// tix_refresh_questions_order();
			}
		});
	});

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

		// Questions v2 (jQuery UI Sortable)
		if ( $( ".tix-ui-sortable" ).length > 0 ) {
			var tix_refresh_questions_order = function() {
				var items = $( ".tix-ui-sortable .tix-item" );
				for ( var i = 0; i < items.length; i++ )
					$(items[i]).find('input.tix-field-order').val(i);
			};

			$( '#tix-add-question-new' ).click(function() {
				$( '#tix-add-question-action' ).hide();
				$( '#tix-add-question-new-form' ).show();
				$( '#tix-add-question-type' ).change();
				return false;
			});

			// Show/hide the values input for certain question types.
			$( '#tix-add-question-type' ).change(function() {
				var value = $(this).val();
				var $row = $('.tix-add-question-values-row');

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
				$( '#tix-add-question-existing-form' ).trigger( 'update.camptix' );
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

					question.set( attr, value );
				});

				console.log( question );
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

					$( parent ).find( 'input' ).each(function() {
						var attr = $(this).data('model-attribute');
						if ( ! attr )
							return;
						question.set( attr, $(this).val() );
					});

					camptix.questions.add( question );

					$(checkbox).attr('checked',false);
				});

				$( '#tix-add-question-existing-form' ).trigger( 'update.camptix' );
				return false;
			});
		}
	});
}(jQuery));