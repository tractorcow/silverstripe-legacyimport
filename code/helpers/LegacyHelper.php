<?php

abstract class LegacyHelper extends Object {

	/**
	 * Parent build task to report to
	 *
	 * @var ImportTask
	 */
	protected $task;
	
	/**
	 * Create a dataobject helper
	 *
	 * @param LegacyImportTask $task Parent task
	 * @param array $parameters Parameter input
	 * @throws InvalidArgumentException
	 */
	public function __construct(ImportTask $task, $parameters) {
		parent::__construct();

		$this->task = $task;
	}

	/**
	 * Update this object with 
	 *
	 * @param DataObject $localObject
	 * @param ArrayData $remoteObject
	 */
	abstract public function updateLocalObject(DataObject $localObject, ArrayData $remoteObject);

	abstract public function init();
}