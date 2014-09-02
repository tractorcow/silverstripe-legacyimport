<?php

class CommentImporter extends DataObjectImporter {

	public function __construct(LegacyImportTask $task, $parameters, $helpers = array()) {
		$this->targetClass = 'Comment';
		parent::__construct($task, $parameters, $helpers);
	}
}
