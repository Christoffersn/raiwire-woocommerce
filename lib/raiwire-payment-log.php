<?php

class Raiwire_Payment_Log {

	private $_domain = 'raiwire_payment';

	private $_logger;

	public function __construct() {
		$this->_logger = new WC_Logger();
	}

	public function get_admin_link() {
		$log_path = wc_get_log_file_path( $this->_domain );
		$log_path_parts = explode( '/', $log_path );
		return add_query_arg( array(
			'page' => 'wc-status',
			'tab' => 'logs',
			'log_file' => end( $log_path_parts ),
		), admin_url( 'admin.php' ) );
	}

	public function log( $param ) {
		if ( is_array( $param ) ) {
			$param = print_r( $param, true );
		}
		$this->_logger->add( $this->_domain, $param );
	}
}
