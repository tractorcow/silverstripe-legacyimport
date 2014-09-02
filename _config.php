<?php

// Configure remote DB name
global $remoteDatabase;
if(empty($remoteDatabase) && defined('SS_REMOTE_DATABASE_NAME')) {
	$remoteDatabase = SS_REMOTE_DATABASE_NAME;
}

// Configure remote DB credentials
if(defined('SS_REMOTE_DATABASE_USERNAME') && defined('SS_REMOTE_DATABASE_PASSWORD')) {
	global $remoteDatabaseConfig;
	$remoteDatabaseConfig = array(
		"type" => defined('SS_REMOTE_DATABASE_CLASS') ? SS_REMOTE_DATABASE_CLASS : "MySQLDatabase",
		"server" => defined('SS_REMOTE_DATABASE_SERVER') ? SS_REMOTE_DATABASE_SERVER : 'localhost',
		"username" => SS_REMOTE_DATABASE_USERNAME,
		"password" => SS_REMOTE_DATABASE_PASSWORD,
		"database" => $remoteDatabase
	);

	// Set the port if called for
	if(defined('SS_REMOTE_DATABASE_PORT')) {
		$remoteDatabaseConfig['port'] = SS_REMOTE_DATABASE_PORT;
	}

	// Set the timezone if called for
	if (defined('SS_REMOTE_DATABASE_TIMEZONE')) {
		$remoteDatabaseConfig['timezone'] = SS_REMOTE_DATABASE_TIMEZONE;
	}

	// For schema enabled drivers:
	if(defined('SS_REMOTE_DATABASE_SCHEMA')) {
		$remoteDatabaseConfig["schema"] = SS_REMOTE_DATABASE_SCHEMA;
	}

	// For schema enabled drivers:
	if(defined('SS_REMOTE_DATABASE_PATH')) {
		$remoteDatabaseConfig["path"] = SS_REMOTE_DATABASE_PATH;
	}
}