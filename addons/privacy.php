<?php

/**
 * Functionality to help CampTix comply with privacy regulations.
 */
class CampTix_Addon_Privacy extends CampTix_Addon {
	/**
	 * Hook into WordPress and CampTix.
	 */
	public function camptix_init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_personal_data_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_personal_data_erasers' ) );
		add_filter( 'wp_privacy_anonymize_data', array( $this, 'data_anonymizers' ), 10, 3 );

		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Registers the personal data exporter for attendees.
	 *
	 * @param array $exporters
	 *
	 * @return array
	 */
	public function register_personal_data_exporters( $exporters ) {
		$exporters['camptix-attendee'] = array(
			'exporter_friendly_name' => __( 'CampTix Attendee Data', 'camptix' ),
			'callback'               => array( $this, 'attendee_personal_data_exporter' ),
		);

		return $exporters;
	}

	/**
	 * Finds and exports personal data associated with an email address from the attendees list.
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return array
	 */
	public function attendee_personal_data_exporter( $email_address, $page ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$page = (int) $page;

		$data_to_export = array();

		/**
		 * Filter: Modify the list of ticket buyer properties to export.
		 *
		 * @param array $props Associative array of properties. Key is the identifier of the data,
		 *                     value is the human-readable label for the data in the export file.
		 */
		$buyer_prop_to_export = apply_filters( 'camptix_privacy_buyer_props_to_export', array(
			'tix_receipt_email' => __( 'Ticket Buyer E-mail Address', 'camptix' ),
		) );

		/**
		 * Filter: Modify the list of attendee properties to export.
		 *
		 * @param array $props Associative array of properties. Key is the identifier of the data,
		 *                     value is the human-readable label for the data in the export file.
		 */
		$attendee_prop_to_export = apply_filters( 'camptix_privacy_attendee_props_to_export', array(
			'tix_first_name'             => __( 'First Name', 'camptix' ),
			'tix_last_name'              => __( 'Last Name', 'camptix' ),
			'tix_email'                  => __( 'E-mail Address', 'camptix' ),
			'questions'                  => '',
			'tix_private_form_submit_ip' => __( 'IP while viewing ticketed content', 'camptix' ),
		) );

		$post_query = $this->get_attendee_posts( $email_address, $page );

		foreach ( (array) $post_query->posts as $post ) {
			$attendee_data_to_export = array();

			if ( $email_address === $post->tix_receipt_email ) {
				foreach ( $buyer_prop_to_export as $key => $label ) {
					$export = array();

					switch ( $key ) {
						case 'tix_receipt_email' :
							$value = get_post_meta( $post->ID, $key, true );

							if ( ! empty( $value ) ) {
								$export[] = array(
									'name'  => $label,
									'value' => $value,
								);
							}
							break;
					}

					/**
					 * Filter: Modify the export data for a particular ticket buyer property.
					 *
					 * @param array   $export The export data.
					 * @param string  $key    The property identifier.
					 * @param string  $label  The data label in the export.
					 * @param WP_Post $post   The attendee post object.
					 */
					$export = apply_filters( 'camptix_privacy_export_buyer_prop', $export, $key, $label, $post );

					if ( ! empty( $export ) ) {
						$attendee_data_to_export = array_merge( $attendee_data_to_export, $export );
					}
				}
			}

			if ( $email_address === $post->tix_email ) {
				foreach ( $attendee_prop_to_export as $key => $label ) {
					$export = array();

					switch ( $key ) {
						case 'tix_first_name' :
						case 'tix_last_name' :
						case 'tix_email' :
							$value = get_post_meta( $post->ID, $key, true );

							if ( ! empty( $value ) ) {
								$export[] = array(
									'name'  => $label,
									'value' => $value,
								);
							}
							break;
						case 'questions' :
							$questions = $camptix->get_sorted_questions( $post->tix_ticket_id );
							$answers   = $post->tix_questions;

							foreach ( $questions as $question ) {
								if ( isset( $answers[ $question->ID ] ) ) {
									$answer = $answers[ $question->ID ];

									if ( is_array( $answer ) ) {
										$answer = implode( ', ', $answer );
									}

									if ( ! empty( $answer ) ) {
										$export[] = array(
											'name'  => esc_html( apply_filters( 'the_title', $question->post_title ) ),
											'value' => nl2br( esc_html( $answer ) ),
										);
									}
								}
							}
							break;
						case 'tix_private_form_submit_ip' :
							$values = get_post_meta( $post->ID, $key );
							/* translators: used between list items, there is a space after the comma */
							$values = implode( __( ', ', 'camptix' ), $values );

							if ( ! empty( $values ) ) {
								$export[] = array(
									'name'  => $label,
									'value' => $values,
								);
							}
							break;
					}

					/**
					 * Filter: Modify the export data for a particular attendee property.
					 *
					 * @param array   $export The export data.
					 * @param string  $key    The property identifier.
					 * @param string  $label  The data label in the export.
					 * @param WP_Post $post   The attendee post object.
					 */
					$export = apply_filters( 'camptix_privacy_export_attendee_prop', $export, $key, $label, $post );

					if ( ! empty( $export ) ) {
						$attendee_data_to_export = array_merge( $attendee_data_to_export, $export );
					}
				}
			}

			if ( ! empty( $attendee_data_to_export ) ) {
				$data_to_export[] = array(
					'group_id'    => 'camptix-attendee',
					'group_label' => __( 'CampTix Attendee Data', 'camptix' ),
					'item_id'     => "camptix-attendee-{$post->ID}",
					'data'        => $attendee_data_to_export,
				);
			}
		}

		$done = $post_query->max_num_pages <= $page;

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Registers the personal data eraser for attendees.
	 *
	 * @param array $erasers
	 *
	 * @return array
	 */
	public function register_personal_data_erasers( $erasers ) {
		$erasers['camptix-attendee'] = array(
			'eraser_friendly_name' => __( 'CampTix Attendee Data', 'camptix' ),
			'callback'             => array( $this, 'attendee_personal_data_eraser' ),
		);

		return $erasers;
	}

	/**
	 * Finds and erases personal data associated with an email address from the attendees list.
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return array
	 */
	public function attendee_personal_data_eraser( $email_address, $page ) {
		/* @var CampTix_Plugin $camptix */
		global $camptix;

		$page           = (int) $page;
		$items_removed  = false;
		$items_retained = false;
		$messages       = array();

		/**
		 * Filter: Modify the list of ticket buyer properties to erase.
		 *
		 * @param array $props Associative array of properties. Key is the identifier of the data,
		 *                     value is the data type that is used with the anonymizer function.
		 */
		$buyer_prop_to_erase = apply_filters( 'camptix_privacy_buyer_props_to_erase', array(
			'tix_receipt_email' => 'email',
		) );

		/**
		 * Filter: Modify the list of attendee properties to erase.
		 *
		 * @param array $props Associative array of properties. Key is the identifier of the data,
		 *                     value is the data type that is used with the anonymizer function.
		 */
		$attendee_prop_to_erase = apply_filters( 'camptix_privacy_attendee_props_to_erase', array(
			'tix_first_name'             => 'camptix_first_name',
			'tix_last_name'              => 'camptix_last_name',
			'tix_email'                  => 'email',
			'questions'                  => 'camptix_questions',
			'tix_private_form_submit_ip' => 'ip',
			'tix_privacy'                => '',
		) );

		$post_query = $this->get_attendee_posts( $email_address, $page );

		foreach ( (array) $post_query->posts as $post ) {
			/**
			 * Filter: Toggle erasure for a particular attendee.
			 *
			 * By default this value is `true`, which will cause the erasure to proceed. The value can be set to a
			 * message string explaining why the data was retained, and the erasure will be skipped.
			 *
			 * @param bool|string $anon_message True to erase. Any other value will skip erasure for the current attendee. Default true.
			 * @param WP_Post     $post         The attendee post object.
			 */
			$anon_message = apply_filters( 'camptix_privacy_erase_attendee', true, $post );

			if ( true !== $anon_message ) {
				if ( $anon_message && is_string( $anon_message ) ) {
					$messages[] = esc_html( $anon_message );
				} else {
					/* translators: %d: Comment ID */
					$messages[] = sprintf( __( 'Attendee %d contains personal data but could not be anonymized.', 'camptix' ), $post->ID );
				}

				$items_retained = true;

				continue;
			}

			if ( $email_address === $post->tix_receipt_email ) {
				foreach ( $buyer_prop_to_erase as $key => $type ) {
					/**
					 * Action: Fires for each ticket buyer property in the erasure list.
					 *
					 * Use this to add erasure procedures for additional properties added via
					 * the `camptix_privacy_buyer_props_to_erase` filter.
					 *
					 * @param string  $key  The property identifier.
					 * @param string  $type The data type of the property.
					 * @param WP_Post $post The attendee post object.
					 */
					do_action( 'camptix_privacy_erase_buyer_prop', $key, $type, $post );

					switch ( $key ) {
						case 'tix_receipt_email' :
							$anonymized_value = wp_privacy_anonymize_data( $type );
							update_post_meta( $post->ID, $key, $anonymized_value );
							break;
					}
				}

				$items_removed = true;
			}

			if ( $email_address === $post->tix_email ) {
				foreach ( $attendee_prop_to_erase as $key => $type ) {
					/**
					 * Action: Fires for each attendee property in the erasure list.
					 *
					 * Use this to add erasure procedures for additional properties added via
					 * the `camptix_privacy_attendee_props_to_erase` filter.
					 *
					 * @param string  $key  The property identifier.
					 * @param string  $type The data type of the property.
					 * @param WP_Post $post The attendee post object.
					 */
					do_action( 'camptix_privacy_erase_attendee_prop', $key, $type, $post );

					switch ( $key ) {
						case 'tix_first_name' :
						case 'tix_last_name' :
						case 'tix_email' :
							$anonymized_value = wp_privacy_anonymize_data( $type );
							update_post_meta( $post->ID, $key, $anonymized_value );
							break;
						case 'questions' :
							$questions = $camptix->get_sorted_questions( $post->tix_ticket_id );
							$answers   = $post->tix_questions;

							$anonymized_answers = array();

							foreach ( $questions as $question ) {
								if ( isset( $answers[ $question->ID ] ) ) {
									/**
									 * Filter: Toggle whether to erase the answer data for a particular question.
									 *
									 * @param bool    $erase    Set to false to retain the answer data.
									 * @param WP_Post $question The question in question.
									 */
									$erase = apply_filters( 'camptix_privacy_erase_attendee_question', true, $question );

									if ( false !== $erase ) {
										$type = 'camptix_question_' . $question->tix_type;
										$anonymized_answers[ $question->ID ] = wp_privacy_anonymize_data( $type );
									}
								}
							}

							update_post_meta( $post->ID, 'tix_questions', $anonymized_answers );
							break;
						case 'tix_private_form_submit_ip' :
							$values = get_post_meta( $post->ID, $key );
							$prev   = '';

							foreach ( $values as $value ) {
								update_post_meta( $post->ID, $key, wp_privacy_anonymize_ip( $value ), $prev );
								$prev = $value;
							}
							break;
						case 'tix_privacy':
							// Set the attendee to be hidden from the public Attendees page
							update_post_meta( $post->ID, $key, 'private' );
							break;
					}
				}

				$items_removed = true;
			}

			// Trigger the CampTix actions for attendee posts.
			// This resets the post title and post content based on certain postmeta values.
			do_action( 'save_post', $post->ID, $post, true );
		}

		$done = $post_query->max_num_pages <= $page;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Get the list of attendee posts related to a particular email address.
	 *
	 * @param string $email_address
	 * @param int    $page
	 *
	 * @return WP_Query
	 */
	private function get_attendee_posts( $email_address, $page ) {
		$number = 20;

		return new WP_Query(
			array(
				'posts_per_page' => $number,
				'paged'          => $page,
				'post_type'      => 'tix_attendee',
				'post_status'    => 'any',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => 'tix_email',
						'value' => $email_address,
					),
					array(
						'key'   => 'tix_receipt_email',
						'value' => $email_address,
					),
				),
			)
		);
	}

