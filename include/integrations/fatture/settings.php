<?php 
    include_once 'include/integrations/fatture/fatturehandler.php';

    $smarty = new vtigerCRM_Smarty();
    //$wa = new corebos_whatsapp();
    
    $isadmin = is_admin($current_user);
    if ($isadmin && isset($_REQUEST['sid'])) {
        $isActive = ((empty($_REQUEST['fatture_isactive']) || $_REQUEST['fatture_isactive']!='on') ? '0' : '1');
    }  

    
    //$smarty->assign('isActive', $wa->isActive());
?>