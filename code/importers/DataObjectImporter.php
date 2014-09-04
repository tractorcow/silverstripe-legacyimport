<?php


class DataObjectImporter extends LegacyImporter {

	/**
	 * Add new items which don't exist on the new site.
	 */
	const STRATEGY_ADD = 'Add';

	/**
	 * Update data from the old site to the new one.
	 */
	const STRATEGY_UPDATE = 'Update';

	/**
	 * Identify matching records that already exist in both old and new sites.
	 */
	const STRATEGY_IDENTIFY = 'Identify';

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
	 * Merge strategy for this task
	 *
	 * @var array
	 */
	protected $strategy;

	/**
	 * List of columns to use to compare legacy dataobjects to existing ones to determine if a match can be made
	 *
	 * @var array
	 */
	protected $idColumns = array();

	/**
	 * Create a dataobject importer
	 *
	 * @param LegacyImportTask $task Parent task
	 * @param array $parameters Parameter input
	 * @param array $helpers List of helper classes
	 * @throws InvalidArgumentException
	 */
	public function __construct(LegacyImportTask $task, $parameters, $helpers = array()) {
		parent::__construct($task, $parameters, $helpers);

		// Importn parameters
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
		
		// Validate
		if(empty($this->targetClass)) {
			throw new InvalidArgumentException("Missing class specified for step ".get_class($this));
		}
		if(empty($this->strategy)) {
			throw new InvalidArgumentException("Missing strategy for step ".get_class($this));
		}
	}

	/**
	 * Get all local objects
	 *
	 * @return DataList
	 */
	protected function getLocalObjects() {
		return DataObject::get($this->targetClass);
	}

	/**
	 * Get all unmatched local objects
	 *
	 * @return DataList
	 */
	protected function getUnmatchedLocalObjects() {
		return $this
			->getLocalObjects()
			->filter('LegacyID', 0);
	}

	/**
	 * Get all matched local objects
	 *
	 * @return DataList
	 */
	protected function getMatchedLocalObjects() {
		return $this->getLocalObjects()
			->exclude('LegacyID', 0);
	}

	/**
	 * Get all remote objects which have been created or edited since last import
	 *
	 * @return ArrayList
	 */
	protected function getNewOrChangedRemoteObjects() {
		$query = $this->getRemoteObjectsQuery();
		$query->addWhereAny(array(
			'"_ImportedID" = 0',
			'"_ImportedDate" < "LastEdited"'
		));
		$items = iterator_to_array($this->task->query($query));
		return new ArrayList($items);
	}

	/**
	 * Get all remote objects which have been edited since last import
	 *
	 * @return ArrayList
	 */
	protected function getChangedRemoteObjects() {
		$query = $this->getRemoteObjectsQuery();
		$query->addWhere('"_ImportedID" > 0');
		$query->addWhere('"_ImportedDate" < "LastEdited"');
		$items = iterator_to_array($this->task->query($query));
		return new ArrayList($items);
	}

	/**
	 * Select all remote objects yet unmatched
	 *
	 * @return ArrayList
	 */
	protected function getUnmatchedRemoteObjects() {
		$query = $this->getRemoteObjectsQuery();
		$query->addWhere('"_ImportedID" = 0');
		$items = iterator_to_array($this->task->query($query));
		return new ArrayList($items);
	}

	/**
	 * Select all remote objects which have local matches
	 *
	 * @return ArrayList
	 */
	protected function getMatchedRemoteObjects() {
		$query = $this->getRemoteObjectsQuery();
		$query->addWhere('"_ImportedID" > 0');
		$items = iterator_to_array($this->task->query($query));
		return new ArrayList($items);
	}

	/**
	 * Get list of remote tables for the target dataobject
	 *
	 * @return array
	 */
	protected function getRemoteClassHierarchy() {
		return ClassInfo::ancestry($this->targetClass, true);
	}

	/**
	 * Get the base class for the target dataobject
	 *
	 * @return type
	 */
	protected function getRemoteBaseTable() {
		return ClassInfo::baseDataClass($this->targetClass);
	}

	/**
	 * @return SQLQuery
	 */
	protected function getRemoteObjectsQuery() {
		// Get all tables to query
		$tables = $this->getRemoteClassHierarchy();
		$baseClass = array_shift($tables);

		// Generate sql query
		$query = new SQLQuery('*', "\"{$baseClass}\"", $this->targetWhere);
		$query->setOrderBy("\"{$baseClass}\".\"ID\" ASC"); // Sort by ID for some performance reasons
		foreach($tables as $class) {
			$query->addLeftJoin($class, "\"{$baseClass}\".\"ID\" = \"{$class}\".\"ID\"");
		}
		return $query;
	}

	/**
	 * Select all remote objects given the query parameters sorted by ID
	 *
	 * @return ArrayList
	 */
	protected function getRemoteObjects() {
		$query = $this->getRemoteObjectsQuery();
		$items = iterator_to_array($this->task->query($query));
		return new ArrayList($items);
	}

