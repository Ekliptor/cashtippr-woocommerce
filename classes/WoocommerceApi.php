<?php
namespace Ekliptor\Cashtippr;

class WoocommerceApiRes {
	public $error = false;
	public $errorMsg = '';
	public $data = array();
	
	public function setError(string $msg/*, int $code*/) {
		$this->error = true;
		//$this->errorCode = $code;
		$this->errorMsg = $msg;
	}
}

class WoocommerceApi {
	/** @var WoocommerceApi */
	private static $instance = null;
	/** @var Woocommerce */
	//protected $woocommercePlugin; // removed binding to main plugin class because it constructor gets called from Woocommerce
	protected $woocommercePlugin = null;
	
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
		
		$orderIdParam = array(
						'required' => true,
						'type' => 'integer',
						'sanitize_callback' => array( self::$instance, 'sanitizeIntParam' ),
						'description' => __( 'The ID of th WC_Order to check the status for.', 'ekliptor' ),
					);
		$nonceParam = array(
						'required' => true,
						'type' => 'string',
						'sanitize_callback' => array( self::$instance, 'sanitizeStringParam' ),
						'description' => __( 'The nonce to authenticate your request.', 'ekliptor' ),
					);
		register_rest_route( 'cashtippr-wc/v1', '/order-status', array(
			array(
				'methods' => \WP_REST_Server::READABLE,
				'permission_callback' => array( self::$instance, 'orderStatusPermissionCallback' ),
				'callback' => array( self::$instance, 'getOrderStatus' ),
				'args' => array(
					'oid' => $orderIdParam,
					'n' => $nonceParam,
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
		try {
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
		}
		catch (\Exception $e) { // invalid order exception
			$response->setError("Invalid order ID");
		}
		return rest_ensure_response($response);
	}
	
	public function getOrderStatus(\WP_REST_Request $request) {
		if ($this->woocommercePlugin === null)
			$this->woocommercePlugin = Woocommerce::getInstance();
		$response = new WoocommerceApiRes();
		$orderID = $request->get_param('oid');
		try {
			$order = new \WC_Order($orderID);
			$this->woocommercePlugin->addChainTransactionID($order);
			$status = 'pending';
			if ($order->is_paid() === true)
				$status = 'paid';
			else if ($this->woocommercePlugin->checkPaidOrder($order) === true) {
				$order->update_status($this->woocommercePlugin->getSuccessPaymentStatus());
				$order->save_meta_data();
				$status = 'paid';
			}
			else
				$this->woocommercePlugin->sendOrderReceivedEmail($order->get_id());
			$response->data[] = array(
					'id' => $orderID,
					'nonce' => $request->get_param('n'),
					'status' => $status,
					'bchAmount' => (float)$order->get_meta('bchAmount', true),
					'bchAmountReceived' => $order->get_meta('bchAmountReceived', true),
					'qrcode' => $this->woocommercePlugin->generateQrCodeForOrder($order),
					'uri' => $this->woocommercePlugin->generatePaymentUriForOrder($order),
			);
			
		}
		catch (\Exception $e) { // invalid order exception, should already be caught in permission callback
			$response->setError("Invalid order ID");
		}
		return $response;
	}
	
	public function updatePermissionCallback(\WP_REST_Request $request) {
		return true; // everyone can access
	}
	
	public function orderStatusPermissionCallback(\WP_REST_Request $request) {
		$route = $request->get_route();
		$orderID = $request->get_param('oid');
		try {
			$order = new \WC_Order($orderID);
			return  static::verifyNonce($request->get_param('n'), $route, 'order-' . $orderID . '-' . $order->get_billing_email());
		}
		catch (\Exception $e) { // invalid order exception
			return false;
		}
	}
	
	public function sanitizeStringParam( $value, \WP_REST_Request $request, $param ) {
		return trim( $value );
	}
	
	public function sanitizeFloatParam( $value, \WP_REST_Request $request, $param ) {
		return (float)trim( $value );
	}
	
	public function sanitizeIntParam( $value, \WP_REST_Request $request, $param ) {
		return (int)trim( $value );
	}
	
	/**
	 * Create a nonce to be used to authenticate AJAX requests.
	 * Based on wp_create_nonce() which depends on WP sessions (and is thus reliably available on /wp-admin/ only).
	 * https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
	 * @param string $route The full route (inlcuding namespace) of the endpoint. For example: /my-plugin/v1/order-status
	 * @param string|int $action The action to generate the nonce from. This should contain a user secret a 3rd party can't easily
	 * guess (such ass address, registration timestamp,...).
	 * @return string
	 */
	public static function createNonce(string $route, $action = -1 ): string {
		$i     = wp_nonce_tick();
		return substr( wp_hash( $i . '|' . $route . '|' . $action , 'nonce' ), -12, 10 );
	}
	
	public static function verifyNonce(string $nonce, string $route, $action = -1): bool {
		$i     = wp_nonce_tick();
		
		// Nonce generated 0-12 hours ago
		$expected = substr( wp_hash( $i . '|' . $route . '|' . $action , 'nonce' ), -12, 10 );
		if (hash_equals($expected, $nonce))
			return true;
		
		// Nonce generated 12-24 hours ago
		$expected = substr( wp_hash( ($i - 1) . '|' . $route . '|' . $action , 'nonce' ), -12, 10 );
		if (hash_equals($expected, $nonce))
			return true;
		return false;
	}
}
?>