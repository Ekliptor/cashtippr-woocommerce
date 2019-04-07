<?php
/*
 * Plugin Name: CashTippr Woocommerce Addon
 * Plugin URI: https://cashtippr.com/
 * Description: Earn money by selling products (digital and real world) in your online store using Bitcoin Cash payments.
 * Version: 1.0.1
 * Author: Ekliptor
 * Author URI: https://twitter.com/ekliptor
 * License: GPLv3
 * Text Domain: ekliptor
 * 
 * WC requires at least: 3.0
 * WC tested up to: 3.5
 */

use Ekliptor\Cashtippr\Woocommerce;
use Ekliptor\Cashtippr\WoocommerceAdmin;
use Ekliptor\Cashtippr\WoocommerceApi;

// Make sure we don't expose any info if called directly
if (! defined( 'ABSPATH' )) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit ();
}

define ( 'CTIP_WOOCOMMERCE_VERSION', '1.0.1' );
define ( 'CTIP_WOOCOMMERCE__MINIMUM_WP_VERSION', '4.7' ); // not used in sub plugin
define ( 'CTIP_WOOCOMMERCE__PLUGIN_DIR', plugin_dir_path ( __FILE__ ) );

function ctip_woocommerce_load() {
	if (!defined('CASHTIPPR_VERSION') || version_compare(CASHTIPPR_VERSION, '1.0.42', '<') === true/* || class_exists('\Cashtippr') === false*/) { // check for min PHP version is done in Cashtippr
		add_action('admin_notices', function() {
			load_plugin_textdomain ( 'ekliptor' );
			$message = '<strong>' . esc_html__ ( 'For CashTippr Woocommerce to work you need at least CashTippr v1.0.42 installed in your WordPress site.', 'ekliptor' ) . '</strong><br> ' . sprintf(esc_html__ ( 'Please download and activate the CashTippr plugin from your WordPress admin panel in the plugin installer or from %s and then install activate this plugin again.', 'ekliptor'),
				sprintf('<a target="_blank" href="%s">%s</a>', 'https://wordpress.org/plugins/cashtippr-bitcoin-cash-moneybutton-payments/', __('here', 'ekliptor')) );
			echo sprintf('<div class="notice notice-%s%s">
	          %s
	         </div>', 'error', '', $message);
		});
		return;
	}
	else if (class_exists(/*'WooCommerce'*/'WC_Payment_Gateway') === false) {
		add_action('admin_notices', function() { // TODO check for Woocommerce version?
			load_plugin_textdomain ( 'ekliptor' );
			$message = '<strong>' . esc_html__ ( 'For CashTippr Woocommerce to work you must have Woocommerce installed and activated in your WordPress site.', 'ekliptor' ) . '</strong><br> ' . sprintf(esc_html__ ( 'Please download and activate the Woocommerce plugin from your WordPress admin panel in the plugin installer or from %s and then install activate this plugin again.', 'ekliptor'),
				sprintf('<a target="_blank" href="%s">%s</a>', 'https://woocommerce.com/', __('here', 'ekliptor')) );
			echo sprintf('<div class="notice notice-%s%s">
	          %s
	         </div>', 'error', '', $message);
		});
		return;
	}
	
	if (is_admin ()/* || (defined ( 'WP_CLI' ) && WP_CLI)*/) {
		require_once (CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/WoocommerceAdmin.php'); // PHP functions + classes always have the global scope
	}
	/*
	Woocommerce::check_plugin_activation();
	Woocommerce::getInstance(\Cashtippr::getInstance());
	add_action ( 'init', array (
			Woocommerce::getInstance(),
			'init' 
	), 11 );
	*/
	
	WoocommerceApi::getInstance(/*Woocommerce::getInstance()*/);
	add_action ( 'rest_api_init', array (
			WoocommerceApi::getInstance(),
			'init' 
	) );
	
	/*
	if (is_admin ()/* || (defined ( 'WP_CLI' ) && WP_CLI)***) {
		WoocommerceAdmin::getInstance(Woocommerce::getInstance());
		add_action ( 'init', array (
				WoocommerceAdmin::getInstance(),
				'init' 
		), 11 );
	}
	*/
	add_filter('woocommerce_payment_gateways', array('Ekliptor\Cashtippr\Woocommerce', 'addWoocommerceGateway'), 10, 1);
}

/* // sub plugin: we must check for activation on every startup after plugins are loaded with autoload option
register_activation_hook ( __FILE__, array (
		'Ekliptor\Cashtippr\Woocommerce',
		'plugin_activation' 
) );*/
register_deactivation_hook ( __FILE__, array (
		'Ekliptor\Cashtippr\Woocommerce',
		'plugin_deactivation' 
) );

add_action( 'plugins_loaded', function () {
	require_once (CTIP_WOOCOMMERCE__PLUGIN_DIR . 'data.php');
	require_once (CTIP_WOOCOMMERCE__PLUGIN_DIR . 'functions.php');
	require_once (CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/Woocommerce.php');
	require_once (CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/WoocommerceApi.php');
	require_once (CTIP_WOOCOMMERCE__PLUGIN_DIR . 'api.php');
	ctip_woocommerce_load();
}, 100 );
?>