<?php

class AssetLinker extends LegacyHelper {

	/**
	 * SQL where filter
	 *
	 * @var array
	 */
	protected $targetWhere = array();

	public function __construct(ImportTask $task, $parameters = array()) {
		parent::__construct($task, $parameters);

		if(!File::has_extension('LegacyDataObject')) {
			throw new Exception("File does not have the LegacyDataObject extension");
		}

		if(!empty($parameters['where'])) {
			$this->targetWhere = $parameters['where'];
		}
	}

	public function init() {
		// Setup schema
		$remoteConn = $this->task->getRemoteConnection();
		$this->task->ensureTableHasColumn(DB::getConn(), 'File', 'LegacyID', 'int(11) not null default 0');
		$this->task->ensureTableHasColumn($remoteConn, 'File', '_ImportedID', 'int(11) not null default 0');
		$this->task->ensureTableHasColumn($remoteConn, 'File', '_ImportedDate', 'datetime');
	}

	/**
	 * Get an array of all image urls in an object's HTML fields
	 *
	 * @param DataObject $localObject
	 * @return array
	 */
	protected function getHTMLImageUrls(DataObject $localObject) {
		$imageURLs = array();
		// Extract all html images
		foreach($localObject->db() as $field => $type) {
			if($type !== 'HTMLText') continue;

			// Find all images
			$htmlValue = Injector::inst()->create('HTMLValue', $localObject->$field);
			$images = $htmlValue->getElementsByTagName('img');
			if(empty($images)) continue;

			// Extract urls of each image
			foreach($images as $img) {
				$url = Director::makeRelative($img->getAttribute('src'));
				if(stripos($url, ASSETS_DIR) === 0) $imageURLs[] = $url;
			}

		}
		return $imageURLs;
	}

	/**
	 * Get a query to select remote files
	 *
	 * @return SQLQuery
	 */
	protected function makeQuery() {
		return new SQLQuery('*', '"File"', $this->targetWhere, '"File"."ID" ASC');
	}

	/**
	 * Copy all non-relation fields from the remote object to the local object
	 *
	 * @param File $localObject
	 * @param ArrayData $remoteObject
	 */
	protected function updateLocalFile(File $localObject, ArrayData $remoteObject) {
		foreach($remoteObject->toMap() as $field => $value) {
			// Skip ID and class
			if(in_array($field, array('ClassName', 'ID'))) continue;

			// Skip obsolete fields
			if(preg_match('/^_obsolete.*/', $field)) continue;

			// Don't map relations
			if(preg_match('/(.+)ID$/', $field)) continue;

			$localObject->$field = $value;
		}

		// Save mapping
		$this->mapObjects($localObject, $remoteObject);
	}

	/**
	 * save mapping betwen these objects
	 *
	 * @param File $localObject
	 * @param ArrayData $remoteObject
	 */
	protected function mapObjects(File $localObject, ArrayData $remoteObject) {
		// Save mapping ID
		$localObject->LegacyID = $remoteObject->ID;
		$localObject->write();

		// Save data to remote object
		$conn = $this->task->getRemoteConnection();
		$conn->query(sprintf(
			'UPDATE "File" SET "_ImportedID" = %d, "_ImportedDate" = NOW() WHERE "ID" = %d',
			intval($localObject->ID),
			intval($remoteObject->ID)
		));
	}

	/**
	 * Get the remote url a file can be accessed to
	 *
	 * @param string $path Site relative url
	 * @return string Absolute external url
	 * @throws InvalidArgumentException
	 */
	protected function getRemoteURL($path) {
		if(!defined('SS_REMOTE_SITE')) throw new InvalidArgumentException('SS_REMOTE_SITE not defined');
		return Controller::join_links(SS_REMOTE_SITE, $path);
	}

	/**
	 * Ensure that a given relative path is copied to the local filesystem
	 *
	 * @param string $path Path relative to baseurl
	 * @return bool True if file exists or is copied
	 */
	public function copyRemoteFile($path) {
		// Check if file exists
		$localPath = Director::baseFolder() . '/' . $path;
		if(file_exists($localPath)) return true;

		// Ensure parent directory exists
		$localDir = dirname($localPath);
		Filesystem::makeFolder($localDir);

		// Copy from remote dir
		$remoteURL = $this->getRemoteURL($path);
		$remoteFile = @file_get_contents($remoteURL);
		if($remoteFile === false) {
			$this->task->error(" * error copying {$path}");
			return false;
		}
		
		// Save
		@file_put_contents($localPath, $remoteFile);
		if(file_exists($localPath)) {
			@chmod($localPath, 0664);
			$this->task->message(" * copied file {$path}");
			return true;
		}

		$this->task->message(" * error saving file to {$localPath}");
		return false;
	}

	/**
	 * Find remote file by condition
	 *
	 * @param array $where
	 */
	protected function findRemoteFile($where = array()) {
		$query = $this->makeQuery();
		if($where) $query->addWhere($where);
		$remoteFile = $this->task->query($query)->first();
		if($remoteFile) return new ArrayData($remoteFile);
	}

	/**
	 * Create a local file record for a given url
	 *
	 * @param string $filename Path relative to site root
	 * @return File Created file record
	 */
	protected function makeLocalFile($filename) {
		// Create folder structure
		$parentFolder = Folder::find_or_make(preg_replace('/^assets\\//', '', dirname($filename)));

		// Find remote file with this name
		$remoteFile = $this->findRemoteFile(array(
			'"File"."Filename" LIKE \''.Convert::raw2sql($filename).'\''
		));

		// Create local file from this data
		$className = File::get_class_for_file_extension(pathinfo($filename, PATHINFO_EXTENSION));
		$localFile = $className::create();
		$localFile->ParentID = $parentFolder->ID;
		if($remoteFile) {
			$this->updateLocalFile($localFile, $remoteFile);
		} else {
			$localFile->Name = basename($filename);
			$localFile->write();
		}
		return $localFile;
	}

