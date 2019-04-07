<?php
namespace Ekliptor\Cashtippr;


class BitcoinComRestApi extends AbstractBlockchainApi {
	
	protected function __construct(\CTIP_Settings $settings) {
		parent::__construct($settings);
		//pre_print_r($this->getConfirmationCount('fe28050b93faea61fa88c4c630f0e1f0a1c24d0082dd0e10d369e13212128f33'));
	}
	
	public function getConfirmationCount(string $transactionID): int {
		$txDetails = $this->getTransactionDetails($transactionID);
		if (!$txDetails || !isset($txDetails->confirmations))
			return -1; // not found
		return (int)$txDetails->confirmations;
	}
	
	protected function getTransactionDetails(string $transactionID): \stdClass {
		$options = array(
				'timeout' => 10, //seconds
				'headers' => array(
					'Accept' => 'application/json',
				),
			);
		$url = sprintf($this->settings->get('blockchain_rest_url') . 'transaction/details/%s', $transactionID);
		$response = wp_remote_get($url, $options);
		if ($response instanceof \WP_Error) {
			\Cashtippr::notifyErrorExt("Error getting transaction details", $response->get_error_messages());
			return null;
		}
		$jsonRes = json_decode(wp_remote_retrieve_body( $response ));
		return $jsonRes;
	}
}
?>