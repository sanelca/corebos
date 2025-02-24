<?php
/*+*******************************************************************************
 *  The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 *********************************************************************************/
require_once 'data/CRMEntity.php';
require_once 'modules/CustomView/CustomView.php';
require_once 'include/Webservices/Utils.php';
require_once 'include/Webservices/RelatedModuleMeta.php';

/**
 * QueryGenerator: class to obtain SQL queries from CRM objects
 */
class QueryGenerator {
	private $module;
	private $customViewColumnList;
	private $stdFilterList;
	private $conditionals;
	private $manyToManyRelatedModuleConditions;
	private $groupType;
	private $whereFields;
	/**
	 * @var VtigerCRMObjectMeta
	 */
	private $meta;
	/**
	 * @var Users
	 */
	private $user;
	private $advFilterList;
	private $fields;
	private $addJoinFields;
	private $referenceModuleMetaInfo;
	private $moduleNameFields;
	private $referenceFieldInfoList;
	private $referenceFieldList;
	private $referenceFieldNameList;
	private $referenceFields;
	private $ownerFields;
	private $columns;
	private $fromClause;
	private $whereClause;
	private $query;
	private $groupInfo;
	private $hasUserReferenceField = false;
	public $conditionInstanceCount;
	private $conditionalWhere;
	public static $AND = 'AND';
	public static $OR = 'OR';
	private $customViewFields;
	public $limit = '';
	private $SearchDocuments = false;

	public function __construct($module, $user) {
		$this->module = $module;
		$this->customViewColumnList = null;
		$this->stdFilterList = null;
		$this->conditionals = array();
		$this->user = $user;
		$this->advFilterList = null;
		$this->fields = array();
		$this->addJoinFields = array();
		$this->referenceModuleMetaInfo = array();
		$this->moduleNameFields = array();
		$this->whereFields = array();
		$this->groupType = self::$AND;
		$this->meta = $this->getMeta($module);
		$this->moduleNameFields[$module] = $this->meta->getNameFields();
		$this->referenceFieldInfoList = $this->meta->getReferenceFieldDetails();
		$this->referenceFieldList = array_keys($this->referenceFieldInfoList);
		$this->ownerFields = $this->meta->getOwnerFields();
		$this->columns = null;
		$this->fromClause = null;
		$this->whereClause = null;
		$this->query = null;
		$this->conditionalWhere = null;
		$this->groupInfo = '';
		$this->manyToManyRelatedModuleConditions = array();
		$this->conditionInstanceCount = 0;
		$this->customViewFields = array();
		$this->setHasUserReferenceField();
		$this->setReferenceFields();
	}

	/**
	 *
	 * @param string Module Name
	 * @return EntityMeta
	 */
	public function getMeta($module) {
		if (empty($this->referenceModuleMetaInfo[$module])) {
			$handler = vtws_getModuleHandlerFromName($module, $this->user);
			$meta_data = $handler->getMeta();
			$this->referenceModuleMetaInfo[$module] = $meta_data;
			$this->moduleNameFields[$module] = $meta_data->getNameFields();
		}
		return $this->referenceModuleMetaInfo[$module];
	}

	public function reset() {
		$this->fromClause = null;
		$this->whereClause = null;
		$this->columns = null;
		$this->query = null;
	}

	private function setHasUserReferenceField() {
		foreach ($this->meta->getModuleFields() as $finfo) {
			$this->hasUserReferenceField = ($finfo->getUIType()=='101');
			if ($this->hasUserReferenceField) {
				break;
			}
		}
	}

	public function setFields($fields_list) {
		$this->fields = array_unique($fields_list);
		$this->setReferenceFields();
	}

	// Support for reference module fields
	public function setReferenceFields() {
		global $current_user;
		$userfields = array_keys($this->getOpenUserFields());
		$this->referenceFieldNameList = array();
		$this->referenceFields = array();
		if (isset($this->referenceModuleField)) {
			foreach ($this->referenceModuleField as $conditionInfo) {
				$refmod = $conditionInfo['relatedModule'];
				if (!vtlib_isEntityModule($refmod)) {
					continue; // reference to a module without fields
				}
				$handler = vtws_getModuleHandlerFromName($refmod, $current_user);
				$meta_data = $handler->getMeta();
				$fields_list = $meta_data->getModuleFields();
				foreach ($fields_list as $fname => $finfo) {
					if ($fname=='roleid' || ($refmod=='Users' && !in_array($fname, $userfields))) {
						continue;
					}
					$this->referenceFieldNameList[] = $fname;
					$this->referenceFieldNameList[] = $refmod.'.'.$fname;
					if ($fname==$conditionInfo['fieldName']) {
						$this->referenceFields[$conditionInfo['referenceField']][$refmod][$fname] = $finfo;
					}
				}
			}
		}
		if (count($this->referenceFieldInfoList)>0 && count($this->fields)>0) {
			foreach ($this->referenceFieldInfoList as $fld => $mods) {
				if ($fld=='modifiedby') {
					$fld = 'assigned_user_id';
				}
				foreach ($mods as $module) {
					if (!vtlib_isEntityModule($module) && $module!='Users') {
						continue; // reference to a module without fields
					}
					$handler = vtws_getModuleHandlerFromName($module, $current_user);
					$meta_data = $handler->getMeta();
					$fields_list = $meta_data->getModuleFields();
					foreach ($fields_list as $fname => $finfo) {
						if ($fname=='roleid' || ($module=='Users' && !in_array($fname, $userfields))) {
							continue;
						}
						$midx = $module;
						if ($module=='Users') {
							if ($fld=='created_user_id') {
								$midx = 'UsersCreator';
							} elseif ($this->hasUserReferenceField) {
								$midx = 'UsersSec';
							}
						}
						$this->referenceFieldNameList[] = $fname;
						$this->referenceFieldNameList[] = $midx.'.'.$fname;
						if (in_array($fname, $this->fields) || in_array($midx.'.'.$fname, $this->fields)) {
							$this->referenceFields[$fld][$midx][$fname] = $finfo;
						}
					}
				}
			}
		}
		if (count($this->ownerFields)>0 && count($this->fields)>0) {
			foreach ($this->fields as $fld) {
				if (in_array($fld, $userfields) || strtolower(substr($fld, 0, 6))=='users.') {
					if (strpos($fld, '.')!==false) {
						list($fmod, $fname) = explode('.', $fld);
					} else {
						$fname = $fld;
					}
					$this->referenceFieldNameList[] = $fname;
					$this->referenceFieldNameList[] = 'Users.'.$fname;
					$this->setReferenceFieldsManually('assigned_user_id', 'Users', $fname);
				}
			}
		}
		$this->referenceFieldNameList = array_unique($this->referenceFieldNameList);
	}

	public function setReferenceFieldsManually($referenceField, $refmod, $fname) {
		global $current_user;
		if ($refmod=='Users') {
			$fields_list = $this->getOpenUserFields();
		} else {
			$handler = vtws_getModuleHandlerFromName($refmod, $current_user);
			$meta_data = $handler->getMeta();
			$fields_list = $meta_data->getModuleFields();
		}
		$this->referenceFields[$referenceField][$refmod][$fname] = $fields_list[$fname];
		$this->setaddJoinFields($refmod.'.'.$fname);
	}

	public function setaddJoinFields($fieldname) {
		$this->addJoinFields[] = $fieldname;
	}

	public function getOpenUserFields() {
		global $adb;
		$sql = "SELECT vtiger_field.*, '0' as readonly
			FROM vtiger_field
			WHERE columnname in ('user_name','first_name','last_name','department')
			ORDER BY vtiger_field.sequence ASC";
		$fields_list = array();
		$result = $adb->pquery($sql, array());
		$noofrows = $adb->num_rows($result);
		for ($i=0; $i<$noofrows; $i++) {
			$webserviceField = WebserviceField::fromQueryResult($adb, $result, $i);
			$fields_list[$webserviceField->getFieldName()] = $webserviceField;
		}
		return $fields_list;
	}

	public function getCustomViewFields() {
		return $this->customViewFields;
	}

	public function getFields() {
		return $this->fields;
	}

	public function getWhereFields() {
		return $this->whereFields;
	}

	public function addWhereField($fieldName) {
		$this->whereFields[] = $fieldName;
	}

	public function getOwnerFieldList() {
		return $this->ownerFields;
	}

	public function getModuleNameFields($module) {
		if (empty($this->moduleNameFields[$module])) {
			$this->getMeta($module);
		}
		return $this->moduleNameFields[$module];
	}

	public function getReferenceFieldList() {
		return $this->referenceFieldList;
	}

	public function getReferenceFieldInfoList() {
		return $this->referenceFieldInfoList;
	}

	public function getReferenceFieldNameList() {
		return $this->referenceFieldNameList;
	}

	public function getReferenceFields() {
		return $this->referenceFields;
	}

