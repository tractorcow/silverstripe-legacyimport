<?php

class ImportTask extends BuildTask
{

    /**
     * DB identifier for the remote database
     *
     * @var string
     * @config
     */
    private static $remote_database = 'remote';
    
    /**
     * @var bool
     */
    protected $quiet = false;

    /**
     * Message verbosity level
     *
     * Defaults to 1
     *
     * Generally 0 = minimal output, 1 = per task / step, 2 = per record, 3 = per field, 4 = per operation
     * -1 means all messages are output
     *
     * @var int
     */
    protected $verbosity = 1;

    /**
     * Make this task quiet
     */
    public function beQuiet()
    {
        $this->quiet = true;
    }

    public function progress($num, $total)
    {
        // Try not to show more than about 1000 dots
        $skip = round($total / 1000)+1;
        if ($num % $skip) {
            return;
        }

        // Echo result
        echo chr(round(25 * $num / $total) + ord('A'));
        if ($num >= $total) {
            echo Director::is_cli() ? "\n" : "<br />";
        }
    }

    /**
     * Output a message
     *
     * @param string $message
     * @param int $verbosity Verbosity required to display this message (1 is default)
     */
    public function message($message, $verbosity = 1)
    {
        if ($this->quiet) {
            return;
        }
        if ($this->verbosity >= 0 && $this->verbosity < $verbosity) {
            return;
        }
        Debug::message(date('Y-m-d H:i:s').': '.$message, false);
    }

    /**
     * Output an error
     *
     * @param string $message
     */
    public function error($message)
    {
        if ($this->quiet) {
            return;
        }

        if (Director::is_cli()) {
            $text = SS_Cli::text(date('Y-m-d H:i:s').': '.$message, 'red')."\n";
            file_put_contents('php://stderr', $text, FILE_APPEND);
        } else {
            $this->message($message);
        }
    }

    /**
     * Generate DB connection to remote site
     */
    protected function connectToRemoteSite()
    {
        $this->message('');
        $this->message('== Connecting to remote DB ==');
        global $remoteDatabaseConfig;
        DB::connect($remoteDatabaseConfig, self::config()->remote_database);
    }

    /**
     * Run a remote query against the remote DB
     *
     * @param SQLQuery $query
     * @return SS_Query
     */
    public function query(SQLQuery $query)
    {
        return $this->getRemoteConnection()
            ->query($query->sql());
    }

    /**
     *
     * @return SS_Database
     */
    public function getRemoteConnection()
    {
        return DB::getConn(self::config()->remote_database);
    }

    public function run($request)
    {
        // Set verbosity
        if ($verbosity = $request->requestVar('verbose')) {
            $this->verbosity = (int)$verbosity;
        }
    }

    /**
     * Ensure that the given table on the given connection has the given field
     *
     * @param SS_Database $conn connection
     * @param string $table Name of table
     * @param string $field Name of field
     * @param string $spec Spec to use if creating this field
     */
    public function ensureTableHasColumn($conn, $table, $field, $spec)
    {
        $fields = $conn->fieldList($table);

        // Make column if not found
        if (!isset($fields[$field])) {
            $this->message(' * Creating '.$field.' on table ' . $table);
            $conn->createField($table, $field, $spec);
        }
    }
}
