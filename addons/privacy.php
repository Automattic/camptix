<?php

/**
 *
 */
class CampTix_Addon_Privacy extends CampTix_Addon {
	/**
	 * Hook into WordPress and CampTix.
	 */
	public function camptix_init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_personal_data_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_personal_data_erasers' ) );
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
			'exporter_friendly_name' => __( 'CampTix Attendee Data' ),
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

		$number = 20;
		$page   = (int) $page;

		$data_to_export = array();

		$buyer_prop_to_export = apply_filters( 'camptix_privacy_buyer_props_to_export', array(
			'tix_buyer_name'  => __( 'Ticket Buyer Name', 'camptix' ),
			'tix_buyer_email' => __( 'Ticket Buyer E-mail Address', 'camptix' ),
		) );

		$attendee_prop_to_export = apply_filters( 'camptix_privacy_attendee_props_to_export', array(
			'tix_first_name' => __( 'First Name', 'camptix' ),
			'tix_last_name'  => __( 'Last Name', 'camptix' ),
			'tix_email'      => __( 'E-mail Address', 'camptix' ),
		) );

		$post_query = new WP_Query(
			array(
				'posts_per_page' => $number,
				'paged'          => $page,
				'post_type'      => 'camptix_attendee',
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
						'key'   => 'tix_buyer_email',
						'value' => $email_address,
					),
				),
			)
		);

		foreach ( (array) $post_query->posts as $post ) {
			$attendee_data_to_export = array();

			if ( $email_address === $post->tix_buyer_email ) {
				foreach ( $buyer_prop_to_export as $key => $label ) {
					$value = get_post_meta( $post->ID, $key, true );

					if ( ! empty( $value ) ) {
						$attendee_data_to_export[] = array(
							'name'  => $label,
							'value' => $value,
						);
					}
				}
			}

			if ( $email_address === $post->tix_email ) {
				foreach ( $attendee_prop_to_export as $key => $label ) {
					$value = get_post_meta( $post->ID, $key, true );

					if ( ! empty( $value ) ) {
						$attendee_data_to_export[] = array(
							'name'  => $label,
							'value' => $value,
						);
					}
				}

				$questions = $camptix->get_sorted_questions( $post->tix_ticket_id );
				$answers   = $post->tix_questions;

				foreach ( $questions as $question ) {
					if ( isset( $answers[ $question->ID ] ) ) {
						$answer = $answers[ $question->ID ];

						if ( is_array( $answer ) ) {
							$answer = implode( ', ', $answer );
						}

						if ( ! empty( $answer ) ) {
							$attendee_data_to_export[] = array(
								'name'  => esc_html( apply_filters( 'the_title', $question->post_title ) ),
								'value' => nl2br( esc_html( $answer ) ),
							);
						}
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
	 * @param array $erasers
	 *
	 * @return array
	 */
	public function register_personal_data_erasers( $erasers ) {
		$erasers['camptix-attendee'] = array(
			'eraser_friendly_name' => __( 'CampTix Attendee Data' ),
			'callback'             => array( $this, 'attendee_personal_data_eraser' ),
		);

		return $erasers;
	}


	public function attendee_personal_data_eraser( $email_address, $page ) {

	}
}

camptix_register_addon( 'CampTix_Addon_Privacy' );
