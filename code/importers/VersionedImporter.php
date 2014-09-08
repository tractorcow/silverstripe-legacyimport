<?php

class VersionedImporter extends DataObjectImporter {

	/**
	 * Determine if this remote record has been published
	 *
	 * @param ArrayData $remoteObject
	 * @return bool
	 */
	protected function isRemoteObjectPublished(ArrayData $remoteObject) {
		$table = $this->getRemoteBaseTable();
		$conn = $this->task->getRemoteConnection();
		$result = $conn->query(sprintf(
			"SELECT COUNT(*) FROM \"{$table}_Live\" WHERE \"ID\" = %d",
			intval($remoteObject->ID)
		));
		return $result->value() > 0;
	}

	protected function updateLocalObject(\DataObject $localObject, \ArrayData $remoteObject) {
		parent::updateLocalObject($localObject, $remoteObject);

		if($this->isRemoteObjectPublished($remoteObject)) {
			// Publish imported pages
			$localObject->publish('Stage', 'Live');
		}
	}
}