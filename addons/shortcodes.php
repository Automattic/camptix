<?php
/**
 * A Shortcodes Addon for CampTix
 *
 * Implements a set of shortcodes that make something useful from the
 * CampTix data. Not all shortcodes are ready for production.
 *
 * @since 1.2
 */

class CampTix_Addon_Shortcodes extends CampTix_Addon {
	/**
	 * Runs during camptix_init, @see CampTix_Addon
	 */
	function camptix_init() {
		global $camptix;

		add_action( 'save_post', array( $this, 'save_post' ) );
		add_action( 'shutdown', array( $this, 'shutdown' ) );
		add_action( 'template_redirect', array( $this, 'shortcode_private_template_redirect' ) );

		add_shortcode( 'camptix_attendees', array( $this, 'shortcode_attendees' ) );
		add_shortcode( 'camptix_stats', array( $this, 'shortcode_stats' ) );
		add_shortcode( 'camptix_private', array( $this, 'shortcode_private' ) );

		// Pre-cache attendees list markup
		if ( ! wp_next_scheduled( 'camptix_cache_all_attendees_shortcodes' ) ) {
			$camptix_options = $camptix->get_options();
			$interval        = ( $camptix_options['archived'] ) ? 'daily' : 'hourly';
			wp_schedule_event( time(), $interval, 'camptix_cache_all_attendees_shortcodes' );
		}
		add_action( 'camptix_cache_all_attendees_shortcodes', array( $this, 'cache_all_attendees_shortcodes' ) );
	}

	/**
	 * @param $message
	 * @param int $post_id
	 * @param null $data
	 * @param string $module
	 *
	 * @return mixed
	 */
	function log( $message, $post_id = 0, $data = null, $module = 'shortcode' ) {
		global $camptix;
		return $camptix->log( $message, $post_id, $data, $module );
	}

	/**
	 * Runs when a post is saved.
	 */
	function save_post( $post_id ) {
		// Only real attendee posts.
		if ( wp_is_post_revision( $post_id ) || 'tix_attendee' != get_post_type( $post_id ) )
			return;

		// Only non-draft attendees.
		$post = get_post( $post_id );
		if ( $post->post_status == 'draft' )
			return;

		// Signal to update the last modified time ( see $this->shutdown )
		$this->update_last_modified = true;
	}

	/**
	 * Runs during shutdown, right before php stops execution.
	 */
	function shutdown() {
		global $camptix;

		if ( ! isset( $this->update_last_modified ) || ! $this->update_last_modified )
			return;

		// Bump the last modified time if we've been told to ( see $this->save_post )
		$camptix->update_stats( 'last_modified', time() );
	}

