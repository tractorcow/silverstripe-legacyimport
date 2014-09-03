<?php

abstract class LegacyImporter extends Object {

	/**
	 * Parent build task to report to
	 *
	 * @var LegacyImportTask
	 */
	protected $task = null;

	/**
	 * Helpers for linking, matching other objects, etc
	 *
	 * @var array[LegacyHelper]
	 */
	protected $helpers = array();

	/**
	 * Add the _ImportedID column to the imported table
	 */
	protected function setupRemoteTable() {
		$conn = $this->task->getRemoteConnection();
		$baseClass = $this->getRemoteBaseTable();

		// Create columns
		$this->task->ensureTableHasColumn($conn, $baseClass, '_ImportedID', 'int(11) not null default 0');
		$this->task->ensureTableHasColumn($conn, $baseClass, '_ImportedDate', 'datetime');
	}

	/**
	 * Create a dataobject importer
	 *
	 * @param LegacyImportTask $task Parent task
	 * @param array $parameters Parameter input
	 * @param array $helpers List of helper classes
	 * @throws InvalidArgumentException
	 */
	public function __construct(LegacyImportTask $task, $parameters, $helpers = array()) {
		parent::__construct();

		// Save running task
		$this->task = $task;
		$this->helpers = $helpers;
	}

	
	abstract public function importPass();
	abstract public function identifyPass();
	abstract public function linkPass();

	abstract public function describe();

	abstract public function flush();
}
