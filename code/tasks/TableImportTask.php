<?php

class TableImportTask extends ImportTask
{

    protected $title = 'Do bulk imports using mysqldump';

    protected $description =
        'Import tables using mysqldump.
		Use tables=Table1,Table2 to specify tables.
		Use keeprelations=true to not reset has_one to zero, or specify a comma separated list of RelationIDs.';

    protected $tempFile;

    /**
     * Get connection commands from the given DB config
     */
    protected function getConnectionArguments($config)
    {
        $arguments = ' --user='.escapeshellarg($config['username']).' --host='.escapeshellarg($config['server']);
        if (!empty($config['password'])) {
            $arguments .= ' --password='.escapeshellarg($config['password']);
        }
        if (!empty($config['port'])) {
            $arguments .= ' --port='.escapeshellarg($config['port']);
        }
        return $arguments;
    }

    /**
     * Export the selected tables from the remote database to the temporary file
     *
     * @global array $remoteDatabaseConfig
     * @param array $tables
     * @throws Exception
     */
    protected function exportTables($tables)
    {
        // Prepare dump command
        global $remoteDatabaseConfig;
        $command = 'mysqldump'.$this->getConnectionArguments($remoteDatabaseConfig);
        $command .= ' ' . escapeshellarg($remoteDatabaseConfig['database']);
        foreach ($tables as $table) {
            $command .= ' ' . $table;
        }

        // Run command
        $source = popen($command, 'r');
        if ($source === false) {
            throw new Exception("Could not invoke mysqldump");
        }
        $dest = fopen($this->tempFile, 'w');
        if ($dest === false) {
            throw new Exception("Could not open temporary file for writing");
        }
        stream_copy_to_stream($source, $dest);
        fclose($dest);
        fclose($source);
    }

    public function importTables()
    {
        // Prepare import command
        global $databaseConfig;
        $command = 'mysql' . $this->getConnectionArguments($databaseConfig);
        $command .= ' -D ' . escapeshellarg($databaseConfig['database']);
        $command .= ' < ' . escapeshellarg($this->tempFile);

        // Import data
        passthru($command, $error);
        if ($error) {
            throw new Exception("Error running mysql import");
        }
    }

    public function run($request)
    {
        parent::run($request);

        $tables = explode(',', $request->getVar('tables'));
        if (empty($tables)) {
            throw new InvalidArgumentException("No 'tables' parameter specified");
        }

        // Check relation we want to keep field
        $keepRelations = $request->getVar('keeprelations');
        switch ($keepRelations) {
            case 'true':
                $keepRelations = true;
                break;
            case 'false':
            case '0':
                $keepRelations = false;
                break;
            default:
                $keepRelations = explode(',', $keepRelations);
        }


        $this->message("== Importing bulk data ==");

        // Create temp file
        $this->message(" * Creating temp file");
        $this->tempFile = tempnam(TEMP_FOLDER, get_class().'_mysqldump'.date('Y-m-d'));

        // Run mysql export on the remote table
        $this->message(" * Exporting tables with mysqldump");
        $this->exportTables($tables);

        // Import data into local table
        $this->message(" * Importing tables into mysql");
        $this->importTables();

        // Rebuild DB
        $this->message("== Rebuilding database ==");
        singleton("DatabaseAdmin")->doBuild(false);

        // Fix relation IDs
        if ($keepRelations === true) {
            $this->message("Keeping relations; Not resetting RelationIDs to zero");
        } else {
            $this->message("Invalidate has_one fields (bypass this with keeprelations=relationid)");
            foreach ($tables as $table) {
                $hasOne = Config::inst()->get($table, 'has_one', Config::UNINHERITED);
                if (empty($hasOne)) {
                    continue;
                }

                foreach ($hasOne as $relation => $class) {
                    $field = $relation."ID";
                    if (is_array($keepRelations) && in_array($field, $keepRelations)) {
                        $this->message(" * Keeping relation {$table}.{$field}");
                    } else {
                        $this->message(" * Resetting relation {$table}.{$field} to zero on migrated table");
                        DB::query('UPDATE "' . $table . '" SET "' . $field . '" = 0');
                    }
                }
            }
        }

        // Mark _ImportedID, _ImportedDate and LegacyID
        $this->connectToRemoteSite();
        $this->message("== Marking records as migrated ==");
        foreach ($tables as $table) {
            // Don't mark non-base tables (subclasses of Member, etc)
            if (ImportHelper::is_a($table, 'DataObject') && $table != ClassInfo::baseDataClass($table)) {
                continue;
            }
            $remoteConn = $this->getRemoteConnection();

            // Setup schema
            $this->ensureTableHasColumn(DB::getConn(), $table, 'LegacyID', 'int(11) not null default 0');
            $this->ensureTableHasColumn($remoteConn, $table, '_ImportedID', 'int(11) not null default 0');
            $this->ensureTableHasColumn($remoteConn, $table, '_ImportedDate', 'datetime');

            // Bulk update
            $this->message(" * Updating {$table}._ImportedID and {$table}._ImportedDate");
            $remoteConn->query('UPDATE "' . $table . '" SET "_ImportedID" = "ID", "_ImportedDate" = NOW()');
            $this->message(" * Updating {$table}.LegacyID");
            DB::query('UPDATE "' . $table . '" SET "LegacyID" = "ID"');
        }

        // Done
        $this->message("Done!");
    }
}
