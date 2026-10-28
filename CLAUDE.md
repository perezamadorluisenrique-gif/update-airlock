# Update Airlock

Repo facts only; the wordpress.org rules (skill `wprequirements`) win, and Enrique's general rules are the Claude project instructions. Slug and text domain `update-airlock`, prefix `updlock` / `UPDLOCK_` / namespace `Updlock\` (PSR-4 autoloader in `update-airlock.php`).

## Commands

See README. Before "done": `vendor/bin/phpcs`, `vendor/bin/phpunit`, `bash bin/build.sh` then `bin/gate.sh` on the build, `bash bin/e2e.sh` on 7.1.3 and `WP_VERSION=6.6` (Actions minutes are exhausted, so every gate runs locally).

## Invariants

- Snapshot at `upgrader_pre_install` priority 5 (before core's deactivation at 10); inspection at `upgrader_source_selection` priority 20. A `WP_Error` from either aborts the install, so our own abort reactivates the plugin core already deactivated.
- Bulk updates (WP-CLI, auto-updates) pass `hook_extra` without `type`: `Hooks::item()` infers it from the `plugin`/`theme` key.
- Schema changes only through `Activator::maybe_upgrade()` (`updlock_db_version`). Column `trigger_by`, not `trigger` (reserved).
- Restore goes through a work folder, hash check, trash folder and the `updlock_swap` record so `Restorer::recover()` can finish an interrupted swap. Never delete the current copy before the restored one verifies.
- A check that could not run is inconclusive, never passed. Only 5xx, new fatals, a collapsed home page and fatal log lines block; the rest warn.
- Dangerous function names appear only as strings in rule tables, never as calls (the gate's RISKY_CALLS check).
- No Pro code, no usage caps, no translation files, no `load_plugin_textdomain()`, CSS selectors prefixed `.updlock-`, no `<script>`/`<style>` literals in PHP.

## e2e traps

- Core refuses automatic updates inside a git checkout; the mu-plugin fakes `automatic_updates_is_vcs_checkout`.
- WP-CLI `plugin install --force` is not the upload screen's call; the upload scenario uses `Plugin_Upgrader` with an overwrite skin.
- Requests-based parallel checks bypass `pre_http_request`, so `Runner::fetch()` goes sequential when that filter is in use (that is how the blocked-loopback scenario works).
- Do not leave a stray `php -S` on the e2e ports.
