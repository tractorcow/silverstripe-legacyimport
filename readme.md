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

## Asset transfers

For asset transfers also please specify the appropriate scp or rsync command to use. Exclude the destination argument
as this will be appended by the importer.

```php
define('SS_REMOTE_SYNC_COMMAND', 'scp -rP 2222 192.168.0.54:/sites/myoldsite/www/assets');
```

or using rsync

```php
define('SS_REMOTE_SYNC_COMMAND', 'rsync -a 192.168.0.54:2222/sites/myoldsite/www/assets);
```

You will also need to set a holding directory for downloaded assets, which should be outside of your webroot

```php
define('SS_REMOTE_ASSETS_STORE', '/sites/mynewsite/importedfiles');
```