	/**
	 * Describes this task
	 *
	 * return @string
	 */
	public function describe() {
		$desc = get_class($this);
		if($this->targetClass) $desc .= ' for ' . $this->targetClass;
		if($this->strategy) {
			$strategy = is_array($this->strategy) ? implode('/', $this->strategy) : $this->strategy;
			$desc .= " with strategy {$strategy}";
		}
		return $desc;
	}

	/**
	 * Query and map all remote objects to local ones
	 */
	public function identifyPass() {
		// Check extensions
		if(!Object::has_extension($this->targetClass, 'LegacyDataObject')) {
			throw new Exception($this->targetClass . " does not have the LegacyDataObject extension");
		}

		// Update remote table to include _ImportedID column
		$this->setupRemoteTable();

		// If we are doing a basic add then there's no identification or merging necessary
		if(!$this->canIdentify()) {
			$this->task->message(' * Skipping identification for add only strategy');
			return;
		}

		// Check for configuration errors
		if(empty($this->idColumns)) {
			throw new Exception("Cannot perform Identify step without idcolumns property on task");
		}

		// Check before status
		$beforeUnmatched = $this->getUnmatchedRemoteObjects()->count();
		if($beforeUnmatched === 0) {
			$this->task->message(" * All remote objects identified, skipping");
			return;
		}

		// Query all local objects which have NOT been matched
		$localObjects = $this->getUnmatchedLocalObjects();
		$localCount = $localObjects->count();
		$this->task->message(" * Checking {$localCount} unmatched local against {$beforeUnmatched} remote objects");

		// Search all items of the given target class
		$checked = 0;
		foreach($localObjects as $localObject) {
			// Find remote object matching this local one
			$remoteData = $this->findRemoteObject($localObject->toMap());
			if($remoteData) {
				// Given the newly matched item save it
				$localObject->LegacyID = $remoteData->ID;
				$localObject->write();

				// Match remote object to this, but not _ImportedDate because it may need to
				// have it's data migrated across
				$conn = $this->task->getRemoteConnection();
				$baseTable = $this->getRemoteBaseTable();
				$conn->query(sprintf(
					'UPDATE "%s" SET "_ImportedID" = %d WHERE "ID" = %d',
					$baseTable,
					intval($localObject->ID),
					intval($remoteData->ID)
				));
			}
			
			// Show progress indicator
			$this->task->progress(++$checked, $localCount);
		}

		// Check real progress (reduce number of unmatched remote object)
		$afterUnmatched = $this->getUnmatchedRemoteObjects()->count();
		$this->task->message(" * Result: {$beforeUnmatched} unmatched objects reduced to {$afterUnmatched}");
	}

	/**
	 * Check if a given strategy is allowed
	 *
	 * @param string $strategy strategy name
	 * @return bool
	 */
	protected function hasStrategy($strategy) {
		if(is_array($this->strategy)) {
			return in_array($strategy, $this->strategy);
		} else {
			return $this->strategy === $strategy;
		}
	}

	/**
	 * Determine if the strategy allows objects to be added
	 *
	 * @return bool
	 */
	protected function canAdd() {
		return $this->hasStrategy(self::STRATEGY_ADD);
	}

	/**
	 * Determine if the strategy allows objects to be updated
	 *
	 * @return bool
	 */
	protected function canUpdate() {
		return $this->hasStrategy(self::STRATEGY_UPDATE);
	}

	/**
	 * Determine if the strategy allows objects to be identified
	 *
	 * @return bool
	 */
	protected function canIdentify() {
		return $this->hasStrategy(self::STRATEGY_IDENTIFY);
	}

