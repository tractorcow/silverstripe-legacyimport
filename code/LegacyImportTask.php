<?php

class LegacyImportTask extends BuildTask {

	/**
	 * DB identifier for the remote database
	 *
	 * @var string
	 * @config
	 */
	private static $remote_database = 'remote';

	protected $title = 'Import a legacy 2.x site';

	protected $description = 'Import assets, pages, and dataobjects from 2.4';
	
	/**
	 * @var bool
	 */
	protected $quiet = false;

	/**
	 * Make this task quiet
	 */
	public function beQuiet() {
		$this->quiet = true;
	}
	
	public function progress($num, $total) {
		// Try not to show more than about 1000 dots
		$skip = round($total / 1000)+1;
		if($num % $skip) return;
		
		// Echo result
		echo chr(round(25 * $num / $total) + ord('A'));
		if($num >= $total) {
			echo Director::is_cli() ? "\n" : "<br />";
		}
	}

	/**
	 * Output a message
	 *
	 * @param string $message
	 */
	public function message($message) {
		if($this->quiet) return;
		Debug::message(date('Y-m-d H:i:s').': '.$message, false);
	}

	/**
	 * @param SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function run($request) {
		$this->message('Beginning import');

		$this->connectToRemoteSite();

		// Check if we only want to do a single step
		if($pass = $request->requestVar('pass')) {
			$this->message("Resuming at {$pass} pass");
			switch($pass) {
				case 'identify':
					$this->identifyPass();
					return;
				case 'import':
					$this->importPass();
					return;
				case 'link':
					$this->linkPass();
					return;
			}
		}

		$this->identifyPass();
		$this->importPass();
		$this->linkPass();
	}

	/**
	 * List of tasks
	 *
	 * @var array
	 */
	protected $tasks = null;

	/**
	 * Get the list of all defined tasks
	 *
	 * @return array
	 */
	public function tasks() {
		if($this->tasks) return $this->tasks;
		$taskConfig = static::config()->tasks;
		$this->tasks = array();
		foreach($taskConfig as $config) {
			$this->tasks[] = $config['importer']::create($this, $config);
		}
		return $this->tasks;
	}

	/**
	 * Generate DB connection to remote site
	 */
	protected function connectToRemoteSite() {
		$this->message('');
		$this->message('== Connecting to remote DB ==');
		global $remoteDatabaseConfig;
		DB::connect($remoteDatabaseConfig, self::config()->remote_database);
	}

	/**
	 * Run a remote query against the remote DB
	 *
	 * @param SQLQuery $query
	 */
	public function query(SQLQuery $query) {
		return DB::query($query->sql(), self::config()->remote_database);
	}

	/**
	 * Generate mapping of remote object ids to local object ids
	 */
	protected function identifyPass() {
		$this->message('');
		$this->message('== Identifying all objects ==');
		foreach($this->tasks() as $task) {
			$this->message('Identifying ' . $task->describe());
			$task->identifyPass();
		}
	}

	/**
	 * Run through the import strategy, importing all objects available
	 */
	protected function importPass() {
		$this->message('');
		$this->message('== Importing all objects ==');
		foreach($this->tasks() as $task) {
			$this->message('Importing ' . $task->describe());
			$task->importPass();
		}
	}

	/**
	 * Link all saved objects to any relations
	 */
	protected function linkPass() {
		$this->message('');
		$this->message('== Linking relations for all imported objects ==');
		foreach($this->tasks() as $task) {
			$this->message('Updating links for ' . $task->describe());
			$task->linkPass();
		}
	}

}