	/**
	 * Check if remote file exists on this server
	 *
	 * @param ArrayData $remoteFile
	 * @return File
	 */
	protected function findOrImportFile($remoteFile) {
		$filename = $remoteFile->Filename;

		// Pull down the remote file to the filesystem
		$this->copyRemoteFile($filename);
		
		// Find matched file
		$localFile = File::get()
			->filter("FileName", $filename)
			->first();
		if($localFile) {
			$this->mapObjects($localFile, $remoteFile);
			return $localFile;
		}

		// Create local file from this data
		$className = $remoteFile->ClassName;
		$localFile = $className::create();

		// Create folder structure
		$parentFolder = Folder::find_or_make(preg_replace('/^assets\\//', '', dirname($filename)));
		$localFile->ParentID = $parentFolder->ID;

		// Update content
		$this->updateLocalFile($localFile, $remoteFile);
		return $localFile;
	}

	/**
	 * Ensures that the given file is saved locally
	 *
	 * @param string $filename
	 * @return File The local file
	 */
	protected function importFile($filename) {
		$this->task->message("Importing file $filename", 3);

		// Detect resampled urls
		$resampled = '';
		if(preg_match('/^(?<before>.*\\/)_resampled\\/([^-]+)-(?<after>.*)$/', $filename, $matches)) {
			$resampled = $filename;
			$filename = $matches['before'].$matches['after'];
		}

		// Find matched file
		$localFile = File::get()
			->filter("FileName", $filename)
			->first();

		// If we don't have a local record, import it from the other server
		if(!$localFile) $localFile = $this->makeLocalFile($filename);

		// Ensure that the physical file exists
		$filename = $localFile->getFilename();
		$this->copyRemoteFile($filename);
		if($resampled) {
			$this->copyRemoteFile($resampled);
		}
		$localFile->write();
		return $localFile;
	}

	/**
	 * Update all has_ones that are linked to assets
	 *
	 * @param \DataObject $localObject
	 * @param \ArrayData $remoteObject
	 * @return null
	 */
	protected function updateLocaleAssetRelations(\DataObject $localObject, \ArrayData $remoteObject) {
		// Now update all has_one => file relations
		$changed = false;
		$relations = $localObject->has_one();
		if(empty($relations)) {
			$this->task->message(" ** No has_ones on {$localObject->Title}", 2);
			return;
		}
		foreach($relations as $relation => $class) {
			$this->task->message(" *** Checking relation name $relation", 3);
			
			// Link file
			if(!ImportHelper::is_a($class, 'File')) {
				$this->task->message(" **** $relation is not a File", 4);
				continue;
			}
			
			// Don't link folders
			if(ImportHelper::is_a($class, 'Folder'))  {
				$this->task->message(" **** $relation is a folder", 4);
				continue;
			}

			// No need to import if found in a previous step
			$field = $relation."ID";
			if($localObject->$field)  {
				$this->task->message(" **** $relation already has value {$localObject->$field} on local object", 4);
				continue;
			}

			// If the remote object doesn't have this field then can also skip it
			$remoteFileID = intval($remoteObject->$field);
			if(empty($remoteFileID)) {
				$this->task->message(" **** $relation has no value on remote object", 4);
				continue;
			}

			// Find remote file with this ID
			$remoteFile = $this->findRemoteFile(array(
				sprintf('"ID" = %d', intval($remoteFileID))
			));
			if(!$remoteFile) {
				$this->task->error("Could not find $relation file with id $remoteFileID");
				continue;
			}

			// Ensure that this file has a valid name
			if(!$this->isValidFile($remoteFile->Name)) {
				$this->task->error("Remote $relation file does not have a valid name '".$remoteFile->Name."'");
				continue;
			}

			// Copy file to filesystem and save
			$localFile = $this->findOrImportFile($remoteFile);
			if(!$localFile) {
				$this->task->error("Failed to import $relation file '".$remoteFile->Name."'");
				continue;
			}

			// Save new file
			$changed = true;
			$this->task->message(" *** $relation assigned local value {$localFile->ID}", 3);
			$localObject->$field = $localFile->ID;
		}
		if($changed) $localObject->write();
		else $this->task->message(" ** No changes made to relations on {$localObject->Title}", 2);
	}

	public function updateLocalObject(\DataObject $localObject, \ArrayData $remoteObject) {
		$this->task->message(" ** Updating local object {$localObject->Title}", 2);

		// Extract all html images
		$imageURLs = $this->getHTMLImageUrls($localObject);
		foreach($imageURLs as $imageURL) $this->importFile($imageURL);

		// Now update all has_one => file relations
		$this->updateLocaleAssetRelations($localObject, $remoteObject);

		// If this object is itself a File, check that the filename is locally copied
		if($localObject instanceof File) {
			$this->copyRemoteFile($localObject->Filename);
		}
	}

	/**
	 * Check if the following path is valid
	 *
	 * @param type $path
	 */
	protected function isValidFile($path) {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$allowed = array_map('strtolower', File::config()->allowed_extensions);
		return in_array($extension, $allowed);
	}

}
