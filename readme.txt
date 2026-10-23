=== My Family Tree ===
Tags: family tree, genealogy, family, privacy, shortcode
Requires at least: 6.5
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Build a private family tree with biographies, touch galleries, multilingual labels, fullscreen viewing and optional demo content.

== Description ==

My Family Tree turns ordinary WordPress posts into an interactive family archive. An oak-inspired tree, leaf-shaped portraits and personal stories help visitors explore relationships across generations.

Add a Family Tree block or the [wp_my_family_tree] shortcode to a page. Choose a full-window layout or embed the tree in your theme. People remain WordPress posts, and compatible records from the older WP Family Tree plugin can be reused.

= Explore the family =

* Drag or swipe the tree, pinch to zoom, find a person and follow family branches.
* Open biographies, dates, places, contact details and optional social profile links.
* Swipe through person photographs; use arrows, gallery dots or the keyboard.
* Open authorized person editing in a new tab.
* Expand only the tree area to fullscreen, with an in-page fallback and an exit control.
* Save or print a tree-only image without the page header or administrator contact footer.
* Use a responsive people directory, a compact email/contact footer and optional home-screen installation help.

= Manage relationships and access =

* Record parents and multiple partners, including current and former relationships.
* Keep both recorded parents in a child's view without inventing step-parent links.
* Choose the person-first editor or enable WordPress's block editor for biographies.
* Require a family viewing password, retain access for up to 30 days and revoke remembered sessions.
* Optionally encrypt family media and deliver protected files through access checks.
* Accept private contact messages with one optional photo through WordPress mail.

New private installations remain locked until an administrator configures a password. The shared family password grants viewing access, not WordPress editing permission. The plugin must remain active for its access controls to apply.

= Languages =

English is the default for new installations. Complete frontend dictionaries are included for English, Spanish, Polish, French, German, Latvian, Lithuanian, Estonian, Danish, Swedish, Norwegian Bokmål, Norwegian Nynorsk, Finnish and Icelandic.

Enable presets, add any number of custom languages, edit label dictionaries and configure translated titles in the Languages settings. Missing labels fall back to English. Administrator translation coverage varies: common controls are translated in the presets, with English fallback for remaining labels and editable administrator overrides.

Polylang is optional. When configured, its translated page URLs and person posts provide content translations. Without it, the plugin language menu changes interface labels. No automatic translation service is used.

= Performance and cleanup =

The server-side relationship index supports automatic storage selection, WordPress transients and an existing persistent WordPress object cache. Redis or Memcached must be supplied by the host or a separate compatible cache plugin; this plugin does not install or replace an object-cache drop-in.

Administrators can clear only the Family Tree index from settings or the WordPress toolbar. The toolbar icon rotates while clearing. Password sessions, anti-spam limits, encrypted media and other plugins' caches are retained.

Production JavaScript and CSS are supplied minified with readable sources. Optional settings compact standalone HTML, remove emoji fallback assets and unnecessary head tags, disable public comments on ordinary posts/pages/media, or omit theme styles from full-window tree pages. Private family pages and API responses retain no-store behavior.

= Optional demo =

An administrator can import eight fictional cinema-inspired people, eight original monogram portraits, three original gallery illustrations and two English pages. Character references include Marty McFly, Amélie Poulain, Paddington Brown and Matilda Wormwood. Dates, places and crossover family links are invented examples, not biographies or film canon.

Importing requires confirmation and preserves existing records, pages, media and settings. Repeating the import preserves edits to marked demo records. It does not create an account or enable public demo access.

On an isolated single-site installation with no real family records, a separately confirmed shared demo mode creates the username demo with password Demo@demo123Editor and front-end password demo. No such account exists by default. An existing demo username is not modified.

The custom Family Tree Demo Editor can change only marked sample stories and selected fields through a constrained editor. It is not a standard WordPress Editor and cannot manage other content, settings, users, plugins or uploads. Contact sending is disabled during shared demo mode. Demo edits are public.

Real family records, including drafts and trashed records, block shared demo mode. If isolation later fails, demo access is locked and shared sessions are revoked. Disabling the mode revokes the shared account and sessions, retains sample content, and restores normal privacy behavior. The normal family password hash is not overwritten.

= Daily demo reset =

While shared demo mode is active, the plugin restores visitor-editable sample titles, stories, dates, locations and occupations to a retained starting copy every 24 hours. It does not restore the whole website or change real family records, relationships, media or normal settings. No backup plugin is required.

The task uses WordPress cron, with an overdue check before the next demo access or edit. On a site with no requests, execution can be later than the scheduled time. Demo settings show the next reset and offer a confirmed Reset demo now control. Old editor forms must be reloaded after a reset. The saved starting copy survives disabling and re-enabling demo mode. Active older demos without a saved copy use the original bundled sample fields.

To replace the saved starting copy deliberately, disable sharing, edit the samples as an administrator, and confirm Save current demo as reset baseline before enabling sharing again.

