# WordPress.org submission and release notes

This package includes a WordPress.org-format readme.txt, a detailed Markdown guide, readable source, prebuilt production assets, licensing and external-service disclosures. Preparing these files does not submit or publish the plugin, and does not mean the WordPress.org Plugins Team has approved it.

## Free plugin distribution

DigitalPulse Family Archive is prepared for free distribution through the [WordPress.org Plugin Directory submission form](https://wordpress.org/plugins/developers/add/). All included plugin features are available free of charge, with no paid plugin license or subscription required. The complete source is distributed under GPL-3.0-or-later. Optional integrations remain subject to their providers' terms.

### Submission description

DigitalPulse Family Archive is a free, open-source WordPress plugin for creating interactive family trees and preserving family stories. It provides person profiles, biographies, photo galleries with mobile swipe navigation, relationship browsing, multilingual interface labels, optional password-protected viewing, a contact form with photo attachments, fullscreen tree viewing and optional demonstration content. Add a Family Tree block or shortcode to a page and manage people through ordinary WordPress posts. All included plugin features are available free of charge under GPL-3.0-or-later, with no paid plugin license or subscription required.

### Reviewer notes

This submission contains the complete installable plugin, readable PHP, JavaScript and CSS sources, prebuilt production assets, build instructions and license information. It has been verified on WordPress 7.1.3; the readme uses the corresponding directory header, `Tested up to: 7.1`.

The plugin has no telemetry, author-operated service or paid feature gate. Polylang is optional for content translations. hCaptcha is optional, disabled by default, and documented with its endpoints, transmitted data and service/privacy links in readme.txt. Demonstration content and shared demo access require separate administrator opt-in; no shared demo account is created on ordinary activation.

## Package metadata

| Item | Value |
| --- | --- |
| Plugin name | DigitalPulse Family Archive |
| Proposed plugin slug / text domain | digitalpulse-family-archive |
| Version / stable tag | 1.3.2 |
| Minimum WordPress | 6.5 |
| Minimum PHP | 7.4 |
| Tested up to (directory header) | 7.1 |
| WordPress runtime used for release verification | 7.1.3 |
| License | GPL-3.0-or-later |
| Author | Raivis Kalnins / DigitalPulse |

**Tested up to** records actual testing, not an assumption that a newer WordPress release was tested. Run release checks on the latest supported WordPress/PHP environment before submitting or updating that field.

The publisher's **WordPress.org username has not been supplied or verified**. The Contributors header is therefore omitted rather than populated with a guessed GitHub username. Before submission, add the verified WordPress.org user ID(s) to readme.txt; the account used for submission must be controlled by the publisher. The PHP Author field already identifies the supplied author.

## Compatibility metadata for resubmission

Version 1.2.4 updates `Tested up to` to `7.1`, the major release branch used by the directory. Release verification uses the official WordPress 7.1.3 archive. The previous local test environment blocked external HTTP, and Plugin Check skipped its version comparison when current release information was unavailable. The release check now requires current official version data and verifies that the former 6.8.3 header is rejected before checking the corrected header.

Version 1.2.5 retains that compatibility header and fixes gallery retention, portrait controls and the language selector. Person-oriented dashboard labels are enabled by default and can be disabled in General settings. The shared demo dashboard now supports adding, editing and removing demo persons and pages, with a read-only settings overview. Its daily reset restores original samples and removes verified visitor-created records. The plugin continues to store people as ordinary posts; existing family records retain their IDs and relationships.

Version 1.2.6 makes saved gallery selections authoritative, including empty selections, and removes automatic portrait insertion from saved galleries. The person editor exposes inherited and legacy photographs so the editable and displayed lists agree. Explicit translated selections take precedence over inherited content. The private-media reference list remains separate and comprehensive, preserving detection, authorization, ownership recording and encryption for images outside the visible gallery. This update needs no database restore and does not delete media files.

Version 1.2.7 adds optional outbound WhatsApp links, per-person photo stories and dates, responsive image loading and capability-aware modern display copies. No external image optimizer or embedded chat script is used. Image conversion is queued or administrator-triggered, with encrypted derivatives and ordinary-format fallback. The demo editor uses the regular visual structure while retaining sample-only permissions and daily reset. Demo tools are optional in General after initial setup.

Version 1.2.9 fixed cached portrait loading state, authenticated fallback for missing ordinary thumbnails, and optimizer queue starvation after a failed photo. Manual optimization handles one image per request, reports failed attachment IDs without blocking later images, and refreshes pending counts without performing conversions. Missing optimized files can be rebuilt. Existing original media, gallery membership and authorization remain protected.

## Name correction for resubmission

Version 1.2.3 changes the PHP `Plugin Name` header and directory readme title to **DigitalPulse Family Archive**, addressing the reviewer's objection to the former leading “WP”. The installable ZIP is `digitalpulse-family-archive-1.4.0.zip`, with one `digitalpulse-family-archive/` folder, the `my-family-tree.php` entry point and the matching `my-family-tree` text domain. Directory acceptance and the final permalink remain subject to the Plugins Team's review.

The source repository remains at `https://digitalpulse.click/`. That existing repository URL is not the proposed WordPress.org permalink. Internal `wpmft` data keys, existing shortcodes and the saved block identifier `wp-digitalpulse-family-archive/tree` are retained for compatibility.

For existing sites, deactivate **WP DigitalPulse Family Archive** before installing and activating **DigitalPulse Family Archive**. The changed folder is a separate WordPress plugin, so it is not an in-place uploader replacement. See the detailed migration steps in readme.md, including backups, private-site access during the switch, verification and optional removal of the old inactive copy. Deactivation revokes shared demo access; re-enable it only when appropriate after migration.

## Public-source and license review

The release contains readable PHP, JavaScript and CSS alongside generated production files. Package metadata and scripts/build-assets.mjs pin esbuild 0.25.5 and describe the build. Run npm ci and npm run build with Node 18 or newer when rebuilding; do not package node_modules.

The frontend uses the React runtime supplied by WordPress. It does not load a runtime from a CDN. Oak artwork and icons are original assets distributed under the plugin's GPL license. Demo import can optionally fetch a fixed set of public-domain archival photographs from Wikimedia Commons; source and licence details are documented in docs/THIRD-PARTY.md. Demo media refresh does not silently substitute placeholder portraits: all allowlisted archival photos must download and validate before replacement. Demo names, dates, biographies and relationships are fictional, and no real family records are included.

The package must exclude local QA fixtures, error logs, database exports, hosting configuration, credentials, backups and private media. The intentionally documented demo password is activated only through the isolated opt-in workflow; it is not a production credential.

## External-service and consent review

hCaptcha is the only optional remote verification service used by the plugin. It is disabled by default, requires administrator-supplied keys and is disclosed with browser/server endpoints, sent fields, terms and privacy links in the directory readme.

Email uses WordPress's configured mail transport. Social/website/author links are ordinary visitor-activated links. The frontend author credit is off by default. No telemetry, unsolicited mail, remote executable updater or automatic Polylang installation is included.

## Before submitting

1. Add the verified WordPress.org contributor username and confirm the intended repository/name. For this resubmission, verify that the plugin header uses DigitalPulse Family Archive and that the ZIP root, entry point and text domain use digitalpulse-family-archive.
2. Run the official readme validator and current Plugin Check, review findings and keep the actual results with the release process.
3. Test activation, updating, signed-out privacy, protected media, demo isolation, language changes, gallery gestures and fullscreen on the intended WordPress/PHP versions.
4. Keep the main PHP version, Stable tag, package metadata, block metadata and generated assets synchronized.
5. Upload the complete installable ZIP through the official submission form. The Plugins Team may request changes; this package does not claim approval.
6. After approval, publish through the WordPress.org repository and tag workflow supplied to the verified maintainer. GitHub publication alone does not create a directory listing.

screenshot-1.png provides the packaged screenshot for the directory. A maintainer can also supply appropriately named banners, icons and updated screenshots in the WordPress.org repository's assets directory.

## Official references

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Plugin readme format and parsing](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Common review issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
- [Readme validator](https://wordpress.org/plugins/developers/readme-validator/)
- [Plugin Check](https://wordpress.org/plugins/plugin-check/)
- [Submit a plugin](https://wordpress.org/plugins/developers/add/)


## 1.3.2 birthday and calendar update

Version 1.3.2 adds a family birthday calendar, optional once-per-year birthday email for living relatives with an email address, Latvian name-day notices, and clearer sibling grouping in the tree. Name days do not trigger email. The optional Latvian name-day feature uses a bundled local Latvian name-day dataset; no family or visitor data is sent to a remote name-day service.
