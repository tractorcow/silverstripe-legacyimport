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
	 * Output an error
	 *
	 * @param string $message
	 */
	public function error($message) {
		if($this->quiet) return;

		if(Director::is_cli()) {
			$text = SS_Cli::text(date('Y-m-d H:i:s').': '.$message, 'red')."\n";
			file_put_contents('php://stderr', $text, FILE_APPEND);
		} else {
			$this->message($message);
		}
	}

	/**
	 * @param SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function run($request) {
		$taskGroup = $request->getVar('tasks') ?: 'tasks';
		$this->message("Beginning import tasks {$taskGroup}");

		$this->connectToRemoteSite();

		// Check if we only want to do a single step
		if($pass = $request->requestVar('pass')) {
			$this->message("Resuming at {$pass} pass");
			switch($pass) {
				case 'identify':
					$this->identifyPass($taskGroup);
					return;
				case 'import':
					$this->importPass($taskGroup);
					return;
				case 'link':
					$this->linkPass($taskGroup);
					return;
			}
		}

		$this->identifyPass($taskGroup);
		$this->importPass($taskGroup);
		$this->linkPass($taskGroup);
	}

	/**
	 * List of task groups
	 *
	 * @var array
	 */
	protected $tasks = array();

	/**
	 * Get the list of all defined tasks
	 *
	 * @param string $taskGroup Name of task group to run
	 * @return array
	 */
	public function tasks($taskGroup) {
		if(isset($this->tasks[$taskGroup])) return $this->tasks[$taskGroup];
		
		// Make all helpers
		$helperConfig = static::config()->helpers;
		$helpers = array();
		if($helperConfig) foreach($helperConfig as $helper) {
			$helpers[] = $helper['helper']::create($this, $helper);
		}

		// Make all tasks
		$taskConfig = static::config()->get($taskGroup);
		$this->tasks[$taskGroup] = array();
		foreach($taskConfig as $config) {
			$this->tasks[$taskGroup][] = $config['importer']::create($this, $config, $helpers);
		}
		return $this->tasks[$taskGroup];
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
	 * @return SS_Query
	 */
	public function query(SQLQuery $query) {
		return $this->getRemoteConnection()
			->query($query->sql());
	}

	/**
	 *
	 * @return SS_Database
	 */
	public function getRemoteConnection() {
		return DB::getConn(self::config()->remote_database);
	}

	/**
	 * Generate mapping of remote object ids to local object ids
	 *
	 * @param string $taskGroup Name of task group to run
	 */
	protected function identifyPass($taskGroup) {
		$this->message('');
		$this->message('== Identifying all objects ==');
		foreach($this->tasks($taskGroup) as $task) {
			$this->message('Identifying ' . $task->describe());
			$task->identifyPass();
			$task->flush();
		}
	}

	/**
	 * Run through the import strategy, importing all objects available
	 *
	 * @param string $taskGroup Name of task group to run
	 */
	protected function importPass($taskGroup) {
		$this->message('');
		$this->message('== Importing all objects ==');
		foreach($this->tasks($taskGroup) as $task) {
			$this->message('Importing ' . $task->describe());
			$task->importPass();
			$task->flush();
		}
	}

	/**
	 * Link all saved objects to any relations
	 *
	 * @param string $taskGroup Name of task group to run
	 */
	protected function linkPass($taskGroup) {
		$this->message('');
		$this->message('== Linking relations for all imported objects ==');
		foreach($this->tasks($taskGroup) as $task) {
			$this->message('Updating links for ' . $task->describe());
			$task->linkPass();
			$task->flush();
		}
	}

}
