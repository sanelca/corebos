<?php
/*************************************************************************************************
 * Copyright 2022 JPL TSolucio, S.L. -- This file is a part of coreBOS Customizations.
 * Licensed under the vtiger CRM Public License Version 1.1 (the "License"); you may not use this
 * file except in compliance with the License. You can redistribute it and/or modify it
 * under the terms of the License. JPL TSolucio, S.L. reserves all rights not expressly
 * granted by the License. coreBOS distributed by JPL TSolucio S.L. is distributed in
 * the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. Unless required by
 * applicable law or agreed to in writing, software distributed under the License is
 * distributed on an "AS IS" BASIS, WITHOUT ANY WARRANTIES OR CONDITIONS OF ANY KIND,
 * either express or implied. See the License for the specific language governing
 * permissions and limitations under the License. You may obtain a copy of the License
 * at <http://corebos.org/documentation/doku.php?id=en:devel:vpl11>
 *************************************************************************************************
 *  Module       : Business Mappings:: Wizard Step View Mapping
 *  Version      : 1.0
 *************************************************************************************************
 * The accepted format is:
<map>
<title>Process Title</title>
<steps>
	<step>
		<title></title>
		<sequence></sequence>
		<detailviewlayoutmap>mapid</detailviewlayoutmap>
		<validations>
			<validation>
				<validationmap>mapid</validationmap>
				<positiveactions>
					<action>workflowid</action>
				</positiveactions>
				<negativeactions>
					<action>workflowid</action>
				</negativeactions>
			</validation>
		</validations>
	</step>
</steps>
</map>
*************************************************************************************************/

class Wizard extends processcbMap {
	private $mapping = array();

	public function processMap($arguments) {
		return $this->convertMap2Array();
	}

	private function convertMap2Array() {
		$xml = $this->getXMLContent();
		if (empty($xml) || empty($xml->steps)) {
			return array();
		}
		$steps = array();
		foreach ($xml->steps->step as $s) {
			$step = array();
			$step['title'] = (string)$s->title;
			$step['sequence'] = (int)$s->sequence;
			$step['detailviewlayoutmap'] = (string)$s->detailviewlayoutmap;
			$validations = array();
			if (isset($s->validations)) {
				foreach ($s->validations->validation as $v) {
					$validation = array();
					$validation['mapid'] = (int)$v->validationmap;
					$actions = array();
					if (isset($v->positiveactions)) {
						foreach ($v->positiveactions->action as $a) {
							$actions[] = (int)$a;
						}
					}
					$validation['positiveactions'] = $actions;
					$actions = array();
					if (isset($v->negativeactions)) {
						foreach ($v->negativeactions->action as $a) {
							$actions[] = (int)$a;
						}
					}
					$validation['negativeactions'] = $actions;
					$validations[] = $validation;
				}
			}
			$step['validations'] = $validations;
			$steps[] = $step;
		}
		$this->mapping['totalsteps'] = count($steps);
		$this->mapping['title'] = (isset($xml->title) ? (string)$xml->title : '');
		usort($steps, function ($a, $b) {
			return $a['sequence'] > $b['sequence'] ? 1 : -1;
		});
		$this->mapping['steps'] = $steps;
		return $this->mapping;
	}
}
?>
