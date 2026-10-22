# wp-my-family-tree
WP My Family Tree - WordPress plugin

=== WP My Family Tree ===
Tags: family tree, genealogy, family history, privacy, shortcode
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An interactive oak family tree with person dialogs, photo galleries, English and Latvian interfaces, and optional password-protected access.

== Description ==

WP My Family Tree connects generations while keeping your family stories in ordinary WordPress posts. Add the Family Tree block or [wp_my_family_tree] shortcode to a page. The plugin provides a responsive oak tree, its own header and footer, person dialogs and a contact form independently of your theme.

Created by Raivis Kalnins, web developer at DigitalPulse: https://digitalpulse.click/

Features:

* React interface using WordPress's bundled wp-element package.
* Bounded Ajax tree loading, person search and navigation to related branches.
* Person dialogs with portraits, biographies, dates, locations and a gallery lightbox.
* A Gutenberg block and theme-independent full-window layout.
* Ordinary WordPress posts for people; use the block editor for biographies.
* Compatibility with the original WP Family Tree category and father, mother, spouse, born, died and gender fields.
* Extra fields for birth/maiden name, birthplace, place of death, location, occupation, additional partners and photograph galleries.
* English and Latvian interface text, with an optional language switch.
* Optional Polylang integration for translated pages and person biographies.
* Optional password access with remembered sessions lasting no more than 30 days.
* No-store responses for private family data and access checks for family endpoints.
* Protected photograph delivery and encrypted family photograph files.
* Contact dialog with optional hCaptcha, server-side validation and anti-spam controls.
* Tree image export with the title and contact footer, plus printing.
* Configurable titles, colours, starting person and contact details.
* Optional DigitalPulse footer attribution, off by default.

The standalone plugin contains no real family records, photographs, access passwords or hCaptcha keys.

= Getting started =

1. Activate the plugin and open Family Tree -> Settings & help.
2. Select the category containing your family posts.
3. Add the Family Tree block or [wp_my_family_tree] to a page, or use Create a tree page.
4. Set the starting person, title and family administrator email.
5. Set a strong family password. New private installations stay locked until a password is configured.
6. Add or edit people under Posts. Enable Include this post as a person in the person details box.

For full instructions, read HELP.txt in the plugin folder.

= Privacy and photograph protection =

When password protection is enabled, access is checked on the server. Remembered access uses a session cookie; the family password is not kept in browser storage. Changing the password or revoking remembered access invalidates old sessions.

The protected-media workflow encrypts linked family image files and serves photographs through the authorized media endpoint. Keep a full backup before protecting existing files. The database encryption key and encrypted uploads belong to the same backup set. Do not remove the plugin or restore only one half of this set while relying on encrypted photographs.

The existing-media batch also protects other attachments belonging to family posts. Protected files outside the supported photograph formats are offered as downloads through the same access check. Automatic protection on person saves covers photographs; the person gallery is for images.

Previously public copies, search-engine copies and CDN caches cannot be recalled by the plugin. Purge old caches, exclude private routes from page caching, and test an old direct image URL while signed out.

Access checks require this plugin to remain active. Original WordPress publication statuses are preserved, so published person text can become accessible again if the plugin is deactivated. Put the site behind separate site-wide access protection before deactivating or uninstalling it. Preserve the media encryption key while encrypted uploads exist.

Contact messages are sent through WordPress wp_mail. The plugin does not save message contents as family records. Your mail server or an installed mail logging plugin may retain messages. The site administrator is responsible for the site's privacy notice and for obtaining appropriate permission to publish family information.

= External services =

hCaptcha is optional and disabled by default. No hCaptcha request is required unless an administrator enables it and supplies the site and secret keys. When enabled on a form, the browser loads hCaptcha's script from https://js.hcaptcha.com and submits technical browser information to hCaptcha. The server verifies the response token with https://api.hcaptcha.com/siteverify. The hCaptcha secret is sent only by the server.

Service: https://www.hcaptcha.com/
Privacy: https://www.hcaptcha.com/privacy
Terms: https://www.hcaptcha.com/terms

