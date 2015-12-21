<?php

/**
 * saved locally and all relations have been attached.
 */
class LegacyDataObject extends DataExtension
{
    
    private static $db = array(
        'LegacyID' => 'Int' // ID of this object on the legacy db
    );

    public function updateCMSFields(\FieldList $fields)
    {
        $fields->removeByName('LegacyID', true);
    }
}
