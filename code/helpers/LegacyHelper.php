<?php

abstract class LegacyHelper extends Object {


	/**
	 * Parent build task to report to
	 *
	 * @var LegacyImportTask
	 */
	protected $task;
	
	/**
	 * Create a dataobject helper
	 *
	 * @param LegacyImportTask $task Parent task
	 * @param array $parameters Parameter input
	 * @throws InvalidArgumentException
	 */
	public function __construct(LegacyImportTask $task, $parameters) {
		parent::__construct();

		$this->task = $task;
	}

	/**
	 * Update this object with 
	 *
	 * @param DataObject $localObject
	 * @param ArrayData $remoteObject
	 */
	abstract function updateLocalObject(DataObject $localObject, ArrayData $remoteObject);
}