	/**
	 * Handle custom data types for anonymization.
	 *
	 * @param string $anonymous
	 * @param string $type
	 * @param mixed  $data
	 *
	 * @return mixed
	 */
	public function data_anonymizers( $anonymous, $type, $data ) {
		switch ( $type ) {
			case 'camptix_full_name' :
				$anonymous = __( 'Anonymous', 'camptix' );
				break;
			case 'camptix_first_name' :
				$anonymous = __( 'Anonymous', 'camptix' );
				break;
			case 'camptix_last_name' :
				$anonymous = '';
				break;
			case 'camptix_question_text' :
			case 'camptix_question_textarea' :
				$anonymous = wp_privacy_anonymize_data( 'text' );
				break;
			case 'camptix_question_url' :
				$anonymous = wp_privacy_anonymize_data( 'url' );
				break;
		}

		return $anonymous;
	}

	/**
	 * Suggested content additions for a privacy policy.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = array();

		$content[] = '<p class="privacy-policy-tutorial">' .
		             __( 'This sample language includes the basics around what personal data your CampTix instance may be collecting, storing and sharing, as well as who may have access to that data. Depending on what settings are enabled and which additional plugins are used, the specific information used by your CampTix instance will vary. We recommend consulting with a lawyer when deciding what information to disclose on your privacy policy.', 'camptix' ) .
		             '</p>';

		$content[] = '<h2>' .
		             __( 'What personal data we collect and why we collect it', 'camptix' ) .
		             '</h2>';

		$content[] = __( 'When you register for one of our events, we’ll ask you to provide information including your name and email address. We may also ask for additional information necessary for a specific event, such as home address, phone number, meal preference, t-shirt size, agreement to the code of conduct, areas of interest, and/or interest in attending associate events. We may use this information to:', 'camptix' );

		$content[] = '<ul>' .
		             '<li>' . __( 'Send you information about your ticket and the event', 'camptix' ) . '</li>' .
		             '<li>' . __( 'Respond to your requests, including refunds and complaints', 'camptix' ) . '</li>' .
		             '<li>' . __( 'Process your payments and prevent fraud', 'camptix' ) . '</li>' .
		             '<li>' . __( 'Comply with any legal obligations we have, such as calculating taxes', 'camptix' ) . '</li>' .
		             '<li>' . __( 'Send you updates about the ticketed event and other associated events, if you choose to receive them', 'camptix' ) . '</li>' .
		             '</ul>';

		$content[] = '<h3>' .
		             __( 'Cookies', 'camptix' ) .
		             '</h3>';

		$content[] = __( 'We use cookies to keep track of the number of unique visitors to the Tickets page, and for managing access to content on the site that is restricted to ticket holders.', 'camptix' );

		$content[] = '<h2>' .
		             __( 'Who has access', 'camptix' ) .
		             '</h2>';

		$content[] = __( 'Members of our team have access to the information you provide us. For example, all Event Organizers can access:', 'camptix' );

		$content[] = '<ul>' .
		             '<li>' . __( 'Registration information such as which tickets were purchased and when they were purchased', 'camptix' ) . '</li>' .
		             '<li>' . __( 'Attendee information like your name, email address, and other relevant event attendance details', 'camptix' ) . '</li>' .
		             '</ul>';

		$content[] = __( 'Our team members have access to this information to help organize the event, process refunds and support you.', 'camptix' );

		$content[] = '<h2>' .
		             __( 'What we share with others', 'camptix' ) .
		             '</h2>';

		$content[] = '<p class="privacy-policy-tutorial">' .
		             __( 'In this section you should list who you’re sharing data with, and for what purpose. This could include, but may not be limited to, analytics, marketing, payment gateways, shipping providers, and third party embeds.', 'camptix' ) .
		             '</p>';

		$content[] = '<h3>' .
		             __( 'Payments', 'camptix' ) .
		             '</h3>';

		$content[] = '<p class="privacy-policy-tutorial">' .
		             __( 'In this subsection you should list which third party payment processors you’re using to take payments on your store since these may handle customer data. We’ve included PayPal as an example, but you should remove this if you’re not using PayPal.', 'camptix' ) .
		             '</p>';

		$content[] = __( 'We accept payments through PayPal. When processing payments, some of your data will be passed to PayPal, including information required to process or support the payment, such as the purchase total and billing information.', 'camptix' );

		$content[] = sprintf(
			wp_kses( __( 'Please see the <a href="%s">PayPal Privacy Policy</a> for more details.', 'camptix' ), array( 'a' => array( 'href' => true ) ) ),
			'https://www.paypal.com/us/webapps/mpp/ua/privacy-full'
		);

		$content = implode( "\n\n", $content );

		wp_add_privacy_policy_content(
			'CampTix',
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}

camptix_register_addon( 'CampTix_Addon_Privacy' );