	public function getReferenceField($fieldName, $returnName = true, $alias = true) {
		if (strpos($fieldName, '.')) {
			list($fldmod,$fldname) = explode('.', $fieldName);
		} else {
			$fldmod = '';
			$fldname = $fieldName;
		}
		$field = '';
		if ($fldmod == '') {  // not FQN > we have to look for it
			$LookForFieldInTheseModules = array_merge($this->referenceFieldInfoList, array('assigned_user_id' => array('Users')));
			foreach ($LookForFieldInTheseModules as $fld => $mods) {
				if ($fld=='modifiedby') {
					$fld='assigned_user_id';
				}
				foreach ($mods as $mname) {
					// if we find a Users field here (with no prefix) we will make it an assigned user field, if you need other related users, use the virtual modules
					if (!empty($this->referenceFields[$fld][$mname][$fldname])) {
						$field = $this->referenceFields[$fld][$mname][$fldname];
						if ($returnName) {
							if ($mname=='Users') {
								return $field->getTableName().'.'.$fldname;
							} else {
								if ($fldname=='assigned_user_id' && false !== strpos($field->getTableName(), 'vtiger_crmentity')) {
									$fldname='smownerid as smowner'.strtolower(getTabModuleName($field->getTabId()));
								} else {
									if ($alias) {
										$fldname=$field->getColumnName().' as '.strtolower(getTabModuleName($field->getTabId())).$field->getColumnName();
									} else {
										$fldname=$field->getColumnName();
									}
								}
								return $field->getTableName().$fld.'.'.$fldname;
							}
						} else {
							return $field;
						}
					}
				}
			}
		} else {  // FQN
			if ($fldmod=='Users' && !empty($this->referenceFields['assigned_user_id'])) {
				if ($returnName) {
					return 'vtiger_users.'.$fldname;
				} elseif (isset($this->referenceFields['assigned_user_id'][$fldmod][$fldname])) {
					return $this->referenceFields['assigned_user_id'][$fldmod][$fldname];
				} else {
					return null;
				}
			}
			foreach ($this->referenceFieldInfoList as $fld => $mods) {
				if ($fld=='modifiedby') {
					$fld = 'assigned_user_id';
				}
				if (!empty($this->referenceFields[$fld][$fldmod][$fldname])) {
					$field = $this->referenceFields[$fld][$fldmod][$fldname];
					if ($returnName) {
						if ($fldmod=='Users') {
							return $field->getTableName().'.'.$fldname;
						} else {
							if ($fldname=='assigned_user_id' && false !== strpos($field->getTableName(), 'vtiger_crmentity')) {
								$fldname='smownerid as smowner'.strtolower(getTabModuleName($field->getTabId()));
							} elseif ($fldmod=='UsersSec') {
								if ($alias) {
									$fldname=$fldname.' as userssec'.$fldname;
								}
							} elseif ($fldmod=='UsersCreator') {
								if ($alias) {
									$fldname=$fldname.' as userscreator'.$fldname;
								}
							} else {
								if ($alias) {
									$fldname=$field->getColumnName().' as '.strtolower(getTabModuleName($field->getTabId())).$field->getColumnName();
								} else {
									$fldname=$field->getColumnName();
								}
							}
							return $field->getTableName().$fld.'.'.$fldname;
						}
					} else {
						return $field;
					}
				}
			}
		}
		return null;
	}

	public function getModule() {
		return $this->module;
	}

	public function getModuleFields() {
		return $this->meta->getModuleFields();
	}

	public function getConditionalWhere() {
		return $this->conditionalWhere;
	}

	public function getDefaultCustomViewQuery() {
		$customView = new CustomView($this->module);
		$unsetit = false;
		if (empty($_REQUEST['action'])) {
			$unsetit = true;
			$_REQUEST['action'] = 'ListView';
		}
		$viewId = $customView->getViewId($this->module);
		if ($unsetit) {
			$_REQUEST['action']='';
		}
		return $this->getCustomViewQueryById($viewId);
	}

	public function initForDefaultCustomView() {
		$customView = new CustomView($this->module);
		$unsetit = false;
		if (empty($_REQUEST['action'])) {
			$unsetit = true;
			$_REQUEST['action'] = 'ListView';
		}
		$viewId = $customView->getViewId($this->module);
		if ($unsetit) {
			$_REQUEST['action']='';
		}
		$this->initForCustomViewById($viewId);
	}

	public function initForCustomViewById($viewId) {
		$customView = new CustomView($this->module);
		$this->customViewColumnList = $customView->getColumnsListByCvid($viewId);
		$viewfields = array();
		foreach ($this->customViewColumnList as $customViewColumnInfo) {
			$details = explode(':', $customViewColumnInfo);
			if (empty($details[2]) && $details[1] == 'crmid' && $details[0] == 'vtiger_crmentity') {
				$name = 'id';
				$this->customViewFields[] = $name;
			} else {
				$minfo = explode('_', $details[3]);
				if ($minfo[0]==$this->module || ($minfo[0]=='Notes' && $this->module=='Documents')) {
					$viewfields[] = $details[2];
				} else {
					$viewfields[] = $minfo[0].'.'.$details[2];
				}
				$this->customViewFields[] = $details[2];
			}
		}

		if ($this->module == 'Documents' && in_array('filename', $viewfields)) {
			if (!in_array('filelocationtype', $viewfields)) {
				$viewfields[] = 'filelocationtype';
			}
			if (!in_array('filestatus', $viewfields)) {
				$viewfields[] = 'filestatus';
			}
		}
		if (in_array('Documents.filename', $viewfields) && !in_array('Documents.note_no', $viewfields)) {
			$viewfields[] = 'Documents.note_no';
		}
		$viewfields[] = 'id';
		$this->setFields($viewfields);

		$this->stdFilterList = $customView->getStdFilterByCvid($viewId);
		$this->advFilterList = $customView->getAdvFilterByCvid($viewId);

		if (is_array($this->stdFilterList)) {
			$value = array();
			if (!empty($this->stdFilterList['columnname'])) {
				$this->startGroup('');
				$name = explode(':', $this->stdFilterList['columnname']);
				$name = $name[2];
				$value[] = $this->fixDateTimeValue($name, $this->stdFilterList['startdate']);
				$value[] = $this->fixDateTimeValue($name, $this->stdFilterList['enddate'], false);
				$this->addCondition($name, $value, 'BETWEEN');
			}
		}
		if ($this->conditionInstanceCount <= 0 && is_array($this->advFilterList) && count($this->advFilterList) > 0) {
			$this->startGroup('');
		} elseif ($this->conditionInstanceCount > 0 && is_array($this->advFilterList) && count($this->advFilterList) > 0) {
			$this->addConditionGlue(self::$AND);
		}
		if (is_array($this->advFilterList) && count($this->advFilterList) > 0) {
			$this->startGroup('');
			foreach ($this->advFilterList as $groupcolumns) {
				$filtercolumns = $groupcolumns['columns'];
				if (count($filtercolumns) > 0) {
					$this->startGroup('');
					foreach ($filtercolumns as $filter) {
						$name = explode(':', $filter['columnname']);
						$mlbl = explode('_', $name[3]);
						$mname = $mlbl[0];
						if (empty($name[2]) && $name[1] == 'crmid' && $name[0] == 'vtiger_crmentity') {
							$name = $this->getSQLColumn('id');
						} else {
							$name = $name[2];
						}
						if ($mname==$this->getModule()) {
							$this->addCondition($name, $filter['value'], $filter['comparator']);
						} else {
							$reffld = '';
							foreach ($this->referenceFieldInfoList as $rfld => $refmods) {
								if (in_array($mname, $refmods)) {
									$reffld = $rfld;
									break;
								}
							}
							$this->addReferenceModuleFieldCondition($mname, $reffld, $name, $filter['value'], $filter['comparator']);
						}
						$columncondition = $filter['column_condition'];
						if (!empty($columncondition)) {
							$this->addConditionGlue($columncondition);
						}
					}
					$this->endGroup();
					$groupConditionGlue = $groupcolumns['condition'];
					if (!empty($groupConditionGlue)) {
						$this->addConditionGlue($groupConditionGlue);
					}
				}
			}
			$this->endGroup();
		}
		if ($this->conditionInstanceCount > 0) {
			$this->endGroup();
		}
	}

	public function getCustomViewQueryById($viewId) {
		$this->initForCustomViewById($viewId);
		return $this->getQuery();
	}

	public function getQuery($distinct = false, $limit = '') {
		if (empty($this->query)) {
			$allFields = array_merge($this->whereFields, $this->fields);
			foreach ($allFields as $fieldName) {
				if (in_array($fieldName, $this->referenceFieldList)) {
					$moduleList = $this->referenceFieldInfoList[$fieldName];
					foreach ($moduleList as $module) {
						if (empty($this->moduleNameFields[$module])) {
							$this->getMeta($module);
						}
					}
				} elseif (in_array($fieldName, $this->ownerFields)) {
					$this->getMeta('Users');
					$this->getMeta('Groups');
				}
			}

			$sql_query  = $this->getSelectClauseColumnSQL();
			$sql_query .= $this->getFromClause();
			if ($this->meta->getTabName() == 'Documents' && $this->SearchDocuments) {
				$search_text = vtlib_purify($_REQUEST['search_text']);
				$sql_query .= ' WHERE vtiger_documentsearchinfo.text LIKE "%'.$search_text.'%" AND vtiger_notes.notesid>0';
			} else {
				$sql_query .= $this->getWhereClause();
			}
			list($specialPermissionWithDuplicateRows,$cached) = VTCacheUtils::lookupCachedInformation('SpecialPermissionWithDuplicateRows');
			$sql_query = 'SELECT '.(($distinct || $specialPermissionWithDuplicateRows) ? 'DISTINCT ' : '') . $sql_query;
			if ($limit!='') {
				$sql_query .= ' limit 0, '.$limit;
			}
			$this->query = $sql_query;
			return $sql_query;
		} else {
			if ($limit!='') {
				$this->query .= ' limit 0, '.$limit;
			}
			return $this->query;
		}
	}

	public function getSQLColumn($name, $alias = true) {
		if ($name == 'id') {
			$baseTable = $this->meta->getEntityBaseTable();
			$moduleTableIndexList = $this->meta->getEntityTableIndexList();
			$baseTableIndex = $moduleTableIndexList[$baseTable];
			return $baseTable.'.'.$baseTableIndex;
		}
		$moduleFields = $this->getModuleFields();
		if (!empty($moduleFields[$name])) {
			$field = $moduleFields[$name];
		} elseif ($this->referenceFieldInfoList) { // Adding support for reference module fields
			return $this->getReferenceField($name, true, $alias);
		}
		if (empty($field)) {
			return '';
		}
		return $field->getTableName().'.'.$field->getColumnName();
	}

