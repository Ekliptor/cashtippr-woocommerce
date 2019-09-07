<?php
namespace Ekliptor\Cashtippr;

use Ekliptor\CashP\BlockchainApi\AbstractBlockchainApi;
use Ekliptor\CashP\BlockchainApi\Http\WordpressHttpAgent;

include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/WoocommerceApi.php';


class Woocommerce extends \WC_Payment_Gateway {
	const DEBUG = true;
	const CHECK_CONFIRMATIONS_WEB_MIN = 10;
	const CHECK_PAYMENT_INTERVAL_SEC = 30;
	const SHOW_ORDER_EXPIRATION_MAX_H = 24; // show payment bust be made within that timeframe if WC manage stock setting is below
	
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
    /** @var bool */
    protected $paymentOptionsShowing = false;
	
	public function __construct(/*\Cashtippr $cashtippr*/) {
		// this gets called from Woocommerce, so make sure we cache this instance
		static::check_plugin_activation();
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
        if ($lastCheck + static::CHECK_CONFIRMATIONS_WEB_MIN*60 <= time()) {
        	//$this->checkTransactionConfirmations(); // better do it at the end of script (to be shown on next page load)
        	add_action('shutdown', array ($this, 'checkTransactionConfirmations' ));
		}
	}
	
	public static function getInstance(/*\Cashtippr $cashtippr = null*/) {
		if (self::$instance === null)
			self::$instance = new self(/*$cashtippr*/);
		return self::$instance;
	}
	
