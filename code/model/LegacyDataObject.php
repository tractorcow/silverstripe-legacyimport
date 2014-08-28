<?php

/**
 * @property string $ObjectClass Class name
 * @property int $LocalID Local Id of the dataobject
 * @property int $RemoteID Remote ID of the dataobject
 * saved locally and all relations have been attached.
 */
class LegacyDataObject extends DataObject {
	
	private static $db = array(
		'ObjectClass' => 'Varchar(255)',
		'LocalID' => 'Int',
		'RemoteID' => 'Int'
	);

	private static $has_one = array(
		'LegacyDataObject' => 'LegacyDataObject'
	);
}