= Privacy and external services =

The plugin has no analytics, telemetry, author-operated service or external update system. Core tree code, styles, icons and demo illustrations are included in the package. Social links, website links and the optional author credit are ordinary links followed only by a visitor's action; the author credit is off by default.

hCaptcha is an optional external anti-spam service and is disabled by default. An administrator must obtain an hCaptcha account, enter a site key and secret, and enable the relevant form before it is used.

On enabled challenge forms, the browser loads https://js.hcaptcha.com/1/api.js and contacts hCaptcha and its challenge/asset hosts. hCaptcha receives browser/network information and challenge interactions as described in its privacy policy. The site's server sends the site key, secret, challenge response token and visitor IP address to https://api.hcaptcha.com/siteverify to verify the response. The secret is not exposed to the browser.

* Service: https://www.hcaptcha.com/
* Terms: https://www.hcaptcha.com/terms
* Privacy: https://www.hcaptcha.com/privacy

Contact messages use wp_mail() and the site's configured mail transport. An optional photograph is attached to the email, not published or added to the Media Library. Temporary upload files are removed after processing. Your mail provider or other mail-logging plugins may retain their own copies.

Normal family viewing uses a server-validated cookie. Demo sharing uses a separate cookie and session namespace. No service worker or offline family-data cache is registered.

= Development and support =

Readable PHP, JavaScript and CSS are included. Minified assets are built with esbuild 0.25.5; package.json, package-lock.json and scripts/build-assets.mjs are supplied. Developers can run npm ci followed by npm run build with Node 18 or newer. Node and npm are not needed on the WordPress server.

Source and ordinary bug reports:
https://github.com/raivis-kalnins/wp-my-family-tree

See readme.md for the complete administration, privacy, backup and troubleshooting guide. See SECURITY.md before reporting a security issue. Do not include real family records, photographs, passwords, keys or private logs in public issues.

== Installation ==

1. Upload the complete my-family-tree-1.2.3.zip through Plugins > Add New Plugin > Upload Plugin, or place the my-family-tree directory in wp-content/plugins.
2. Activate My Family Tree. If migrating from WP My Family Tree, follow the rename instructions below before activation.
3. Deactivate the older WP Family Tree plugin if it is still active. Keep its existing posts and media.
4. Open Family Tree settings, select the family category and starting person, and create or select a tree page.
5. Insert the Family Tree block or [wp_my_family_tree] shortcode on the page.
6. Set a family viewing password under Privacy, or deliberately choose a public tree.
7. Review appearance, contact settings and languages. Polylang and persistent object caching are optional.
8. Check the visitor experience in a signed-out browser window.

The shortcode accepts root="123" for a starting person or view="list" for the directory. Legacy [family-tree] and [family-members] shortcodes are supported for migration.

= Upgrading from WP My Family Tree 1.2.2 or earlier =

Version 1.2.3 changes the public plugin name and folder to My Family Tree / my-family-tree. WordPress treats it as a separate plugin, so its uploader will not replace the old wp-my-family-tree folder.

1. Back up the database and uploads together. For a private archive, enable independent site access protection during the switch because the old plugin's access checks stop when it is deactivated.
2. Deactivate WP My Family Tree, including its network activation if applicable.
3. Upload the complete new ZIP and activate My Family Tree. Do not activate both copies together.
4. Verify the tree page, privacy settings, contact form, photographs and person editing. Existing people, relationships, settings, media-encryption keys, shortcodes and saved blocks are retained; no database import is required.
5. After verification, remove the inactive old plugin through Plugins. Its bundled code has no uninstall data-deletion routine. Keep the new My Family Tree plugin active.

Deactivation revokes shared demo access. On an isolated demonstration site, re-enable shared demo mode explicitly if needed; imported samples and their saved reset baseline remain available. Future updates within the my-family-tree folder can use WordPress's normal replacement workflow.

== Frequently Asked Questions ==

= Does it work without Polylang? =

Yes. Interface languages work independently. Polylang is optional for translated WordPress pages and biographies. The settings provide an installation link when it is absent; it is not installed automatically.

= Can I add a language that is not included? =

Yes. Add a language code, display name, locale and optional frontend/admin label overrides in Languages. There is no fixed language-count limit. Missing translations use English.

= Is the entire WordPress dashboard translated by this plugin? =

No. The plugin's interface-language control affects its own labels. Administrator presets have varying coverage and use English where a translation is missing. WordPress core and the theme use their own language packs.

= Does this replace the older WP Family Tree data? =

It can read compatible existing person posts and relationship metadata. Deactivate the older plugin to prevent competing shortcodes and its legacy errors. Keep the existing posts and photographs; a database import is not required.

= Will clearing the cache remove my family data or log visitors out? =

No. It clears this site's Family Tree performance index and updates plugin asset version URLs. It does not clear viewing sessions, passwords, anti-spam limits, encrypted files or other plugins' caches.

