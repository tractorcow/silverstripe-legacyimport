<?php

/**
 * Does a basic read-write from one table to the other, deleting all prior records
 *
 * Warning, do not use on SiteTree unless you really know what you're doing!
 *
 * Does NOT do any relationID mapping
 */
class TruncateImporter extends DataObjectImporter
{

    /**
     * Truncate only has a single strategy
     */
    const STRATEGY_TRUNCATE = 'Truncate';

    public function __construct(\LegacyImportTask $task, $parameters, $helpers = array())
    {
        $this->strategy = self::STRATEGY_TRUNCATE;
        parent::__construct($task, $parameters, $helpers);
    }

    public function identifyPass()
    {
        // Message
        $this->task->message("Skipping identify for TruncateImporter");
    }

    public function importPass()
    {
        if (ImportHelper::is_a($this->targetClass, 'SiteTree')) {
            throw new InvalidArgumentException("Don't run TruncateImporter on a SiteTree class");
        }
        
        // Check extensions
        if (!Object::has_extension($this->targetClass, 'LegacyDataObject')) {
            throw new Exception($this->targetClass . " does not have the LegacyDataObject extension");
        }

        // Update remote table to include _ImportedID column
        $this->setupRemoteTable();

        // Delete all existing records
        $existingRecords = DataObject::get($this->targetClass);
        $existingCount = $existingRecords->count();

        // Get other records
        $query = $this->getRemoteObjectsQuery();
        $remoteObjects = $this->task->query($query);
        $remoteCount = $remoteObjects->numRecords();

        // Start
        $this->task->message(" * Replacing {$existingCount} records with {$remoteCount} ones");

        // Truncate all tables
        $tables = ClassInfo::dataClassesFor($this->targetClass);
        foreach ($tables as $table) {
            DB::query('TRUNCATE "'.$table.'"');
        }

        $this->task->message(" * ".count($tables). " tables truncated");

        // Add all objects
        $total = 0;
        foreach ($remoteObjects as $remoteObject) {
            // Show progress indicator
            $this->task->progress(++$total, $remoteCount);

            // Make new object
            $class = (
                    isset($remoteObject['ClassName'])
                    && ImportHelper::is_a($remoteObject['ClassName'], $this->targetClass)
                )
                    ? $remoteObject['ClassName']
                    : $this->targetClass;

            // Direct copy data into the new object
            $localObject = $class::create();
            foreach ($remoteObject as $field => $value) {
                $localObject->$field = $value;
            }
            $localObject->LegacyID = $remoteObject['ID'];
            $localObject->write(false, true);
        }

        // Bulk update remote table
        $conn = $this->task->getRemoteConnection();
        $baseTable = $this->getRemoteBaseTable();
        $conn->query('UPDATE "' . $baseTable . '" SET "_ImportedID" = "ID", "_ImportedDate" = NOW()');

        // Done!
        $this->task->message(" * Result: {$total} added");
    }
}