	public function getSelectClauseColumnSQL() {
		$columns_arr = array();
		$moduleFields = $this->getModuleFields();
		$accessibleFieldList = array_keys($moduleFields);
		$accessibleFieldList[] = 'id';
		$allfields = $accessibleFieldList;
		if ($this->referenceFieldInfoList) { // Adding support for reference module fields
			$accessibleFieldList = array_merge($this->referenceFieldNameList, $accessibleFieldList);
		}
		if (in_array('*', $this->fields)) {
			$this->fields = $allfields;
		} else {
			$this->fields = array_intersect($this->fields, $accessibleFieldList);
		}
		foreach ($this->fields as $field) {
			if ($field == 'filename' && $this->getModule()=='Emails') {
				continue;
			}
			$sql = $this->getSQLColumn($field);
			$columns_arr[] = $sql;
		}
		$this->columns = implode(', ', $columns_arr);
		return $this->columns;
	}

	public function getFromClause() {
		global $current_user;
		if (!empty($this->query) || !empty($this->fromClause)) {
			return $this->fromClause;
		}
		$baseModule = $this->getModule();
		$moduleFields = $this->getModuleFields();
		$tableList = array();
		$tableJoinMapping = array();
		$tableJoinCondition = array();

		$moduleTableIndexList = $this->meta->getEntityTableIndexList();
		foreach ($this->fields as $fieldName) {
			if ($fieldName == 'id' || empty($moduleFields[$fieldName]) || ($fieldName=='filename' && $baseModule=='Emails')) {
				continue;
			}

			$field = $moduleFields[$fieldName];
			$baseTable = $field->getTableName();
			$tableIndexList = $this->meta->getEntityTableIndexList();
			$baseTableIndex = $tableIndexList[$baseTable];
			if ($field->getFieldDataType() == 'reference') {
				$moduleList = $this->referenceFieldInfoList[$fieldName];
				$tableJoinMapping[$baseTable] = 'INNER JOIN';
				$fldcolname = $field->getColumnName();
				foreach ($moduleList as $module) {
					if ($module == 'Users' && $baseModule != 'Users') {
						$tableJoinCondition[$fieldName]['vtiger_users'.$fieldName] = $baseTable.'.'.$fldcolname.' = vtiger_users'.$fieldName.'.id';
						$tableJoinMapping['vtiger_users'.$fieldName] = 'LEFT JOIN vtiger_users AS';
					}
				}
			} elseif ($field->getFieldDataType() == 'owner') {
				$tableList['vtiger_users'] = 'vtiger_users';
				$tableList['vtiger_groups'] = 'vtiger_groups';
				$tableJoinMapping['vtiger_users'] = 'LEFT JOIN';
				$tableJoinMapping['vtiger_groups'] = 'LEFT JOIN';
			}
			$tableList[$baseTable] = $baseTable;
			$tableJoinMapping[$baseTable] = $this->meta->getJoinClause($baseTable);
		}
		$baseTable = $this->meta->getEntityBaseTable();
		$moduleTableIndexList = $this->meta->getEntityTableIndexList();
		$baseTableIndex = $moduleTableIndexList[$baseTable];
		foreach ($this->whereFields as $fieldName) {
			if (empty($fieldName)) {
				continue;
			}
			if (empty($moduleFields[$fieldName])) {
				// not accessible field.
				continue;
			}
			$field = $moduleFields[$fieldName];
			$baseTable = $field->getTableName();
			// When a field is included in Where Clause, but not in Select Clause, and the field table is not base table,
			// The table will not be present in tablesList and hence needs to be added to the list.
			if (empty($tableList[$baseTable])) {
				$tableList[$baseTable] = $baseTable;
				$tableJoinMapping[$baseTable] = $this->meta->getJoinClause($baseTable);
			}
			if ($field->getFieldDataType() == 'reference') {
				$moduleList = $this->referenceFieldInfoList[$fieldName];
				// This is special condition as the data is not stored in the base table,
				// If empty search is performed on this field then it fails to retrieve any information.
				if ($fieldName == 'parent_id' && $baseTable == 'vtiger_seactivityrel') {
					$tableJoinMapping[$baseTable] = 'LEFT JOIN';
				} elseif ($fieldName == 'contact_id' && $baseTable == 'vtiger_cntactivityrel') {
					$tableJoinMapping[$baseTable] = 'LEFT JOIN';
				} else {
					$tableJoinMapping[$baseTable] = 'INNER JOIN';
				}
				foreach ($moduleList as $module) {
					$tabid = getTabid($module);
					$meta_data = $this->getMeta($module);
					$nameFields = $this->getModuleNameFields($module);
					$nameFieldList = explode(',', $nameFields);
					foreach ($nameFieldList as $column) {
						$joinas = 'LEFT JOIN';
						// for non admin user users module is inaccessible.
						// so need hard code the tablename.
						if ($module == 'Users' && $baseModule != 'Users') {
							$referenceTable = 'vtiger_users'.$fieldName;
							$referenceTableIndex = 'id';
							$joinas = 'LEFT JOIN vtiger_users AS';
						} else {
							$column = getColumnnameByFieldname($tabid, $column);
							$referenceField = $meta_data->getFieldByColumnName($column);
							if (!$referenceField) {
								continue;
							}
							$referenceTable = $referenceField->getTableName();
							$tableIndexList = $meta_data->getEntityTableIndexList();
							$referenceTableIndex = $tableIndexList[$referenceTable];
						}
						if (isset($moduleTableIndexList[$referenceTable])) {
							$referenceTableName = "$referenceTable $referenceTable$fieldName";
							$referenceTable = "$referenceTable$fieldName";
						} else {
							$referenceTableName = $referenceTable;
						}
						//should always be left join for cases where we are checking for null
						//reference field values.
						if (!array_key_exists($referenceTable, $tableJoinMapping)) { // table already added in from clause
							$tableJoinMapping[$referenceTableName] = $joinas;
							$tableJoinCondition[$fieldName][$referenceTableName] = $baseTable.'.'.$field->getColumnName().' = '.$referenceTable.'.'.$referenceTableIndex;
						}
					}
				}
			} elseif ($field->getFieldDataType() == 'owner') {
				$tableList['vtiger_users'] = 'vtiger_users';
				$tableList['vtiger_groups'] = 'vtiger_groups';
				$tableJoinMapping['vtiger_users'] = 'LEFT JOIN';
				$tableJoinMapping['vtiger_groups'] = 'LEFT JOIN';
			} else {
				$tableList[$baseTable] = $baseTable;
				$tableJoinMapping[$baseTable] = $this->meta->getJoinClause($baseTable);
			}
		}

		$defaultTableList = $this->meta->getEntityDefaultTableList();
		foreach ($defaultTableList as $table) {
			if (!in_array($table, $tableList)) {
				$tableList[$table] = $table;
				$tableJoinMapping[$table] = 'INNER JOIN';
			}
		}
		$ownerFields = $this->meta->getOwnerFields();
		if (!empty($ownerFields)) {
			$ownerField = $ownerFields[0];
		}
		$baseTable = $this->meta->getEntityBaseTable();
		$sql = " FROM $baseTable ";
		unset($tableList[$baseTable]);
		foreach ($defaultTableList as $tableName) {
			$sql .= " $tableJoinMapping[$tableName] $tableName ON $baseTable.$baseTableIndex = $tableName.$moduleTableIndexList[$tableName]";
			unset($tableList[$tableName]);
		}
		$specialTableJoins = array();
		foreach ($tableList as $tableName) {
			if ($tableName == 'vtiger_users') {
				$field = $moduleFields[$ownerField];
				$sql .= " $tableJoinMapping[$tableName] $tableName ON ".$field->getTableName().'.'.$field->getColumnName()." = $tableName.id";
			} elseif ($tableName == 'vtiger_groups') {
				$field = $moduleFields[$ownerField];
				$sql .= " $tableJoinMapping[$tableName] $tableName ON ".$field->getTableName().'.'.$field->getColumnName()." = $tableName.groupid";
			} else {
				$sql .= " $tableJoinMapping[$tableName] $tableName ON $baseTable.$baseTableIndex = $tableName.$moduleTableIndexList[$tableName]";
				$specialTableJoins[]=$tableName.$baseTable;
			}
		}

		if ($this->meta->getTabName() == 'Documents') {
			$tableJoinCondition['folderid'] = array(
				'vtiger_attachmentsfolder'=>"$baseTable.folderid = vtiger_attachmentsfolder.folderid"
			);
			$tableJoinMapping['vtiger_attachmentsfolder'] = 'LEFT JOIN';
		}
		$referenceFieldTableList = array();
		$alias_count=2;
		foreach ($tableJoinCondition as $fieldName => $conditionInfo) {
			foreach ($conditionInfo as $tableName => $condition) {
				if (!empty($tableList[$tableName])) {
					$tableNameAlias = $tableName.$alias_count;
					$alias_count++;
					$condition = str_replace($tableName, $tableNameAlias, $condition);
				} else {
					$tableNameAlias = '';
				}
				$sql .= " $tableJoinMapping[$tableName] $tableName $tableNameAlias ON $condition";
				$referenceFieldTableList[] = ($tableNameAlias=='' ? $tableName : $tableNameAlias);
			}
		}

		foreach ($this->manyToManyRelatedModuleConditions as $conditionInfo) {
			$relatedModuleMeta = RelatedModuleMeta::getInstance(
				$this->meta->getTabName(),
				$conditionInfo['relatedModule']
			);
			$relationInfo = $relatedModuleMeta->getRelationMeta();
			$relatedModule = $this->meta->getTabName();
			$sql .= ' INNER JOIN '.$relationInfo['relationTable'].' ON '.
			$relationInfo['relationTable'].".$relationInfo[$relatedModule]=$baseTable.$baseTableIndex";
		}
		// Adding support for conditions on reference module fields
		if (count($this->referenceFieldInfoList)>0) {
			$alreadyinfrom = array_keys($tableJoinMapping);
			$alreadyinfrom[] = $baseTable;
			if (isset($this->referenceModuleField) && is_array($this->referenceModuleField)) {
				foreach ($this->referenceModuleField as $conditionInfo) {
					if (empty($conditionInfo['relatedModule'])) {
						continue;
					}
					if ($conditionInfo['relatedModule'] == 'Users' && $baseModule != 'Users'
					 && !in_array('vtiger_users', $referenceFieldTableList) && !in_array('vtiger_users', $tableList)) {
						$sql .= ' LEFT JOIN vtiger_users ON vtiger_users.id = vtiger_crmentity.smownerid ';
						$referenceFieldTableList[] = 'vtiger_users';
						$sql .= ' LEFT JOIN vtiger_groups ON vtiger_groups.groupid = vtiger_crmentity.smownerid ';
						$referenceFieldTableList[] = 'vtiger_groups';
						continue;
					}
					$handler = vtws_getModuleHandlerFromName($conditionInfo['relatedModule'], $current_user);
					$meta_data = $handler->getMeta();
					$reltableList = $meta_data->getEntityTableIndexList();
					$fieldName = $conditionInfo['fieldName'];
					$referenceFieldObject = $moduleFields[$conditionInfo['referenceField']];
					$fields_list = $meta_data->getModuleFields();
					if ($fieldName=='id') {
						$tableName = $meta_data->getEntityBaseTable();
					} else {
						if (empty($fields_list[$fieldName])) {
							continue;
						}
						$fieldObject = $fields_list[$fieldName];
						$tableName = $fieldObject->getTableName();
					}

					if (!in_array($tableName, $referenceFieldTableList) && !in_array($tableName.$conditionInfo['referenceField'], $referenceFieldTableList)) {
						if ($baseTable != $referenceFieldObject->getTableName() && !in_array($referenceFieldObject->getTableName(), $alreadyinfrom)) {
							if ($this->getModule() == 'Emails') {
								$join = 'INNER JOIN ';
							} else {
								$join = 'LEFT JOIN ';
							}
							$joinclause =  $join.$referenceFieldObject->getTableName().' ON '.$referenceFieldObject->getTableName().'.'
								.$moduleTableIndexList[$referenceFieldObject->getTableName()].'='.$baseTable.'.'.$baseTableIndex;

							$referenceFieldTableList[] = $referenceFieldObject->getTableName();
							if (!in_array($referenceFieldObject->getTableName().$baseTable, $specialTableJoins)) {
								$sql .= " $joinclause ";
								$specialTableJoins[] = $referenceFieldObject->getTableName().$baseTable;
							}
						}
						$sql .= ' LEFT JOIN '.$tableName.' AS '.$tableName.$conditionInfo['referenceField'].' ON '.$tableName.$conditionInfo['referenceField'].'.'
							.$reltableList[$tableName].'='.$referenceFieldObject->getTableName().'.'.$referenceFieldObject->getColumnName();
						$referenceFieldTableList[] = $tableName.$conditionInfo['referenceField'];
					}
				}
			}
			$joinFields = array_merge($this->addJoinFields, $this->fields);
			foreach ($joinFields as $fieldName) {
				if ($fieldName == 'id' || !empty($moduleFields[$fieldName])) {
					continue;
				}
				if (strpos($fieldName, '.')) {
					list($fldmod,$fldname) = explode('.', $fieldName);
				} else {
					$fldmod = '';
					$fldname = $fieldName;
				}
				$field = '';
				if ($fldmod == '') {  // not FQN > we have to look for it
					foreach ($this->referenceFieldInfoList as $fld => $mods) {
						if ($fld=='modifiedby' || $fld == 'assigned_user_id') {
							continue;
						}
						foreach ($mods as $mname) {
							if (!empty($this->referenceFields[$fld][$mname][$fldname])) {
								$handler = vtws_getModuleHandlerFromName($mname, $current_user);
								$meta_data = $handler->getMeta();
								$reltableList = $meta_data->getEntityTableIndexList();
								$referenceFieldObject = $this->referenceFields[$fld][$mname][$fldname];
								$tableName = $referenceFieldObject->getTableName();
								if (!in_array($moduleFields[$fld]->getTableName(), array_merge($referenceFieldTableList, $alreadyinfrom))) {
									$fldtname = $moduleFields[$fld]->getTableName();
									$sql .= " LEFT JOIN $fldtname ON $fldtname".'.'.$moduleTableIndexList[$fldtname].'='.$baseTable.'.'.$baseTableIndex;
									$alreadyinfrom[] = $fldtname;
								}
								if (!in_array($tableName.$fld, $referenceFieldTableList)) {
									$sql .= ' LEFT JOIN '.$tableName.' AS '.$tableName.$fld.' ON '.
										$tableName.$fld.'.'.$reltableList[$tableName].'='.$moduleFields[$fld]->getTableName().'.'.$moduleFields[$fld]->getColumnName();
									$referenceFieldTableList[] = $tableName.$fld;
								}
								break 2;
							}
						}
					}
				} else {  // FQN
					if ($fldmod=='Users' && !in_array('vtiger_users', $referenceFieldTableList) && !in_array('vtiger_users', $alreadyinfrom)) {
						$sql .= ' LEFT JOIN vtiger_users ON vtiger_users.id = vtiger_crmentity.smownerid ';
						$referenceFieldTableList[] = $alreadyinfrom[] = 'vtiger_users';
						$sql .= ' LEFT JOIN vtiger_groups ON vtiger_groups.groupid = vtiger_crmentity.smownerid ';
						$referenceFieldTableList[] = $alreadyinfrom[] = 'vtiger_groups';
						continue;
					}
					foreach ($this->referenceFieldInfoList as $fld => $mods) {
						if ($fld=='modifiedby' || $fld == 'assigned_user_id' || $moduleFields[$fld]->getUIType()=='77') { // we should add support for uitype 77
							continue;
						}
						if (!empty($this->referenceFields[$fld][$fldmod][$fldname])) {
							$hmod = ($fldmod=='UsersSec' || $fldmod=='UsersCreator' ? 'Users' : $fldmod);
							$handler = vtws_getModuleHandlerFromName($hmod, $current_user);
							$meta_data = $handler->getMeta();
							$reltableList = $meta_data->getEntityTableIndexList();
							$referenceFieldObject = $this->referenceFields[$fld][$fldmod][$fldname];
							$tableName = $referenceFieldObject->getTableName();
							if (!in_array($moduleFields[$fld]->getTableName(), array_merge($referenceFieldTableList, $alreadyinfrom))) {
								$fldtname = $moduleFields[$fld]->getTableName();
								$sql .= " LEFT JOIN $fldtname ON $fldtname".'.'.$moduleTableIndexList[$fldtname].'='.$baseTable.'.'.$baseTableIndex;
								$alreadyinfrom[] = $fldtname;
							}
							if (!in_array($tableName.$fld, $referenceFieldTableList)) {
								$sql .= ' LEFT JOIN '.$tableName.' AS '.$tableName.$fld.' ON '.
									$tableName.$fld.'.'.$reltableList[$tableName].'='.$moduleFields[$fld]->getTableName().'.'.$moduleFields[$fld]->getColumnName();
								$referenceFieldTableList[] = $tableName.$fld;
							}
							break;
						}
					}
				}
			}
		}

		$sql .= $this->meta->getEntityAccessControlQuery();
		if ($this->meta->getTabName() == 'Documents' && $this->SearchDocuments) {
			$sql .= ' LEFT JOIN vtiger_documentsearchinfo ON vtiger_documentsearchinfo.documentid=vtiger_notes.notesid ';
		}
		$this->fromClause = $sql;
		return $sql;
	}