	/**
	 * Routine to preemptively cache the content for all of a site's instances of
	 * the [camptix_attendees] shortcode.
	 */
	public function cache_all_attendees_shortcodes() {
		// Get posts containing the `camptix_attendees` shortcode
		$params = array(
			'post_type'              => 'page',
			'post_status'            => 'publish',
			's'                      => '[camptix_attendees',
			'posts_per_page'         => 50,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);
		$posts  = get_posts( $params );

		if ( ! $posts ) {
			return;
		}

		$regex = get_shortcode_regex( array( 'camptix_attendees' ) );

		foreach ( $posts as $post ) {
			$matches = array();

			if ( ! preg_match_all( "/$regex/", $post->post_content, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$attr = shortcode_parse_atts( $match[3] );
				$attr = $this->sanitize_attendees_atts( $attr );

				$this->get_attendees_shortcode_content( $attr );
			}
		}
	}

	/**
	 * Callback for the [camptix_attendees] shortcode.
	 */
	public function shortcode_attendees( $attr ) {
		// Required scripts
		wp_enqueue_script( 'wp-util' ); // For wp.template()
		if ( wp_script_is( 'jquery.spin', 'registered' ) ) {
			wp_enqueue_script( 'jquery.spin' ); // Enqueue Jetpack's spinner script if available
		}
		wp_enqueue_script( 'camptix' );

		// Only print the JS template once.
		if ( ! has_action( 'wp_print_footer_scripts', array( $this, 'avatar_js_template' ) ) ) {
			add_action( 'wp_print_footer_scripts', array( $this, 'avatar_js_template' ) );
		}

		$attr = $this->sanitize_attendees_atts( $attr );

		return $this->get_attendees_shortcode_content( $attr );
	}

	/**
	 * Generate the key for a particular configuration to use when
	 * setting or retrieving a cached value.
	 *
	 * @param array $attr Sanitized shortcode attributes
	 *
	 * @return string     The cache key
	 */
	protected function generate_attendees_cache_key( $attr ) {
		return 'camptix-attendees-' . md5( maybe_serialize( $attr ) );
	}

	/**
	 * Get the content for an instance of the [camptix_attendees] shortcode.
	 *
	 * This checks for a cached version first. If none is found, it generates
	 * the content and caches it before returning.
	 *
	 * @param array $attr Sanitized shortcode attributes
	 * @param bool $force_refresh True to generate the content even if cached value is found.
	 *
	 * @return string                 Rendered shortcode content
	 */
	public function get_attendees_shortcode_content( $attr, $force_refresh = false ) {
		global $camptix;

		/**
		 * Action: Fires just before the [camptix_attendees] shortcode is rendered.
		 *
		 * @param array $attr The shortcode instance's attributes
		 */
		do_action( 'camptix_attendees_shortcode_init', $attr );

		$cache_key = $this->generate_attendees_cache_key( $attr );

		// Cache duration. Day for active sites, month for archived sites.
		$camptix_options = $camptix->get_options();
		$cache_time      = ( $camptix_options['archived'] ) ? MONTH_IN_SECONDS : DAY_IN_SECONDS;

		// Timestamp for last change in Camptix purchases/profile edits.
		$last_modified = $camptix->get_stats( 'last_modified' );

		// Return the cached value if nothing has changed since it was generated
		// Since key changed, backcompat with non-array cache values is no longer necessary
		if ( ! $force_refresh && false !== ( $cached = get_transient( $cache_key ) ) ) {
			// Allow outdated cached content on non-cronjob requests to avoid long page loads for visitors
			if ( $cached['time'] > $last_modified || ! defined( 'DOING_CRON' ) || ! DOING_CRON ) {
				return $cached['content'];
			}
		}

		$content = $this->render_attendees_list( $attr );

		set_transient(
			$cache_key,
			array(
				'time'    => time(),
				'content' => $content,
			),
			$cache_time
		);

		return $content;
	}

	/**
	 * Normalize, sanitize, and validate attribute values for the [camptix_attendees] shortcode.
	 *
	 * @param array $attr Raw attributes
	 *
	 * @return array      Sanitized attributes
	 */
	public function sanitize_attendees_atts( $attr ) {
		$attr = shortcode_atts(
			array(
				'order'          => 'asc',
				'orderby'        => 'title',
				'posts_per_page' => 10000,
				'tickets'        => false,
				'columns'        => 3,
				'questions'      => '',
			),
			$attr,
			'camptix_attendees'
		);

		// @todo validate atts here

		if ( ! in_array( strtolower( $attr['order'] ), array( 'asc', 'desc' ) ) ) {
			$attr['order'] = 'asc';
		}

		if ( ! in_array( strtolower( $attr['orderby'] ), array( 'title', 'date' ) ) ) {
			$attr['orderby'] = 'title';
		}

		if ( $attr['tickets'] ) {
			$attr['tickets'] = array_map( 'intval', explode( ',', $attr['tickets'] ) );
		}

		$attr['posts_per_page'] = absint( $attr['posts_per_page'] );

		return $attr;
	}

	/**
	 * Render the HTML markup for an instance of [camptix_attendees].
	 *
	 * @param array $attr Sanitized shortcode attributes
	 *
	 * @return string     HTML
	 */
	protected function render_attendees_list( $attr ) {
		global $camptix;

		$query_args = array();
		if ( is_array( $attr['tickets'] ) && count( $attr['tickets'] ) > 0 ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => 'tix_ticket_id',
					'compare' => 'IN',
					'value'   => $attr['tickets'],
				)
			);
		}

		$questions = $this->get_questions_from_titles( $attr['questions'] );

		$paged   = 0;
		$printed = 0;

