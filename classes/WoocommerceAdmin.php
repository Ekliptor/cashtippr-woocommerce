<?php
namespace Ekliptor\Cashtippr;
// this file is currently not used. All settings are placed within Woocommerce settings (and not WordPress admin main settings).

//require_once CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/WoocommerceTemplateEngine.php';

class WoocommerceAdmin {
	//const PAGE_HOOK = 'cashtippr_page_cashtippr_woocommerce'; // not used currently. plugin only has a menu entry in Woocommerce settings
	
	/** @var WoocommerceAdmin */
	private static $instance = null;
	
	/**
	 * Name of the page hook when the menu is registered.
	 * For example: toplevel_page_cashtippr
	 * @var string Page hook
	 */
	//public $pageHook = '';
	
	/** @var \CTIP_TemplateEngine */
	//public $tpl = null;
	/** @var WoocommerceTemplateEngine */
	//public $tplWoocommerce = null;
	
	
	/** @var \Cashtippr */
	protected $cashtippr;
	
	/** @var \CashtipprAdmin */
	protected $cashtipprAdmin = null;
	
	/** @var Woocommerce */
	protected $woocommercePlugin;
	
	/** @var \CTIP_Settings */
	protected $settings = null;
	
	private function __construct(Woocommerce $woocommercePlugin) {
		if ($woocommercePlugin === null)
			throw new \Error("CashTippr Woocommerce plugin class must be provided in constructor of " . get_class($this));
		$this->woocommercePlugin = $woocommercePlugin;
		$this->cashtippr = $this->woocommercePlugin->getCashtippr();
		
		add_action('cashtippr_admin_init', array($this, 'onCashtipprAdminInit'));
		//add_action('cashtippr_admin_menu', array($this, 'onCashtipprAdminMenuCreate'));
		//add_action( 'current_screen', array( $this, 'initCurrentScreen' ), 11, 1 );
		add_action('cashtippr_admin_notices', array($this, 'addAdminNotices'));
		add_filter('cashtippr_admin_metaboxes', array($this, 'addMetaBoxes'), 10, 2);
		//add_filter('cashtippr_settings_sanitizer', array($this, 'addSettingsSanitizer'), 10, 5);
	}
	
	public static function getInstance(Woocommerce $woocommercePlugin = null) {
		if (self::$instance === null)
			self::$instance = new self($woocommercePlugin);
		return self::$instance;
	}
	
	public function init() {
		$this->settings = $this->cashtippr->getSettings();
		//$this->tplWoocommerce = new WoocommerceTemplateEngine($this->settings);
		
		// init hooks
		// note that this init function is called after the main plugin's init function. so we can't listen to events fired from there
	}
	
	public function onCashtipprAdminInit(\CashtipprAdmin $cashtipprAdmin) {
		$this->cashtipprAdmin = $cashtipprAdmin;
		//$this->tpl = $cashtipprAdmin->getTpl();
	}
	
	/*
	public function onCashtipprAdminMenuCreate(\CashtipprAdmin $cashtipprAdmin) {
		add_submenu_page( 'cashtippr' , __( 'Woocommerce', 'ekliptor' ), __( 'Woocommerce', 'ekliptor' ), 'edit_plugins', 'cashtippr_woocommerce', array( $this->cashtipprAdmin, 'displaySettings' ) );
	}
	
	public function initCurrentScreen(\WP_Screen $screen) {
		$this->pageHook = $this->cashtipprAdmin->getPageHook();
	}
	*/
	
	public function addAdminNotices() {
		// add admin notices to be shown by main plugin here
	}
	
	public function addMetaBoxes(array $pluginBoxes, string $post_type/*, WP_Post $post*/) {
		//if ($this->pageHook === static::PAGE_HOOK) {
		//}
        $pluginBoxes['Woocommerce'] = true;
        return $pluginBoxes;
    }
    
    /*
    public function addSettingsSanitizer(array $sanitizer, \CTIP_Sanitizer $defaultSanitizer, \CTIP_TemplateEngine $tpl, array $defaults, \CTIP_Settings $settings): array {
    	$woocommercePluginAdmin = $this;
    	$addonSanitizer = array(
    	);
    	return array_merge($sanitizer, $addonSanitizer);
    }
    */
}
?>