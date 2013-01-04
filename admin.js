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

		className: 'tix-item tix-item-sortable',

		events: {
			'click a.tix-item-delete': 'clear',
			'click a.tix-item-edit': 'edit'
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
		},

		edit: function(e) {
			camptix.views.NewQuestionForm.hide();
			camptix.views.ExistingQuestionForm.hide();
			camptix.views.EditQuestionForm.show( this.model );
			return this;
		}
	});

	var Questions = Backbone.Collection.extend({
		model: Question,

		initialize: function() {
			this.on( 'add', this._add, this );
		},

		_add: function( item ) {
			item.set( 'order', this.length );
		}
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
	camptix.views.QuestionsView = new QuestionsView({ collection: camptix.questions });

	var QuestionForm = Backbone.View.extend({
		template: null,
		data: {},

		initialize: function() {
			this.$container = $( '#tix-question-form' );
			this.$action = $( '#tix-add-question-action' );

			this.template = _.template( $( this.template ).html(), null, camptix.template_options );
			this.render.apply( this );
			return this;
		},

		render: function() {
			this.$el.html( this.template( this.data ) );
			this.hide.apply( this );

			this.$container.append( this.$el );
			return this;
		},

		show: function() {
			this.$action.hide();
			this.$el.show();
			return this;
		},

		hide: function() {
			this.$el.hide();
			this.$action.show();
			return this;
		}
	});

	var NewQuestionForm = QuestionForm.extend({
		template: '#camptix-tmpl-new-question-form',

		events: {
			'click .tix-cancel': 'hide',
			'click .tix-add': 'add'
		},

		render: function() {
			var that = this;
			QuestionForm.prototype.render.apply( this, arguments );
			this.$type = this.$el.find( '#tix-add-question-type' );
			this.$type.on( 'change', function() { that.typeChange.apply( that ); } );

			this.typeChange.apply( this );
			return this;
		},

		add: function( e ) {
			var question = new camptix.models.Question();

			this.$el.find( 'input, select' ).each( function() {
				var attr = $( this ).data( 'model-attribute' );
				var attr_type = $( this ).data( 'model-attribute-type' );

				if ( ! attr )
					return;

				var value = $( this ).val();

				// Special treatment for checkboxes.
				if ( 'checkbox' == attr_type )
					value = !! $( this ).prop('checked');

				question.set( attr, value, { silent: true } );
			});

			camptix.questions.add( question );

			// Clear form
			this.$el.find( 'input[type="text"], select' ).val( '' );
			this.$el.find( 'input[type="checkbox"]' ).prop( 'checked', false );
			this.typeChange.apply( this );

			e.preventDefault();
			return this;
		},

		typeChange: function() {
			var value = this.$type.val();
			var $row = this.$el.find( '.tix-add-question-values-row' );

			if ( value.match( /radio|checkbox|select/ ) )
				$row.show();
			else
				$row.hide();

			return this;
		}
	});

	var EditQuestionForm = NewQuestionForm.extend({
		render: function() {
			NewQuestionForm.prototype.render.apply( this, arguments );

			this.$el.find( 'h4' ).text( 'Edit question:' );
			this.$el.find( '.tix-add' ).text( 'Save Question' );
			return this;
		},

		show: function( question ) {
			this.question = question;

			this.$el.find( 'input, select' ).each( function() {
				var attr = $( this ).data( 'model-attribute' );
				var attr_type = $( this ).data( 'model-attribute-type' );

				if ( ! attr )
					return;

				// Special treatment for checkboxes.
				if ( 'checkbox' == attr_type )
					$( this ).prop( 'checked', !! question.get( attr ) );
				else
					$( this ).val( question.get( attr ) );
			} );

			this.typeChange.apply( this );
			NewQuestionForm.prototype.show.apply( this, arguments );
		},

		add: function( e ) {
			question = this.question;

			this.$el.find( 'input, select' ).each( function() {
				var attr = $( this ).data( 'model-attribute' );
				var attr_type = $( this ).data( 'model-attribute-type' );
				var value;

				if ( ! attr )
					return;

				value = $( this ).val();

				// Special treatment for checkboxes.
				if ( 'checkbox' == attr_type )
					value = !! $( this ).prop( 'checked' );

				question.set( attr, value, { silent: false } );
			} );

			delete this.question;
			this.hide.apply( this );
			e.preventDefault();
			return this;
		}
	});

	var ExistingQuestionForm = QuestionForm.extend({
		template: '#camptix-tmpl-existing-question-form',

		events: {
			'click .tix-cancel': 'hide',
			'click .tix-add': 'add'
		},

		initialize: function() {
			QuestionForm.prototype.initialize.apply( this, arguments );
			camptix.questions.on( 'add remove', this.update_disabled, this );
		},

		render: function() {
			QuestionForm.prototype.render.apply( this, arguments );
			this.update_disabled.apply( this );
			return this;
		},

		add: function( e ) {
			this.$el.find( '.tix-existing-checkbox:checked' ).each( function( index, checkbox ) {
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

			e.preventDefault();
			return this;
		},

		update_disabled: function() {
			this.$el.find( '.tix-existing-question' ).each( function() {
				var question_id = $( this ).data( 'tix-question-id' );
				var cb = $( this ).find( '.tix-existing-checkbox' );
				var found = camptix.questions.where( { post_id: parseInt( question_id, 10 ) } );

				$( cb ).prop( 'disabled', found.length > 0 );
				$( this ).toggleClass( 'tix-disabled', found.length > 0 );
			} );

			return this;
		}
	});

	$(document).ready(function(){

		camptix.views.NewQuestionForm = new NewQuestionForm();
		camptix.views.EditQuestionForm = new EditQuestionForm();
		camptix.views.ExistingQuestionForm = new ExistingQuestionForm();

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
				model.set( 'order', i + 1 );
			}
		} );

		$( '#tix-add-question-new' ).click( function() {
			camptix.views.NewQuestionForm.show();
			return false;
		} );

		$( '#tix-add-question-existing' ).click(function() {
			camptix.views.ExistingQuestionForm.show();
			return false;
		});
	});
}(jQuery));