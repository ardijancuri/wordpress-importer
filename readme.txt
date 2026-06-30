=== WP Site Migrator ===
Contributors: oninova
Tags: migration, export, import, database, backup
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export and import a complete single-site WordPress install with database, uploads, themes, plugins, languages, and selected root files.

== Description ==

WP Site Migrator creates a downloadable migration package on the source site and imports that package on a destination site. It is designed for single-site WordPress migrations, including local-to-live moves.

Version 0.1.0 intentionally focuses on package-based migrations. It does not support multisite, direct server-to-server push, retained destination backups, or WordPress core replacement.

== Migration Scope ==

The export package includes:

* Database tables using the current WordPress table prefix, including custom plugin tables.
* Uploads, themes, plugins, mu-plugins, and languages.
* Selected root files: `.htaccess`, `web.config`, `robots.txt`, and `favicon.ico`.

The package excludes WordPress core, `wp-config.php`, database credentials, salts, migration packages, and transient upload cache/log/backup folders.

== Security ==

Only administrators with `manage_options` can use the plugin. REST requests require WordPress REST nonces. Migration packages are stored in randomized protected folders under `wp-content`, and imports validate checksums before replacing data.

== Installation ==

1. Upload the `wp-site-migrator` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to Tools > Site Migrator.

== Changelog ==

= 0.1.1 =
Fix plugin/theme exports so required source folders named `cache` or `caches`, such as WooCommerce cache classes, are included. Improve import/download reliability.

= 0.1.0 =
Initial package-based single-site export/import implementation.
