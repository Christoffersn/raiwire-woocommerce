<?php

class Raiwire_Payment_Helper
{

	public static function get_raiwire_payment_transaction_id( $order ) {
		$transaction_id = $order->get_transaction_id();

		if( empty( $transaction_id ) ) {
			$order_id = $order->get_id(); //$order->id;
			$transaction_id = get_post_meta( $order_id, 'Transaction ID', true );
			if( !empty( $transaction_id ) ) {
				//Transform Legacy to new standards
				delete_post_meta( $order_id, 'Transaction ID');
				$order->set_transaction_id( $transaction_id );
				$order->save();
			}
		}

		return $transaction_id;
	}

	public static function get_callback_url( $order_id ) {
		$args = array( 'wc-api' => 'Raiwire_Payment', 'wcorderid' => $order_id);
		return add_query_arg( $args , site_url( '/' ) );
	}

	public static function get_accept_url( $order ) {
		if ( method_exists( $order, 'get_checkout_order_received_url' ) ) {
			return str_replace( '&amp;', '&', $order->get_checkout_order_received_url() );
		}

		return add_query_arg( 'key', $order->order_key, add_query_arg(
				'order', $order->get_id(),
				get_permalink( get_option( 'woocommerce_thanks_page_id' ) )
			)
		);
	}

	public static function get_cancel_url( $order ) {
		if ( method_exists( $order, 'get_cancel_order_url' ) ) {
			return str_replace( '&amp;', '&', $order->get_cancel_order_url() );
		}

		return add_query_arg( 'key', $order->get_order_key(), add_query_arg(
				array(
					'order' => self::is_woocommerce_3() ? $order->get_id() : $order->id,
					'payment_cancellation' => 'yes',
				),
				get_permalink( get_option( 'woocommerce_cart_page_id' ) ) )
		);
	}

	public static function create_raiwire_payment_payment_html( $payment_data ) {
		$html = '<section>';
		$html .= '<h3>' . __( 'Thank you for using Raiwire!', 'raiwire-payment' ) . '</h3>';
		$html .= '	<form action="https://raiwire.com/payment/paymentwindow" method="post" id="raiwireForm">';
		foreach($payment_data as $key => $value){
			$html .= '<input type="hidden" name="'.$key.'" value="'.$value.'">';
		}
		$html .= '</form>';
	
		$html .= '<div class="raiwire_paymentwindow_container">
			<p class="payment_module">
				<a class="button alt" title="Pay using Raiwire" href="javascript: raiwireForm.submit();">Continue to RAIWIRE
				</a>
			</p>
  			</div>';
		$html .= '</section>';
		return $html;
	}


	public static function validate_callback_params( $params, $secret, &$order, &$message ) {
		// Check for empty params
		if ( ! isset( $params ) || empty( $params ) ) {
			$message = "Params are empty";
			return false;
		}

		if( empty( $params['orderid'] ) ) {
			$message = "No Order Id is set";
			return false;
		}

		$order = wc_get_order( $params['orderid'] );
		if ( empty( $order ) ) {
			$message = "Order id {$params["wcorderid"]} not found";
			return false;
		}

		if ( !isset( $params['txnid'] ) ) {
			$message = 'No transction ID is set';
			return false;
		}

		$var = '';
		if ( strlen( $secret ) > 0 ) {
			$var .= $params['txnid'];
            $var .= $params['amount'];
            $var .= $params['orderid'];
  
			$storeHash = md5( $var . $secret );

			if (strtoupper($storeHash) !== strtoupper($params['hash']) ) {
				$message = 'Hash validation failed, check your secret';
				return false;
			}
		}

		return true;
	}

	public static function get_debug_log_link() {
		$html = '<h3 class="wc-settings-sub-title">Debug</h3>';

		$html .= sprintf( '<a id="raiwire-admin-log" class="button" href="%s" target="_blank">View debug logs</a>', self::RP_instance()->get_raiwire_logger()->get_admin_link() );

		return $html;
	}

	public static function RP_instance() {
		return Raiwire_Payment::get_instance();
	}

}