	/**
	 * Determine list of remote objects to import, based on current strategy
	 *
	 * @return ArrayList
	 */
	protected function getImportableObjects() {
		// Optimise list of items to import
		if($this->canUpdate() && $this->canAdd()) {
			// Add or Update operations should look at all objects
			$remoteObjects = $this->getNewOrChangedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} new or changed records");
			return $remoteObjects;

		} elseif($this->canUpdate()) {
			// If only updating then only limit to matched records
			$remoteObjects = $this->getChangedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} changed records");
			return $remoteObjects;

		} elseif($this->canAdd()) {
			// If update isn't allowed we don't need to bother with those records
			$remoteObjects = $this->getUnmatchedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} new records");
			return $remoteObjects;

		} else {
			$this->task->message(' * Skipping import for identify only strategy');
			return null;
		}
	}

	/**
	 * Query and create all remote objects, making sure to set all has_one fields to 0
	 */
	public function importPass() {
		// Prepare list of objects for import
		$remoteObjects = $this->getImportableObjects();
		if($remoteObjects === null) return;
		
		// Add all objects
		$updated = 0;
		$added = 0;
		$total = 0;
		$remoteCount = $remoteObjects->count();
		foreach($remoteObjects as $remoteObject) {
			// Show progress indicator
			$this->task->progress(++$total, $remoteCount);

			// If allowed, detect updateable objects
			$localObject = null;
			if($this->canUpdate() && ($localObject = $this->findMatchedLocalObject($remoteObject->ID))) {
				// Update existing object
				$this->updateLocalObject($localObject, $remoteObject);
				++$updated;
			}

			// If allowed, create a new object
			// If this step has an add only strategy, then this step relies on getImportableObjects
			// to filter out objects that have already been added.
			if(empty($localObject) && $this->canAdd()) {
				// Make a new object
				$this->createLocalFromRemoteObject($remoteObject);
				++$added;
			}
		}
		// Done!
		$this->task->message(" * Result: {$added} added, {$updated} updated");
	}

	/**
	 * Run over all imported objects and link them to their respective associative objects
	 */
	public function linkPass() {
	}

	/**
	 * Retrieves any previously identified local object based on remote ID
	 *
	 * @param int $legacyID ID of remote object to match against
	 * @return DataObject The matched local dataobject if it can be found
	 */
	protected function findMatchedLocalObject($legacyID) {
		return $this
			->getLocalObjects()
			->filter('LegacyID', $legacyID)
			->first();
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
			$value = isset($data[$column]) ? $data[$column] : null;
			$query = $query->filter($column, $value);
		}
		return $query->first();
	}

	/**
	 * Finds remote dataobject which matches the given $data
	 *
	 * @param array $data array of data to query against
	 * @return ArrayData Data for remote object if found
	 */
	protected function findRemoteObject($data) {
		$items = $this->getRemoteObjects();
		foreach($this->idColumns as $column) {
			$value = isset($data[$column]) ? $data[$column] : null;
			$items = $items->filter($column, $value);
		}
		if($result = $items->first()) return new ArrayData($result);
	}

	/**
	 * Cache of mapped relations
	 *
	 * @array
	 */
	protected $mappedRelations = array();

	/**
	 * Find the RemoteID for a specific has_one
	 *
	 * @param string $field Field name (excluding ID)
	 * @param string $legacyID ID on remote db
	 * @return mixed Local ID that matches the $legacyID for the given has_one field, null if not findable,
	 * or 0 if not found
	 */
	protected function findMappedRelation($field, $legacyID) {
		// Return cached result
		if(isset($this->mappedRelations[$field][$legacyID])) {
			return $this->mappedRelations[$field][$legacyID];
		}

		// Skip if no relation
		$singleton = singleton($this->targetClass);
		$relationClass = $singleton->has_one($field);
		if(empty($relationClass)) return null;

		// Skip if relation class isn't an imported class
		$relationSingleton = singleton($relationClass);
		if(!$relationSingleton->db('LegacyID')) return null;

		// Find related object with this legacyID
		$localObject = $relationClass::get()->filter('LegacyID', $legacyID)->first();
		$localID = $localObject ? $localObject->ID : 0;

		// Save result, even if it was a failed match
		if(!isset($this->mappedRelations[$field])) {
			$this->mappedRelations[$field] = array();
		}
		$this->mappedRelations[$field][$legacyID] = $localID;
		return $localID;
	}

	/**
	 * Copy all non-relation fields from the remote object to the local object
	 *
	 * @param DataObject $localObject
	 * @param ArrayData $remoteObject
	 */
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
		
		// Let helpers update each object
		foreach($this->helpers as $helper) {
			$helper->updateLocalObject($localObject, $remoteObject);
		}

		// Save mapping ID
		// Note: If using a non-identifying strategy (e.g. Add) then this step is important
		// to ensure that this object is not re-added in subsequent imports
		$localObject->LegacyID = $remoteObject->ID;
		$localObject->write();

		// Save data to remote object
		$conn = $this->task->getRemoteConnection();
		$baseTable = $this->getRemoteBaseTable();
		$conn->query(sprintf(
			'UPDATE "%s" SET "_ImportedID" = %d, "_ImportedDate" = NOW() WHERE "ID" = %d',
			$baseTable,
			intval($localObject->ID),
			intval($remoteObject->ID)
		));
	}

	/**
	 * Create a local dataobject for import from an external dataset
	 *
	 * @param ArrayData $remoteObject Data from remote object
	 * @return DataObject New dataobject created from $remoteObject
	 */
	protected function createLocalFromRemoteObject(ArrayData $remoteObject) {
		// Allow data to override class
		$class = ($remoteObject->ClassName && is_a($remoteObject->ClassName, $this->targetClass, true))
			? $remoteObject->ClassName
			: $this->targetClass;

		// Populate
		$localObject = $class::create();
		$this->updateLocalObject($localObject, $remoteObject);
		return $localObject;
	}

	/**
	 * Clear temporary data
	 */
	public function flush() {
		$this->mappedRelations = array();
		$this->remoteObjects = null;
		gc_collect_cycles();
	}
}
