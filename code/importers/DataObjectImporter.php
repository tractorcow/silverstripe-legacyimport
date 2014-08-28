<?php


class DataObjectImporter extends Object {

	/**
	 * Source selector query
	 *
	 * @var SQLQuery
	 */
	protected $sourceQuery = null;

	/**
	 * Target class
	 *
	 * @var string
	 */
	protected $targetClass = null;

	/**
	 * SQL where filter
	 *
	 * @var array
	 */
	protected $targetWhere = array();

	/**
	 * Merge strategy. These could be:
	 *
	 * - 'Add' Only add new objects but do not merge with existing
	 * - 'Identify' Do not add or update objects, but create LegacyDataObject record for matched records
	 * - 'Update' Only update existing objects. Do not add.
	 * - 'AddOrIdentify' Add new objects, but only identify existing objects
	 * - 'AddOrUpdate' Add new objects or update existing objects
	 *
	 * @var string
	 */
	protected $strategy = 'Add';

	/**
	 * List of columns to use to compare legacy dataobjects to existing ones to determine if a match can be made
	 *
	 * @var array
	 */
	protected $idColumns = array();

	/**
	 * Parent build task to report to
	 *
	 * @var LegacyImportTask
	 */
	protected $task;

	public function __construct(LegacyImportTask $task, $parameters) {
		parent::__construct();

		// Save running task
		$this->task = $task;

		if(!empty($parameters['strategy'])) {
			$this->strategy = $parameters['strategy'];
		}
		if(!empty($parameters['class'])) {
			$this->targetClass = $parameters['class'];
		}
		if(!empty($parameters['idcolumns'])) {
			$this->idColumns = $parameters['idcolumns'];
		}
		if(!empty($parameters['where'])) {
			$this->targetWhere = $parameters['where'];
		}
	}

	/**
	 * Cache of queried remote objects
	 *
	 * @var ArrayList
	 */
	protected $remoteObjects = null;

	/**
	 * Select all remote objects given the query parameters
	 *
	 * @return array
	 */
	protected function getRemoteObjects() {
		if($this->remoteObjects) return $this->remoteObjects;

		// Get all tables to query
		$tables = ClassInfo::ancestry($this->targetClass, true);
		$baseClass = array_shift($tables);

		// Generate sql query
		$query = new SQLQuery('*', "\"{$baseClass}\"", $this->targetWhere);
		foreach($tables as $class) {
			$query->addLeftJoin($class, "\"{$baseClass}\".\"ID\" = \"{$class}\".\"ID\"");
		}

		// Run query and cache
		return $this->remoteObjects = new ArrayList(iterator_to_array($this->task->query($query)));
	}

	/**
	 * Describes this task
	 *
	 * return @string
	 */
	public function describe() {
		$desc = get_class($this);
		if($this->targetClass) $desc .= ' for ' . $this->targetClass;
		if($this->strategy) $desc .= ' with strategy ' . $this->strategy;
		return $desc;
	}

	/**
	 * Query and map all remote objects to local ones
	 *
	 * @param LegacyImport $import
	 */
	public function identifyStep() {
		$results = $this->getRemoteObjects();
		$this->task->message("Identifying ".$results->count()." records");
	}

	/**
	 * Query and create all remote objects, making sure to set all has_one fields to 0
	 *
	 * @param LegacyImport $import
	 */
	public function importStep() {
		$results = $this->getRemoteObjects();
		$this->task->message('Importing '.$results->count().' records');
		
	}

	/**
	 * Run over all imported objects and link them to their respective associative objects
	 */
	public function linkStep() {

	}

	/**
	 * Note identification of local object with a remote one
	 *
	 * @param int $localID
	 * @param int $remoteID
	 */
	protected function addMatching($localID, $remoteID) {
		$baseClass = ClassInfo::baseDataClass($this->targetClass);
		$object = LegacyDataObject::get()
			->filter('ObjectClass', $baseClass)
			->filterAny(array(
				'RemoteID' => $remoteID,
				'LocalID' => $localID
			))
			->first();
		$object = $object ?: LegacyDataObject::create();
		$object->RemoteID = $remoteID;
		$object->LocalID = $localID;
		$object->write();
	}

	/**
	 * Find any previously matching for a given remote object
	 *
	 * @param int $remoteID
	 * @return LegacyDataObject Matching data for the given remote ID
	 */
	protected function findMatchingByRemote($remoteID) {
		$baseClass = ClassInfo::baseDataClass($this->targetClass);
		return LegacyDataObject::get()
			->filter(array(
				'ObjectClass' => $baseClass,
				'RemoteID' => $remoteID
			))->first();
	}

	/**
	 * Find any previously matching for a given local object
	 *
	 * @param int $localID
	 * @return LegacyDataObject Matching data for the given local ID
	 */
	protected function findMatchingByLocal($localID) {
		$baseClass = ClassInfo::baseDataClass($this->targetClass);
		return LegacyDataObject::get()
			->filter(array(
				'ObjectClass' => $baseClass,
				'LocalID' => $localID
			))->first();
	}

	/**
	 * Find local dataobject which matches the given $data using the current $idColumns
	 *
	 * @param array $data array of data to query against
	 * @return DataObject
	 */
	protected function findLocalObject($data) {
		$query = DataObject::get($this->targetClass);
		foreach($this->idColumns as $column) {
			$query = $query->filter($column, $data[$column]);
		}
		return $query->first();
	}

	/**
	 * Finds remote dataobject which matches the given $data
	 *
	 * @param array $data array of data to query against
	 * @return array Data for remote object if found
	 */
	protected function findRemoteObject($data) {
		$items = $this->getRemoteObjects();
		foreach($this->idColumns as $column) {
			$items = $items->filter($column, $data[$column]);
		}
		return $items->first();
	}
}
