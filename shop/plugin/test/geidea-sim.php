<?php
/**
 * Geidea Online Payments 3.5.2 callback handler, copied from the site copy (functions/ReturnHandler.php,
 * functions/PaymentComplete.php, the save_token() excerpt and class.geidea.php get_options()).
 * The logic is unchanged; only these test changes:
 *  - the class does not extend WC_Payment_Gateway: get_option() reads woocommerce_geidea_settings like WC_Settings_API;
 *  - save_token() records that it was called (user id only) instead of creating a WC_Payment_Token_CC;
 *  - sim mode "fatal" (test config, or env LP_GEIDEA_SIM=fatal): payment_complete() calls an order method that does not exist, after the meta update,
 *    i.e. a PHP fatal error inside Geidea's handler after the safety net completed the order.
 * return_handler() echoes and calls die() exactly like the original (so it only runs in the php -S test).
 */

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $id, $key, $value, $unique = false ) {
		if ( $unique && isset( $GLOBALS['lp_test']['post_meta'][ $id ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['lp_test']['post_meta'][ $id ][ $key ] = $value;
		return true;
	}
	function get_post_meta( $id, $key = '', $single = false ) {
		return $GLOBALS['lp_test']['post_meta'][ $id ][ $key ] ?? '';
	}
	function current_time( $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

class LP_Test_Geidea_Gateway {
	public $id = 'geidea';

	public function get_option( $key ) {
		$o = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		return $o[ $key ] ?? '';
	}

	private function get_options(): array {
		$options  = get_option( 'woocommerce_' . $this->id . '_settings' );
		$settings = array();
		if ( isset( $options['merchant_gateway_key'] ) ) {
			$settings['merchantGatewayKey'] = $options['merchant_gateway_key'];
		}
		if ( isset( $options['order_status_success'] ) ) {
			$settings['orderStatusSuccess'] = str_replace( 'wc-', '', $options['order_status_success'] );
		}
		$settings['returnUrl'] = get_site_url() . '/?wc-api=geidea';
		return $settings;
	}

	public function save_token( $token_id, $card_number, $expiry_date, $card_type, $user_id ) {
		$GLOBALS['lp_test']['tokens_saved'][] = array( 'user' => $user_id );
	}

	public function payment_complete( $order ) {
		do_action( 'woocommerce_pre_payment_complete', $order->get_id() );

		if ( null !== WC()->session ) {
			WC()->session->set( 'order_awaiting_payment', false );
		}
		if ( $order->get_id() ) {
			$order_needs_processing = false;
			if ( count( $order->get_items() ) > 0 ) {
				foreach ( $order->get_items() as $item ) {
					if ( 'fatal' === ( $GLOBALS['lp_sim_config']['sim'] ?? getenv( 'LP_GEIDEA_SIM' ) ) ) {
						$_product = $order->get_product_from_item_v352( $item ); // Test: an order method that does not exist.
					} else {
						$_product = $order->get_product_from_item( $item );
					}
					if ( $_product ) {
						$virtual_downloadable_item = $_product->is_downloadable() && $_product->is_virtual();
						if ( apply_filters( 'woocommerce_order_item_needs_processing', ! $virtual_downloadable_item, $_product, $order->get_id() ) ) {
							$order_needs_processing = true;
							break;
						}
					} else {
						$order_needs_processing = true;
						break;
					}
				}
			}

			$options = $this->get_options();
			$order->update_status( apply_filters( 'woocommerce_payment_complete_order_status', str_replace( 'wc-', '', $options['orderStatusSuccess'] ), $order->get_id() ) );

			add_post_meta( $order->get_id(), '_paid_date', current_time( 'mysql' ), true );

			if ( apply_filters( 'woocommerce_payment_complete_reduce_order_stock', ! get_post_meta( $order->get_id(), '_order_stock_reduced', true ), $order->get_id() ) ) {
				$order->reduce_order_stock();
			}
			do_action( 'woocommerce_payment_complete', $order->get_id() );
		} else {
			do_action( 'woocommerce_payment_complete_order_status_' . $order->get_status(), $order->get_id() );
		}
	}

	public function return_handler() {
		$GLOBALS['lp_test']['geidea_handler_ran'] = ( $GLOBALS['lp_test']['geidea_handler_ran'] ?? 0 ) + 1;
		try {
			$json_body = file_get_contents( 'php://input' );
			$result    = json_decode( $json_body, true );

			if ( $result == null ) {
				echo esc_html( 'Invalid request!' );
				http_response_code( 400 );
				die();
			}

			$order              = $result['order'];
			$callback_signature = $result['signature'];

			if ( $order['merchantReferenceId'] == null ) {
				echo esc_html( 'Order id is not defined!' );
				http_response_code( 400 );
				die();
			}

			$merchant_key          = $this->get_option( 'merchant_gateway_key' );
			$currency              = $order['currency'];
			$order_id              = $order['orderId'];
			$order_status          = $order['status'];
			$merchant_reference_id = $order['merchantReferenceId'];
			$merchant_password     = $this->get_option( 'merchant_password' );
			$timeStamp             = $result['timeStamp'];

			$amount = number_format( $order['amount'], 2, '.', '' );

			$result_string = $merchant_key . $amount . $currency . $order_id . $order_status . $merchant_reference_id . $timeStamp;

			$hash             = hash_hmac( 'sha256', $result_string, $merchant_password, true );
			$result_signature = base64_encode( $hash );

			if ( $result_signature != $callback_signature ) {
				echo esc_html( 'Invalid signature!' );
				http_response_code( 400 );
				die();
			}

			try {
				$wc_order = wc_get_order( $order['merchantReferenceId'] );
			} catch ( Exception $e ) {
				echo esc_html( 'Order with id ' . $order['merchantReferenceId'] . ' not found!' );
				http_response_code( 404 );
				die();
			}

			$options = $this->get_options();
			if (
				mb_strtolower( $order['status'] ) == 'success' &&
				mb_strtolower( $order['detailedStatus'] ) == 'paid'
			) {
				$user_id = $wc_order->get_user_id();
				if ( $order['cardOnFile'] && $user_id != 0 ) {
					$token_id    = $order['tokenId'];
					$card_number = substr( $order['paymentMethod']['maskedCardNumber'], -4 );
					$expiry_date = $order['paymentMethod']['expiryDate'];
					$card_type   = $order['paymentMethod']['brand'];

					$this->save_token( $token_id, $card_number, $expiry_date, $card_type, $user_id );
				}

				$wc_order->update_meta_data( 'Geidea Order Id', $order_id );
				$wc_order->update_meta_data( 'Merchant Reference Id', $merchant_reference_id );
				$wc_order->update_meta_data( 'Order Success Status Setting', 'wc-' . $options['orderStatusSuccess'] );

				$this->payment_complete( $wc_order );
				WC()->cart->empty_cart();

				echo esc_html( 'Order is completed!' );
				http_response_code( 200 );
				die();
			} elseif (
				mb_strtolower( $order['status'] ) == 'failed' &&
				$wc_order->get_status() != $options['orderStatusSuccess']
			) {
				$last_transaction = end( $order['transactions'] );
				$codes            = $last_transaction['codes'];

				$text = sprintf(
					'%s: %s; %s: %s',
					$codes['responseCode'],
					$codes['responseMessage'],
					$codes['detailedResponseCode'],
					$codes['detailedResponseMessage']
				);
				$wc_order->add_order_note( $text );
				$wc_order->update_meta_data( 'Geidea Order Id', $order_id );
				$wc_order->update_meta_data( 'Merchant Reference Id', $merchant_reference_id );
				$wc_order->update_meta_data( 'Detailed payment gate response message', $text );
				$wc_order->update_meta_data( 'Order Success Status Setting', 'wc-' . $options['orderStatusSuccess'] );

				$wc_order->update_status( apply_filters( 'woocommerce_payment_complete_order_status', 'failed', $wc_order->id ) );

				echo esc_html( 'Payment failed!' );
				http_response_code( 200 );
			} else {
				echo esc_html( 'Not found!' );
				http_response_code( 404 );
			}
			die();
		} catch ( Exception $return_handler_exc ) {
			echo esc_html( 'Internal Server Error!' );
			echo esc_html( $return_handler_exc );
			http_response_code( 500 );
			die();
		}
	}
}
