<?php


class DataObjectImporter extends Object {

	/**
	 * Just add new items regardless of identification
	 */
	const STRATEGY_ADD = 'Add';

	/**
	 * Add any objects which don't exist, but identify matching objects without updating them
	 */
	const STRATEGY_ADD_OR_IDENTIFY = 'AddOrIdentify';

	/**
	 * Update items but don't add new ones.
	 * Implies Identify
	 */
	const STRATEGY_UPDATE = 'Update';

	/**
	 * If an object exists in both, update. Otherwise add it.
	 * Implies Identify
	 */
	const STRATEGY_ADD_OR_UPDATE = 'AddOrUpdate';

	/**
	 * Only identify matching records. Do not match or update any.
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
	 * @var string
	 */
	protected $strategy;

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
	 * Cache of queried remote objects
	 *
	 * @var ArrayList
	 */
	protected $remoteObjects = null;

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
		return $this->getLocalObjects()
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
	 * Select all remote objects yet unmatched
	 *
	 * @return ArrayList
	 */
	protected function getUnmatchedRemoteObjects() {
		$remoteObjects = $this->getRemoteObjects();
		$legacyIDs = $this
			->getMatchedLocalObjects()
			->sort('"LegacyID" ASC')
			->column('LegacyID');
		if(empty($legacyIDs)) return $remoteObjects;

		// Odd performance optimisation begins here

		// Slightly more optimised array search, based on assumption
		// that both $matchedIDs and $objects are sorted by ID
		// Should average to O(n) performance (size of remote objects + size of matches)
		$matched = array();
		foreach($remoteObjects as $remoteObject) {
			$id = $remoteObject->ID;
			// Pop items from start of matches until we find one that might be the current object
			while($legacyIDs && (reset($legacyIDs) < $id)) {
				array_shift($legacyIDs);
			}
			if($legacyIDs && reset($legacyIDs) == $id) continue;
			$matched[] = $remoteObject;
		}
		return new ArrayList($matched);
	}

	/**
	 * Select all remote objects which have local matches
	 *
	 * @return ArrayList
	 */
	protected function getMatchedRemoteObjects() {
		$legacyIDs = $this
			->getMatchedLocalObjects()
			->sort('"LegacyID" ASC')
			->column('LegacyID');
		if(empty($legacyIDs)) return ArrayList::create();

		// Odd performance optimisation begins here

		// Slightly more optimised array search, based on assumption
		// that both $matchedIDs and $objects are sorted by ID
		// Should average to O(n) performance (size of remote objects + size of matches)
		$remoteObjects = $this->getRemoteObjects();
		$matched = array();
		foreach($remoteObjects as $remoteObject) {
			$id = $remoteObject->ID;
			// Pop items from start of matches until we find one that might be the current object
			while($legacyIDs && (reset($legacyIDs) < $id)) {
				array_shift($legacyIDs);
			}
			if($legacyIDs && reset($legacyIDs) == $id) $matched[] = $remoteObject;
		}
		return new ArrayList($matched);
	}

	/**
	 * Select all remote objects given the query parameters sorted by ID
	 *
	 * @return ArrayList
	 */
	protected function getRemoteObjects() {
		if($this->remoteObjects) return $this->remoteObjects;

		// Get all tables to query
		$tables = ClassInfo::ancestry($this->targetClass, true);
		$baseClass = array_shift($tables);

		// Generate sql query
		$query = new SQLQuery('*', "\"{$baseClass}\"", $this->targetWhere);
		$query->setOrderBy("\"{$baseClass}\".\"ID\" ASC"); // Sort by ID for some performance reasons
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
	 */
	public function identifyPass() {
		// If we are doing a basic add then there's no identification or merging necessary
		if(!$this->canIdentify()) {
			$this->task->message(' * Skipping identification for add only strategy');
			return;
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
			}
			
			// Show progress indicator
			$this->task->progress(++$checked, $localCount);
		}

		// Check real progress (reduce number of unmatched remote object)
		$afterUnmatched = $this->getUnmatchedRemoteObjects()->count();
		$this->task->message(" * Result: {$beforeUnmatched} unmatched objects reduced to {$afterUnmatched}");
	}

	/**
	 * Determine if the strategy allows objects to be added
	 *
	 * @return bool
	 */
	protected function canAdd() {
		switch($this->strategy) {
			case self::STRATEGY_IDENTIFY:
			case self::STRATEGY_UPDATE:
				return false;
			default:
				return true;
		}
	}

	/**
	 * Determine if the strategy allows objects to be updated
	 *
	 * @return bool
	 */
	protected function canUpdate() {
		switch($this->strategy) {
			case self::STRATEGY_IDENTIFY:
			case self::STRATEGY_ADD:
			case self::STRATEGY_ADD_OR_IDENTIFY:
				return false;
			default:
				return true;
		}
	}

	/**
	 * Determine if the strategy allows objects to be identified
	 *
	 * @return bool
	 */
	protected function canIdentify() {
		return $this->strategy !== self::STRATEGY_ADD;
	}

	/**
	 * Determine list of remote objects to import, based on current strategy
	 */
	protected function getImportableObjects() {
		// Optimise list of items to import
		if($this->canUpdate() && $this->canAdd()) {
			// Add or Update operations should look at all objects
			$remoteObjects = $this->getRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} remote records");
			return $remoteObjects;

		} elseif($this->canUpdate()) {
			// If only updating then only limit to matched records
			$remoteObjects = $this->getMatchedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} remote records (limited to identified records)");
			return $remoteObjects;

		} elseif($this->canAdd()) {
			// If update isn't allowed we don't need to bother with those records
			$remoteObjects = $this->getUnmatchedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} remote records (limited to new records)");
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
		$this->flushMappedRelations();

		// Todo : link objects which may not have been linkable during import
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

	protected $mappedRelations = array();

	protected function flushMappedRelations() {
		$this->mappedRelations = array();
	}

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
				// Try to find local ID that corresponds to this relation
				$localID = $this->findMappedRelation($matches['relation'], $value);
				// Skip empty
				if($localID) $localObject->$field = $localID;
			} else {
				$localObject->$field = $value;
			}
		}
		// Save mapping ID
		// Note: If using a non-identifying strategy (e.g. Add) then this step is important
		// to ensure that this object is not re-added in subsequent imports
		$localObject->LegacyID = $remoteObject->ID;
		$localObject->write();
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
}
