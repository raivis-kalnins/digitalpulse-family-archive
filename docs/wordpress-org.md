# WordPress.org submission and release notes

This package includes a WordPress.org-format readme.txt, a detailed Markdown guide, readable source, prebuilt production assets, licensing and external-service disclosures. Preparing these files does not submit or publish the plugin, and does not mean the WordPress.org Plugins Team has approved it.

## Package metadata

| Item | Value |
| --- | --- |
| Plugin name | WP My Family Tree |
| Plugin slug / text domain | wp-my-family-tree |
| Version / stable tag | 1.2.2 |
| Minimum WordPress | 6.5 |
| Minimum PHP | 7.4 |
| WordPress runtime used for current local checks | 6.8.3 |
| License | GPL-3.0-or-later |
| Author | Raivis Kalnins / DigitalPulse |

**Tested up to** records actual testing, not an assumption that a newer WordPress release was tested. Run release checks on the latest supported WordPress/PHP environment before submitting or updating that field.

The publisher's **WordPress.org username has not been supplied or verified**. The Contributors header is therefore omitted rather than populated with a guessed GitHub username. Before submission, add the verified WordPress.org user ID(s) to readme.txt; the account used for submission must be controlled by the publisher. The PHP Author field already identifies the supplied author.

## Public-source and license review

The release contains readable PHP, JavaScript and CSS alongside generated production files. Package metadata and scripts/build-assets.mjs pin esbuild 0.25.5 and describe the build. Run npm ci and npm run build with Node 18 or newer when rebuilding; do not package node_modules.

The frontend uses the React runtime supplied by WordPress. It does not load a runtime from a CDN. Oak artwork, icons, demo monograms and landscapes are original assets distributed under the plugin's GPL license. See assets/demo/README.txt. Fictional character references are illustrative; no movie stills, studio logos, celebrity photographs or real family records are included.

The package must exclude local QA fixtures, error logs, database exports, hosting configuration, credentials, backups and private media. The intentionally documented demo password is activated only through the isolated opt-in workflow; it is not a production credential.

## External-service and consent review

hCaptcha is the only optional remote verification service used by the plugin. It is disabled by default, requires administrator-supplied keys and is disclosed with browser/server endpoints, sent fields, terms and privacy links in the directory readme.

Email uses WordPress's configured mail transport. Social/website/author links are ordinary visitor-activated links. The frontend author credit is off by default. No telemetry, unsolicited mail, remote executable updater or automatic Polylang installation is included.

## Before submitting

1. Add the verified WordPress.org contributor username and confirm ownership of the intended repository/name.
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
