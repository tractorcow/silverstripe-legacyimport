<?php

class SiteTreeImporter extends DataObjectImporter {
	
	public function __construct(LegacyImportTask $task, $parameters) {
		$this->targetClass = 'SiteTree';
		$this->idColumns = array(
			'ClassName',
			'URLSegment'
		);
		parent::__construct($task, $parameters);
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
			$remoteID = null;
			
			// Scan for previously found matches for this page
			if($match = $this->findMatchingByLocal($localPage->ID)) {
				$remoteID = $match->RemoteID;
			} elseif($remoteParentID !== null) {
				// If we have a known remote parent then filter possible children to find a match
				$remoteFilter = array_merge($localPage->toMap(), array('ParentID' => $remoteParentID));
				$remoteData = $this->findRemoteObject($remoteFilter);
				if($remoteData) {
					// Given the newly matched item save it
					$remoteID = $remoteData->ID;
					$this->addMatching($localPage->ID, $remoteID);
				}
			}

			$this->identifySubtree($remoteItems, $localPage->ID, $remoteID);
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
	
}
