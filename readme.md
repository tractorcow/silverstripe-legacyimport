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

## Running the importer

You can run this as a dev task using the following

`./framework/sake dev/tasks/LegacyImportTask flush=all`

The import itself is made up of several steps, with each step focusing on importing a single object type.

The actual import itself is broken down into several passes, within each pass a different method on each step
is invoked. For example if your import configuration looks like the below:

```yaml
---
Name: mylegacyimport
---
LegacyImportTask:
  tasks:
    - importer: DataObjectImporter
      class: Group
      # Don't update groups, but identify links
      strategy: Identify
      # Identify matching groups by code
      idcolumns:
        - Code
    - importer: SiteTreeImporter
      # Just import top level pages, but don't try to update pages with existing url segments
      strategy: AddOrUpdate
      class: ForumHolder
      where:
		- '"ParentID" = 0'
      idcolumns:
        - URLSegment
		- ParentID
    - importer: DataObjectImporter
      class: Member
      strategy: AddOrIdentify
      idcolumns:
        - Email
```

The actual process will perform the following tasks

* identify groups
* identify pages
* identify members
* import pages (add or update)
* import members (only add new ones)
* link page relations
* link member relations

If you want to run a single pass you can skip to one using the 'pass' param.

`./framework/sake dev/tasks/LegacyImportTask flush=all pass=identify`

Warning: Some steps may rely on identification being performed up front, and you should not begin an import
at a later step if prior steps have not been completed.

The passes are as follows:

### identify

Remote objects are selected and compared to all local objects used specified criterea. Then a mapping of all
identified objects is created.

### import

All objects are created (as allowed) or updated (as allowed)

### link

All has_one and many_many relations are generated between all matched objects, using the mapping table to determine
the mapping between different IDs on each server

## Importers

These are the following importers and their supported strategies:

### DataObjectImporter

This is the basic importer, and can import just about any object.

You can use one of the following strategies:

* Add - Objects are added from the remote server without matching against the local ones
* AddOrIdentify - Object are added from the remote server, but only if a matched record isn't found.
* AddOrUpdate - Objects are added if they don't exist, or updated if they are
* Identify - Only a mapping table record is created for this step if a matched record can be found.
* Update - If a record is found it is updated, but otherwise a new one won't be added

### AssetImporter

This importer loads assets into your site, and has only two specific strategies.

#### Preload

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

#### OnDemand

Files will be downloaded as needed. You must define a remote site root to determine this.

```php
define('SS_REMOTE_SITE', 'http://www.myoldsite.com');
```


