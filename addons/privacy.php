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
					'group_label' => __( 'CampTix Attendee Data' ),
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
}

camptix_register_addon( 'CampTix_Addon_Privacy' );
