<?php
namespace Ekliptor\Cashtippr;

class WoocommerceApiRes {
	public $error = false;
	public $errorMsg = '';
	public $data = array();
}

class WoocommerceApi {
	/** @var WoocommerceApi */
	private static $instance = null;
	/** @var Woocommerce */
	//protected $woocommercePlugin; // removed binding to main plugin class because it constructor gets called from Woocommerce
	
	private function __construct(/*Woocommerce $woocommercePlugin*/) {
		/*
		if ($woocommercePlugin === null)
			throw new \Error("CashTippr Woocommerce plugin class must be provided in constructor of " . get_class($this));
		$this->woocommercePlugin = $woocommercePlugin;
		*/
	}
	
	public static function getInstance(/*Woocommerce $woocommercePlugin = null*/) {
		if (self::$instance === null)
			self::$instance = new self(/*$woocommercePlugin*/);
		return self::$instance;
	}
	
	public function init() {
		// init hooks
		$dbTxidParam = array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => array( self::$instance, 'sanitizeStringParam' ),
						'description' => __( 'The internal TXID of the payment in MySQL.', 'ekliptor' ),
					);
		$txidParam = array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => array( self::$instance, 'sanitizeStringParam' ),
						'description' => __( 'The blockchain TXID of the payment.', 'ekliptor' ),
					);
		$amountParam = array(
						'required' => true,
						'type' => 'number',
						'sanitize_callback' => array( self::$instance, 'sanitizeFloatParam' ),
						'description' => __( 'The amount of the payment.', 'ekliptor' ),
					);
		register_rest_route( 'cashtippr-wc/v1', '/validate', array(
			array(
				'methods' => \WP_REST_Server::READABLE,
				//'permission_callback' => array( self::$instance, 'cashtipprPermissionCallback' ),
				'callback' => array( self::$instance, 'validatePayment' ),
				'args' => array(
					'dbtxid' => $dbTxidParam,
					'txid' => $txidParam,
					'am' => $amountParam
				)
			)
		) );
	}
	
	public function validatePayment(\WP_REST_Request $request) {
		global $wpdb;
		$table = \Cashtippr::getTableName('transactions');
		
		$response = new WoocommerceApiRes();
		$txid = $request->get_param('txid');
		$dbtxid = $request->get_param('dbtxid');
		$query = $wpdb->prepare("SELECT txid, session_id, post_id, days FROM $table WHERE txid = '%s'", array($dbtxid));
		$row = $wpdb->get_row($query);
		if (empty($row)) {
			$response->error = true;
			$response->errorMsg = 'Order not found';
			return rest_ensure_response($response);
		}
		
		$woocommercePlugin = Woocommerce::getInstance();
		$order = new \WC_Order($row->post_id);
		$order->add_meta_data('bchTransactionID', $txid, true);
		if ($woocommercePlugin->isPaidOrder($order) === false) {
			sleep(3); // wait a bit since we don't know how fast our blockchain API updates
			if ($woocommercePlugin->isPaidOrder($order) === false) {
				$order->save_meta_data();
				$response->error = true;
				$response->errorMsg = 'Order has not been paid (yet).';
				return rest_ensure_response($response);
			}
		}
		$order->update_status($woocommercePlugin->getSuccessPaymentStatus());
		$order->save_meta_data(); // to be sure
		$response->data[] = array('url' => $woocommercePlugin->get_return_url($order)); // might be the same page or a different one, depending on WC
		$wpdb->delete($table, array('txid' => $dbtxid)); // otherwise gets deleted on expiration
		return rest_ensure_response($response);
	}
	
	public function updatePermissionCallback(\WP_REST_Request $request) {
		return true; // everyone can access
	}
	
	public function sanitizeStringParam( $value, \WP_REST_Request $request, $param ) {
		return trim( $value );
	}
	
	public function sanitizeFloatParam( $value, \WP_REST_Request $request, $param ) {
		return (float)trim( $value );
	}
}
?>