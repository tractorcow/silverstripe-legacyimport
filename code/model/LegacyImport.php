<?php

/**
 * Represents a single import run
 */
class LegacyImport extends DataObject {

	private static $has_many = array(
		'Objects' => 'LegacyDataObject'
	);
}
