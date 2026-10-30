=== Update Airlock ===
Contributors: lenrique5454
Tags: updates, rollback, backup, security, maintenance
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks every plugin and theme update for risky code changes, keeps a snapshot, tests your site afterward and rolls back when a check fails.

== Description ==

WordPress restores an automatic update when it causes a PHP fatal error. Update Airlock adds a code-change report before each update, a snapshot, checks of the pages you choose afterward, and a rollback when they fail. Everything runs on your own site: no account, no service, no headless browser.

**Before the update: a report of what changed**

Update Airlock compares the installed version with the new one and lists the risky additions it finds, in plain language, for example "adds 2 outbound hosts: example.com, example.net" or "unserializes remote data". It reads the code of the new version as text. Nothing from the new version is included or run.

* Code that runs strings as code, shell commands, remote includes, deserialization of remote or request data.
* Chains of decoding functions, long packed strings, PHP code hidden in picture or text files, new PHP files in asset folders.
* New outbound hosts in PHP and JavaScript.
* Writes to wp-config.php, .htaccess and mu-plugins, new administrator accounts, hidden users, open REST routes, changes to update information.
* A changed author, author link, contributors list or Update URI, and a changelog that only mentions compatibility next to a large code change.

Each update is rated Low, Review or High. Low-risk updates stay quiet. A side-by-side diff shows every changed file with the flagged lines marked. You can inspect an update from its row on the Plugins screen, from the Updates screen, or from Tools > Update Airlock, and every update is also inspected as it installs, whether it was started by you, in bulk, automatically or by uploading a zip.

By default, an automatic update rated High is held for review, and a manual update rated High asks you to confirm in the report before it proceeds. Both are settings.

**During the update: a snapshot**

Before each plugin or theme update, the installed folder is saved as a zip with a list of file hashes, in a folder inside wp-content that has a random name and, on Apache and IIS, rules that deny direct access. On nginx those rules do nothing, so only the random folder name protects the files; the saved plugin options can contain keys, so deny that folder in your nginx configuration if you can. The number of snapshots kept per plugin or theme is a setting. If a snapshot cannot be made, the update is cancelled (you can turn that off).

**After the update: checks and rollback**

Update Airlock requests your own pages (home page, latest post, a page, a category, the login page, the REST index, the admin dashboard when you are signed in, WooCommerce shop pages when active, and any addresses you add in Settings, with no limit) and compares them with a baseline taken earlier: HTTP status, PHP fatal errors on the page, the structure of the home page, load time, and whether scripts and stylesheets from the updated code load. When a readable error log exists it also looks for new fatal errors from the updated plugin or theme.

When a blocking check fails, the snapshot is restored, the restored files are verified against their hashes, the failed version is held back from automatic updates, and the site admin is emailed the evidence. A newer version clears the hold. If your host does not let the site request its own pages, checks are marked inconclusive, never passed, and the snapshot is kept.

**More**

* History screen with filters and CSV or JSON export, dashboard widget, Site Health tests.
* One-click restore of any kept snapshot, with an option to put back the option values saved with it.
* No tracking, no accounts, nothing is sent to the author.

**What it is not**

Update Airlock reads what changed between two versions. It is not a malware scanner. It will miss code that was already there, and code that is hidden well. The report helps you decide; it does not decide for you.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from Plugins > Add New.
2. Activate it. A short welcome checks your server, captures a baseline of your pages and inspects the updates that are already waiting.
3. Open Tools > Update Airlock to see updates, reports, history and snapshots. Settings has the policy for High-rated updates, the number of snapshots to keep and extra pages to check.

== Frequently Asked Questions ==

= Is this a malware scanner? =

No. It reads the code changes of an update and points at risky additions. It does not scan your site and it can miss code that was already present or well hidden.

= Does it send my code anywhere? =

No. Everything is read and stored on your server.

= Does it download code? =

It downloads only the package WordPress itself is about to install, from the address WordPress already holds for that update, when you press Inspect. The package is unpacked in a temporary folder, read as text, never included or run, and the folder is deleted afterward. During a normal update it reads the files WordPress has already unpacked.

= What if my host blocks requests from the site to itself? =

The checks cannot run. They are marked inconclusive (never passed), the snapshot is kept, you get an email, and Site Health explains the cause.

= Does it work with premium plugins? =

Yes at install time: the files WordPress unpacked are inspected whatever their origin. The Inspect button before the update needs a download address from WordPress, which some premium plugins do not provide.

= Does it work on FTP hosts? =

All file writes go through the WordPress filesystem API. If WordPress needs file credentials and has none stored, a snapshot cannot be made and the update is cancelled (a setting), and a restore needs you to restore by hand.

= What does "held" mean? =

A held version is skipped by automatic updates. It shows as "Held by Update Airlock" in its update row, with a link to the report. Release it in Tools > Update Airlock when you have reviewed it. A newer version clears the hold by itself.

= How do I restore by hand if WordPress itself will not load? =

Open `wp-content/updlock-snapshots/` with an FTP client. The folder inside has a random name. Each snapshot is a zip named by its id, with a JSON file of the same name that lists the plugin and version. Unzip it over the plugin or theme folder.

= Does it work with multisite? =

Yes. Network-activate it (it cannot be activated for one site). The screen is under Network Admin > Settings > Update Airlock, and network administrators use it. Snapshots, history and settings are shared by the whole network, and the checks run against the main site and the pages you list for it.

== External services ==

Update Airlock does not use a service run by its author and sends no data to one.

It makes two kinds of requests:

1. When you press Inspect before an update, it downloads the update package from the address WordPress holds for that update, usually downloads.wordpress.org or the address the plugin or theme author declared. This is the same file WordPress downloads to install the update. Only the request for the file is made; no site data is sent. For wordpress.org: [Terms](https://wordpress.org/about/tos/) and [Privacy](https://wordpress.org/about/privacy/).
2. For the checks, it requests pages of your own site and the files of the updated plugin or theme (loopback requests). They go to your own site address and send no data elsewhere.

== Changelog ==

= 1.0.0 =
* First release: update inspector with side-by-side diff, snapshots, page checks, automatic rollback, holds, email alerts, history with export, dashboard widget, Site Health tests and multisite support.
