<?php
if (!defined('ABSPATH'))
	exit("denied");
?>
<p id="ct-pay-instructions" class="ct-payment-select">
  <?php echo esc_html( 'Please send', 'ekliptor' ); ?>
  <span id="ct-pay-amount-txt"><?php echo $btnConf['amountDisplay'];?></span> 
  <span id="ct-payment-remaining" style="display: none"><?php esc_html_e('(remaining', 'ekliptor');?></span> <?php printf(esc_html( '%s to %s', 'ekliptor' ), $btnConf['tickerDisplay'], $btnConf['recAddress']); ?>
</p>
<div class="ct-payment-list ct-center">
  <?php if($btnConf['addQrCode'] === true):?>  
    <div class="ct-qrcode-pay-wrap ct-payment-option">
      <img id="ct-qr-code-image" src="<?php echo esc_attr($btnConf['qrcode']);?>" width="110" height="110" alt="<?php esc_attr_e('Pay with QR Code', 'ekliptor');?>" title="<?php esc_attr_e('Pay with QR Code', 'ekliptor');?>">
    </div>
  <?php endif;?> 
  <div class="ct-manual-payment ct-payment-option">
    <?php include CTIP_WOOCOMMERCE__PLUGIN_DIR . 'tpl/client/payManualAddress.php';?>
  </div>
  <?php if($btnConf['addBadger'] === true):?>
    <div class="ct-button ct-payment-option">
	  <div id="ct-button-text-<?php echo $btnConf['txid'];?>" class="ct-button-text">
        <?php include CASHTIPPR__PLUGIN_DIR . 'tpl/moneybuttonCode.php';?>
      </div>
    </div>
  <?php endif;?>
  <div id="ct-payment-status-container">
    <p><b><?php esc_html_e( 'Payment status:', 'ekliptor' );?></b> <span id="ct-payment-status"><?php esc_html_e( 'Pending', 'ekliptor' );?></span></p>
    <div id="ct-payment-pending">
      <p><img id="ct-payment-pending-icon" src="<?php echo esc_attr($btnConf['loadingImage']);?>" width="128" height="128" alt="<?php esc_attr_e('pending', 'ekliptor');?>" title="<?php esc_attr_e('pending', 'ekliptor');?>" /></p>
      <p><?php esc_html_e( 'This page will automatically refresh. You may close it and your payment will still be received.', 'ekliptor'); echo $btnConf['orderTimeoutTxt'];?></p>
    </div>
  </div>
</div>
<?php if(false):?>
<p class="ct-reload-info"><a href="document.location.reload();><?php esc_html_e( 'Please reload this page to check the payment status.', 'ekliptor' ); ?></a></p>
<?php endif;?>