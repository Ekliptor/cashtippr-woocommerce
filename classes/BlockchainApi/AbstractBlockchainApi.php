<?php
namespace Ekliptor\Cashtippr;


abstract class AbstractBlockchainApi {
	/** @var AbstractBlockchainApi */
	private static $instance = null;
	
	/** @var \CTIP_Settings */
	protected $settings;
	
	protected function __construct(\CTIP_Settings $settings) {
		$this->settings = $settings;
	}
	
	public static function getInstance($className, \CTIP_Settings $settings) {
		if (self::$instance !== null)
			return self::$instance;
		switch ($className) {
			case 'BitcoinComRestApi':
				self::$instance = new BitcoinComRestApi($settings);
				return self::$instance;
		}
		throw new \Error("Unable to load bloackchain API class (not existing?): " . $className);
	}
	
	/**
	 * Return the number of confirmation for the given blockchain transaction ID.
	 * @param string $transactionID
	 * @return int The number of confirmations or -1 if the $transactionID doesn't exist.
	 */
	public abstract function getConfirmationCount(string $transactionID): int;
}

// include subclasses after
include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'classes/BlockchainApi/BitcoinComRestApi.php';
?>