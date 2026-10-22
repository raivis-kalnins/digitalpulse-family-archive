# WP My Family Tree

An oak-inspired WordPress family tree for the stories, photographs and people that connect generations. Explore relatives in a responsive React interface, open a person in a detailed modal, and manage the archive through familiar WordPress posts.

**Version 1.1.0** · **WordPress 6.5+** · **PHP 7.4+** · **GPL-3.0-or-later**  
Developed by **Raivis Kalnins**, web developer at [DigitalPulse](https://digitalpulse.click/).  
Project: [github.com/raivis-kalnins/wp-my-family-tree](https://github.com/raivis-kalnins/wp-my-family-tree)

![WP My Family Tree with its page title, oak tree, language switch and contact footer](docs/screenshot.png)

*The screenshot contains fictional demonstration records and illustrated portraits. No private family records or photographs are included in this plugin.*

## What is new in 1.1.0

- **Person details first.** Family people open with their portrait, dates, relationships, contact details and gallery form directly below the post title. Gutenberg is disabled for people by default and can be enabled in settings. Other posts and pages keep their normal editor.
- **Richer person profiles.** Add email, telephone, mobile phone, postal address, website, Facebook, Instagram, LinkedIn, X / Twitter, YouTube and TikTok links.
- **Private photo messages.** The Contact Us modal accepts one photo through a keyboard-accessible picker or drag and drop. The default limit is 10 MB, with size and supported formats configurable by the administrator.
- **Clearer form labels.** “Your full name” in English and “Vārds Uzvārds” in Latvian.
- **English and Latvian administration.** Person-field labels follow the person's Polylang language. A plugin-interface language selector also changes the plugin's labels without replacing unsaved names, biographies or form values.
- **Quick editing.** A small light-grey Edit button appears in a person's modal only when the signed-in WordPress account is allowed to edit that record.
- **Home Screen installation.** Android and Apple footer controls, a web-app manifest, a new oak favicon and `touch.png`, and 192 / 512 px app icons. Installed views continue to require an internet connection and the configured family access.
- **Cleaner header.** The brown decorative stripe has been removed.
- **One complete guide.** Installation, administration, privacy, troubleshooting and development notes are consolidated in this `readme.md`.

The earlier top-aligned photograph frames, Polylang page-language synchronization, contact-button spacing, and current/former partner fixes are included.

## Features at a glance

| Area | Included |
| --- | --- |
| Tree | Fluid oak layout, leaf portraits, pan, zoom, relative navigation, search and people directory |
| Person modal | Portrait, biography, dates, places, relationships, contact information, social links and gallery lightbox |
| WordPress content | Ordinary posts for people; a Family Tree block and `[wp_my_family_tree]` shortcode for the page |
| Theme integration | Optional full-window page with its own H1, header and contact footer; embedded mode for a theme layout |
| Languages | English and Latvian interfaces, translated plugin administration, native Polylang page links and optional biography translations |
| Privacy | Optional family password, remembered viewing access up to 30 days, server access checks and no-store responses |
| Photographs | Top-aligned portraits and optional encrypted family-media delivery |
| Contact | Independent recipient settings, optional hCaptcha, private single-photo attachment and WordPress mail delivery |
| Installation | Oak favicon, Apple touch icon, web-app manifest and Android / Apple footer help |
| Sharing | Optional tree PNG export and printing with the page title and contact footer |

## Update an existing installation

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
2. Select the complete `wp-my-family-tree-1.1.0.zip` archive and click **Install Now**.
3. When WordPress finds the existing plugin, choose **Replace current with uploaded**.
4. Open **Family Tree → Settings & help** to review the new editor, app-installation and contact-upload settings.

This release is a **plugin-only update**. No database import, theme replacement or UpdraftPlus restore is needed. The archive keeps the existing `wp-my-family-tree` plugin folder. Your person posts, relationships, uploaded photographs, stored password, settings and media-encryption key remain in the site's existing data. New settings receive their defaults automatically.

Use the complete installable ZIP for WordPress's replacement uploader. A patch or a ZIP containing only changed files is not a complete plugin package. Keep your usual site backup before any plugin update, particularly when the site uses encrypted photographs.

## First-time setup

1. Install and activate **WP My Family Tree**.
2. If the original **WP Family Tree** plugin is active, deactivate it to avoid competing shortcodes and older public endpoints. Retain its people and media.
3. Open **Family Tree → Settings & help**. Select the category containing family posts and choose a starting person.
4. Use **Create a tree page**, or put the **Family Tree** block / shortcode on an existing page and select it as the tree page.
5. Under **Privacy & access**, set a family password, or deliberately switch password protection off for a public tree. New private installations remain locked until a password is configured.
6. Under **Appearance**, set the English and Latvian titles and introductions, colours and optional installation controls.
7. Under **Contact & hCaptcha**, enter the family administrator's name and recipient email address. Review photo uploads and optional hCaptcha.
8. Open the page in a signed-out browser window to check the visitor experience.

The original WP Family Tree category is detected when available. Existing `father`, `mother`, `spouse`, `born`, `died` and `gender` metadata are reused; people do not have to be moved to another post type or assigned new IDs.

### Block and shortcodes

Insert the **Family Tree** block on a WordPress page. Its starting-person option accepts the person's post ID. The equivalent shortcodes are:

```text
[wp_my_family_tree]
[wp_my_family_tree root="123"]
[wp_my_family_tree view="list"]
```

Legacy `[family-tree]` and `[family-members]` shortcodes are supported for migration. A configured starting person removes the need for a name-based legacy shortcode.

Full-window mode supplies the tree page's complete layout, including its H1, header and footer, independently of the theme. Turn that setting off to place the tree inside the active theme's normal page. The page block remains available even when Gutenberg is disabled for person records.

## Add and edit people

Use **Family Tree → Settings & help → Add a person**, or edit an existing family post. The post title is the person's full name.

With the default editor setting, the person form appears immediately below the title. It includes:

- A portrait chooser using the post's featured image.
- Gender, birth and death dates, birth / maiden name, birthplace, place of death, location and occupation.
- Father / parent 1, mother / parent 2, primary and additional partners, and explicit partner statuses.
- Email, telephone, mobile phone, postal address and website.
- Facebook, Instagram, LinkedIn, X / Twitter, YouTube and TikTok profile URLs.
- A gallery picker for adding and reordering photographs.

The biography editor follows the form. Save or publish the WordPress post to save its details. The plugin retains an existing biography, including its block markup, when you only change person fields. If you edit the biography itself in the classic editor, it is saved using that editor's normal behaviour.

For a block-based biography workflow, enable **General → Enable the WordPress block editor for family people**. The person fields then appear as a metabox in the block editor. This setting does not change the editor used by unrelated posts or pages.

### Dates, contact details and photographs

Dates accept `YYYY`, `YYYY-MM` or `YYYY-MM-DD`. Leave the date of death blank for a living person. Existing legacy date text remains intact unless it is changed. Birth dates of living people can be hidden under **Privacy & access**.

Use full `https://` or `http://` URLs for websites and social profiles. Contact details are visible to everyone who can view the tree, including public visitors when password protection is off. Add details with the person's agreement. Empty fields do not produce empty contact cards in the modal.

Portraits and gallery previews align to the top of the available photograph. The lightbox shows the full image. If the original file was already cropped, upload an uncropped source to restore the missing area. Removing a photograph from a gallery removes its gallery reference; it does not delete the original media file.

### Current and former partners

In **Partner relationships**, select the other person and choose **Together**, **Divorced**, **Separated** or **Former partner**. Use **Together** for a current partner. The paired-rings icon means a current relationship and does not assume marriage.

| Recorded relationship | Tree behaviour |
| --- | --- |
| Together | Current partner appears beside the selected person, with paired rings |
| Divorced / separated / former | Former partner is excluded from the selected person's partner branch |
| Shared child after separation | Selecting that child still shows both recorded parents and their parent-to-child lines |
| Shared child without an explicit status | Parents remain neutral co-parents; no current-partner rings are inferred |
| New current partner | Does not become a child's parent unless explicitly selected in that child's parent fields |

A status recorded on either person applies to the pair. Saving one person does not silently edit the other person's post. If both records contain conflicting explicit statuses, a former status takes precedence; review both records and make them agree. Legacy spouse links without an explicit current status remain neutral.

### Edit from a person modal

A WordPress user who can edit the displayed person sees a small **Edit** button in the modal. It opens the appropriate post editor and preserves the selected plugin language. A shared family password only grants viewing access; it does not grant editing permissions or expose an edit link.

## English, Latvian and Polylang

English and Latvian are included. Without configured Polylang languages, the plugin's EN / LV control changes the interface and keeps the choice in the page URL.

To synchronize the tree with the theme:

1. Install and activate [Polylang](https://wordpress.org/plugins/polylang/).
2. Add English (`en`) and Latvian (`lv`) under **Languages**. The Latvian WordPress locale is `lv`.
3. Create English and Latvian tree pages, put the same block or shortcode on each, and connect them as translations in Polylang.
4. Set the English and Latvian titles and introductions in **Appearance**. Polylang string translations can override the registered title and introduction.
5. Keep the plugin language switch enabled. It opens Polylang's translated page URLs, so the theme and tree use the same page language.

Names and biographies remain exactly as entered; the plugin does not machine-translate them or duplicate people. A published linked person translation is used when available, with the original record as a fallback. Relationship metadata and the new contact fields are covered by `wpml-config.xml`.

An old `wpmft_lang` query parameter does not override a configured Polylang page language. This release supports same-origin language URLs, such as `/` and `/lv/dzimtas-koks/`. Separate language domains or subdomains are outside the supported viewing-session and origin-check setup.

### Language in the WordPress dashboard

Person-form labels initially follow the person's Polylang language, falling back to the WordPress user's language. Changing the Polylang selector updates plugin labels. The plugin also includes a **Plugin interface language** control on its settings and person forms; it changes plugin labels without replacing unsaved field contents.

The WordPress dashboard menus, core editor controls and theme use their own language configuration. For a Latvian dashboard throughout, set the WordPress user's language to Latvian and install the corresponding WordPress language pack. The plugin does not override another plugin's or WordPress's translations.

If Gutenberg is enabled for people, Polylang may save/reload the editor when changing a post's content language. Follow Polylang's confirmation prompt. The plugin's own interface-language selector only changes labels.

## Contact form and photo attachments

The footer's **Contact Us** button opens the contact modal. Set the recipient in **Contact & hCaptcha**. The form asks for the visitor's full name, email address, message and an optional photograph. The privacy text has space above the Send button.

### Upload settings

| Setting | Default | Options |
| --- | --- | --- |
| Photo attachment | Enabled | Enable or disable independently of the contact form |
| Maximum photo size | 10 MB | 1–100 MB, subject to lower server limits |
| Allowed formats | JPG, JPEG, PNG, WebP | Optionally GIF and AVIF when the server supports decoding them |
| Photos per message | One | Single optional attachment |

Visitors can drag a photograph onto the upload area or use its keyboard-accessible chooser. The form shows a preview, filename and size, with controls to remove or replace the photo. It retains the selected photo if sending fails.

The effective file limit is the lowest of the plugin setting and the hosting server's upload/request limits, with space reserved for the message and request fields. Raising the plugin setting cannot override a lower PHP or web-server limit. The form advertises the actual effective limit and formats supported by the server.

The server checks real image content, extension, file size and decoder support. SVG, executable files, renamed scripts and invalid images are not allowed. Very large pixel dimensions may be rejected even when a compressed file is below the byte limit.

The original photo is attached privately to the administrator's email. It is **not** published, added to the WordPress Media Library or automatically attached to a person's gallery. Temporary upload files are removed after processing, including failed sending attempts. The plugin does not save contact messages as family records. The mail provider or a separate mail-logging plugin may retain its own message and attachment copies.

### Mail delivery and hCaptcha

Messages use `wp_mail()` and the site's configured mail transport. A successful response means WordPress accepted the send operation; delivery to the recipient's inbox depends on the mail service. Verify the recipient and use a correctly configured SMTP transport if required by the host.

hCaptcha is optional and disabled by default. Supply your own site key and secret before enabling it on the contact form, password form or both. The browser loads hCaptcha only when configured; the secret is verified on the server. Leaving the secret field blank in settings preserves the stored value.

The form also retains server-side origin and CSRF checks, a honeypot, a minimum completion interval and rate limits. Review [hCaptcha privacy](https://www.hcaptcha.com/privacy) and [terms](https://www.hcaptcha.com/terms) when enabling that external service.

## Favicon and Home Screen installation

The included forest-green oak icon is supplied as `touch.png` (180 × 180), SVG, PNG favicons, an ICO file, 192 / 512 px app icons and a maskable Android icon.

Under **Appearance**, independently enable the oak favicon/touch icons and the footer's Android / Apple installation controls. The favicon changes only on family-tree pages; other pages retain the theme's site icon. The manifest uses the selected language's tree title and starting page, without including people, passwords, contact details or session tokens.

- **Android:** the footer button opens the browser's install prompt when available. Otherwise it shows instructions for the browser menu's install / Add to Home screen option.
- **iPhone / iPad:** the footer button shows instructions for opening the page in Safari, using Share → Add to Home Screen, and enabling Open as Web App where offered.
- **Already opened as an installed app:** the footer reflects the standalone installed state.

Browser installation requires HTTPS on a live site. Browser support and menu wording vary, so a custom native prompt is not available everywhere. This is an installable web view of the family page; it is not an App Store or Google Play package.

No service worker or offline family-data cache is registered. An internet connection is required. The installed app uses the same password protection and server access checks as the website. Apple/browser storage policies can require signing in again; the plugin does not promise that an existing browser session will transfer to a Home Screen installation.

References: [MDN installability guide](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable) and [Apple's Home Screen guide](https://support.apple.com/en-gb/guide/iphone/iphea86e5236/ios).

## Privacy, access and backups

A family password grants viewing access. Editing still requires a WordPress account with appropriate permissions. Set a password of at least 12 characters under **Privacy & access**. Remembered access can last up to 30 days and uses a server-validated cookie; the family password is not stored in browser local storage. Changing the password or using **Revoke all remembered access** invalidates existing viewing sessions.

Private pages, AJAX responses and protected media use no-store headers. Exclude the family page, private media endpoints and family AJAX requests from host/CDN page caches. Password-protected family records are excluded from public discovery routes. A public tree deliberately makes its selected details visible to visitors.

### Protect existing family photographs

The optional media-protection workflow encrypts linked family files and serves them through the family access check. **Protect existing family photos** processes older files in batches. The automatic-protection setting controls new files when people are saved; turning it off does not decrypt previously protected photographs.

Keep encrypted uploads and the database together in your usual backups: the database holds the encryption key required to read the files. Retain a complete original backup before protecting existing files. Restoring encrypted uploads without their matching database key cannot restore readable photographs.

Keep the plugin active while relying on its access controls. Original WordPress post statuses are preserved; disabling the plugin can make previously published person text accessible through normal WordPress routes. Use independent site access protection before maintenance that disables the plugin.

Old public photograph URLs, third-party copies and external caches are outside the plugin's control. Purge old caches and check previously public URLs after migration. Exported tree images and printed copies are separate copies of the information, governed by how their recipients share them.

## Troubleshooting

| Problem | What to check |
| --- | --- |
| The tree is locked after activation | Set a family password, then test signed out; WordPress editing access and visitor password access are separate |
| No people appear | Check the configured category, person checkbox, published status and starting person |
| A person still opens in Gutenberg | Disable the person block-editor setting and confirm the post is included as a family person; use Add a person for new records |
| The editor has an old photograph | Check the featured image and upload an uncropped original; framed images cannot recover an already cropped source |
| Latvian labels do not appear | Check the post's Polylang language or plugin-interface selector; configure EN/LV languages and linked tree pages for theme synchronization |
| A former partner still appears as current | Inspect partner statuses on both records; former statuses take precedence when they conflict |
| A child is missing a parent | Set the child's actual parent fields; a partner relationship alone does not create parentage |
| Uploads stop below the configured limit | Review PHP `upload_max_filesize`, `post_max_size`, host request limits and mail-provider attachment limits |
| A format is selected but not offered | The local WordPress image editor must support decoding that format |
| An apparently small image is rejected | Check actual type, file integrity and pixel dimensions; compressed size alone does not measure decoding cost |
| Contact mail is not received | Check recipient, spam folder, sender domain and WordPress/SMTP mail transport |
| No automatic installation prompt appears | Use HTTPS and the browser's install menu; Apple uses Home Screen instructions and some browsers do not provide an in-page prompt |
| Old icon or styling remains | Reload after the update; an already installed Home Screen icon may need to be removed and added again |
| A protected photograph is missing | Confirm that the matching encrypted uploads and database key are present; preserve the original backup while investigating |
| The theme header is still visible | Enable full-window mode and verify that the selected page contains the supported block or shortcode |

## Īsā pamācība latviski

1. Atjauniniet spraudni sadaļā **Spraudņi → Pievienot jaunu → Augšupielādēt spraudni**, izvēloties `wp-my-family-tree-1.1.0.zip`. Aizstājiet esošo spraudni. Datubāzes imports un motīva nomaiņa šim atjauninājumam nav vajadzīga.
2. Spraudņa iestatījumos izvēlieties dzimtas ierakstu kategoriju, sākuma personu un koka lapu. Lapā ievietojiet bloku **Family Tree** vai īskodu `[wp_my_family_tree]`.
3. Personas rediģēšanā detalizētā forma atrodas uzreiz zem virsraksta. Virsrakstā norādiet personas pilnu vārdu, izvēlieties portretu, vecākus, partnerus, datumus un galeriju. Dzīvesstāsta redaktors atrodas zem formas. Bloku redaktoru personām pēc vajadzības var ieslēgt spraudņa iestatījumos.
4. Pievienojiet e-pastu, tālruni, mobilo tālruni, adresi, mājaslapu un sociālo tīklu saites tikai tad, ja persona piekrīt tās kopīgot ar koka apmeklētājiem.
5. Pašreizējam partnerim izvēlieties statusu **Kopā**; bijušajam partnerim norādiet atbilstošu šķiršanās statusu. Bērna kokā saglabājas abi norādītie vecāki. Jauns partneris nekļūst par bērna vecāku automātiski.
6. Polylang iestatiet angļu un latviešu valodu, sasaistiet tulkotās koka lapas un ievietojiet tajās vienu un to pašu īskodu vai bloku. Personas formas uzraksti seko personas valodai. Spraudņa valodas pārslēgs maina uzrakstus, saglabājot ievadītās lauku vērtības un nesaglabātos personas datus.
7. Kontaktformā tiek prasīts **Vārds Uzvārds**. Var pievienot vienu fotogrāfiju līdz 10 MB pēc noklusējuma. Administrators var mainīt izmēru un atļautos formātus; servera ierobežojumi joprojām ir spēkā. Fotogrāfija tiek nosūtīta e-pastā, nevis publicēta multivides bibliotēkā.
8. Kājene piedāvā Android un Apple instalēšanas iespējas. iPhone / iPad ierīcē izmantojiet Safari kopīgošanas izvēlni un **Add to Home Screen**. Dzimtas datiem nepieciešams interneta savienojums.
9. Privātam kokam iestatiet vismaz 12 rakstzīmju paroli. Piekļuvi var atcerēties līdz 30 dienām. WordPress lietotājs ar rediģēšanas tiesībām personas logā redz nelielu rediģēšanas pogu.
10. Šifrētos attēlus un datubāzi vienmēr dublējiet kopā, lai saglabātu attēlu atšifrēšanas atslēgu.

## Source, repository and licensing

All runtime PHP, JavaScript and CSS source is included and readable. The frontend uses WordPress's bundled `wp-element` React APIs. No JavaScript build step is required.

| Path | Purpose |
| --- | --- |
| `wp-my-family-tree.php` | Plugin entry point, version and WordPress hooks |
| `includes/` | People, relationships, access, private media, contact uploads, AJAX, app manifest and administration |
| `assets/app.js`, `assets/app.css` | React tree, dialogs, contact form and responsive styles |
| `assets/admin.js`, `assets/admin.css` | Person editing, live labels and settings |
| `assets/install.js` | Captures the browser install prompt without offline storage |
| `assets/icons/`, `touch.png` | Original oak artwork, favicon and installation icons |
| `assets/editor.js`, `assets/editor.asset.php`, `block.json` | WordPress page block and dependencies |
| `templates/standalone.php` | Optional full-window template |
| `languages/` | Translation sources, Latvian MO and block translation catalogs |
| `wpml-config.xml` | Person-field translation and synchronization rules |
| `docs/screenshot.png` | Fictional demonstration screenshot rendered above on GitHub |
| `readme.md`, `LICENSE` | This guide and the complete license |

Preserve this folder structure when replacing the public Git repository. Do not flatten includes, assets or language files into the repository root. For an installable ZIP, place these files inside one `wp-my-family-tree/` directory. This archive intentionally uses `readme.md` only and contains no `readme.txt` or separate `HELP.txt`.

Keep databases, real family media, Updraft backup sets, passwords, keys, test credentials and production configuration out of the public repository. The included screenshot and icon artwork are suitable for the public project. Preparing this package does not publish it to GitHub or submit it to the WordPress.org directory.

Copyright © 2026 **Raivis Kalnins / DigitalPulse**. Licensed under the [GNU General Public License, version 3 or later](LICENSE). Icon artwork is included under the same license. Legacy-data compatibility refers to WP Family Tree by Arvind Shah; this is a separate plugin using compatible existing data.

## Changelog

### 1.1.0

Person-first classic editing with optional Gutenberg; contact and social profile fields; translated plugin administration; capability-checked modal edit links; configurable private photo attachments; full-name form labels; oak icons and web-app installation controls; cleaner header; consolidated Markdown documentation.

### 1.0.2

Explicit current, divorced, separated and former partner statuses; rings only for current partners; former partners hidden from each other's own tree while preserving both recorded parents in their child's tree; no inferred step-parent links.

### 1.0.1

Top-aligned image frames and proportional portraits; Polylang page-language synchronization; translated page URLs; contact-button spacing; populated people directory; public fictional screenshot and repository documentation.

### 1.0.0

Initial oak-tree interface, legacy post-data compatibility, person modals and galleries, private viewing access, English / Latvian interface and independent contact settings.
