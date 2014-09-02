<?php

class AssetLinker extends LegacyHelper {

	/**
	 * SQL where filter
	 *
	 * @var array
	 */
	protected $targetWhere = array();

	public function __construct(\LegacyImportTask $task, $parameters) {
		parent::__construct($task, $parameters);

		if(!File::has_extension('LegacyDataObject')) {
			throw new Exception("File does not have the LegacyDataObject extension");
		}

		if(!empty($parameters['where'])) {
			$this->targetWhere = $parameters['where'];
		}
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

		// Save mapping ID
		$localObject->LegacyID = $remoteObject->ID;
		$localObject->write();
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
	protected function copyRemoteFile($path) {
		// Check if file exists
		$localPath = Director::baseFolder() . '/' . $path;
		if(file_exists($localPath)) return true;

		// Ensure parent directory exists
		$localDir = dirname($localPath);
		if(!file_exists($localPath)) Filesystem::makeFolder($localDir);

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
	 * Create a local file record for a given url
	 *
	 * @param string $filename Path relative to site root
	 * @return File Created file record
	 */
	protected function makeLocalFile($filename) {
		// Create folder structure
		$parentFolder = Folder::find_or_make(preg_replace('/^assets\\//', '', dirname($filename)));

		// Find remote file
		$query = $this->makeQuery();
		$query->addWhere('"File"."Filename" LIKE \''.Convert::raw2sql($filename).'\'');
		$remoteFile = $this->task->query($query)->first();
		if($remoteFile) $remoteFile = new ArrayData($remoteFile);

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
	 * Ensures that the given file is saved locally
	 *
	 * @param string $filename
	 * @return File The local file
	 */
	protected function importFile($filename) {
		// Detect resampled urls
		$resampled = '';
		if(preg_match('/^(?<before>.*\\/)_resampled\\/([^-]+)-(?<after>.*)$/', $filename, $matches)) {
			$resampled = $filename;
			$filename = $matches['before'].$matches['after'];
		}

		// Find matched file
		$localFile = File::get()
			->filter("FileName:ExactMatch:nocase", $filename)
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

	public function updateLocalObject(\DataObject $localObject, \ArrayData $remoteObject) {
		// Extract all html images
		$imageURLs = $this->getHTMLImageUrls($localObject);
		foreach($imageURLs as $imageURL) $this->importFile($imageURL);
	}

}
