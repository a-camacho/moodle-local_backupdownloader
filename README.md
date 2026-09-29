# local_backupdownloader

Moodle local plugin that keeps Moodle backup archives (`.mbz`) downloadable in **frozen contexts**. In a frozen course it adds a **Backup downloads** tab to the course secondary navigation. The page lists every backup the current user can download, from the course file areas and from their private backup area, with a one-click download button.

Outside frozen contexts the core restore page already does this job, so the tab is hidden and the page only shows a notice pointing to the restore page.

Requires **Moodle 5.0, 5.1 or 5.2** (works with either the classic or the `public/` webroot layout) and **PHP 8.2+**.

## Features

| Origin | Context | Component / file area | Who can see it |
|---|---|---|---|
| Course backup | course | `backup` / `course` | `moodle/backup:downloadfile` **or** `local/backupdownloader:download` |
| Section backup | course | `backup` / `section` | `moodle/backup:downloadfile` **or** `local/backupdownloader:download` |
| Automated backup | course | `backup` / `automated` | (`moodle/backup:downloadfile` **and** `moodle/restore:userinfo`) **or** (`local/backupdownloader:download` **and** `local/backupdownloader:downloadautomated`) |
| User private backup area | user | `user` / `backup` | The owner only (never the guest user) |

The guest user and not-logged-in visitors never pass the course rules, even if the plugin capabilities are allowed to the guest role, matching the core behaviour for write capabilities.

Download links point to the plugin's own `download.php`, which applies exactly the rules above before streaming the file with forced download. Only files that core's `pluginfile.php` would serve are listed (item id 0 for the course, automated and private areas; the section id for section backups).

## Frozen contexts

When a course, category or the whole site is frozen (*Site administration → Development → Experimental → Context freezing*), Moodle blocks every **write** capability in that context. The core backup capabilities are write capabilities, so the Backup and Restore pages, `pluginfile.php` downloads and this plugin's tab all disappear for regular users.

The plugin therefore only acts in frozen contexts (the course itself, its category or the whole site, while context freezing is enabled), and defines two **read** capabilities, which context freezing leaves untouched:

| Capability | Grants |
|---|---|
| `local/backupdownloader:download` | The tab plus course and section backups |
| `local/backupdownloader:downloadautomated` | Automated backups (needs `download` as well) |

They are not granted to any role by default. Backups may contain personal data, so assign them deliberately (for instance to a copy of the *Editing teacher* role, or as an override on the frozen category) via *Site administration → Users → Permissions → Define roles*.

Site administrators keep the core capabilities in frozen contexts, and therefore the tab, unless *Context freezing applies to administrators* (`contextlockappliestoadmin`) is enabled.

When the course is not frozen, the page shows a notice instead of the list, with a **Go to restore page** button (only for users holding `moodle/restore:restorecourse`) and a **Back to course** button, and `download.php` refuses every file.

Each visit of the page in a frozen context triggers the `\local_backupdownloader\event\backup_list_viewed` event (course context, read-only), visible in the course and site logs.

## How it works

- **Navigation** — a callback registered in `db/hooks.php` listens to `\core\hook\navigation\secondary_extend` (Hooks API, no legacy `lib.php` callbacks) and adds the tab when the course is frozen (`access::is_frozen()`) and the user passes the course backup rule of `classes/local/access.php`. Single-activity courses have no course-level secondary navigation and therefore no tab; the page stays reachable by URL.
- **Access rules** — `classes/local/access.php` combines the core capabilities checked by `file_pluginfile()` with the plugin's own capabilities.
- **File lookup** — `classes/local/backup_finder.php` queries the File Storage API (`get_file_storage()->get_area_files()`) for the areas above, keeps `.mbz` files only and sorts them by modification date. Its `can_serve()` method applies the same rules to a single file.
- **Download** — `download.php` loads the file by id, checks that the course is frozen and that `can_serve()` accepts the file, then streams it with `send_stored_file()`.
- **Rendering** — `classes/output/backup_list.php` (renderable + templatable) exports the data to `templates/backup_list.mustache`, a Bootstrap 5 table.
- **Logging** — `classes/event/backup_list_viewed.php` is triggered by `index.php` once the capability check has passed.

## Installation

1. Copy or clone this repository into `local/backupdownloader` inside your Moodle installation (`public/local/backupdownloader` on Moodle 5.1+).
2. Visit *Site administration → Notifications* or run:

   ```sh
   php admin/cli/upgrade.php
   php admin/cli/purge_caches.php
   ```

3. Grant `local/backupdownloader:download` (and optionally `local/backupdownloader:downloadautomated`) to the roles that need it, then open a frozen course: a **Backup downloads** tab appears in the course navigation. The page is also reachable at `/local/backupdownloader/index.php?id=<courseid>` (the front page is not supported).

## Tests

A GitHub Actions workflow (`.github/workflows/moodle-ci.yml`) runs [moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) on every push and pull request.

PHPUnit (access rules, finder, hook callback, renderable, event):

```sh
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_backupdownloader_testsuite
```

Behat (navigation tab, listing, download link, student access, frozen and non-frozen course):

```sh
php admin/tool/behat/cli/init.php
php admin/tool/behat/cli/run.php --tags=@local_backupdownloader
```

On Moodle 5.1+ prefix the `admin/tool/...` paths with `public/`.

## Notes

- Automated backups stored on disk (`backup_auto_storage = 1`) are not in the file storage and therefore not listed.
- Activity backups (module context) are not listed in this version.

## Translating

Only the English strings ship with the plugin (`lang/en/local_backupdownloader.php`), as the [plugin contribution checklist](https://moodledev.io/general/community/plugincontribution/checklist) requires. Every string the interface shows belongs to the `local_backupdownloader` component, so there is nothing to hunt for in the code.

- **On your own site, right now** — *Site administration → Language → Language customisation*, pick a language pack, *Open language pack for editing*, then filter **Show strings of these components** on `local_backupdownloader`.
- **For everyone** — once the plugin is published in the [plugins directory](https://moodle.org/plugins/), its strings are picked up by [AMOS](https://lang.moodle.org/) and can be translated there like any other plugin.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
