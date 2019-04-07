<?php
namespace Ekliptor\Cashtippr;

include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/WoocommerceApi.php';
include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/BlockchainApi/AbstractBlockchainApi.php';


class Woocommerce extends \WC_Payment_Gateway {
	const DEBUG = true;
	const CHECK_CONFIRMATIONS_WEB_MIN = 10;
	
	/** @var Woocommerce */
	private static $instance = null;
	private static $cron_events = array (
		);
	private static $cron_events_hourly = array(
			'check_transaction_confirmations'
		);
	private static $clear_scheduled_hooks = array(
	);
	
	/** @var \Cashtippr */
	protected $cashtippr;
	/** @var \CTIP_Settings */
	protected $cashtipprSettings; // settings already used in parent class
	
	/** @var AbstractBlockchainApi */
	protected $blockchainApi = null;
	/** @var \WC_Session|\WC_Session_Handler */
    protected $session = null;
    /** @var \WC_Cart */
    //protected $cart = null;
	
	public function __construct(/*\Cashtippr $cashtippr*/) {
		// this gets called from Woocommerce, so make sure we cache this instance
		//$this->cashtippr = $cashtippr;
		$this->cashtippr = \Cashtippr::getInstance();
		if (self::$instance === null)
			self::$instance = $this;
		
		$this->id            		= 'cashtippr_woocommerce';
        $this->medthod_title 		= __('CashTippr Woocommerce', 'ekliptor');
        $this->has_fields    		= true;
        $this->method_description 	= __('Earn money by selling products (digital and real world) in your online store using Bitcoin Cash payments.', 'ekliptor');
        $this->icon					= plugins_url( 'img/bch_48.png', CASHTIPPR__PLUGIN_DIR . 'cashtippr.php' );
        
        $this->init();
        
        $title = isset($this->settings['title']) ? $this->settings['title'] : $this->getFrontendDefaultTitle();
        $description = isset($this->settings['description']) ? $this->settings['description'] : $this->getFrontendDefaultDescription();
        $this->title       			= $title; // for frontend (user), also shown in WC settings
        $this->description 			= $description; // for frontend (user)
        //$this->order_button_text 	= "fooo..."; // TODO add option to replace the whole button HTML by overwriting parent functions
        
        $this->session 				= WC()->session;
        //$this->cart    				= WC()->cart;
        
        //$lastCheck = isset($this->settings['lastConfirmationCheck']) ? $this->settings['lastConfirmationCheck'] : 0; // doesn't get saved
        $lastCheck = $this->cashtipprSettings->get('lastCheckedTransactions');
        if ($lastCheck + static::CHECK_CONFIRMATIONS_WEB_MIN*60 <= time())
        	$this->checkTransactionConfirmations();
	}
	
	public static function getInstance(/*\Cashtippr $cashtippr = null*/) {
		if (self::$instance === null)
			self::$instance = new self(/*$cashtippr*/);
		return self::$instance;
	}
	
	public function init() {
		$this->cashtipprSettings = $this->cashtippr->getSettings();
		$this->cashtipprSettings->set('blockchain_api', 'BitcoinComRestApi');
		$this->blockchainApi = AbstractBlockchainApi::getInstance($this->cashtipprSettings->get('blockchain_api'), $this->cashtipprSettings);
		$this->init_settings();
		$this->init_form_fields();
		
		// init hooks
		// note that functions must be public because they are called from event stack
		// call the main class which will call this plugin via our own action
		
		add_filter('cashtippr_js_config', array($this, 'addJavascriptConfig'), 10, 1);
		add_filter('cashtippr_default_settings', array($this, 'addDefaultSettings'), 10, 1);
		
		// WC gateway hooks
		//add_action('woocommerce_api_wc_coinpay', array($this, 'checkIpnResponse')); // called after payment if we use a 3rd party callback
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_order_details_before_order_table', array ($this, 'addPluginPaymentOptions' ), 100, 1);
		
		// Crons
		add_action ( 'check_transaction_confirmations', array ($this, 'checkTransactionConfirmations' ) );
	}
	
	public static function check_plugin_activation() {
		// WP min version check done in main plugin
		$pluginVersion = get_option ( 'cashtippr_woocommerce_installed' );
		if ($pluginVersion === CTIP_WOOCOMMERCE_VERSION)
			return;
		
		foreach ( self::$cron_events as $cron_event ) {
			$timestamp = wp_next_scheduled ( $cron_event );
			if (!$timestamp)
				wp_schedule_event(time(), 'daily', $cron_event);
		}
		foreach ( self::$cron_events_hourly as $cron_event ) {
			$timestamp = wp_next_scheduled ( $cron_event );
			if (!$timestamp)
				wp_schedule_event(time(), 'hourly', $cron_event);
		}
		
		update_option ( 'cashtippr_woocommerce_installed', CTIP_WOOCOMMERCE_VERSION );
	}
	
