<?php
/**
 * Plugin Name: Raiwire Payment
 * Plugin URI: https://www.raiwire.com
 * Description: Raiwire payment for WooCommerce
 * Version: 0.1
 * Author: Christoffer Samuel Nielsen
 * Author URI: https://raiwire.com
 * Text Domain: raiwire-payment
 *
 * @author Christoffersn
 * @package raiwire-payment
 */

define( 'RAIWIRE_PATH', dirname( __FILE__ ) );
define( 'RAIWIRE_VERSION', '0.1' );

add_action( 'plugins_loaded', 'init_raiwire_payment', 0 );


function init_raiwire_payment() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
    
	include( RAIWIRE_PATH . '/lib/raiwire-payment-helper.php' );
	include( RAIWIRE_PATH . '/lib/raiwire-payment-log.php' );

	class Raiwire_Payment extends WC_Payment_Gateway {

		private static $_instance;

		private $_logger;

		public static function get_instance() {

			if ( ! isset( self::$_instance ) ) {
								

				self::$_instance = new self();
			}
			return self::$_instance;
		}

		public function __construct() {

			$this->id = 'raiwire';
			$this->method_title = 'Raiwire';
			$this->icon = WP_PLUGIN_URL . '/' . plugin_basename( dirname( __FILE__ ) ) . '/logo.png';
			$this->has_fields = false;
			$this->method_description = 'Pay with RaiBlocks using Raiwire';
			$this->title              = 'Raiwire';
            $this->description 		  = 'Pay with RaiBlocks using Raiwire';
			
			$this->supports = array(
				'products'
				);

			
			$this->_logger = new Raiwire_Payment_Log();

			$this->init_form_fields();

			$this->init_settings();

			$this->init_raiwire_settings();

			$this->set_raiwire_payment_description_for_checkout();
		}

		public function init_raiwire_settings() {
			$this->enabled = array_key_exists( 'enabled', $this->settings ) ? $this->settings['enabled'] : 'yes';
			$this->merchant = array_key_exists( 'merchant', $this->settings ) ? $this->settings['merchant'] : '';
			$this->secret = array_key_exists( 'secret', $this->settings ) ? $this->settings['secret'] : '';
		}


		public function init_hooks() {
			add_action( 'woocommerce_api_' . strtolower( get_class() ), array( $this, 'raiwire_payment_callback' ) );
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

			if ( is_admin() ) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'wp_before_admin_bar_render', array( $this, 'raiwire_payment_actions' ) );
			}

			wp_enqueue_script( 'jquery' );
		}


		public function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
								'title' => 'Activate module',
								'type' => 'checkbox',
								'label' => 'Enable Raiwire',
								'default' => 'yes',
							),
				'merchant' => array(
								'title' => 'Merchant number',
								'type' => 'text',
								'description' => 'The number identifying your merchant account.',
								'default' => '',
							),
				'secret' => array(
								'title' => 'Secret',
								'type' => 'text',
								'description' => 'The secret is used to stamp data sent between WooCommerce and Raiwire. The key is optional but if used here, must be the same as in the administration.',
								'default' => '',
							),

				);
		}

		public function admin_options() {
			$version = RAIWIRE_VERSION;

			$html = "<h3>Raiwire {$version}</h3>";
			$html .= Raiwire_Payment_Helper::get_debug_log_link();
			$html .= '<h3 class="wc-settings-sub-title">Module configuration</h3>';
			$html .= '<table class="form-table">';

			$html .= $this->generate_settings_html( array(), false );
			$html .= '</table>';

			echo ent2ncr( $html );
		}

		public function payment_fields() {
			if ( $this->description ) {
				$text_replace = wptexturize( $this->description );
				$text_remove_double_lines = wpautop( $text_replace );

				echo $text_remove_double_lines;
			}
		}

		public function set_raiwire_payment_description_for_checkout() {
			global $woocommerce;
			$merchant_number = $this->merchant;

			$cart = WC()->cart;

			if ( ! $cart || ! $merchant_number ) {
				return;
			}
			$html = '';

			$this->description .= $html;
		}


		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		}

		//Used for system to system call-back, similar to Paypal IPN
		public function raiwire_payment_callback() {
			$params = stripslashes_deep( $_GET );
			$message = '';
			$order = null;
			$response_code = 400;
			try {
				$is_valid_call = Raiwire_Payment_Helper::validate_callback_params( $params, $this->secret, $order, $message );
				if ( $is_valid_call ) {
					$message = $this->process_raiwire_payment_callback( $order, $params );
					$response_code = 200;
				} else {
					if ( ! empty( $order ) ) {
						$order->update_status( 'failed', $message );
					}
					$this->_logger->log( "Callback failed - {$message} - GET params:" );
					$this->_logger->log( $params );

				}
			} catch (Exception $ex) {
				$message = 'Callback failed Reason: ' . $ex->getMessage();
				$response_code = 500;
				$this->_logger->log( "Callback failed - {$message} - GET params:" );
				$this->_logger->log( $params );
			}

			$header = 'X-Raiwire-System: "WooCommerce - Raiwire '.RAIWIRE_VERSION.'"';
			header( $header, true, $response_code );
			die( $message );

		}


		protected function process_raiwire_payment_callback( $order, $params ) {
			try {
				$action = $this->process_standard_payments( $order, $params );
				$type = "Standard Payment {$action}";
			} catch ( Exception $e ) {
				throw $e;
			}

			return  "Callback completed - {$type}";
		}


		protected function process_standard_payments( $order, $params ) {
			$action = '';
			$old_transaction_id = Raiwire_Payment_Helper::get_raiwire_payment_transaction_id( $order );
			if ( empty( $old_transaction_id ) ) {
				$order->add_order_note( sprintf( __( 'Rawire payment successful with transaction id %s', 'raiwire-payment' ), $params['txnid'] ) );
				$action = 'created';
			} else {
				$action = 'created (Called multiple times)';
			}
			$order->payment_complete( $params['txnid'] );
			return $action;
		}

		public function receipt_page( $order_id ) {

			$order = wc_get_order( $order_id );
			
			$order_currency = $order->get_currency();
			$order_total =$order->get_total();

			$payment_args = array(
				'cms' => 'WooCommerce - Raiwire '.RAIWIRE_VERSION,
				'storeid' => $this->merchant,
				'currency' => $order_currency,
				'amount' => $order_total,
				'orderid' => $order->get_order_number(),
				'cancelurl' => Raiwire_Payment_Helper::get_cancel_url( $order ),
				'accepturl' => Raiwire_Payment_Helper::get_accept_url( $order ),
				'callbackurl' => Raiwire_Payment_Helper::get_callback_url( $order_id ),
				'callbackType' => 'GET'
			);
			
			if ( strlen( $this->md5key ) > 0 ) {
				$hash = '';
				foreach ( $payment_args as $value ) {
					$hash .= $value;
				}
				$payment_args['hash'] = md5( $hash . $this->md5key );
			}
			
			$payment_html = Raiwire_Payment_Helper::create_raiwire_payment_payment_html($payment_args);

			echo ent2ncr( $payment_html );
		}
		

		public function get_raiwire_logger() {
			return $this->_logger;
		}
		public function plugin_url( $path ) {
			return plugins_url( $path, __FILE__ );
		}
	}

	add_filter( 'woocommerce_payment_gateways', 'add_raiwire_payment_woocommerce' );

	Raiwire_Payment::get_instance()->init_hooks();

	function add_raiwire_payment_woocommerce( $methods ) {
		$methods[] = 'Raiwire_Payment';
		return $methods;
	}

	$plugin_dir = basename( dirname( __FILE__ ) );
	load_plugin_textdomain( 'raiwire-payment', false, $plugin_dir . '/languages' );
}
