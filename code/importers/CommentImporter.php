<?php

class CommentImporter extends DataObjectImporter {

	public function __construct(LegacyImportTask $task, $parameters, $helpers = array()) {
		$this->targetClass = 'Comment';
		parent::__construct($task, $parameters, $helpers);
	}

	protected function getRemoteClassHierarchy() {
		return array('PageComment' => 'PageComment');
	}

	protected function getRemoteBaseTable() {
		return 'PageComment';
	}

	protected function updateLocalObject(DataObject $localObject, ArrayData $remoteObject) {
		foreach($remoteObject->toMap() as $field => $value) {
			// Skip ID and class
			if(in_array($field, array('ClassName', 'ID'))) continue;

			// Skip obsolete fields
			if(preg_match('/^_obsolete.*/', $field)) continue;

			// While updating map any relation field that we can
			if(preg_match('/(?<relation>.+)ID$/', $field, $matches)) {
				if($value) {
					// Try to find local ID that corresponds to this relation
					$localID = $this->findMappedRelation($matches['relation'], $value);
					// Skip empty
					if($localID) $localObject->$field = $localID;
				}
			} else {
				$localObject->$field = $value;
			}
		}

		// Fix mapped tables from old schema to new one
		$parentPage = SiteTree::get()->filter('LegacyID', $remoteObject->ParentID)->first();
		$localObject->Moderated = !$remoteObject->NeedsModeration;
		$localObject->URL = $remoteObject->CommenterURL;
		$localObject->BaseClass = 'SiteTree';
		$localObject->ParentID = $parentPage ? $remoteObject->ID : 0;

		// Let helpers update each object
		foreach($this->helpers as $helper) {
			$helper->updateLocalObject($localObject, $remoteObject);
		}

		// Save mapping ID
		// Note: If using a non-identifying strategy (e.g. Add) then this step is important
		// to ensure that this object is not re-added in subsequent imports
		$this->identifyRecords($localObject, $remoteObject, true);
	}
}
