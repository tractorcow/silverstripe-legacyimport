<?php

class AssetImporter extends DataObjectImporter {
	
	public function __construct(LegacyImportTask $task, $parameters) {
		$this->targetClass = 'File';
		$this->idColumns = array(
			'Filename'
		);
		parent::__construct($task, $parameters);
	}
}