<?php

class CommentImporter extends DataObjectImporter
{

    public function __construct(LegacyImportTask $task, $parameters, $helpers = array())
    {
        $this->targetClass = 'Comment';
        parent::__construct($task, $parameters, $helpers);
    }

    protected function getRemoteClassHierarchy()
    {
        return array('PageComment' => 'PageComment');
    }

    protected function getRemoteBaseTable()
    {
        return 'PageComment';
    }

    protected function updateLocalObject(DataObject $localObject, ArrayData $remoteObject)
    {
        // Copy fields
        $this->copyToLocalObject($localObject, $remoteObject);

        // Fix mapped tables from old schema to new one
        $parentPage = SiteTree::get()->filter('LegacyID', $remoteObject->ParentID)->first();
        $localObject->Moderated = !$remoteObject->NeedsModeration;
        $localObject->URL = $remoteObject->CommenterURL;
        $localObject->BaseClass = 'SiteTree';
        $localObject->ParentID = $parentPage ? $parentPage->ID : 0;

        // Let helpers update each object
        foreach ($this->helpers as $helper) {
            $helper->updateLocalObject($localObject, $remoteObject);
        }

        // Save mapping ID
        // Note: If using a non-identifying strategy (e.g. Add) then this step is important
        // to ensure that this object is not re-added in subsequent imports
        $this->identifyRecords($localObject, $remoteObject, true);
    }
}
