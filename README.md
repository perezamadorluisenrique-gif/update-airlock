# Update Airlock

Free WordPress plugin: reads the code changes in each plugin and theme update, snapshots the old copy, checks the site afterward and rolls back when a check fails. It is not a malware scanner; it shows what changed and helps you decide.

- Inspector: line diff of added code, about 30 rules (execution, deserialization, obfuscation, persistence, new outbound hosts, privilege changes, JS) and provenance signals (author, links, changelog).
- Holds: a High-rated automatic update is held; a High-rated manual update asks for confirmation first.
- Safety net: snapshot before install, loopback checks after, automatic rollback, email.
- Screens under Tools > Update Airlock, update-row badges, dashboard widget, Site Health, CSV/JSON export.

wordpress.org listing text: `readme.txt`. Requires WordPress 6.6 and PHP 7.4.

## Develop

```bash
composer install
vendor/bin/phpcs            # WordPress + PHPCompatibilityWP 7.4-
vendor/bin/phpunit          # rules and differ
bash bin/build.sh           # build/update-airlock + build/update-airlock.zip (from .distignore)
RISKY_CALLS="..." bash bin/gate.sh build/update-airlock updlock $PWD/build/update-airlock.zip
bash bin/e2e.sh             # throwaway WordPress on SQLite; WP_VERSION=6.6 for the oldest
```

`bin/e2e.sh` needs php with pdo_sqlite and zip, git, curl, and Node with Playwright (`NODE_PATH` to the global install) for the screen walkthrough; `SKIP_UI=1` skips it.
