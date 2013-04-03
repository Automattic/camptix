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
		    $payment_method_obj->payment_checkout( $payment_token );
			$default_parameters['variation_values'] = null;
			$default_parameters['quantity'] = $ticket_qty;
			$default_parameters['provided_price'] = null;
			$default_parameters['comment'] = null;
			$default_parameters['time_requested'] = null;
			$default_parameters['custom_message'] = null;
			$default_parameters['file_data'] = null;
			$default_parameters['is_customisable'] = false;
			$default_parameters['meta'] = null;
			global $wpsc_cart;
			
			$meta_values = get_post_meta($ticket_id, 'ticket-to-prod', true);  
			update_post_meta($meta_values, 'payment-token',$payment_token);
			$cart_item = $wpsc_cart->set_item( $meta_values, $default_parameters );
			$product = get_post( $meta_values );
			if ( is_object( $cart_item ) ){
				do_action( 'wpsc_add_to_cart', $product, $cart_item );
				wp_safe_redirect(get_option( 'shopping_cart_url' ));
			}

		$post_id_wpsc = wp_insert_post($post_array );
		$cat_id = get_term_by('name', 'Ticket Category', 'wpsc_product_category'); 
		update_post_meta($post_id, 'ticket-to-prod', $post_id_wpsc);
		update_post_meta($post_id_wpsc, 'prod-to-ticket', $post_id);
		wp_set_post_terms( $post_id_wpsc,$cat_id->term_id,'wpsc_product_category');
		

		// Security check.
		// $nonce_action = 'update-tix_ticket_' . $post_id; // see edit-form-advanced.php
		// check_admin_referer( $nonce_action );

		if ( isset( $_POST['tix_price'] ) ){
			update_post_meta( $post_id, 'tix_price', $_POST['tix_price'] );
			update_post_meta( $post_id_wpsc, '_wpsc_price', $_POST['tix_price'] );
			update_post_meta( $post_id_wpsc, '_edit_lock', '' );
			update_post_meta( $post_id_wpsc, '_edit_lock', '' );
			update_post_meta( $post_id_wpsc, '_wpsc_sku', "pro-".$post_id."-tkt" );
			update_post_meta( $post_id_wpsc, '_edit_last', $post_author );
			update_post_meta( $post_id_wpsc, '_wpsc_special_price', '0' );
			update_post_meta( $post_id_wpsc, '_wpsc_stock', '' );
			update_post_meta( $post_id_wpsc, '_wpsc_is_donation', '' );
			update_post_meta( $post_id_wpsc, '_wpsc_currency', array() );
			update_post_meta( $post_id_wpsc, '_wpsc_product_metadata', unserialize('a:21:{s:25:"wpec_taxes_taxable_amount";s:0:"";s:18:"wpec_taxes_taxable";s:2:"on";s:13:"external_link";s:0:"";s:18:"external_link_text";s:0:"";s:20:"external_link_target";s:0:"";s:11:"no_shipping";s:1:"0";s:6:"weight";s:1:"0";s:11:"weight_unit";s:5:"pound";s:10:"dimensions";a:6:{s:6:"height";s:1:"0";s:11:"height_unit";s:2:"in";s:5:"width";s:1:"0";s:10:"width_unit";s:2:"in";s:6:"length";s:1:"0";s:11:"length_unit";s:2:"in";}s:8:"shipping";a:2:{s:5:"local";s:1:"0";s:13:"international";s:1:"0";}s:14:"merchant_notes";s:0:"";s:8:"engraved";s:1:"0";s:23:"can_have_uploaded_image";s:1:"0";s:15:"enable_comments";s:0:"";s:21:"notify_when_none_left";s:1:"0";s:24:"unpublish_when_none_left";s:1:"0";s:16:"quantity_limited";s:1:"0";s:7:"special";s:1:"0";s:17:"display_weight_as";s:5:"pound";s:16:"table_rate_price";a:2:{s:8:"quantity";a:0:{}s:11:"table_price";a:0:{}}s:17:"google_prohibited";s:1:"0";}') );
		
		}

// Register this class as a CampTix Addon.
camptix_register_addon( 'CampTix_Addon_Shortcodes' );
