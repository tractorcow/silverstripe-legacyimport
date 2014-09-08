<?php

class LegacyImportTask extends ImportTask {

	protected $title = 'Import a legacy 2.x site';

	protected $description = 'Import assets, pages, and dataobjects from 2.4';
	
	/**
	 * @param SS_HTTPRequest $request
	 * @return SS_HTTPResponse
	 */
	public function run($request) {
		// Disable filters
		if(class_exists('ContentNotifierExtension')) ContentNotifierExtension::disable_filtering();
		if(class_exists('Post')) Config::inst()->update('Post', 'allow_reading_spam', true);

		// Init tasks
		$taskGroup = $request->getVar('tasks') ?: 'tasks';
		$this->message("Beginning import tasks {$taskGroup}");
		
		$this->connectToRemoteSite();
		Versioned::reading_stage('Stage');

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
			$helper = $helper['helper']::create($this, $helper);
			$helper->init();
			$helpers[] = $helper;
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