		ob_start();
		?>
        <div id="tix-attendees">
            <ul class="tix-attendee-list tix-columns-<?php echo absint( $attr['columns'] ); ?>">
				<?php
				while ( true && $printed < $attr['posts_per_page'] ) {
					$paged ++;

					$attendee_args = apply_filters( 'camptix_attendees_shortcode_query_args', array_merge(
						array(
							'post_type'      => 'tix_attendee',
							'posts_per_page' => 200,
							'post_status'    => array( 'publish', 'pending' ),
							'paged'          => $paged,
							'order'          => $attr['order'],
							'orderby'        => $attr['orderby'],
							'fields'         => 'ids', // ! no post objects
							'cache_results'  => false,
						),
						$query_args
					), $attr );
					$attendees = get_posts( $attendee_args );

					if ( ! is_array( $attendees ) || count( $attendees ) < 1 ) {
						break; // life saver!
					}

					// Disable object cache for prepared metadata.
					$camptix->filter_post_meta = $camptix->prepare_metadata_for( $attendees );

					foreach ( $attendees as $attendee_id ) {
						$attendee_answers = (array) get_post_meta( $attendee_id, 'tix_questions', true );
						if ( $printed >= $attr['posts_per_page'] ) {
							break;
						}

						// Skip attendees marked as private.
						$privacy = get_post_meta( $attendee_id, 'tix_privacy', true );
						if ( $privacy == 'private' ) {
							$printed ++;
							continue;
						}

						echo '<li>';

						$first = get_post_meta( $attendee_id, 'tix_first_name', true );
						$last  = get_post_meta( $attendee_id, 'tix_last_name', true );

						// Avatar placeholder
						echo $this->get_avatar_placeholder( get_post_meta( $attendee_id, 'tix_email', true ) );
						?>

                        <div class="tix-field tix-attendee-name">
							<?php echo $camptix->format_name_string( '<span class="tix-first">%first%</span> <span class="tix-last">%last%</span>', esc_html( $first ), esc_html( $last ) ); ?>
                        </div>

						<?php foreach ( $questions as $question ) :
							if ( ! empty ( $attendee_answers[ $question->ID ] ) ) : ?>
								<div class="tix-field tix-<?php echo esc_attr( $question->post_name ); ?>">
									<?php
									$answer = $attendee_answers[ $question->ID ];

									/**
									 * Make sure values stored as arrays are displayed as a comma separated list.
									 */
									if ( is_array( $answer ) ) {
										/* translators: used between list items, there is a space after the comma */
										$answer = implode( __( ', ', 'camptix' ), $answer );
									}

									echo esc_html( $answer );
									?>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>

						<?php
						/**
						 * Action: Fires at the end of each item in the [camptix_attendees] list.
                         *
                         * @param WP_Post $attendee_id The post object for the attendee
						 */
						do_action( 'camptix_attendees_shortcode_item', $attendee_id );

						echo '</li>';

						$printed ++;
					} // foreach

					$camptix->filter_post_meta = false; // cleanup
				} // while true
				?>
            </ul>
        </div>
        <br class="tix-clear"/>
	<?php
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Generate an avatar placeholder element with a data attribute that contains
	 * the Gravatar hash so the real avatar can be loaded asynchronously.
	 *
	 * @param string $id_or_email
	 *
	 * @return string
	 */
	protected function get_avatar_placeholder( $id_or_email ) {
		// @todo Allow customization of avatar and placeholder size
		$size = 96;

		return sprintf(
			'<div
                class="avatar avatar-placeholder"
                data-url="%s"
                data-url2x="%s"
                data-size="%s"
                data-alt="%s"
                data-appear-top-offset="500"
                ></div>',
			get_avatar_url( $id_or_email ),
			get_avatar_url( $id_or_email, array( 'size' => $size * 2 ) ),
			$size,
			''
		);
	}

	/**
	 * An Underscore.js template for the attendee avatar.
	 */
	public function avatar_js_template() {
		?>
        <script type="text/html" id="tmpl-tix-attendee-avatar">
            <img
                    alt="{{ data.alt }}"
                    src="{{ data.url }}"
                    srcset="{{ data.url2x }} 2x"
                    class="avatar avatar-{{ data.size }} photo"
                    height="{{ data.size }}"
                    width="{{ data.size }}"
            >
        </script>
	<?php
	}

	/**
	 * Get full `tix_question` posts from their corresponding titles
	 *
	 * @param string $titles Pipe-separated list of `tix_question` titles.
	 *
	 * @return array Array of question post objects matched to the given titles.
	 */
	protected function get_questions_from_titles( $titles ) {
		/** @var $camptix CampTix_Plugin */
		global $camptix;

		if ( empty( $titles ) ) {
			return array();
		}

		$slugs         = array_map( 'sanitize_title', explode( '|', $titles ) );
		$all_questions = $camptix->get_all_questions();
		$questions     = array();

		foreach ( $slugs as $slug ) {
			$matched = wp_list_filter( $all_questions, array( 'post_name' => $slug ) );

			if ( ! empty( $matched ) ) {
				$questions = array_merge( $questions, $matched );
			}
		}

		return $questions;
	}

	/**
	 * Callback for the [camptix_attendees] shortcode.
	 */
	function shortcode_stats( $atts ) {
		global $camptix;

		return isset( $atts['stat'] ) ? $camptix->get_stats( $atts['stat'] ) : '';
	}