Polylang is optional. It is installed separately and follows its own configuration and privacy behaviour. The tree interface works in English and Latvian without Polylang.

== Installation ==

1. In WordPress, open Plugins -> Add New -> Upload Plugin.
2. Upload wp-my-family-tree.zip and activate it.
3. If migrating from WP Family Tree, deactivate the original plugin. Do not delete your family posts or media.
4. Open Family Tree -> Settings & help and review the detected category and access settings.
5. Insert the block or shortcode on a page.
6. Set a password and verify the tree from a private browser window before sharing it.

The plugin ZIP is separate from an UpdraftPlus backup. Do not upload a plugin ZIP into the UpdraftPlus restore screen. Use the matching database, plugins, themes and uploads files when restoring a supplied UpdraftPlus set.

== Frequently Asked Questions ==

= Do I need a special theme? =

No. Full-window mode uses the plugin's own page layout and removes the theme header and footer on the tree page. Turn full-window mode off to embed the tree inside your theme.

= What is the shortcode? =

[wp_my_family_tree]

To choose a person by WordPress post ID:

[wp_my_family_tree root="123"]

The legacy [family-tree] and [family-members] shortcodes are supported for migration. Deactivate the original plugin to avoid shortcode conflicts and its older public routes.

= Will this keep my existing WP Family Tree people? =

The plugin reads ordinary posts in your family category and reuses the legacy relationship and date fields. It does not require changing post IDs or moving people into a new post type. Keep a full database and uploads backup before migration.

= How do I add a person? =

Create a WordPress post. Use its title for the person's name, its block editor content for the biography and its featured image for the portrait. In Family tree — person details, enable the person and choose parents, partners, dates and a gallery. Publish the post when it is ready for family visitors.

= Why are there no visitors on the tree after activation? =

Private installations start locked until a family password has been set. Configure a password under Family Tree -> Privacy & access, or explicitly turn password protection off for a public tree.

= Does the password protect photographs as well? =

Photographs encrypted by the plugin are served through its access check. Existing unprotected uploads need the Protect existing family photos action. Old public CDN or third-party copies are outside WordPress control. Keep the encrypted uploads and their database key together in backups.

= Does it automatically translate names and biographies? =

No. English and Latvian interface text is included. You can translate your content manually with Polylang. Existing Latvian names and biographies are preserved.

= Can visitors edit people? =

No. The family password permits viewing. Person and settings changes require the appropriate WordPress editor or administrator permissions.

= Does hCaptcha need an API key? =

Yes. Create a site key and secret in your own hCaptcha account, then enable the relevant forms in the plugin settings. Both forms can work with hCaptcha disabled.

= Is email delivery guaranteed? =

No. The contact form uses your WordPress mail configuration. Check mail delivery on staging or use a properly configured SMTP plugin if your host requires one.

== Screenshots ==

1. Desktop oak tree with the page title, header, branch controls and contact footer. All people and illustrated portraits are synthetic demonstration data.
2. Person dialog with a portrait, dates, location, biography and photograph gallery, using synthetic demonstration data.
3. Responsive mobile tree in Latvian, including the language switch, page title and contact footer, using synthetic demonstration data.

== Changelog ==

= 1.0.0 =
* Initial release with the oak tree interface, legacy post data compatibility, person galleries, private access, English/Latvian interface and independent contact settings.

== Upgrade Notice ==

= 1.0.0 =
Back up the database and uploads together before migration. Deactivate the original WP Family Tree plugin and review privacy settings before sharing the new tree.

== Development ==

All PHP, JavaScript and CSS source is readable and included in the ZIP. The frontend and block use WordPress-provided React and block packages. No JavaScript compilation step is required. assets/editor.asset.php declares the block's WordPress script dependencies.

Copyright (c) 2026 Raivis Kalnins / DigitalPulse. Distributed under the GNU General Public License, version 3 or any later version. The complete license is included in LICENSE.

Legacy data compatibility refers to WP Family Tree by Arvind Shah. This is a separate plugin; the original plugin's data schema is retained for migration, and its runtime is not required.

