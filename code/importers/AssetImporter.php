<?php

/**
 * Imports assets.
 */
class AssetImporter extends DataObjectImporter {

	const STRATEGY_ONDEMAND = 'OnDemand';
	const STRATEGY_PRELOAD = 'Preload';
	
	public function __construct(LegacyImportTask $task, $parameters) {
		$this->targetClass = 'File';
		$this->idColumns = array(
			'Filename'
		);
		parent::__construct($task, $parameters);
	}

	public function importPass() {
		if($this->strategy === self::STRATEGY_PRELOAD) {
			// Prior to import we must do a file copy from the remote server
			$this->task->message(' * Beginning asset synchronisation');
			passthru(SS_REMOTE_SYNC_COMMAND);
			$this->task->message(" * Asset synchronisation complete");
		} else {
			$this->task->message(' * Skipping import step for ondemand strategy');
		}
	}
}