	/**
	 * Executes during template_redirect, watches for the private
	 * shortcode form submission, searches attendees, sets view token cookies.
	 *
	 * @see shortcode_private
	 */
	function shortcode_private_template_redirect() {
		global $camptix;

		// Indicates this function did run, nothing more.
		$this->did_shortcode_private_template_redirect = 1;

		if ( isset( $_POST['tix_private_shortcode_submit'] ) ) {
			$email      = isset( $_POST['tix_email'] )      ? trim( stripslashes( $_POST['tix_email'] ) )      : '';

			// Remove cookies if a previous one was set.
			if ( isset( $_COOKIE['tix_view_token'] ) ) {
				setcookie( 'tix_view_token', '', time() - 60*60, COOKIEPATH, COOKIE_DOMAIN, false );
				unset( $_COOKIE['tix_view_token'] );
			}

			if ( empty( $email ) ) {
				return $camptix->error( __( 'Please enter the e-mail address that was used to register for your ticket.', 'camptix' ) );
			}

			if ( ! is_email( $email ) )
				return $camptix->error( __( 'The e-mail address you have entered does not seem to be valid.', 'camptix' ) );

			$attendees = get_posts( array(
				'posts_per_page' => 50, // sane enough?
				'post_type' => 'tix_attendee',
				'post_status' => 'publish',
				'meta_query' => array(
					array(
						'key' => 'tix_email',
						'value' => $email,
					),
				),
			) );

			if ( $attendees ) {
				$attendee = $attendees[0];

				$view_token = $this->generate_view_token_for_attendee( $attendee->ID );
				setcookie( 'tix_view_token', $view_token, time() + 60*60*48, COOKIEPATH, COOKIE_DOMAIN, false );
				$_COOKIE['tix_view_token'] = $view_token;

				foreach ( $attendees as $attendee ) {
					update_post_meta( $attendee->ID, 'tix_view_token', $view_token );
					$count = get_post_meta( $attendee->ID, 'tix_private_form_submit_count', true );
					if ( ! $count ) $count = 0;
					$count++;
					update_post_meta( $attendee->ID, 'tix_private_form_submit_count', $count );
					add_post_meta( $attendee->ID, 'tix_private_form_submit_entry', $_SERVER );
					add_post_meta( $attendee->ID, 'tix_private_form_submit_ip', @$_SERVER['REMOTE_ADDR'] );
					$this->log( sprintf( 'Viewing private content using %s', @$_SERVER['REMOTE_ADDR'] ), $attendee->ID, $_SERVER );
				}
			} else {
				$this->log( __( 'The information you have entered is incorrect. Please try again.', 'camptix' ) );
			}
		}
	}

