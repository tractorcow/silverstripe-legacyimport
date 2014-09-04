<?php

class VersionedImporter extends DataObjectImporter {

	protected function updateLocalObject(\DataObject $localObject, \ArrayData $remoteObject) {
		parent::updateLocalObject($localObject, $remoteObject);

		// Publish imported pages
		$localObject->publish('Stage', 'Live');
	}
}