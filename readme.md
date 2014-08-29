# Legacy Importer

Import your 2.x sites into 3.x!

## Setting up DB credentials

You'll need to set the following in your _ss_environment.php to point to the old site DB

SS_REMOTE_DATABASE_USERNAME
SS_REMOTE_DATABASE_PASSWORD
SS_REMOTE_DATABASE_CLASS
SS_REMOTE_DATABASE_SERVER
SS_REMOTE_DATABASE_PORT
SS_REMOTE_DATABASE_TIMEZONE

## Importers

These are the following importers and their supported strategies:

### AssetImporter

This importer loads assets into your site, and supports the following strategies

### strategy: Preload

All assets will be downloaded to the local server under a temporary directory prior to synchronisation

For asset transfers also please specify the appropriate rsync or scp command to use.
You will also need to set a holding directory for downloaded assets, which should be outside of your webroot.

rsync command (preferred)

```php
define(
	'SS_REMOTE_SYNC_COMMAND',
	'rsync -rz -e \'ssh -p 2222\' --progress someuser@192.168.0.54:/sites/myoldsite/www/assets /sites/mynewsite/importedfiles'
);
```

scp command (if rsync is not available)

```php
define(
	'SS_REMOTE_SYNC_COMMAND',
	'scp -rP 2222 someuser@192.168.0.54:/sites/myoldsite/www/assets /sites/mynewsite/importedfiles'
);
```

Specify location of assets directory once the above command has executed.


```php
define('SS_REMOTE_SYNC_STORE', '/sites/mynewsite/importedfiles/assets');
```

### strategy: OnDemand

Files will be downloaded as needed. You must define a remote site root to determine this.

```php
define('SS_REMOTE_SITE', 'http://www.myoldsite.com');
```