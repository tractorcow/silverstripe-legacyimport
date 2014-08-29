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
		$baseClass = ClassInfo::baseDataClass($this->targetClass);
		return $this->getLocalObjects()
			->leftJoin(
				'LegacyDataObject',
				"\"{$baseClass}\".\"ID\" = \"LegacyDataObject\".\"LocalID\" AND
				 \"LegacyDataObject\".\"ObjectClass\" = '".Convert::raw2sql($baseClass)."'"
			)
			->where("\"LegacyDataObject\".\"ID\" IS NULL");
	}

	/**
	 * Get all matched local objects
	 *
	 * @return DataList
	 */
	protected function getMatchedLocalObjects() {
		$baseClass = ClassInfo::baseDataClass($this->targetClass);
		return $this->getLocalObjects()
			->innerJoin(
				'LegacyDataObject',
				"\"{$baseClass}\".\"ID\" = \"LegacyDataObject\".\"LocalID\" AND
				 \"LegacyDataObject\".\"ObjectClass\" = '".Convert::raw2sql($baseClass)."'"
			);
	}

	/**
	 * Select all remote objects yet unmatched
	 *
	 * @return ArrayList
	 */
	protected function getUnmatchedRemoteObjects() {
		$objects = $this->getRemoteObjects();
		$matchedIDs = $this->findMatchings()->column('RemoteID');
		if(empty($matchedIDs)) return $objects;
		return $objects->exclude('ID', $matchedIDs);
	}

	/**
	 * Select all remote objects which have local matches
	 *
	 * @return ArrayList
	 */
	protected function getMatchedRemoteObjects() {
		$matchedIDs = $this->findMatchings()->column('RemoteID');
		if(empty($matchedIDs)) return ArrayList::create();
		return $this
			->getRemoteObjects()
			->filter('ID', $matchedIDs);
	}

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
				$remoteID = $remoteData['ID'];
				$this->addMatching($localObject->ID, $remoteID);
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
	 * Query and create all remote objects, making sure to set all has_one fields to 0
	 */
	public function importPass() {
		// Optimise list of items to import
		if($this->canUpdate() && $this->canAdd()) {
			// Add or Update operations should look at all objects
			$remoteObjects = $this->getRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} remote records");

		} elseif($this->canUpdate()) {
			// If only updating then only limit to matched records
			$remoteObjects = $this->getMatchedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} remote records (limited to identified records)");
			
		} elseif($this->canAdd()) {
			// If update isn't allowed we don't need to bother with those records
			$remoteObjects = $this->getUnmatchedRemoteObjects();
			$remoteCount = $remoteObjects->count();
			$this->task->message(" * Importing {$remoteCount} remote records (limited to new records)");
			
		} else {
			$this->task->message(' * Skipping import for identify only strategy');
			return;
		}

		// Add all objects
		$updated = 0;
		$added = 0;
		$total = 0;
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
		$object->ObjectClass = $baseClass;
		$object->RemoteID = $remoteID;
		$object->LocalID = $localID;
		$object->write();
	}

	/**
	 * Get all matchings for the current class
	 *
	 * @return DataList
	 */
	protected function findMatchings() {
		$baseClass = ClassInfo::baseDataClass($this->targetClass);
		return LegacyDataObject::get()
			->filter(array(
				'ObjectClass' => $baseClass
			));
	}

	/**
	 * Find any previously matching for a given remote object
	 *
	 * @param int $remoteID
	 * @return LegacyDataObject Matching data for the given remote ID
	 */
	protected function findMatchingByRemote($remoteID) {
		return $this
			->findMatchings()
			->filter('RemoteID', $remoteID)
			->first();
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
	 * Retrieves any previously identified local object based on remote ID
	 *
	 * @param int $remoteID ID of remote object to match against
	 * @return DataObject The matched local dataobject if it can be found
	 */
	protected function findMatchedLocalObject($remoteID) {
		$matching = $this->findMatchingByRemote($remoteID);
		if($matching) {
			return $this->getLocalObjects()->byID($matching->LocalID);
		}
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
	 * @return array Data for remote object if found
	 */
	protected function findRemoteObject($data) {
		$items = $this->getRemoteObjects();
		foreach($this->idColumns as $column) {
			$value = isset($data[$column]) ? $data[$column] : null;
			$items = $items->filter($column, $value);
		}
		return $items->first();
	}

	/**
	 * Copy all non-relation fields from the remote object to the local object
	 *
	 * @param DataObject $localObject
	 * @param ArrayData $remoteObject
	 */
	protected function updateLocalObject(DataObject $localObject, ArrayData $remoteObject) {
		foreach($remoteObject->toMap() as $field => $value) {
			// Skip all relations, since ID or foreign IDs on the other server don't make sense here
			if(preg_match('/.*ID$/', $field)) continue;
			// Don't change class
			if($field === 'ClassName') continue;
			
			$localObject->$field = $value;
		}
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
		$localObject = $class::create();

		// Populate
		$this->updateLocalObject($localObject, $remoteObject);

		// Immediately save identification
		// Note: If using a non-identifying strategy (e.g. Add) then this step is important
		// to ensure that this object is not re-added in subsequent imports
		$this->addMatching($localObject->ID, $remoteObject->ID);
		return $localObject;
	}
}
