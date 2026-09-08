# Chengyu 0.19.0 — English quick start

New installs: open DEPLOY_PREPARE.html locally, generate your own key, and upload key.php to install/key.php before using the installer. Public packages contain no shared installation secret. Upgrade 0.18 without reinstalling; keep config.php, secret, database and storage. Current database schema: v11. See TEST_REPORT.md for current native runtime evidence.

The following describes retained 0.18 features and historical limits, not the current verification summary.

This release extends the uploaded **0.17.0** independent PHP application. Database schema is **v10**; the installation configuration format remains 1. It is not WordPress and does not contain the proprietary reference theme. Complete historical feature equivalence is not claimed.

## Install or upgrade

Upload only the upload ZIP contents into the public website root. Keep the complete package, tests, recovery tools and INSTALL_KEY.txt private. Open `/install/`, supply the private installation key, your database and administrator credentials. Remove the installer after successful installation. Required: 64-bit PHP, native PDO MySQL or SQLite, OpenSSL, Fileinfo, writable protected storage and effective web-server directory restrictions. SQLite must be outside the public web root.

For an existing site, pause writes and scheduled financial jobs; consistently back up the database, code, original app/config.php, its secret and all storage. Preserve configuration and storage while updating. Do not reinstall. Rollback requires matching database/code/secrets/storage and reconciliation of money already moved externally.

PHP 7.4–8.5 use the same business paths. Only PHP 8.4.23 was actually executed here, with a test-only adapter to real SQLite, not native PDO/MySQL. Runtime Composer, Node, Redis, FFI, SSH and permanent workers are not required. The optional CLI recovery workflow needs a separate compatible local/VPS environment when the shared host has no SSH.

## New workspaces

**External search:** optional Meilisearch over validated public HTTPS on port 443. Use a dedicated index beginning with cy_, an index-scoped server-side key and explicit consent to publish public search metadata. No paid bodies, private notes or support messages are indexed. Enqueue bounded batches and process the outbox from the admin panel; accepted asynchronous tasks are not marked complete until confirmed. Local permissions and content versions are rechecked after remote ranking, and local search remains the fallback. Changing servers leaves old remote data under the operator's control. This is not the full Meilisearch facets/synonyms administration UI.

**Multiple parcels:** up to 20 independently tracked parcels per paid physical order, including revisions, duplicate-submission protection and ownership checks. Each parcel has its own manual/provider events. This does not allocate item quantities, print shipping labels, manage warehouses or automatically confirm receipt or settle money.

**Recovery vault:** password-protected database snapshots with optional completed local media, bounded by size and execution time. Download the archive and keep its independent password separately. Source code, database connection password, unfinished uploads and cloud object bytes are not included. The encrypted archive contains the original application secret and sensitive account/commerce data; treat both archive and password as confidential.

Use `tools/recover-backup.php` only in a separate private CLI environment. It authenticates the whole archive before writing and requires an empty target, the same application/schema version and the same database engine. Read the password on standard input, not as a command-line argument. Restore defaults to maintenance mode, pauses jobs and disables external services; verify balances, credentials and original cloud buckets before re-enabling. This is not a cross-engine converter, automatic offsite backup or one-click live-site overwrite. See BACKUP_RECOVERY.md for exact commands and failure cleanup.

**Verification limits:** independent IP/recipient fixed-window limits for ten minutes, one hour and one day, plus a true elapsed cooldown. All checks reserve quota atomically. This extends email verification only; it does not add missing SMS providers.

## Existing features and limits

Courses, private progress/notes, layout drafts/history, support tickets, local indexed search, normalized CSV reconciliation, Alipay WAP/PAGE, WeChat H5, Stripe Checkout and PayPal digital checkout remain. PayPal Payouts requires review and a real account. Financial tasks need actual scheduling or manual admin execution. No implicit currency conversion is performed.

Frontend and admin locale preferences are independent; merchant-authored content is not automatically translated. The new workspaces support Chinese/English and reduced-motion preferences. Third-party services, native databases, real shared hosts, full browser URL navigation and all seven PHP runtimes were not verified here. Use ACCEPTANCE_018.md before accepting real payments.

See PLATFORM_018.md, BACKUP_RECOVERY.md, FEATURE_MATRIX.md and TEST_REPORT.md. Read START_HERE.html from the complete package for the full offline manual.