	public static function plugin_deactivation() {
		// Remove any scheduled cron jobs.
		$events = array_merge(self::$cron_events, self::$cron_events_hourly);
		foreach ( $events as $cron_event ) {
			$timestamp = wp_next_scheduled ( $cron_event );
			if ($timestamp) {
				wp_unschedule_event ( $timestamp, $cron_event );
			}
		}
		foreach (self::$clear_scheduled_hooks as $hook) {
			wp_clear_scheduled_hook($hook);
		}
		
		delete_option('cashtippr_woocommerce_installed');
	}
	
	public static function addWoocommerceGateway(array $load_gateways) {
		$load_gateways[] = '\\Ekliptor\\Cashtippr\\Woocommerce';
		return $load_gateways;
	}
	
	public function getCashtippr(): \Cashtippr {
		return $this->cashtippr;
	}
	
	public function getSettings(): \CTIP_Settings {
		return $this->cashtipprSettings;
	}
	
	public function init_settings() {
		parent::init_settings();
		$this->enabled  = ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
	}
	
	public function init_form_fields() {
		$this->form_fields = array(
				'enabled' => array(
						'title' 		=> __('Enable CashTippr Woocommerce', 'ekliptor'),
						'type'			=> 'checkbox',
						'description'	=> '',
						'default'		=> 'yes'
				),
				'enable0Conf' => array(
						'title' 		=> __('Enable 0-conf payments', 'ekliptor'),
						'type'			=> 'checkbox',
						'description'	=> sprintf(__('If enabled, Woocommerce orders will be instantly marked as paid once the transaction has been broadcast to the network.<br>Otherwise transactions will be marked as pending until %s confirmations have been received.', 'ekliptor'), $this->cashtipprSettings->get('wait_confirmations')),
						'default'		=> 'yes',
						'desc_tip'		=> true
				),
				
				'title' => array(
						'title' 		=> __('Title', 'ekliptor'),
						'type'			=> 'text',
						'description'	=> __('The payment method title your customers will see on your shop.', 'ekliptor'),
						'default'		=> $this->getFrontendDefaultTitle(),
						'desc_tip'		=> true
				),
				'description' => array(
						'title' 		=> __('Description', 'ekliptor'),
						'type'			=> 'text',
						'description'	=> __('The payment method description your customers will see on your shop.', 'ekliptor'),
						'default'		=> $this->getFrontendDefaultDescription(),
						'desc_tip'		=> true
				)
		);
    }
    
    public function is_available() {
    	if (empty($this->cashtippr->getSettings()->get('bch_address')))
    		return false;
    	return parent::is_available();
    }
    
    public function validate_text_field(/*string */$key, $value) { // validate_description_field
    	$value = trim($value);
    	if (empty($value))
    		return $key === 'description' ? $this->getFrontendDefaultDescription() : $this->getFrontendDefaultTitle();
    	return $value;
    }
    
    /*
    public function payment_fields() {
    	// the description (or additional form fields) showing on the order form when this payment method is selected
    	echo "Foo fields...";
    }*/
    
    public function process_payment($order_id) {
    	// "place order" has just been clicked
    	$this->session->set("orderID", $order_id);
    	$order = new \WC_Order($order_id);
    	/*
    	if ($this->isPaidOrder($order_id, false) === true) {
    		sleep(2);
    		if ($this->isPaidOrder($order_id, true) === true) // retry to avoid concurrency issues
    			return;
    	}
    	*/
    	//$wcOrder = wc_get_order($order_id);
    	
		return array(
				'result' => 'success',
				'redirect' => $this->get_return_url($order) // just redirect to the order details page, whe show our payment button(s) there
		);
	}
	
