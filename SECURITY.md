# Security and privacy

My Family Tree stores family stories and relationships in the site's WordPress database. It is designed to keep viewing access separate from editing permission.

## Access model

- Normal family viewing can require a password of at least 12 characters. A shared family password grants viewing access only.
- WordPress editing remains capability-checked for each person. The modal Edit link is shown only to an authorized account and opens a new tab.
- Private tree pages, AJAX responses and protected media send no-store directives. Host/CDN caches must also respect or explicitly bypass these routes.
- Protected family posts are excluded from public discovery paths while protection is active. Original WordPress post statuses are preserved.
- The plugin must remain active for its access checks to apply. Use independent site access protection before disabling it when published records are private.

## Media and messages

Optional family-media protection encrypts files and serves them after a family-access check. Back up the database and encrypted uploads together: the database contains the key needed to read those files. Existing public copies and caches are outside the plugin's control.

Contact photographs are validated for type, extension, size and decoding support. They are temporary email attachments and are not published or added to the Media Library. Contact messages use the site's mail transport. External mail services and mail-logging plugins can retain their own copies.

hCaptcha is off by default. Enabling it sends challenge information to hCaptcha; the exact endpoints and data are disclosed in readme.txt and readme.md. The plugin has no telemetry or author-operated API.

## Demo sharing

The documented demo account is created only after explicit administrator confirmation on an isolated single-site demo. Its fixed published credentials are intentional sample access, not a default administrator account or access to an ordinary family tree.

The custom demo role can use only a constrained editor for marked sample people/pages. It does not receive standard WordPress Editor privileges, upload access, user management or plugin settings. Demo editing removes dynamic blocks and shortcode syntax and sanitizes permitted story markup.

Real family records, including drafts and trash, block demo activation. If isolation fails later, shared sessions are revoked and demo access is locked. Demo cookies and generations are separate from ordinary family sessions. Enabling demo mode does not replace the normal family password hash. Disabling it or deactivating the plugin revokes its shared account and sessions.

Public demo story edits are visible to visitors and are restored to a retained starting copy every 24 hours while sharing is active. Only marked demo records and the fields the shared editor may change are eligible. Reset operations validate live ownership and isolation, and stale editor forms cannot reapply edits from an earlier reset cycle. The scheduled task is removed when sharing is disabled or quarantined, and on plugin deactivation. An overdue access check covers delayed cron execution. The demo is intended for a separate demonstration installation.

## Cache scope

The relationship cache holds numeric relationships and translation/status metadata, not full biographies, media, contact details, viewing tokens or authorization decisions. Cache clearing is restricted to administrators, requires a nonce and clears only this plugin's performance namespace. Persistent object storage is supplied by WordPress/hosting infrastructure; this plugin does not overwrite the site's object-cache drop-in.

## Reporting a suspected vulnerability

Use the repository's private vulnerability-reporting option if it is available. Otherwise, contact the maintainer through the public project profile and request a private reporting channel before sending sensitive details:

https://github.com/raivis-kalnins/wp-my-family-tree

Include the affected plugin version, WordPress/PHP versions, a minimal reproduction using fictional records, the expected access boundary and the observed result. Do not post live passwords, encryption keys, private family records, unredacted logs or exploitable production URLs in a public issue.

No response-time or security-certification guarantee is made by this document.

## Birthday and Latvian name-day privacy

Birthday reminders are calculated inside WordPress from the saved person records. Birthday email is optional and sends only to the email address saved on that living person's record; the plugin records the last successful birthday-email year to avoid duplicates.

When Latvian name days are enabled, the plugin reads its bundled local Latvian name-day data. No family names, dates, contact details, passwords or visitor identifiers are sent to a remote name-day service. Name days never trigger email.
