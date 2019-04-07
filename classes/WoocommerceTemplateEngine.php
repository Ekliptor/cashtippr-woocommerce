<?php
namespace Ekliptor\Cashtippr;

class WoocommerceTemplateEngine extends \CTIP_TemplateEngine {
	
	public function __construct(\CTIP_Settings $settings) {
		parent::__construct($settings);
	}
	
	// no metaboxes and global plugin settings (yet)
}
?>