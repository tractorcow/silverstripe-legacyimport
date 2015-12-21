<?php

/**
 * Imports a single many_many relation
 */
class RelationImporter extends LegacyImporter
{

    protected $tableName;

    protected $fields;
    
    /**
     * Create a dataobject importer
     *
     * @param LegacyImportTask $task Parent task
     * @param array $parameters Parameter input
     * @param array $helpers List of helper classes
     * @throws InvalidArgumentException
     */
    public function __construct(LegacyImportTask $task, $parameters, $helpers = array())
    {
        parent::__construct($task, $parameters, $helpers);
        $this->tableName = $parameters['table'];
        $this->fields = $parameters['fields'];
    }
    
    public function identifyPass()
    {
        // Check extensions on each side of this relation
        foreach ($this->fields as $field => $class) {
            if (!Object::has_extension($class, 'LegacyDataObject')) {
                throw new Exception($class . " does not have the LegacyDataObject extension");
            }
        }

        // Update remote table to include _ImportedID column
        $this->setupLocalTable();
        $this->setupRemoteTable();
    }

    /**
     * Ensure the LegacyID column exists on the local table
     */
    protected function setupLocalTable()
    {
        $this->task->ensureTableHasColumn(
            DB::getConn(),
            $this->tableName,
            'LegacyID',
            'int(11) not null default 0'
        );
    }

    protected function getRemoteObjectsQuery()
    {
        // Do something really lazy here; Join on all tables to do the mapping really sneakily
        $query = new SQLQuery('"'.$this->tableName.'"."ID"');
        $query->setFrom('"'.$this->tableName.'"');

        // relations are add-only, so just get unimported relations
        $query->setWhere('"' . $this->tableName . '"."_ImportedID" = 0');
        foreach ($this->fields as $field => $class) {
            // Join table
            $query->addInnerJoin(
                $class,
                "\"{$class}\".\"ID\" = \"{$this->tableName}\".\"{$field}\""
            );
            // Remove unmapped related tables
            $query->addWhere("\"{$class}\".\"_ImportedID\" > 0");

            // Substitute imported ID from related class for that ID
            $query->selectField("\"{$class}\".\"_ImportedID\"", $field);
        }

        return $query;
    }

    public function importPass()
    {
        $query = $this->getRemoteObjectsQuery();
        $items = $this->task->query($query);
        $itemsCount = $items->numRecords();

        $this->task->message(" * Found {$itemsCount} items to import");

        $total = 0;
        $updated = 0;
        $inserted = 0;
        foreach ($items as $item) {
            $this->task->progress(++$total, $itemsCount);

            // Build select query for existing object
            $values = array();
            $select = new SQLQuery("ID", "\"{$this->tableName}\"");
            foreach ($this->fields as $field => $class) {
                $value = intval($item[$field]);
                $values[] = $value;
                $select->addWhere(sprintf("\"$field\" = %d", $value));
            }
            $values[] = $item['ID'];

            // Check for existing record
            if ($localID = DB::query($select->sql())->value()) {
                // Update local many_many record instead of making new row
                DB::query(sprintf(
                    'UPDATE "%s" SET "LegacyID" = %d WHERE "ID" = %d',
                    $this->tableName,
                    $item['ID'],
                    $localID
                ));
                $updated++;
            } else {
                // Insert mapping into the many_many table
                $insert  = "INSERT INTO \"{$this->tableName}\" (";
                $insert .= '"'.implode('", "', array_keys($this->fields)).'"';
                $insert .= ', LegacyID';
                $insert .= ') VALUES (';
                $insert .= implode(', ', $values);
                $insert .= ')';
                DB::query($insert);
                $localID = DB::getGeneratedID($this->tableName);
                $inserted++;
            }

            // Mark this relation as imported in the remote table
            $conn = $this->task->getRemoteConnection();
            $conn->query(sprintf(
                'UPDATE "%s" SET "_ImportedID" = %d, "_ImportedDate" = NOW() WHERE "ID" = %d',
                $this->tableName,
                $localID,
                $item['ID']
            ));
        }

        // Done!
        $this->task->message(" * Result: {$inserted} added, {$updated} updated");
    }

    public function linkPass()
    {
    }

    protected function getRemoteBaseTable()
    {
        return $this->tableName;
    }

    /**
     * Describes this task
     *
     * return @string
     */
    public function describe()
    {
        return get_class($this) . ' for ' . $this->tableName;
    }

    public function flush()
    {
    }
}