	public function addPluginPaymentOptions() {
		if (!$this->session || !$this->session->get("orderID"))
			return; // shouldn't happen
		$order = new \WC_Order($this->session->get("orderID"));
		if ($order->get_payment_method() !== 'cashtippr_woocommerce')
			return; // the user chose another payment method
		if ($order->is_paid() === true || $order->get_status() === 'cancelled')
			return;
		
		// call isPaidOrder() here again (for older orders) and update status if user manually reloads this page to get the payment completed before cron?
		$bchTxID = $order->get_meta('bchTransactionID');
		if ($bchTxID) { // user says the payment has already been sent
			if ($this->isPaidOrder($order) === false) {
				if ($this->settings['enable0Conf'] === 'yes')
					esc_html_e('Please wait while your payment is being broadcasted to the network. You may close this page or reload it in a few seconds for updates.', 'ekliptor');
				else
					printf(esc_html('Your payment is waiting for %s confirmations. You may close this page and check the store again in couple of minutes. Alternatively just reload this page.', 'ekliptor'), $this->cashtipprSettings->get('wait_confirmations'));
			}
			else {
				$order->update_status($this->getSuccessPaymentStatus());
				$order->save_meta_data();
			}
			return;
		}
		
		$btnConf = array();
		$includedMoneybuttonScript = $this->cashtippr->getIncludedMoneybuttonScript();
		$btnConf['recAddress'] = $this->cashtippr->getReceiverAddress();
		$btnConf['addQrCode'] = false;
		$btnConf['qrcodeStatic'] = '';
		$btnConf['unit'] = $this->cashtipprSettings->get('button_currency');
		$btnConf['amount'] = $order->get_total();
		if ($btnConf['amount'] < 0.00000001)
			$btnConf['amount'] = 0.00000001;
		$btnConf['sats'] = \Cashtippr::toSatoshis($btnConf['amount'] / $this->cashtipprSettings->get('rate_usd_bch'));
		$btnConf['amountBCH'] = $this->cashtippr->toAmountBCH($btnConf['amount'], $btnConf['unit']);
		$btnConf['txid'] = $this->cashtippr->createTransactionId($btnConf['recAddress'], $btnConf['amount'], 0, $order->get_id());
		if ($btnConf['txid'] === false)
			return esc_html("Unable to create a transaction ID. Please try again or report a bug if the problem persists.", "ekliptor") . '<br><br>';
		
		// TODO create 1-time addresses using xPub and show them on this page as a manual payment alternative
		$btnConf['btnText'] = __('Pay', 'ekliptor');
		$btnConf['callbackData'] = array('woocommerce' => true);
		include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'tpl/paymentControls.php';
		$this->cashtippr->setIncludedMoneybuttonScript($includedMoneybuttonScript);
	}
	
	public function checkTransactionConfirmations() {
		$this->cashtipprSettings->set('lastCheckedTransactions', time());
		$orders = $this->getPendingOrders();
		foreach ($orders as $order) {
			if ($this->isPaidOrder($order) === false)
				continue;
			$order->update_status($this->getSuccessPaymentStatus(), '');
			//$order->save_meta_data(); // not needed when calling update_status()
		}
	}
	
	public function addJavascriptConfig(array $cfg): array {
		$cfg['ajaxConfirm'] = true;
		$cfg['keepTransaction'] = true;
		return $cfg;
	}
	
	public function addDefaultSettings(array $defaults): array {
		$addonDefaults = array(
				//'foo' => 1
		);
		return array_merge($defaults, $addonDefaults);
	}
	
	//protected function isPaidOrder(int $orderID, $updateOrderErrors = true): bool {
		//if (!isset($this->session) || !isset($this->session->bchTransactionID))
			//return false;
	public function isPaidOrder(\WC_Order $order, $updateOrderErrors = false): bool {
		$confirmations = $this->blockchainApi->getConfirmationCount($order->get_meta('bchTransactionID'));
		if ($confirmations === -1)
			return false; // TX doesn't exist (yet)
		if ($this->settings['enable0Conf'] !== 'yes' && $confirmations < $this->cashtipprSettings->get('wait_confirmations'))
			return false;
		$this->clearCustomSessionVariables();
		// TODO display errors as HTML if we call this via HTTP from client. currently we just wait (for confirmations) and let the user reload manually
		return true;
	}
	
	public function getSuccessPaymentStatus(): string {
		return 'processing'; // TODO add option to set it to completed immediately (for digial goods)? but processing is default
	}
	
	protected function getPendingOrders(): array {
		$args = array(
				'status' => 'pending',
				'payment_method' => 'cashtippr_woocommerce',
				//'created_via' => '',
				'orderby' => 'modified',
    			'order' => 'DESC',
				'limit' => 5000, // TODO more within 1h?
		);
		$orders = wc_get_orders( $args );
		return $orders;
	}
	
	protected function clearCustomSessionVariables() {
		// shouldn't be needed since the whole session gets destroyed eventually, but let's be clean
		if (!$this->session)
			return;
		unset($this->session->orderID);
	}
	
	protected function getFrontendDefaultTitle(): string {
		return __('Bitcoin Cash (BCH)', 'ekliptor');
	}
	
	protected function getFrontendDefaultDescription(): string {
		return __('Instant and low fee Bitcoin Cash payment on-chain without going through a 3rd party.', 'ekliptor');
	}
	
	protected static function bailOnActivation($message, $escapeHtml = true, $deactivate = true) {
		include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'tpl/message.php';
		if ($deactivate) {
			$plugins = get_option ( 'active_plugins' );
			$cashtippr = plugin_basename ( CTIP_WOOCOMMERCE__PLUGIN_DIR . 'cashtippr-woocommerce.php' );
			$update = false;
			foreach ( $plugins as $i => $plugin ) {
				if ($plugin === $cashtippr) {
					$plugins [$i] = false;
					$update = true;
				}
			}
			
			if ($update) {
				update_option ( 'active_plugins', array_filter ( $plugins ) );
			}
		}
		exit ();
	}
}

// imported from Admin page (admin page currently not used)
add_filter('cashtippr_admin_metaboxes', function (array $pluginBoxes, string $post_type/*, WP_Post $post*/) {
        $pluginBoxes['Woocommerce'] = true;
        return $pluginBoxes;
    }, 10, 2);
?>