	public function hasWhereConditions() {
		return (count($this->conditionals)>0);
	}

	public function getWhereClause() {
		global $current_user;
		if (!empty($this->query) || !empty($this->whereClause)) {
			return $this->whereClause;
		}
		$db = PearDatabase::getInstance();
		$deletedQuery = $this->meta->getEntityDeletedQuery();
		$sql = '';
		if (!empty($deletedQuery)) {
			$sql .= " WHERE $deletedQuery";
		}
		if ($this->conditionInstanceCount > 0) {
			$sql .= ' AND ';
		} elseif (empty($deletedQuery)) {
			$sql .= ' WHERE ';
		}
		$moduleFieldList = $this->getModuleFields();
		$baseTable = $this->meta->getEntityBaseTable();
		$moduleTableIndexList = $this->meta->getEntityTableIndexList();
		$baseTableIndex = $moduleTableIndexList[$baseTable];
		$groupSql = $this->groupInfo;
		$fieldSqlList = array();
		foreach ($this->conditionals as $index => $conditionInfo) {
			$fieldName = $conditionInfo['name'];
			if ($fieldName=='id') {
				if (empty($conditionInfo['value'])) {
					$conditionInfo['value'] = '0';
				}
				if (!is_array($conditionInfo['value'])) {
					$value = "'".$conditionInfo['value']."'";
				} else {
					$value = ''; // will get loaded below
				}
				switch ($conditionInfo['operator']) {
					case 'e':
						$sqlOperator = '=';
						break;
					case 'n':
						$sqlOperator = '<>';
						break;
					case 'l':
						$sqlOperator = '<';
						break;
					case 'g':
						$sqlOperator = '>';
						break;
					case 'm':
						$sqlOperator = '<=';
						break;
					case 'h':
						$sqlOperator = '>=';
						break;
					case 'i':
					case 'ni':
					case 'nin':
						$sqlOperator = '';
						$vals = array_map(array( $db, 'quote'), $conditionInfo['value']);
						$value = (($conditionInfo['operator']=='ni' || $conditionInfo['operator']=='nin') ? 'NOT ':'').'IN ('.implode(',', $vals).')';
						break;
					default:
						$sqlOperator = '=';
				}
				$fieldSqlList[$index] = "($baseTable.$baseTableIndex $sqlOperator $value)";
				continue;
			}
			if (empty($moduleFieldList[$fieldName]) || $conditionInfo['operator'] == 'None') {
				continue;
			}
			$field = $moduleFieldList[$fieldName];
			$fieldSql = '(';
			$fieldGlue = '';
			$valueSqlList = $this->getConditionValue($conditionInfo['value'], $conditionInfo['operator'], $field);
			if ($conditionInfo['operator']=='exists') {
				$fieldSqlList[$index] = '('.$valueSqlList[0].')';
				continue;
			}
			$valueSqlList = (array)$valueSqlList;
			foreach ($valueSqlList as $valueSql) {
				if (in_array($fieldName, $this->referenceFieldList)) {
					$moduleList = $this->referenceFieldInfoList[$fieldName];
					foreach ($moduleList as $module) {
						$tabid = getTabid($module);
						$nameFields = $this->getModuleNameFields($module);
						$nameFieldList = explode(',', $nameFields);
						$meta_data = $this->getMeta($module);
						$columnList = array();
						foreach ($nameFieldList as $column) {
							if ($module == 'Users') {
								$referenceTable = 'vtiger_users'.$fieldName;
							} else {
								$column = getColumnnameByFieldname($tabid, $column);
								$referenceField = $meta_data->getFieldByColumnName($column);
								if (!$referenceField) {
									continue;
								}
								$referenceTable = $referenceField->getTableName();
							}
							if (isset($moduleTableIndexList[$referenceTable])) {
								$referenceTable = "$referenceTable$fieldName";
							}
							$columnList[$column] = "$referenceTable.$column";
						}
						if (count($columnList) > 1) {
							$columnSql = getSqlForNameInDisplayFormat($columnList, $module);
						} else {
							$columnSql = implode('', $columnList);
						}

						$fieldSql .= "$fieldGlue trim($columnSql) $valueSql";
						if ($conditionInfo['operator'] == 'e' && (empty($conditionInfo['value']) || $conditionInfo['value'] == 'null') && $field->getFieldDataType() == 'reference') {
							$fieldGlue = ' AND';
						} else {
							$fieldGlue = ' OR';
						}
					}
				} elseif (in_array($fieldName, $this->ownerFields)) {
					$fieldSql .= "$fieldGlue (trim(vtiger_users.ename) $valueSql or vtiger_groups.groupname $valueSql)";
				} else {
					if (($fieldName == 'birthday' && !$this->isRelativeSearchOperators($conditionInfo['operator'])) || $conditionInfo['operator'] == 'monthday') {
						$fieldSql .= "$fieldGlue DATE_FORMAT(".$field->getTableName().'.'.$field->getColumnName().",'%m%d') ".$valueSql;
					} else {
						if ($conditionInfo['operator'] == 'sx' || $conditionInfo['operator'] == 'nsx') {
							if (($field->getUIType() == 15 || $field->getUIType() == 16) && hasMultiLanguageSupport($field->getFieldName())) {
								$fieldSql .= "$fieldGlue ".$field->getTableName().'.'.$field->getColumnName().' IN (
									select translation_key
									from vtiger_cbtranslation
									where locale="'.$current_user->language.'" and forpicklist="'.$this->getModule().'::'.$field->getFieldName()
									.'" and SOUNDEX(i18n)'.($conditionInfo['operator']=='nsx' ? ' NOT' : '').' LIKE SOUNDEX("'.$conditionInfo['value'].'"))'
									.($conditionInfo['operator']=='nsx' ? ' AND ' : ' OR ').$valueSql;
							} else {
								$fieldSql .= "$fieldGlue ". $valueSql;
							}
						} else {
							if (($field->getUIType() == 15 || $field->getUIType() == 16) && hasMultiLanguageSupport($field->getFieldName())) {
								$fieldSql .= "$fieldGlue ".$field->getTableName().'.'.$field->getColumnName().' IN (
									select translation_key
									from vtiger_cbtranslation
									where locale="'.$current_user->language.'" and forpicklist="'.$this->getModule().'::'.$field->getFieldName().'" and i18n '.$valueSql.')'
									.(in_array($conditionInfo['operator'], array('n', 'ni', 'nin', 'k', 'dnsw', 'dnew')) ? ' AND ' : ' OR ')
									.$field->getTableName().'.'.$field->getColumnName().' '.$valueSql;
							} else {
								$fieldSql .= "$fieldGlue ".$field->getTableName().'.'.$field->getColumnName().' '.$valueSql;
							}
						}
					}
				}
				if ($conditionInfo['operator'] == 'n' || $conditionInfo['operator'] == 'k' || $conditionInfo['operator'] == 'dnsw') {
					$fieldGlue = ' AND';
				} else {
					$fieldGlue = ' OR';
				}
			}
			$fieldSql .= ')';
			$fieldSqlList[$index] = $fieldSql;
		}
		foreach ($this->manyToManyRelatedModuleConditions as $index => $conditionInfo) {
			$relatedModuleMeta = RelatedModuleMeta::getInstance($this->meta->getTabName(), $conditionInfo['relatedModule']);
			$relationInfo = $relatedModuleMeta->getRelationMeta();
			$fieldSql = '('. $relationInfo['relationTable']. '.'. $relationInfo[$conditionInfo['column']]. $conditionInfo['SQLOperator']. $conditionInfo['value']. ')';
			$fieldSqlList[$index] = $fieldSql;
		}

