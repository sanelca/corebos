<?php 
require_once 'data/VTEntityDelta.php';
require_once 'include/freetag/freetag.class.php';
include_once 'vtlib/Vtiger/Module.php';
require_once 'include/Webservices/Revise.php';
require_once 'include/Webservices/Create.php';
require "vendor/autoload.php";

use Twilio\Rest\Client;

class cbFattureHandler {
    const KEY_ISACTIVE = 'fatture_isactive';

	public function saveSettings($isactive) {
		coreBOS_Settings::setSetting(self::KEY_ISACTIVE, $isactive);
	}

	public function getSettings() {
		return array(
			'isActive' => coreBOS_Settings::getSetting(self::KEY_ISACTIVE, '')
		);
	}

	public function isActive() {
		$isactive = coreBOS_Settings::getSetting(self::KEY_ISACTIVE, '0');
		return ($isactive=='1');
	}
}
?>