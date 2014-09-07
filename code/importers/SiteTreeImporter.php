<?php

class SiteTreeImporter extends VersionedImporter {
	
	public function __construct(LegacyImportTask $task, $parameters, $helpers = array()) {
		$this->targetClass = 'SiteTree';
		$this->idColumns = array(
			'ClassName',
			'URLSegment'
		);
		parent::__construct($task, $parameters, $helpers);
	}

	/**
	 * Identify all pages in a remote subtree.
	 *
	 * @param ArrayList $remoteItems List of all remote items
	 * @param int $localParentID ID of local parent
	 * @param int $remoteParentID ID of remote parent, or null if not known
	 */
	protected function identifySubtree($remoteItems, $localParentID, $remoteParentID) {
		// Loop through each subpage of this parent
		foreach(SiteTree::get()->filter('ParentID', $localParentID) as $localPage) {
			
			// Check if this page is already matched to a remote object
			// Use ?: null to distinguish between no found id, and id = 0 (root)
			$legacyID = $localPage->LegacyID ?: null; 
			if(empty($legacyID) && ($remoteParentID !== null)) {
				// If we have a known remote parent then filter possible children to find a match
				$remoteFilter = array_merge($localPage->toMap(), array('ParentID' => $remoteParentID));
				$remotePage = $this->findRemoteObject($remoteFilter);
				if($remotePage) {
					// Given the newly matched item save it
					$legacyID = $remotePage->ID;
					$this->identifyRecords($localPage, $remotePage);
				}
			}

			$this->identifySubtree($remoteItems, $localPage->ID, $legacyID);
		}
	}

	/**
	 * Query and map all remote objects to local ones
	 */
	public function identifyPass() {
		// If we are not searching hierchally then the default implementation is sufficient
		if(!in_array('ParentID', $this->idColumns)) {
			return parent::identifyPass();
		}

		// Update remote table to include _ImportedID column
		$this->setupRemoteTable();

		// Identify remote objects
		$beforeUnmatched = $this->getUnmatchedRemoteObjects()->count();

		// Get items to identify
		$remoteItems = $this->getRemoteObjects();
		$this->task->message(" * Performing depth first search of pages via ParentID");

		// Match subtree starting at the root
		$this->identifySubtree($remoteItems, 0, 0);

		// Check real progress (reduce number of unmatched remote object)
		$afterUnmatched = $this->getUnmatchedRemoteObjects()->count();
		$this->task->message(" * Result: {$beforeUnmatched} unmatched objects reduced to {$afterUnmatched}");
	}

	protected function updateLocalObject(\DataObject $localObject, \ArrayData $remoteObject) {
		parent::updateLocalObject($localObject, $remoteObject);

		// Publish imported pages
		if($remoteObject->Staus == 'Published') {
			$localObject->publish('Stage', 'Live');
		}
	}
	
}