		// This is added to support reference module fields
		if (isset($this->referenceModuleField)) {
			foreach ($this->referenceModuleField as $index => $conditionInfo) {
				if (empty($conditionInfo['relatedModule'])) {
					continue;
				}
				$handler = vtws_getModuleHandlerFromName($conditionInfo['relatedModule'], $current_user);
				$meta_data = $handler->getMeta();
				$fieldName = $conditionInfo['fieldName'];
				$fields_list = $meta_data->getModuleFields();
				if ($fieldName=='id') {
					if (!is_array($conditionInfo['value'])) {
						$value = "'".$conditionInfo['value']."'";
					} else {
						$value = ''; // will get loaded below
					}
					switch ($conditionInfo['SQLOperator']) {
						case 'e':
							$sqlOperator = '=';
							break;
						case 'n':
							$sqlOperator = '<>';
							break;
						case 'l':
							$sqlOperator = '<';
							break;
						case 'g':
							$sqlOperator = '>';
							break;
						case 'm':
							$sqlOperator = '<=';
							break;
						case 'h':
							$sqlOperator = '>=';
							break;
						case 'i':
						case 'ni':
						case 'nin':
							$sqlOperator = '';
							$vals = array_map(array( $db, 'quote'), $conditionInfo['value']);
							$value = (($conditionInfo['SQLOperator']=='ni' || $conditionInfo['SQLOperator']=='nin') ? 'NOT ':'').'IN ('.implode(',', $vals).')';
							break;
						default:
							$sqlOperator = '=';
					}
					if (!empty($value)) {
						$fname = $meta_data->getObectIndexColumn();
						$bTable = $meta_data->getEntityBaseTable();
						if ($bTable=='vtiger_users') {
							$fieldSqlList[$index] = "(vtiger_users.id $sqlOperator $value or vtiger_groups.groupid $sqlOperator $value)";
						} else {
							$tname = $bTable.$conditionInfo['referenceField'];
							if (strpos($this->fromClause, $tname)===false) {
								$tname = $bTable;
							}
							if ($conditionInfo['SQLOperator'] == 'empty' || $conditionInfo['SQLOperator'] == 'y') {
								$fieldSqlList[$index] = "($tname.$fname IS NULL OR $tname.$fname = '' OR $tname.$fname = '0')";
								continue;
							}
							$fieldSqlList[$index] = "($tname.$fname $sqlOperator $value)";
						}
					}
					continue;
				}
				if (empty($fields_list[$fieldName])) {
					continue;
				}
				$fieldObject = $fields_list[$fieldName];
				$columnName = $fieldObject->getColumnName();
				$tableName = $fieldObject->getTableName();
				$uiType = $fieldObject->getUIType();
				if ($uiType == Field_Metadata::UITYPE_CHECKBOX) {
					if ($conditionInfo['value'] == 'true:boolean') {
						$conditionInfo['value'] = '1';
					}
					if ($conditionInfo['value'] == 'false:boolean') {
						$conditionInfo['value'] = '0';
					}
				}
				$valueSQL = $this->getConditionValue($conditionInfo['value'], $conditionInfo['SQLOperator'], $fieldObject, $tableName.$conditionInfo['referenceField']);
				if ($conditionInfo['SQLOperator']=='exists') {
					$fieldSqlList[$index] = '('.$valueSQL[0].')';
					continue;
				}
				if ($tableName=='vtiger_users') {
					$reffield = $moduleFieldList[$conditionInfo['referenceField']];
					if ($reffield->getUIType() == '101') {
						$fieldSql = '('.$tableName.$conditionInfo['referenceField'].'.'.$columnName.' '.$valueSQL[0].')';
					} else {
						$fieldSql = '('.$tableName.'.'.$columnName.' '.$valueSQL[0].')';
					}
				} else {
					$fieldSql = '('.$tableName.$conditionInfo['referenceField'].'.'.$columnName.' '.$valueSQL[0].')';
				}
				$fieldSqlList[$index] = $fieldSql;
			}
		}
		// This is needed as there can be condition in different order and there is an assumption in makeGroupSqlReplacements API
		// that it expects the array in an order and then replaces the sql with its the corresponding place
		ksort($fieldSqlList);
		$groupSql = $this->makeGroupSqlReplacements($fieldSqlList, $groupSql);
		if ($this->conditionInstanceCount > 0) {
			$this->conditionalWhere = $groupSql;
			$sql .= $groupSql;
		}
		$sql .= " AND $baseTable.$baseTableIndex > 0";
		$this->whereClause = $sql;
		return $sql;
	}

	/**
	 * Function returns table column for the given sort field name
	 * @param string field name
	 * @return string column name
	 */
	public function getOrderByColumn($fieldName) {
		$fieldList = $this->getModuleFields();
		if (empty($fieldList[$fieldName])) {
			// we may have been given a columnname directly, but we still need to check so we try to convert it to a field name
			global $adb;
			$rs = $adb->pquery('select fieldname from vtiger_field where columnname=? and tabid=?', array($fieldName, getTabid($this->module)));
			if ($rs && $adb->num_rows($rs)>0) {
				$fname = $adb->query_result($rs, 0, 0);
				if (empty($fieldList[$fname])) {
					return $fieldName;
				} else {
					$fieldName = $fname;
				}
			} else {
				return $fieldName;
			}
		}
		$orderByFieldModel = $fieldList[$fieldName];

		$parentReferenceField = '';
		preg_match('/(\w+) ; \((\w+)\) (\w+)/', $fieldName, $matches);
		if (count($matches) != 0) {
			list($full, $parentReferenceField, $referenceModule, $fieldName) = $matches;
		}
		if ($orderByFieldModel && $orderByFieldModel->getFieldDataType() == 'reference') {
			$referenceModules = $orderByFieldModel->getReferenceList();
			if (in_array('DocumentFolders', $referenceModules)) {
				$orderByColumn = 'vtiger_attachmentsfolder.foldername';
			} elseif (in_array('Currency', $referenceModules)) {
				if ($parentReferenceField) {
					$orderByColumn = 'vtiger_currency_info'.$parentReferenceField.$orderByFieldModel->getFieldName().'.currency_name';
				} else {
					$orderByColumn = 'vtiger_currency_info.currency_name';
				}
			} elseif (in_array('Users', $referenceModules)) {
				$orderByColumn = 'vtiger_users'.$parentReferenceField.$fieldName.'.ename';
			} else {
				$orderByColumn = '';
				foreach ($referenceModules as $mod) {
					$efinfo = getEntityField($mod, true);
					$orderByColumn .= $efinfo['fieldname'].',';
				}
				if (count($referenceModules)>1) {
					$orderByColumn = 'COALESCE('.trim($orderByColumn, ',').')';
				} else {
					$orderByColumn = trim($orderByColumn, ',');
				}
				//$orderByColumn = $orderByFieldModel->getColumnName();
				//'vtiger_crmentity'.$parentReferenceField.$orderByFieldModel->getFieldName().'.label'; //.$fieldModel->get('column');
			}
		} elseif ($orderByFieldModel && $orderByFieldModel->getFieldDataType() == 'owner') {
			if ($parentReferenceField) {
				$userTableName = 'vtiger_users'.$parentReferenceField.$orderByFieldModel->getFieldName();
				$groupTableName = 'vtiger_groups'.$parentReferenceField.$orderByFieldModel->getFieldName();
				$orderByColumn = "COALESCE($userTableName.ename,$groupTableName.groupname)";
			} else {
				$orderByColumn = 'COALESCE(vtiger_users.ename,vtiger_groups.groupname)';
			}
		} elseif ($orderByFieldModel) {
			$orderByColumn = $orderByFieldModel->getTableName().$parentReferenceField.'.'.$orderByFieldModel->getColumnName();
		}
		return $orderByColumn;
	}

	/**
	 *
	 * @param mixed $value
	 * @param string $operator
	 * @param WebserviceField $field
	 */
	private function getConditionValue($value, $operator, $field, $referenceFieldName = '') {
		$operator = strtolower($operator);
		$db = PearDatabase::getInstance();
		$noncommaSeparatedFieldTypes = array('currency','percentage','double','number');
		$likeOperators = array('s','ew','c','cnc','k','dnsw','dnew');

		// if ($field->getFieldDataType() == 'multipicklist' && in_array($operator, array('e', 'n'))) {
			// $valueArray = getCombinations($valueArray);
			// foreach ($valueArray as $key => $value) {
				// $valueArray[$key] = ltrim($value, ' |##| ');
			// }
		// } else
		if (is_string($value) && $operator != 'e' && $operator != 'cnc' && !in_array($field->getFieldDataType(), $noncommaSeparatedFieldTypes)) {
			$valueArray = explode(',', $value);
		} else {
			$valueArray = (array)$value;
		}
		$sql = array();
		if ($operator=='exists') {
			global $current_user;
			$mid = getTabModuleName($field->getTabId());
			$qg = new QueryGenerator($mid, $current_user);
			$qg->addCondition($field->getFieldName(), $value, 'e');
			$sql[] = 'SELECT EXISTS(SELECT 1 '.$qg->getFromClause().$qg->getWhereClause().')';
			return $sql;
		}
		if ($operator=='i' || $operator=='in' || $operator=='ni' || $operator=='nin') {
			$vals = array_map(array($db, 'quote'), $valueArray);
			$sql[] = (($operator=='ni' || $operator=='nin') ? ' NOT ':'').'IN ('.implode(',', $vals).')';
			return $sql;
		}
		if ($operator=='[]' || $operator=='[[' || $operator==']]' || $operator=='][') {
			$valueArray = explode(',', $value);
			$vals = array_map(
				function ($v) {
					$db = PearDatabase::getInstance();
					if (!is_numeric($v)) {
						$v = $db->quote($v);
					}
					return $v;
				},
				$valueArray
			);
			$rangeFName = ($referenceFieldName=='' ? $this->getSQLColumn($field->getFieldName(), false) : $referenceFieldName.'.'.$field->getColumnName());
			switch ($operator) {
				case '[]':
					$sql[] = sprintf('>= %s AND %s <= %s', $vals[0], $rangeFName, $vals[1]);
					break;
				case '[[':
					$sql[] = sprintf('>= %s AND %s < %s', $vals[0], $rangeFName, $vals[1]);
					break;
				case ']]':
					$sql[] = sprintf('> %s AND %s <= %s', $vals[0], $rangeFName, $vals[1]);
					break;
				case '][':
					$sql[] = sprintf('> %s AND %s < %s', $vals[0], $rangeFName, $vals[1]);
					break;
			}
			return $sql;
		}
		if ($operator == 'between' || $operator == 'bw' || $operator == 'notequal') {
			if ($field->getFieldName() == 'birthday') {
				$valueArray[0] = getValidDBInsertDateTimeValue($valueArray[0]);
				$valueArray[1] = getValidDBInsertDateTimeValue($valueArray[1]);
				$sql[] = 'BETWEEN DATE_FORMAT('.$db->quote($valueArray[0]).", '%m%d') AND DATE_FORMAT(".$db->quote($valueArray[1]).", '%m%d')";
			} else {
				if ($this->isDateType($field->getFieldDataType())) {
					$valueArray[0] = getValidDBInsertDateTimeValue($valueArray[0]);
					$valueArray[1] = getValidDBInsertDateTimeValue($valueArray[1]);
				}
				$sql[] = 'BETWEEN '.$db->quote($valueArray[0]).' AND '. $db->quote($valueArray[1]);
			}
			return $sql;
		}
		$yes = strtolower(getTranslatedString('yes'));
		$no = strtolower(getTranslatedString('no'));
		foreach ($valueArray as $value) {
			if (!$this->isStringType($field->getFieldDataType())) {
				$value = trim($value);
			}
			if ($operator == 'empty' || $operator == 'y') {
				$sql[] = sprintf(
					"IS NULL OR %s = ''",
					($referenceFieldName=='' ? $this->getSQLColumn($field->getFieldName(), false) : $referenceFieldName.'.'.$field->getColumnName())
				);
				continue;
			}
			if ($operator == 'ny') {
				$sql[] = sprintf(
					"IS NOT NULL AND %s != ''",
					($referenceFieldName=='' ? $this->getSQLColumn($field->getFieldName(), false) : $referenceFieldName.'.'.$field->getColumnName())
				);
				continue;
			}
			if ((strtolower(trim($value))=='null') || (trim($value)=='' && !$this->isStringType($field->getFieldDataType())) && ($operator=='e' || $operator=='n')) {
				if ($operator == 'e') {
					$sql[] = 'IS NULL';
					continue;
				}
				$sql[] = 'IS NOT NULL';
				continue;
			} elseif ($field->getFieldDataType() == 'boolean') {
				$value = strtolower($value);
				if ($value == 'yes' || $value == $yes) {
					$value = 1;
				} elseif ($value == 'no' || $value == $no) {
					$value = 0;
				}
			} elseif ($this->isDateType($field->getFieldDataType())) {
				if (substr($value, 0, 3)!='::#' && !in_array($operator, $likeOperators)) {
					$value = getValidDBInsertDateTimeValue($value);
				}
				if (empty($value)) {
					if ($operator == 'n') {
						$sql[] = 'IS NOT NULL';
					} else {
						$sql[] = 'IS NULL';
					}
					return $sql;
				}
			} elseif ($field->getFieldDataType() === 'currency') {
				if (substr($value, 0, 3)!='::#') {
					$uiType = $field->getUIType();
					if ($uiType == 72) {
						$value = CurrencyField::convertToDBFormat($value, null, true);
					} elseif ($uiType == 71) {
						$value = CurrencyField::convertToDBFormat($value, $this->user);
					}
				}
			}

			if ($field->getFieldName() == 'birthday' && !$this->isRelativeSearchOperators($operator)) {
				$value = 'DATE_FORMAT('.$db->quote($value).", '%m%d')";
			} else {
				$value = $db->sql_escape_string($value);
			}

			if (trim($value) == '' && ($operator == 's' || $operator == 'ew' || $operator == 'c' || $operator == 'cnc')
					&& ($this->isStringType($field->getFieldDataType()) ||
					$field->getFieldDataType() == 'picklist' ||
					$field->getFieldDataType() == 'multipicklist')) {
				$sql[] = "LIKE ''";
				continue;
			}

			if (trim($value) == '' && ($operator == 'k') && $this->isStringType($field->getFieldDataType())) {
				$sql[] = "NOT LIKE ''";
				continue;
			}
			$addquotes = true;
			switch ($operator) {
				case 'e':
					$sqlOperator = '=';
					break;
				case 'n':
					$sqlOperator = '<>';
					break;
				case 's':
					$sqlOperator = 'LIKE';
					$value = "$value%";
					break;
				case 'ew':
					$sqlOperator = 'LIKE';
					$value = "%$value";
					break;
				case 'c':
				case 'cnc':
					$sqlOperator = 'LIKE';
					$value = "%$value%";
					break;
				case 'k':
					$sqlOperator = 'NOT LIKE';
					$value = "%$value%";
					break;
				case 'dnsw':
					$sqlOperator = 'NOT LIKE';
					$value = "$value%";
					break;
				case 'dnew':
					$sqlOperator = 'NOT LIKE';
					$value = "%$value";
					break;
				case 'monthday':
					$sqlOperator = '=';
					if (substr($value, 0, 3)=='::#') {
						$addquotes = false;
						$value = 'DATE_FORMAT('.$value.",'%m%d') ";
					} else {
						list($void, $m, $d) = explode('-', getValidDBInsertDateValue($value));
						$value = $m.$d;
					}
					break;
				case 'nsx':
				case 'sx':
					$sqlOperator = 'SOUNDEX';
					break;
				case 'rgxp':
					$sqlOperator = 'REGEXP';
					break;
				case 'l':
					$sqlOperator = '<';
					break;
				case 'g':
					$sqlOperator = '>';
					break;
				case 'm':
					$sqlOperator = '<=';
					break;
				case 'h':
					$sqlOperator = '>=';
					break;
				case 'a':
					$sqlOperator = '>';
					break;
				case 'b':
					$sqlOperator = '<';
					break;
			}
			if ($field->getFieldDataType() == 'reference' && $operator == 'e' && empty($value)) {
				$sql[] = ' IS NULL';
			}
			if ($this->requiresQuoteSearchOperators($operator) || (!$this->isNumericType($field->getFieldDataType()) && $addquotes &&
				($field->getFieldName() != 'birthday' || ($field->getFieldName() == 'birthday' && $this->isRelativeSearchOperators($operator))))
			) {
				$value = "'$value'";
			}
			if ($this->isNumericType($field->getFieldDataType())) {
				if (empty($value)) {
					$value = '0';
				} elseif (strpos((string)$value, ',')>0  || (!is_numeric($value) && strpos($value, "'") === false)) {
					$value = "'$value'";
				}
			}
			if ($this->requiresSoundex($operator)) {
				$sql[] = 'SOUNDEX('.$field->getTableName().'.'.$field->getColumnName().') '.($operator=='nsx' ? 'NOT ' : '')."LIKE SOUNDEX($value)";
			} else {
				$sql[] = "$sqlOperator $value";
			}
		}
		return $sql;
	}

	private function makeGroupSqlReplacements($fieldSqlList, $groupSql) {
		$pos = 0;
		$nextOffset = 0;
		for ($index = 0; $index < $this->conditionInstanceCount; $index++) {
			$pos = strpos($groupSql, $index.'', $nextOffset);
			if ($pos !== false) {
				$beforeStr = substr($groupSql, 0, $pos);
				$afterStr = substr($groupSql, $pos + strlen($index));
				if (isset($fieldSqlList[$index])) {
					$fieldSql = $fieldSqlList[$index];
				} else {
					$fieldSql = 'false';
				}
				$nextOffset = strlen($beforeStr.$fieldSql);
				$groupSql = $beforeStr.$fieldSql.$afterStr;
			}
		}
		return $groupSql;
	}

	private function isRelativeSearchOperators($operator) {
		$nonDaySearchOperators = array('l','g','m','h');
		return in_array($operator, $nonDaySearchOperators);
	}
	private function requiresQuoteSearchOperators($operator) {
		$requiresQuote = array('s','ew','c','cnc','k');
		return in_array($operator, $requiresQuote);
	}
	private function requiresREGEXP($operator) {
		return ($operator == 'rgxp');
	}
	private function isNumericType($type) {
		return ($type == 'integer' || $type == 'double' || $type == 'currency');
	}

	private function isStringType($type) {
		return ($type == 'string' || $type == 'text' || $type == 'email' || $type == 'reference' || $type == 'phone');
	}

	private function isDateType($type) {
		return ($type == 'date' || $type == 'datetime');
	}

	private function requiresSoundex($operator) {
		return ($operator == 'sx' || $operator == 'nsx');
	}

	public function fixDateTimeValue($name, $value, $first = true) {
		$moduleFields = $this->getModuleFields();
		$field = $moduleFields[$name];
		$type = $field ? $field->getFieldDataType() : false;
		if ($type == 'datetime' && strrpos($value, ' ') === false) {
			if ($first) {
				return $value.' 00:00:00';
			} else {
				return $value.' 23:59:59';
			}
		}
		return $value;
	}

	public function addCondition($fieldname, $value, $operator, $glue = null, $newGroup = false, $newGroupType = null) {
		$conditionNumber = $this->conditionInstanceCount++;
		if ($glue != null && $conditionNumber > 0) {
			$this->addConditionGlue($glue);
		}

		$this->groupInfo .= "$conditionNumber ";
		$this->whereFields[] = $fieldname;
		$this->reset();
		$this->conditionals[$conditionNumber] = $this->getConditionalArray($fieldname, $value, $operator);
	}

	public function addRelatedModuleCondition($relatedModule, $column, $value, $SQLOperator) {
		$conditionNumber = $this->conditionInstanceCount++;
		$this->groupInfo .= "$conditionNumber ";
		$this->manyToManyRelatedModuleConditions[$conditionNumber] = array('relatedModule'=>
			$relatedModule,'column'=>$column,'value'=>$value,'SQLOperator'=>$SQLOperator);
		$this->setReferenceFields();
	}

	public function addReferenceModuleFieldCondition($relatedModule, $referenceField, $fieldName, $value, $SQLOperator, $glue = null) {
		$conditionNumber = $this->conditionInstanceCount++;
		if ($glue != null && $conditionNumber > 0) {
			$this->addConditionGlue($glue);
		}

		$this->groupInfo .= "$conditionNumber ";
		$this->referenceModuleField[$conditionNumber] = array(
			'relatedModule'=> $relatedModule,
			'referenceField'=> $referenceField,
			'fieldName'=>$fieldName,
			'value'=>$value,
			'SQLOperator'=>$SQLOperator,
		);
		$this->setReferenceFields();
	}

	private function getConditionalArray($fieldname, $value, $operator) {
		if (is_string($value)) {
			$value = trim($value);
		} elseif (is_array($value)) {
			$value = array_map('trim', $value);
		}
		return array('name'=>$fieldname,'value'=>$value,'operator'=>$operator);
	}

	public function startGroup($groupType = '') {
		if ($this->groupInfo == '') {
			$groupType=''; // first grouping cannot have glue
		}
		$this->groupInfo .= " $groupType (";
	}

	public function endGroup() {
		$this->groupInfo .= ')';
	}

	public function addConditionGlue($glue) {
		$this->groupInfo .= " $glue ";
	}

	public static function constructAdvancedSearchURLFromReportCriteria($conditions, $module) {
		$conds = array();
		$grpcd = array(null);
		foreach ($conditions as $grp => $cols) {
			$grpcd[] = array('groupcondition' => $cols['condition']);
			foreach ($cols['columns'] as $col) {
				$col['groupid'] = $grp;
				$col['columncondition'] = $col['column_condition'];
				unset($col['column_condition']);
				$finfo = explode(':', $col['columnname']);
				$col['columnname'] = $finfo[0].':'.$finfo[1].':'.$finfo[3].':'.$finfo[2].':'.$finfo[4];
				$conds[] = $col;
			}
		}
		return 'index.php?module=' . $module . '&action=index&query=true&search=true&searchtype=advance&advft_criteria='
			.urlencode(json_encode($conds)).'&advft_criteria_groups=' . urlencode(json_encode($grpcd));
	}

	public function constructAdvancedSearchConditions($module, $conditions) {
		$output = array();
		if (empty($module) || empty($conditions)) {
			return $output;
		}
		$output['query'] = 'true';
		$output['searchtype'] = 'advance';
		$advft_criteria_groups = array();
		$advft_criteria = array();
		$groupid = 1;
		foreach ($conditions as $fields_list) {
			$lastcondition = '';
			$curfld = 1;
			foreach ($fields_list as $field) {
				$fieldcond = array(
					'groupid' => $groupid,
					'columnname' => CustomView::getFilterFieldDefinition($field['field'], $module),
					'comparator' => $field['op'],
					'value' => $field['value'],
					'columncondition' => ($curfld==count($fields_list) ? '' : $field['glue']),
				);
				$lastcondition = $field['glue'];
				$advft_criteria[] = $fieldcond;
				$curfld++;
			}
			$advft_criteria_groups[$groupid] = array('groupcondition' => ($groupid==count($conditions) ? '' : $lastcondition));
			$groupid++;
		}
		$output['advft_criteria'] = json_encode($advft_criteria);
		$output['advft_criteria_groups'] = json_encode($advft_criteria_groups);
		return $output;
	}

	public function addUserSearchConditions($input) {
		global $default_charset;
		if (isset($input['searchtype']) && $input['searchtype']=='advance') {
			$advft_criteria = (empty($input['advft_criteria']) ? (empty($_REQUEST['advft_criteria']) ? '' : $_REQUEST['advft_criteria']) : $input['advft_criteria']);
			if (!empty($advft_criteria)) {
				$advft_criteria = json_decode($advft_criteria, true);
			}
			if (empty($advft_criteria) || count($advft_criteria) <= 0) {
				return ;
			}

			$advft_criteria_groups = (empty($input['advft_criteria_groups']) ?
				(isset($_REQUEST['advft_criteria_groups']) ? $_REQUEST['advft_criteria_groups'] : null) :
				$input['advft_criteria_groups']);
			if (!empty($advft_criteria_groups)) {
				$advft_criteria_groups = json_decode($advft_criteria_groups, true);
			}

			$advfilterlist = getAdvancedSearchCriteriaList($advft_criteria, $advft_criteria_groups, $this->getModule());

			if (empty($advfilterlist) || count($advfilterlist) <= 0) {
				return ;
			}

			if ($this->conditionInstanceCount > 0) {
				$this->startGroup(self::$AND);
			} else {
				$this->startGroup('');
			}
			foreach ($advfilterlist as $groupcolumns) {
				$filtercolumns = $groupcolumns['columns'];
				if (count($filtercolumns) > 0) {
					$this->startGroup('');
					foreach ($filtercolumns as $index => $filter) {
						$name = explode(':', $filter['columnname']);
						$relatedModule = substr($name[3], 0, strpos($name[3], '_'));
						if (empty($name[2]) && $name[1] == 'crmid' && $name[0] == 'vtiger_crmentity') {
							$name = $this->getSQLColumn('id');
						} else {
							$name = $name[2];
						}
						if ($relatedModule==$this->module) {
							$this->addCondition($name, $filter['value'], $filter['comparator']);
						} else {
							$referenceField = getFirstFieldForModule($this->module, $relatedModule);
							$this->addReferenceModuleFieldCondition($relatedModule, $referenceField, $name, $filter['value'], $filter['comparator'], $filter['column_condition']);
						}
						$columncondition = $filter['column_condition'];
						if (!empty($columncondition)) {
							$this->addConditionGlue($columncondition);
						}
					}
					$this->endGroup();
					$groupConditionGlue = $groupcolumns['condition'];
					if (!empty($groupConditionGlue)) {
						$this->addConditionGlue($groupConditionGlue);
					}
				}
			}
			$this->endGroup();
		} elseif (isset($input['type']) && $input['type']=='dbrd') {
			if ($this->conditionInstanceCount > 0) {
				$this->startGroup(self::$AND);
			} else {
				$this->startGroup('');
			}
			$allConditionsList = $this->getDashBoardConditionList();
			$conditionList = $allConditionsList['conditions'];
			$relatedConditionList = $allConditionsList['relatedConditions'];
			$noOfConditions = count($conditionList);
			$noOfRelatedConditions = count($relatedConditionList);
			foreach ($conditionList as $index => $conditionInfo) {
				$this->addCondition(
					$conditionInfo['fieldname'],
					$conditionInfo['value'],
					$conditionInfo['operator']
				);
				if ($index < $noOfConditions - 1 || $noOfRelatedConditions > 0) {
					$this->addConditionGlue(self::$AND);
				}
			}
			foreach ($relatedConditionList as $index => $conditionInfo) {
				$this->addRelatedModuleCondition(
					$conditionInfo['relatedModule'],
					$conditionInfo['conditionModule'],
					$conditionInfo['finalValue'],
					$conditionInfo['SQLOperator']
				);
				if ($index < $noOfRelatedConditions - 1) {
					$this->addConditionGlue(self::$AND);
				}
			}
			$this->endGroup();
		} else {
			if (isset($input['search_field']) && $input['search_field'] !='') {
				$fieldName=vtlib_purify($input['search_field']);
			} else {
				return ;
			}
			if ($this->conditionInstanceCount > 0) {
				$this->startGroup(self::$AND);
			} else {
				$this->startGroup('');
			}
			$moduleFields = $this->getModuleFields();
			$field = $moduleFields[$fieldName];
			$type = $field->getFieldDataType();
			if (isset($input['search_text']) && $input['search_text']!='') {
				// search other characters like "|, ?, ?" by jagi
				$value = $input['search_text'];
				$stringConvert = function_exists('iconv') ? @iconv('UTF-8', $default_charset, $value) : $value;
				if (!$this->isStringType($type)) {
					$value=trim($stringConvert);
				}

				if ($type == 'currency') {
					// Some of the currency fields like Unit Price, Total, Sub-total etc of Inventory modules, do not need currency conversion
					if ($field->getUIType() == '72') {
						$value = CurrencyField::convertToDBFormat($value, null, true);
					} else {
						$currencyField = new CurrencyField($value);
						if ($this->getModule() == 'Potentials' && $fieldName == 'amount') {
							$currencyField->setNumberofDecimals(2);
						}
						$value = $currencyField->getDBInsertedValue();
					}
				}
			} else {
				$value = '';
			}
			if (!empty($input['operator'])) {
				$operator = $input['operator'];
			} elseif (strtolower(trim($value)) == 'null') {
				$operator = 'e';
			} else {
				if (!$this->isNumericType($type) && !$this->isDateType($type)) {
					$operator = 'c';
				} elseif ($this->isDateType($type)) {
					$operator = 'e';
				} else {
					$operator = 'h';
				}
			}
			if (isset($_REQUEST['mode']) && $_REQUEST['mode'] == 'SearchDocuments') {
				$this->SearchDocuments = true;
			}
			$this->addCondition($fieldName, $value, $operator);
			$this->endGroup();
		}
	}

	public function getDashBoardConditionList() {
		if (isset($_REQUEST['leadsource'])) {
			$leadSource = $_REQUEST['leadsource'];
		}
		if (isset($_REQUEST['date_closed'])) {
			$dateClosed = $_REQUEST['date_closed'];
		}
		if (isset($_REQUEST['sales_stage'])) {
			$salesStage = $_REQUEST['sales_stage'];
		}
		if (isset($_REQUEST['closingdate_start'])) {
			$dateClosedStart = $_REQUEST['closingdate_start'];
		}
		if (isset($_REQUEST['closingdate_end'])) {
			$dateClosedEnd = $_REQUEST['closingdate_end'];
		}
		if (isset($_REQUEST['owner'])) {
			$owner = vtlib_purify($_REQUEST['owner']);
		}
		if (isset($_REQUEST['campaignid'])) {
			$campaignId = vtlib_purify($_REQUEST['campaignid']);
		}
		if (isset($_REQUEST['quoteid'])) {
			$quoteId = vtlib_purify($_REQUEST['quoteid']);
		}
		if (isset($_REQUEST['invoiceid'])) {
			$invoiceId = vtlib_purify($_REQUEST['invoiceid']);
		}
		if (isset($_REQUEST['purchaseorderid'])) {
			$purchaseOrderId = vtlib_purify($_REQUEST['purchaseorderid']);
		}

		$conditionList = array();
		if (!empty($dateClosedStart) && !empty($dateClosedEnd)) {
			$conditionList[] = array('fieldname'=>'closingdate', 'value'=>$dateClosedStart, 'operator'=>'h');
			$conditionList[] = array('fieldname'=>'closingdate', 'value'=>$dateClosedEnd, 'operator'=>'m');
		}
		if (!empty($salesStage)) {
			if ($salesStage == 'Other') {
				$conditionList[] = array('fieldname'=>'sales_stage', 'value'=>'Closed Won', 'operator'=>'n');
				$conditionList[] = array('fieldname'=>'sales_stage', 'value'=>'Closed Lost', 'operator'=>'n');
			} else {
				$conditionList[] = array('fieldname'=>'sales_stage', 'value'=> $salesStage, 'operator'=>'e');
			}
		}
		if (!empty($leadSource)) {
			$conditionList[] = array('fieldname'=>'leadsource', 'value'=>$leadSource, 'operator'=>'e');
		}
		if (!empty($dateClosed)) {
			$conditionList[] = array('fieldname'=>'closingdate', 'value'=>$dateClosed, 'operator'=>'h');
		}
		if (!empty($owner)) {
			$conditionList[] = array('fieldname'=>'assigned_user_id', 'value'=>$owner, 'operator'=>'e');
		}
		$relatedConditionList = array();
		if (!empty($campaignId)) {
			$relatedConditionList[] = array('relatedModule'=>'Campaigns','conditionModule'=>
				'Campaigns','finalValue'=>$campaignId, 'SQLOperator'=>'=');
		}
		if (!empty($quoteId)) {
			$relatedConditionList[] = array('relatedModule'=>'Quotes','conditionModule'=>
				'Quotes','finalValue'=>$quoteId, 'SQLOperator'=>'=');
		}
		if (!empty($invoiceId)) {
			$relatedConditionList[] = array('relatedModule'=>'Invoice','conditionModule'=>
				'Invoice','finalValue'=>$invoiceId, 'SQLOperator'=>'=');
		}
		if (!empty($purchaseOrderId)) {
			$relatedConditionList[] = array('relatedModule'=>'PurchaseOrder','conditionModule'=>
				'PurchaseOrder','finalValue'=>$purchaseOrderId, 'SQLOperator'=>'=');
		}
		return array('conditions'=>$conditionList,'relatedConditions'=>$relatedConditionList);
	}

	public function initForGlobalSearchByType($type, $value, $operator = 's') {
		$fieldList = $this->meta->getFieldNameListByType($type);
		if ($this->conditionInstanceCount <= 0) {
			$this->startGroup('');
		} else {
			$this->startGroup(self::$AND);
		}
		$nameFieldList = explode(',', $this->getModuleNameFields($this->module));
		foreach ($nameFieldList as $nameList) {
			$field = $this->meta->getFieldByColumnName($nameList);
			$this->fields[] = $field->getFieldName();
		}
		foreach ($fieldList as $index => $field) {
			$fieldName = $this->meta->getFieldByColumnName($field);
			$this->fields[] = $fieldName->getFieldName();
			if ($index > 0) {
				$this->addConditionGlue(self::$OR);
			}
			$this->addCondition($fieldName->getFieldName(), $value, $operator);
		}
		$this->endGroup();
		if (!in_array('id', $this->fields)) {
			$this->fields[] = 'id';
		}
	}
}
?>