= Does object caching require Redis? =

No separate service is required for transient caching. Persistent object caching needs a supported host/cache plugin backend, which can use Redis, Memcached or another compatible store. The plugin reports the available backend and falls back to transients when necessary.

= Is demo sharing safe to enable on my real family archive? =

Shared mode is intentionally blocked when any real family record exists, including unpublished and trashed records. Use a separate demonstration site. Importing sample content by itself does not enable shared access.

= Can the public demo account edit my other WordPress pages? =

No. The custom demo role uses a restricted editor for marked sample records only. It has no normal Editor privileges, upload access or settings access. Disable demo mode to revoke the account and its sessions.

= Are the demo names and images real family records? =

No. Character names are illustrative references. Dates, locations and relationships are invented. The included monograms and landscapes are original artwork; no movie stills, celebrity photos or private records are shipped.

= How do I share only the tree? =

Use the fullscreen button in the tree panel. The close button or Escape exits. Save tree image and Print tree render the selected family branch without the site header, footer or administrator contact details.

= What happens when a gallery photograph is zoomed? =

Swiping pans the enlarged photograph. Exit zoom to swipe between photographs; arrows and keyboard navigation remain available.

= Does the home-screen view work offline? =

No. It requires an internet connection and the same family access checks as the website. Browser installation support and menu labels vary.

= What should I back up when using encrypted family media? =

Back up the database and encrypted uploads together. The matching encryption key is stored in the database. Keep original backups before enabling protection for existing photographs.

= What happens when I deactivate or delete the plugin? =

Demo sharing is revoked on deactivation. Person posts, settings and media are retained. The plugin does not automatically erase archive data or encryption keys. Its access controls stop when it is inactive, so independently protect private published content before disabling it.

== Screenshots ==

1. Fictional family records and original illustrated portraits in the interactive oak-inspired tree.

== Changelog ==

= 1.2.3 =

* Renamed the public plugin to My Family Tree, with the my-family-tree folder and matching translation domain, for directory resubmission.
* Updated plugin metadata, translation filenames and release documentation while preserving family data, settings, existing shortcodes and saved blocks.
* Added guidance and activation protection for switching from the old wp-my-family-tree folder.

= 1.2.2 =

* Restored the footer contact form for signed-out and signed-in visitors, replacing unintended account links.
* Updated Latvian Pieteikties labels and relative-description guidance while retaining photo attachments and contact protections.

= 1.2.1 =

* Added a built-in 24-hour reset for public demo edits, a retained baseline, overdue recovery and an administrator Reset now control.
* Moved the optional developer credit below the left footer motto.
* Added footer WordPress Sign in / Dashboard links with icons.
* Improved relationship connectors and highlighting in dense family trees.

= 1.2.0 =

* Added touch gallery swiping with drag feedback, zoom-aware navigation and accessible controls.
* Opened authorized person editing in a new tab.
* Added tree-only fullscreen, an in-page fallback, exit controls and tree-only PNG/print output.
* Added persistent object-cache selection and scoped animated toolbar cache clearing.
* Added fourteen frontend language presets, unlimited custom languages and editable label dictionaries.
* Added optional Polylang installation guidance.
* Added confirmed, additive cinema demo import and separately enabled restricted demo sharing.
* Compacted the footer and made its email address a mail button.
* Added legacy-plugin conflict guidance and public distribution documentation.

= 1.1.2 =

* Added the relationship index cache, settings-panel clearing, WordPress cleanup controls and minified assets.
* Added conservative standalone HTML compaction, duplicate viewport removal and optional lean theme styling.

= 1.1.1 =

* Improved mobile tree dragging and pinch gestures.
* Aligned gallery removal controls in the person editor.

= 1.1.0 =

* Added the person-first editor, contact and social fields, private photo messages and home-screen installation controls.

= 1.0.2 =

* Added explicit current/former partner status and preserved both recorded parents in a child's tree.

= 1.0.1 =

* Improved photograph framing, Polylang page-language synchronization and the people directory.

= 1.0.0 =

* Initial interactive tree, person gallery, legacy-data compatibility and private viewing support.

== Upgrade Notice ==

= 1.2.3 =

Renamed to My Family Tree. Back up, deactivate WP My Family Tree, install this ZIP, then activate My Family Tree. The new folder is a separate plugin. Family data and settings are retained. See Installation for migration steps.

= 1.2.2 =

Replace the plugin and clear the page cache. The footer Contact us / Pieteikties button opens the administrator contact form again, including the optional photo attachment. Existing settings and family data are retained.

= 1.2.1 =

Use the complete ZIP to replace the plugin. Shared demos now restore their editable sample fields every 24 hours. Existing active demos without a saved baseline use the original bundled sample fields. Footer and relationship-line improvements apply immediately.

= 1.2.0 =

Use the complete ZIP to replace the plugin. Existing family data and privacy settings are retained. Demo import and shared demo mode require separate opt-in.
