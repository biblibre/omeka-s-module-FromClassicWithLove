FromClassicWithLove (module for Omeka S)
===============================

[FromClassicWithLove] (or **FCWL** for short) is a module for [Omeka S] and will allow an administrator to import item sets, items, media and tags from an Omeka Classic SQL dump.

Installation
------------

See general end user documentation for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules).

Usage
-----

To use the module, you first need an Omeka (classic) instance of your choice, then you need to create a database dump from that.
The module is not made to create the dump; the administrator needs to do it themselves.

**Step 1 — Configure the dump database**

Import the SQL dump into a database accessible by the Omeka S database user (as configured in `config/database.ini`), then declare its name in `config/local.config.php` (not `module.config.php`, to keep environment-specific config out of version control).
The `table_prefix` key corresponds to the prefix configured in your Omeka Classic `db.ini` file (default is `omeka_`):
```php
    // This is in config/local.config.php
    'fromclassicwithlove' => [
        'dump_database' => 'my_omeka_classic_db',
        'table_prefix' => 'omeka_',
    ]
```

The Omeka S database user must have read access to the dump database. If needed, grant it:
```bash
sudo mysql -uroot
[MySQL] > GRANT SELECT ON my_omeka_classic_db.* TO 'omeka_s_user'@'localhost';
```

**Step 2 — Upload media files (optional)**

The media files contained in `omeka/files/original` must also be uploaded to the Omeka-S instance (anywhere Omeka-S can reach on disk) if you want media to be imported.

**Step 3 — Run the import from the interface**

In the "FromClassicWithLove" tab you will find a form with two optional fields:
- **Path to the original media files** — leave empty to skip media import
- **URL of the old Omeka Classic instance** — does not need to still work; it is used to detect and convert internal links (e.g. `my-omeka-classic.com/items/show/5`) into links to the corresponding imported resource in Omeka-S

Fill in the form and click **Next**. You will then be able to see what properties and resource classes can be mapped. All values set on properties that are **not** mapped will **not** be imported!

On the mapping screen:

- **Import collections** — check this to import Omeka Classic collections as Omeka-S item sets.
- **Import Item sets tree** — only appears if the dump contains CollectionsTree data and the [ItemSetsTree](https://github.com/biblibre/omeka-s-module-ItemSetsTree) module is active. Imports the collection hierarchy.
- **Update** — see the *Updating a previous import* section below.

For each property, you may select **Clean HTML** to strip HTML tags from the property values. For example, if `dcterms:title` is `<strong>Hello!</strong>`, "Clean HTML" will only keep `Hello!`.

For each property, you can also check **Map URIs**. When checked, any value containing a URL is processed:
- If the URL points to the old Omeka Classic instance (matching the URL provided in the form), it is converted into an internal **resource link** (`type: resource`) pointing to the corresponding imported resource in Omeka-S.
- Any other URL is imported as a plain **URI value**, with the surrounding text used as label (e.g. `Notice du catalogue : http://example.com` becomes a URI with label `Notice du catalogue :`).
- Values like `<a href="https://example.com">My link!</a>` are also handled and converted into a proper URI value.
- If no URL is detected in the value and **Map URIs** is checked, the value is silently skipped — leave it unchecked for fields with mixed URL and plain-text values.

In the **Tags** section at the bottom of the mapping screen, you can optionally select an Omeka-S property to map Omeka Classic tags to (e.g. `dcterms:subject`). If left empty, tags are not imported.

Finally, if no errors occur, all imported resources will be available in your Omeka-S instance.

Migration report
----------------

Before and after each import, the job automatically logs a migration report visible in the **Job log**. It includes:

- Number of collections / item sets (public and private)
- Number of items (public and private)
- Number of items with and without media
- Total number of media
- List of properties used with their value count

The report is logged twice: once from the Omeka Classic database (before import) and once from Omeka S (after import), so you can compare the two and verify the migration was complete.

Example:

```
[Classic] Collections: 12 public, 3 private | Items: 1083 public, 47 private | With media: 892, Without: 238 | Media: 1204
[Classic] Properties used:
  Dublin Core > Creator (1847)
  Dublin Core > Date (1102)
  Dublin Core > Identifier (1130)
[Omeka S] Item sets: 12 public, 3 private | Items: 1083 public, 47 private | With media: 892, Without: 238 | Media: 1204
[Omeka S] Properties used:
  dcterms:creator (1847)
  dcterms:date (1102)
  dcterms:identifier (1130)
```

Updating a previous import
---------------------------

If you have already run an import and want to re-import from a refreshed dump (e.g. the source Omeka Classic database has changed), you can update the previously imported resources instead of creating duplicates.

**Step 1** — Re-import the refreshed SQL dump into the dump database before proceeding.

**Step 2** — In the **Past imports** tab, click **Update import** next to the import you wish to update. This will pre-fill the form with the parameters of the original import.

**Step 3** — On the mapping screen, the **Update** checkbox will be pre-checked. Click **Next** to confirm and start the update job.

During an update:
- Resources that still exist in the new dump are **updated in place**.
- Resources that no longer exist in the new dump are **deleted** from Omeka-S.
- New resources are **created**.

Past imports and Undo
---------------------

The **Past imports** tab lists all previous import and update jobs with their statistics (number of item sets, items, URIs imported or updated).

From this view you can:
- **Undo** a past import by checking its checkbox and clicking Submit. This will delete all resources that were created during that import. Update jobs cannot be undone (the checkbox is disabled).
- **Update import** — re-run an import against a refreshed dump (see above).
- Access the **Job details** and **Log** for each job for debugging purposes.

Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check your archives regularly so you can roll back if needed.

If you don't know what you're doing, you're probably not the target audience for this module. It should only be used by administrators.

Troubleshooting
---------------

See online issues on the [Omeka forum] and the [module issues] page on GitHub.


Contact
-------

Current maintainers:

* BibLibre
* [Abel B.]


All rights not expressly granted are reserved.

* Copyright Biblibre, 2026-present

[FromClassicWithLove]: https://github.com/biblibre/FromClassicWithLove
[Omeka S]: https://omeka.org/s
[Omeka forum]: https://forum.omeka.org/c/omeka-s/modules
[module issues]: https://github.com/omeka-s-modules/CSVImport/issues
[GNU/GPL v3]: https://www.gnu.org/licenses/gpl-3.0.html
[Abel B.]: https://github.com/Bebel00