	public function init() {
		$this->cashtipprSettings = $this->cashtippr->getSettings();
		$this->cashtipprSettings->set('blockchain_api', 'BitcoinComRestApi');
		$this->blockchainApi = $this->cashtippr->createBlockchainApiInstance();
		
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
		
		// different WC plugins might overwrite the order details page. so register for all and only print data once with the first hook (far up on the page)
		add_action('woocommerce_order_details_before_order_table', array ($this, 'addPluginPaymentOptions' ), 100, 1);
		add_action('woocommerce_order_details_after_order_table', array ($this, 'addPluginPaymentOptions' ), 100, 1);
		add_action('woocommerce_order_details_before_order_table_items', array ($this, 'addPluginPaymentOptions' ), 100, 1);
		//add_action('woocommerce_thankyou', array ($this, 'addPluginPaymentOptions' ), 100, 1);
		add_action('woocommerce_thankyou_' . $this->id, array ($this, 'addPluginPaymentOptions' ), 100, 1);
		
		// WC email hooks
		add_action( 'woocommerce_email_before_order_table', array($this, 'addBlockchainDataToEmail'), 10, 4 );
		add_action( 'woocommerce_order_status_pending', array($this, 'sendOrderReceivedEmail'), 10, 1 ); // unreliable as this plugin might not be instantiated when this hook triggers
		
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
				),
				'enableBadger' => array(
						'title' 		=> __('Enable BadgerWallet', 'ekliptor'),
						'type'			=> 'checkbox',
						'description'	=> __('Show a BadgerButton for the BadgerWallet browser extension as payment option.', 'ekliptor'),
						'default'		=> 'yes',
						'desc_tip'		=> true
				),
				'sendPaymentInstructions' => array(
						'title' 		=> __('Send payment instructions email', 'ekliptor'),
						'type'			=> 'checkbox',
						'description'	=> __('If enabled, this plugin will send Woocommerce invoice templates (see Woocommerce -> Settings -> Emails) with payment instructions as email.', 'ekliptor'),
						'default'		=> 'yes',
						'desc_tip'		=> true
				),
		);
    }
    
    public function is_available() {
    	$settings = $this->cashtippr->getSettings();
    	if (empty($settings->get('bch_address')) || empty($settings->get('xPub')))
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
    	if (!$this->session/* || !$this->session->get("bchAddress")*/) { // shouldn't happen (and if it does the cart will be empty too)
    		wc_add_notice(esc_html("Your session has expired. You have not been charged. Please add your item(s) to the cart again.", "ekliptor"), 'error');
    		return;
    	}
    	// "place order" has just been clicked. This is the moment the order has been created and we can instantiate an order object by ID
    	$this->clearCustomSessionVariables(); // ensure there is no plugin data left from the previous payment
    	$this->session->set("orderID", $order_id);
    	$order = new \WC_Order($order_id);
    	
    	// decide how much BCH shall be paid
    	$totalFiat = $order->get_total();
    	$bchAmount = $totalFiat / $this->cashtipprSettings->get('rate_usd_bch');
    	if ($bchAmount < \Cashtippr::BCH_DUST_LIMIT_SAT)
    		$bchAmount = 0.0;
    	$order->add_meta_data('bchAmount', $bchAmount, true);
    	$order->add_meta_data('bchAmountReceived', 0.0, true);
    	$order->save_meta_data();
    	
    	/*
    	if ($this->isPaidOrder($order_id, false) === true) {
    		sleep(2);
    		if ($this->isPaidOrder($order_id, true) === true) // retry to avoid concurrency issues
    			return;
    	}
    	*/
    	//$wcOrder = wc_get_order($order_id);
    	//$this->sendOrderReceivedEmail($order_id); // better do it after redirect on payment page
    	
		return array(
				'result' => 'success',
				'redirect' => $this->get_return_url($order) // just redirect to the order details page, whe show our payment button(s) there
		);
	}
	
	public function addPluginPaymentOptions($order_id) {
		if ($this->paymentOptionsShowing === true)
			return;
		if (!$this->session)
			return; // shouldn't happen
		if (!$order_id) {
			if (!$this->session->get("orderID"))
				return;
			$order_id = $this->session->get("orderID");
		}
		$this->paymentOptionsShowing = true;
		try {
			$order = is_object($order_id) ? $order_id : new \WC_Order($order_id); // some hooks return the order object already
			$this->session->set("orderID", $order->get_id()); // ensure it's the current order
		}
		catch (\Exception $e) { // invalid order exception
			echo esc_html('This order does not exist.', 'ekliptor') . '<br><br>';
			return;
		}
		
		if ($order->get_payment_method() !== 'cashtippr_woocommerce')
			return; // the user chose another payment method
		$this->isPaidOrder($order); // trigger the check on manual page reload
		if ($order->get_status() === 'cancelled')
			return;
		if ($order->is_paid() === true) {
			echo esc_html('Your order has been fully paid.', 'ekliptor') . '<br><br>';
			return;
		}
		
		// call isPaidOrder() here again (for older orders) and update status if user manually reloads this page to get the payment completed before cron?
		$this->addChainTransactionID($order);
		$bchTxID = $order->get_meta('bchTransactionID');
		//if ($bchTxID) { // user says the payment has already been sent
			if ($this->checkPaidOrder($order) === false) {
				if ($this->settings['enable0Conf'] === 'yes') // we accepted the payment already and set the order status to "processing"
					esc_html_e('Please wait while your payment is being broadcasted to the network. You may close this page or reload it in a few seconds for updates.', 'ekliptor');
				else
					printf(esc_html('Your payment is waiting for %s confirmations. You may close this page and check the store again in couple of minutes. Alternatively just reload this page.', 'ekliptor'), $this->cashtipprSettings->get('wait_confirmations'));
				echo "<br><br>";
			}
			else {
				$order->update_status($this->getSuccessPaymentStatus());
				$order->save_meta_data();
				return;
			}
		//}
		
		$btnConf = array();
		$includedMoneybuttonScript = $this->cashtippr->getIncludedMoneybuttonScript();
		//$btnConf['recAddress'] = $this->cashtippr->getReceiverAddress(); // we generate anew address for every order to check for manual payments
		$btnConf['recAddress'] = $this->session->get("bchAddress");
		if (empty($btnConf['recAddress'])) {
			$nextCount = $this->cashtipprSettings->get('addressCount') + 1;
			$this->cashtipprSettings->set('addressCount', $nextCount);
			$this->cashtipprSettings->set('hdPathFormat', '0/%d'); // TODO remove in later version (from update)
			$btnConf['recAddress'] = $this->blockchainApi->createNewAddress($this->cashtipprSettings->get('xPub'), $nextCount, $this->cashtipprSettings->get('hdPathFormat'));
			if ($btnConf['recAddress'] === null)
				return esc_html("Unable to create a new address. Please try again or report a bug if the problem persists.", "ekliptor") . '<br><br>';
			// store BCH address in session as it is unique for this customer
			$this->session->set("bchAddress", $btnConf['recAddress']); // TODO also store in DB to have same address if user deletes session (clears cookies)
		}
		$btnConf['recAddress'] = $btnConf['recAddress']->cashAddress;
		if (!$order->get_meta('chainAddress')) {
			$order->add_meta_data('chainAddress', $btnConf['recAddress'], true); // TODO move to a better place?
			$order->save_meta_data();
		}
		
		$btnConf['unit'] = $this->cashtipprSettings->get('button_currency');
		$btnConf['amount'] = $order->get_total();
		if ($btnConf['amount'] < 0.00000001)
			$btnConf['amount'] = 0.00000001;
		$btnConf['sats'] = \Cashtippr::toSatoshis($btnConf['amount'] / $this->cashtipprSettings->get('rate_usd_bch'));
		$btnConf['amountBCH'] = $this->cashtippr->toAmountBCH($btnConf['amount'], $btnConf['unit']);
		//$btnConf['txid'] = $this->session->get("txid");
		$btnConf['txid'] = $order->get_meta('ct-txid');
		if (empty($btnConf['txid'])) {
			$btnConf['txid'] = $this->cashtippr->createTransactionId($btnConf['recAddress'], $btnConf['amount'], 0, $order->get_id());
			if ($btnConf['txid'] === false)
				return esc_html("Unable to create a transaction ID. Please try again or report a bug if the problem persists.", "ekliptor") . '<br><br>';
			//$this->session->set("txid", $btnConf['txid']);
			$order->add_meta_data('ct-txid', $btnConf['txid'], true);
			$order->save_meta_data();
		}
		
		$btnConf['addQrCode'] = true;
		$btnConf['noQrButton'] = true;
		//$btnConf['qrcodeStatic'] = ''; // we show the real code right away
		$btnConf['qrcode'] = $this->cashtippr->generateQrCode($btnConf['txid']);
		$btnConf['btnText'] = __('Pay with Badger Wallet', 'ekliptor');
		$btnConf['uri'] = $this->cashtippr->createPaymentURI($btnConf['recAddress'], $btnConf['amountBCH']);
		$btnConf['callbackData'] = array('woocommerce' => true);
		$btnConf['addBadger'] = $this->settings['enableBadger'] === 'yes';
		$btnConf['loadingImage'] = plugins_url( 'img/loading2.gif', CASHTIPPR__PLUGIN_DIR . 'cashtippr.php' );
		
		$wc_held_duration = floor(get_option( 'woocommerce_hold_stock_minutes' ) / 60);
		$btnConf['orderTimeoutTxt'] = '';
		if ( $wc_held_duration >= 1 && $wc_held_duration <= static::SHOW_ORDER_EXPIRATION_MAX_H && 'yes' === get_option( 'woocommerce_manage_stock' ) ) // be safer and only check for the time setting as admin might change that after order?
			$btnConf['orderTimeoutTxt'] = ' ' . sprintf(__('You must pay within %s hours.', 'ekliptor'), $wc_held_duration);
		
		// headline text
		$btnConf['amountDisplay'] = number_format($btnConf['amountBCH'], $this->cashtipprSettings->get('tokenDigits'));
		$btnConf['tickerDisplay'] = 'BCH';
		
		include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'tpl/paymentControls.php';
		//$this->cashtippr->setIncludedMoneybuttonScript($includedMoneybuttonScript);
		$this->cashtippr->setIncludedMoneybuttonScript(true);
		wp_enqueue_script( 'badger-wallet', \Cashtippr::BADGER_WALLET_JS, array(), CASHTIPPR_VERSION, true ); // use main plugin version
		$this->sendOrderReceivedEmail($order->get_id());
	}
	
	public function checkTransactionConfirmations() {
		$this->cashtipprSettings->set('lastCheckedTransactions', time());
		$orders = $this->getPendingOrders();
		foreach ($orders as $order) {
			$this->addChainTransactionID($order);
			if ($this->checkPaidOrder($order) === false)
				continue;
			$order->update_status($this->getSuccessPaymentStatus(), '');
			//$order->save_meta_data(); // not needed when calling update_status()
		}
	}
	
	public function addJavascriptConfig(array $cfg): array {
		$cfg['ajaxConfirm'] = true;
		$cfg['keepTransaction'] = true;
		$cfg['orderID'] = 0;
		if ($this->session) {
			$cfg['orderID'] = $this->session->get("orderID", 0);
			if (is_object($cfg['orderID'])) // legacy
				$cfg['orderID'] = $cfg['orderID']->get_id();
		}
		if ($cfg['orderID'] !== 0) {
			try {
				$order = new \WC_Order($cfg['orderID']);
				if ($order->get_payment_method() === 'cashtippr_woocommerce')
					$cfg['nonce'] = WoocommerceApi::createNonce('/cashtippr-wc/v1/order-status', 'order-' . $cfg['orderID'] . '-' . $order->get_billing_email());
			}
			catch (\Exception $e) { // invalid order exception
			} 
		}
		$cfg['checkPaymentIntervalSec'] = static::CHECK_PAYMENT_INTERVAL_SEC;
		$cfg['paidTxt'] = __('Paid', 'ekliptor');
		return $cfg;
	}
	
	public function addDefaultSettings(array $defaults): array {
		$addonDefaults = array(
				//'foo' => 1
		);
		return array_merge($defaults, $addonDefaults);
	}
	
	/**
	 * Check if an order has been paid. This will use (and update) the cache and is therefore preferred to a direct call of isPaidOrder().
	 * @param \WC_Order $order
	 * @return bool
	 */
	public function checkPaidOrder(\WC_Order $order): bool {
		if ($order->is_paid() === true)
			return true;
		$lastCheck = $order->get_meta('lastCheckedChainPayment');
		if (!$lastCheck)
			$lastCheck = 0;
		if ($lastCheck + static::CHECK_PAYMENT_INTERVAL_SEC > time())
			return false;
		
		$isPaid = $this->isPaidOrder($order);
		$order->add_meta_data('lastCheckedChainPayment', time(), true);
		$order->save_meta_data();
		return $isPaid;
	}
	
	//protected function isPaidOrder(int $orderID, $updateOrderErrors = true): bool {
		//if (!isset($this->session) || !isset($this->session->bchTransactionID))
			//return false;
	public function isPaidOrder(\WC_Order $order, $updateOrderErrors = false): bool {
		// we use unique addresses per order. so only check the number of confirmations if 0-conf is disabled
		if (!$order->get_meta('bchTransactionID')) {
			//\Cashtippr::notifyErrorExt("Can not check if order is paid without TXID", $order); // happens on call from AJAX API
			return false;
		}
		$confirmations = $this->blockchainApi->getConfirmationCount($order->get_meta('bchTransactionID'));
		if ($confirmations === -1)
			return false; // TX doesn't exist (yet)
		if ($this->settings['enable0Conf'] !== 'yes' && $confirmations < $this->cashtipprSettings->get('wait_confirmations'))
			return false;
		
		// check if the amount has been fully received
		$address = $order->get_meta('chainAddress');
		if (!$address) // should always be set
			return false;
		// check if all transactions are confirmed (usually only 1)
		$this->addChainTransactionID($order); // should be already done
		$transactions = $order->get_meta('addressTransactionIDs');
		$txMaxAgeSec = time() - \Cashtippr::MAX_AGE_TX_ACCEPT*MINUTE_IN_SECONDS;
		if ($transactions) {
			$transactions = maybe_unserialize($transactions); // sometimes already unserialized. how?
			for ($i = 1; $i < count($transactions); $i++) { // the first one we already checked above
				$confirmations = $this->blockchainApi->getConfirmationCount($transactions[$i]);
				if ($confirmations === -1)
					return false; // TX doesn't exist (yet)
				$blocktime = $this->blockchainApi->getBlocktime($transactions[$i]);
				if ($blocktime < $txMaxAgeSec)
					return false; // this TX was sent to this address before our payment request (address used by another wallet). safe to return false
				if ($this->settings['enable0Conf'] !== 'yes' && $confirmations < $this->pluginSettings->get('wait_confirmations'))
					return false;
			}
		}
		
		// all TX have enough confirmations. check balance
		$bchAmount = (float)$order->get_meta('bchAmount');
		if ($bchAmount < 0.0) {
			\Cashtippr::notifyErrorExt("BCH payment amount is not set in order", $order);
			return false;
		}
		$maxFeeLost = min(\Cashtippr::BCH_TX_FEE_TOLERANCE, $bchAmount/4.0);
		if (\Cashtippr::toSatoshis($bchAmount) > \Cashtippr::BCH_DUST_LIMIT_SAT && $this->blockchainApi->getAddressBalance($address) + $maxFeeLost < $bchAmount)
			return false;
		
		$this->clearCustomSessionVariables();
		// TODO display errors as HTML if we call this via HTTP from client. currently we just wait (for confirmations) and let the user reload manually
		return true;
	}
	
	public function getSuccessPaymentStatus(): string {
		return 'processing'; // TODO add option to set it to completed immediately (for digial goods)? but processing is default
	}
	
	public function addBlockchainDataToEmail(\WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email) {
		if ($order->get_payment_method() !== 'cashtippr_woocommerce')
			return; // the user chose another payment method
		//if ($order->is_paid() !== true) // just to be sure
			//return; // also called for customer invoice
		if ($order->is_paid() === true && !$order->get_meta('bchTransactionID')) {
			\Cashtippr::notifyErrorExt("TXID not available when sending confirmation order email", $order);
			return;
		}
		
		$tplVars = array(
				'paid' => $order->is_paid() === true,
				'txid' => $order->get_meta('bchTransactionID'),
				'amountDisplay' => number_format((float)$order->get_meta('bchAmount'), $this->cashtipprSettings->get('tokenDigits')),
				'tickerDisplay' => 'BCH',
				'recAddress' => $order->get_meta('chainAddress')
		);
		ob_start();
		include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'tpl/email/invoice.php'; // TODO add qr code, badger button,... ?
		$docHtml = ob_get_contents();
		ob_end_clean();
		echo $docHtml;
	}
	
	public function sendOrderReceivedEmail(int $orderID) {
		if ($this->settings['sendPaymentInstructions'] !== 'yes')
			return;
		$order = new \WC_Order($orderID);
		if ($order->get_payment_method() !== 'cashtippr_woocommerce')
			return; // the user chose another payment method
		if ($order->get_meta('sentChainOrderMail') === '1')
			return; // don't send email twice (if admin changes order status back and forth)
		
		$mailer = WC()->mailer();
		/* // use the existing WC invoice template
		$recipient = $order->get_billing_email();
		$subject = __('Payment instructions for your order', 'ekliptor');
		$content = $this->getOrderReceivedMailContent($order, $subject, $mailer);
		$headers = "Content-Type: text/html\r\n";
		$mailer->send( $recipient, $subject, $content, $headers );
		*/
		$mailer->customer_invoice($order);
		
		$order->add_meta_data('sentChainOrderMail', '1', true);
		$order->save_meta_data();
	}
	
	/*
	protected function getOrderReceivedMailContent(\WC_Order $order, string $heading, \WC_Emails $mailer): string {
		//$template = 'emails/customer-processing-order.php';
		$template = 'emails/customer-invoice.php';
	    return wc_get_template_html( $template, array(
	        'order'         => $order,
	        'email_heading' => $heading,
	        'sent_to_admin' => false,
	        'plain_text'    => false,
	        'email'         => $mailer
	    ) );
	}
	*/
	
	public function addChainTransactionID(\WC_Order $order) {
		// for all orders not paid with BadgerWallet we don't have TX ID
		// so we must watch the associated 1-time address
		$bchTxID = $order->get_meta('bchTransactionID');
		if (!empty($bchTxID))
			return; // already set
		$address = $order->get_meta('chainAddress');
		if (empty($address)) {
			//SlpPaymentsCore::notifyErrorExt("Found WC order without chain address", $order);
			return; // shouldn't happen
		}
		// an address might have multiple TXIDs, although generally our 1-time addresses will only have 1
		$addressDetails = $this->blockchainApi->getAddressDetails($address);
		if ($addressDetails === null || empty($addressDetails->transactions))
			return; // no TX yet
		$order->add_meta_data('bchTransactionID', $addressDetails->transactions[0], true); // the first is usucally the only one
		$order->add_meta_data('addressTransactionIDs', serialize($addressDetails->transactions), true);
		$order->save_meta_data();
	}
	
	public function generateQrCodeForOrder(\WC_Order $order): string {
		$address = $order->get_meta('chainAddress');
		$bchRemaining = max(0.0, (float)$order->get_meta('bchAmount') - (float)$order->get_meta('bchAmountReceived'));
		return $this->cashtippr->generateQrCodeForAddress($order->get_meta('ct-txid'), $address, $order->get_total(), $bchRemaining);
	}
	
	public function generatePaymentUriForOrder(\WC_Order $order): string {
		$address = $order->get_meta('chainAddress');
		$bchRemaining = max(0.0, (float)$order->get_meta('bchAmount') - (float)$order->get_meta('bchAmountReceived'));
    	return $this->cashtippr->createPaymentURI($address, $bchRemaining);
	}
	
	protected function getPendingOrders(): array {
		// orders will be cancelled by Woocommerce after 1 hour. hook woocommerce_cancel_unpaid_order filter to change that
		// https://docs.woocommerce.com/wc-apidocs/source-function-wc_cancel_unpaid_orders.html#904
		// TODO add a setting? already present in Woocommerce core as setting
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
		$keys = array(/*"orderID", */"bchAddress"); // don't remove the order ID because we need it to show success message
		foreach ($keys as $key) {
			//$this->session->__unset($key); // unreliable // TODO why?
			$this->session->set($key, null);
		}
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