	/**
	 * [camptix_private] shortcode callback, depends on the template redirect
	 * part to set cookies, looks for attendee by post by view token, compares
	 * requested ticket ids and shows content or login form.
	 *
	 * @see shortcode_private_template_redirect
	 * @see shortcode_private_login_form
	 * @see shortcode_private_display_content
	 */
	function shortcode_private( $atts, $content ) {
		global $camptix;

		if ( ! isset( $this->did_shortcode_private_template_redirect ) )
			return __( 'An error has occurred.', 'camptix' );

		// Lazy load the camptix js.
		wp_enqueue_script( 'camptix' );

		// Don't cache this page.
		if ( ! defined( 'DONOTCACHEPAGE' ) )
			define( 'DONOTCACHEPAGE', true );

		$args = shortcode_atts( array(
			'ticket_ids' => null,
			'logged_out_message' => '',
		), $atts );

		$can_view_content = false;
		$error = false;

		// If we have a view token cookie, we cas use that to search for attendees.
		if ( isset( $_COOKIE['tix_view_token'] ) && ! empty( $_COOKIE['tix_view_token'] ) ) {
			$view_token = $_COOKIE['tix_view_token'];
			$attendees_params = apply_filters( 'camptix_private_attendees_parameters', array(
				'posts_per_page' => 50, // sane?
				'post_type' => 'tix_attendee',
				'post_status' => 'publish',
				'meta_query' => array(
					array(
						'key' => 'tix_view_token',
						'value' => $view_token,
					),
				),
			) );
			$attendees = get_posts( $attendees_params );

			// Having attendees is one piece of the puzzle.
			// Making sure they have the right tickets is the other.
			if ( $attendees ) {
				$attendee = $attendees[0];

				// Let's try and recreate the view token and see if it was generated for this user.
				$expected_view_token = $this->generate_view_token_for_attendee( $attendee->ID );
				if ( $expected_view_token != $view_token ) {
					$camptix->error( __( 'Looks like you logged in from a different computer. Please log in again.', 'camptix' ) );
					$error = true;
				}

				/** @todo: maybe cleanup the nested ifs **/
				if ( ! $error ) {
					if ( $args['ticket_ids'] )
						$args['ticket_ids'] = array_map( 'intval', explode( ',', $args['ticket_ids'] ) );
					else
						$can_view_content = true;

					// If at least one ticket is found, break.
					if ( $args['ticket_ids'] ) {
						foreach ( $attendees as $attendee ) {
							if ( in_array( get_post_meta( $attendee->ID, 'tix_ticket_id', true ), $args['ticket_ids'] ) ) {
								$can_view_content = true;
								break;
							}
						}
					}

					if ( ! $can_view_content && isset( $_POST['tix_private_shortcode_submit'] ) ) {
						$camptix->error( __( 'Sorry, but your ticket does not allow you to view this content.', 'camptix' ) );
					}
				}

			} else {
				 if ( isset( $_POST['tix_private_shortcode_submit'] ) )
					$camptix->error( __( 'Sorry, but your ticket does not allow you to view this content.', 'camptix' ) );
			}
		}

		if ( $can_view_content && $attendee ) {
			if ( isset( $_POST['tix_private_shortcode_submit'] ) )
				$camptix->info( __( 'Success! Enjoy your content!', 'camptix' ) );

			return $this->shortcode_private_display_content( $args, $content );
		} else {
			if ( ! isset( $_POST['tix_private_shortcode_submit'] ) && ! $error )
				$camptix->notice( __( 'The content on this page is private. Please log in using the form below.', 'camptix' ) );

			return $this->shortcode_private_login_form( $args, $content );
		}
	}

	/**
	 * [camptix_private] shortcode, displays the login form.
	 */
	function shortcode_private_login_form( $atts, $content ) {
		$email = isset( $_POST['tix_email'] ) ? $_POST['tix_email'] : '';
		ob_start();

		// @todo Note that in order to include HTML markup in the logged out message, the shortcode
		// attribute needs to enclose the value in single instead of double quotes. TinyMCE enforces
		// double quotes on HTML attributes, which will break the shortcode if it also uses double quotes.
		if ( ! empty( $atts['logged_out_message'] ) ) {
			echo wpautop( $atts['logged_out_message'] );
		}

		?>

		<div id="tix">
			<?php do_action( 'camptix_notices' ); ?>
			<form method="POST" action="#tix">
				<input type="hidden" name="tix_private_shortcode_submit" value="1" />
				<input type="hidden" name="tix_post_id" value="<?php the_ID(); ?>" />
				<table class="tix-private-form">
					<tr>
						<th class="tix-left" colspan="2"><?php _e( 'Have a ticket? Sign in', 'camptix' ); ?></th>
					</tr>
					<tr>
						<td class="tix-left"><?php _e( 'E-mail', 'camptix' ); ?></td>
						<td class="tix-right"><input name="tix_email" value="<?php echo esc_attr( $email ); ?>" type="text" /></td>
					</tr>
				</table>
				<p class="tix-submit">
					<input type="submit" value="<?php esc_attr_e( 'Login &rarr;', 'camptix' ); ?>">
					<br class="tix-clear">
				</p>
			</form>
		</div>
		<?php

		if ( isset( $atts['logged_out_message_after'] ) ) {
			// support calling a callback function, for situations where advanced HTML, scripting, etc is desired
			if ( $atts['logged_out_message_after'] == 'callback' ) {
				do_action( 'camptix_logged_out_message_after' );
			} else {
				echo wpautop( $atts['logged_out_message_after'] );
			}
		}

		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}

	/**
	 * [camptix_private] shortcode, this part displays the actual content in a #tix div
	 * with notices.
	 */
	function shortcode_private_display_content( $atts, $content ) {
		ob_start();
		echo '<div id="tix">';
		do_action( 'camptix_notices' );

		echo do_shortcode( $content );

		echo '</div>';
		$content = ob_get_contents();
		ob_end_clean();
		return $content;
	}

	function generate_view_token_for_attendee( $attendee_id ) {
		$email = get_post_meta( $attendee_id, 'tix_email', true );
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';

		$view_token = md5( 'tix-view-token-' . strtolower( $email . $ip ) );
		return $view_token;
	}
}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Addon_Shortcodes' );
