<?php
/**
 * CashTippr Woocommerce Uninstall
 *
 * Uninstall and delete all stored plugin data from all users.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once (plugin_dir_path ( __FILE__ ) . 'data.php');
//require_once (plugin_dir_path ( __FILE__ ) . '../cashtippr-woocommerce/cashtippr.php'); // load the main plugin

class CashtipprWoocommerceUninstall {
	public function __construct() {
	}
	
	public function uninstall() {
		global $wpdb, $wp_version;
		
		// Only remove all user session + payment data if this is set to true.
		// This is to prevent data loss when deleting the plugin from the backend
		// and to ensure only the site owner can perform this action.
		if (CashtipprWoocommerceData::REMOVE_ALL_DATA !== true)
			return;
		
		// nothing to do in this plugin yet
	}
}

$uninstall = new CashtipprWoocommerceUninstall();
$uninstall->uninstall();
