<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

/** Get the details of a KeyMetrics on Home page
* @returns  $customviewlist Array in the following format
* $values = Array('Title'=>Array(0=>'image name',
*				1=>'Key Metrics',
*				2=>'home_metrics'
*				),
*		'Header'=>Array(0=>'Metrics',
*				1=>'Count'
*				),
*		'Entries'=>Array($cvid=>Array(
*				0=>$customview name,
*				1=>$no of records for the view
*				),
*		$cvid=>Array(
*				0=>$customview name,
*				1=>$no of records for the view
*				),
*		|
*		|
*		$cvid=>Array(
*				0=>$customview name,
*				1=>$no of records for the view
*				)
*	)
*/
function getKeyMetrics($maxval, $calCnt) {
	require_once 'data/Tracker.php';
	require_once 'modules/CustomView/CustomView.php';
	require_once 'include/logging.php';
	require_once 'include/ListView/ListView.php';

	global $app_strings, $adb, $log;

	$log = LoggerManager::getLogger('metrics');

	$metriclists = getMetricList();

	// Determine if the KeyMetrics widget should appear or not?
	if ($calCnt == 'calculateCnt') {
		return count($metriclists);
	}

	if (isset($metriclists)) {
		global $current_user;
		foreach ($metriclists as $key => $metriclist) {
			$queryGenerator = new QueryGenerator($metriclist['module'], $current_user);
			$queryGenerator->initForCustomViewById($metriclist['id']);
			$metricsql = $queryGenerator->getQuery();
			$metricsql = mkCountQuery($metricsql);
			$metricresult = $adb->query($metricsql);
			if ($metricresult) {
				$rowcount = $adb->fetch_array($metricresult);
				$metriclists[$key]['count'] = $rowcount['count'];
			}
		}
	}
	$title=array();
	$title[]='keyMetrics.gif';
	$title[]=$app_strings['LBL_HOME_KEY_METRICS'];
	$title[]='home_metrics';
	$header=array();
	$header[]=$app_strings['LBL_HOME_METRICS'];
	$header[]=$app_strings['LBL_MODULE'];
	$header[]=$app_strings['LBL_HOME_COUNT'];
	$entries=array();
	if (isset($metriclists)) {
		foreach ($metriclists as $metriclist) {
			$value=array();
			$CVname = textlength_check($metriclist['name'], GlobalVariable::getVariable('HomePage_KeyMetrics_Max_Text_Length', 20, $metriclist['module']));
			$uname = ' ('. $metriclist['user'] .')';
			$mlisturl = '<a href="index.php?action=ListView&module='.$metriclist['module'].'&viewname='.$metriclist['id'].'" title="'.strtr($CVname, '"', '').$uname.'">';
			$value[] = $mlisturl.$CVname.'</a>';
			$value[] = $mlisturl.getTranslatedString($metriclist['module'], $metriclist['module']).'</a>';
			$value[] = $mlisturl.$metriclist['count'].'</a>';
			$entries[$metriclist['id']]=$value;
		}
	}
	return array('Title'=>$title, 'Header'=>$header, 'Entries'=>$entries, 'search_qry'=>'');
}

/** to get the details of a customview Entries
* @returns  $metriclists Array in the following format
* $customviewlist []= Array(
*		'id'=>custom view id,
*		'name'=>custom view name,
*		'module'=>modulename,
*		'count'=>''
*	)
*/
function getMetricList() {
	global $adb, $current_user;
	$userprivs = $current_user->getPrivileges();

	$ssql='select vtiger_customview.* from vtiger_customview inner join vtiger_tab on vtiger_tab.name=vtiger_customview.entitytype where vtiger_customview.setmetrics=1 ';
	$sparams = array();

	if (!$userprivs->isAdmin()) {
		$ssql .= " and (vtiger_customview.status=0 or vtiger_customview.userid = ? or vtiger_customview.status =3 or vtiger_customview.userid in
			(select vtiger_user2role.userid
				from vtiger_user2role
				inner join vtiger_role on vtiger_role.roleid=vtiger_user2role.roleid
				where vtiger_role.parentrole like '".$userprivs->getParentRoleSequence()."::%'))";
		$sparams[] = $current_user->id;
	}
	$ssql .= ' order by vtiger_customview.entitytype';
	$result = $adb->pquery($ssql, $sparams);
	while ($cvrow=$adb->fetch_array($result)) {
		$metricslist = array();
		if (vtlib_isModuleActive($cvrow['entitytype'])) {
			$metricslist['id'] = $cvrow['cvid'];
			$metricslist['name'] = $cvrow['viewname'];
			$metricslist['module'] = $cvrow['entitytype'];
			$metricslist['user'] = getUserFullName($cvrow['userid']);
			$metricslist['count'] = '';
			if (isPermitted($cvrow['entitytype'], 'index') == 'yes') {
				$metriclists[] = $metricslist;
			}
		}
	}
	return $metriclists;
}
?>
