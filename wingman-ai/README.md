# Wingman AI for Pelican

A console assistant that watches server starts, crashes, and installs, explains what went wrong, and gives steps to fix it, with one-click fixes for common problems. It can also check for lag, answer questions about a server, and (for admins) suggest fixes to a server's egg. It uses the selected server's egg, available startup settings, and console output to identify the application rather than assuming a particular game.

This release targets **Pelican Panel 1.0.0-rc.1**, its plugin API 1, and PHP 8.4/8.5. The manifest deliberately restricts installation to that panel version because Pelican's console and internal services can change between releases. An older panel needs an adapted build; do not remove the version restriction just to force installation.

## What it does

**Automatic startup protection is enabled by default.** Start your server using Pelican's normal controls, an API request, or a schedule. Wingman AI observes those start/restart requests, receives authenticated Wings state changes, and watches startup through background jobs, even while your browser is closed. It never sends a power action from the automatic watcher.

Successful starts stay quiet after a short running observation. If startup fails or reaches the configured watch timeout, the watcher retrieves the latest Wings console lines, prepares a report, and makes it available on the server's **Console** page. The console refreshes the automatic status and latest report every ten seconds. A timeout is an unconfirmed result, not proof of a crash.

Open **Plugins → Wingman AI → Settings** to disable automatic protection or change its limits. The default is at most 20 automatic reports per server in a 24-hour window, with at least five minutes between reports, to limit repeated reports during crash loops. AI calls are made only for automatic failure or timeout reports. Basic checks are used when AI is disabled, unconfigured, or unavailable.

The background watcher requires Pelican's working **asynchronous queue worker** and Wings state callbacks. Supported background queue drivers are database, Redis, SQS, and Beanstalkd. A `sync` or deferred queue driver cannot run it. If the queue worker is stopped or Wings cannot reach the panel, automatic detection cannot run reliably. This is startup troubleshooting, not continuous application or host health monitoring.

If Wings rejects a normal Pelican start before entering `starting`, the observed start request still creates a watch. If no startup transition is confirmed before the deadline, it produces an inconclusive timeout report. A direct Wings request made outside Pelican that fails before any state callback is outside the observer's visibility.

For an immediate manual check, choose **Start & Diagnose**. The assistant connects to the server's console first, then requests a normal start if it is offline. It observes an already running or starting server without restarting it. This manual capture needs the page to stay open.

The report contains a likely cause, confidence, evidence, and numbered fix steps. It can recognize missing game files, JARs, and executables, incompatible Java versions, address binding errors, permissions, memory errors, disk space problems, dependency errors, and other startup failures. An optional AI model can explain unfamiliar errors. Coverage depends on what the application reports; no tool can establish every cause from console output alone.

**Analyze existing logs** reads recent Wings console history without starting the server. Automatic reports also use recent logs that may include an earlier run; matching errors are treated as possible causes rather than proof about the latest attempt. A running state only means the server remained running during observation.

### Crash watch

If a server crashes **while it is running** (for example out of memory during play, or a mod failing when a player joins), Wingman explains that crash too. It uses the crash report Wings sends (exit code, out-of-memory flag, and last console lines) plus recent console history. The report appears in the Wingman box as **Crash while running** and stays for 24 hours, even if Wings restarts the server automatically. Crashes during a watched start are explained by the startup report instead, so you do not get two reports. Crash reports share the automatic limits (cooldown and reports per day) to keep crash loops quiet.

### Install check

After every install or reinstall, Wingman reads the install log. Many install scripts keep going after an error, so Pelican can mark a server as installed although a download failed (for example SteamCMD's `ERROR! Failed to install app ... (Missing configuration)`). Wingman recognizes failed SteamCMD downloads and logins, failed downloads, damaged archives, full disks, missing commands, and other errors. It shows an **Install check** report with a **Reinstall server** fix. A later clean install clears the warning.

### Check performance (lag doctor)

**Check performance** takes three quick readings of CPU, memory, and disk use and reads recent console output for lag warnings (for example Minecraft's "Can't keep up!", watchdog warnings, or Java running out of memory). It reports which limits are being hit and what to do. With an AI connection, it adds an explanation in plain language; without one, the built-in checks still work. A short check can miss lag that comes and goes, so run it while the lag is happening.

### Ask Wingman

People with console access can type a question about their server (for example "How do I add mods?" or "Why does it lag at night?"). Wingman answers using the server's game, limits, startup settings (with startup access), and recent console output. It cannot change anything. Each person can ask a limited number of questions per day (20 by default), and the box needs an AI connection. AI answers can be wrong; check before changing anything important.

### Server health

A collapsible **Server health** section shows:

- **Backups:** how many backups the server keeps, the newest one, and whether automatic backups are scheduled. It warns when the backup limit is 0, when there are no backups, when the newest is over a week old, when nothing is scheduled, and when scheduled backups cannot run because the panel's task timer (cron) is not running. **Back up now** starts a panel backup; **Back up every night** creates a normal Pelican schedule (4 AM in your timezone) whose backup task replaces the oldest unlocked backup when the limit is reached. You can change or remove the schedule on the Schedules page.
- **Last 24 hours:** crashes, starts, stops, reinstalls, backups made or failed, and disk growth, from the panel's own activity log (needs `activity.read`).
- **Usage and limits:** Wingman records CPU, memory, and disk use every 10 minutes through Pelican's scheduler and keeps 7 days of hourly figures in the panel cache. After a day of use it suggests better limits, for example raising memory that keeps running out, lowering a limit the server never comes close to, setting a limit on an unlimited server, or warning when the disk will fill up. Usage tracking can be turned off in the plugin settings.

### Change a game setting

People who may read and edit a server's files can describe a change in plain words, like "allow 8 players" or "turn on whitelist". With an AI connection, Wingman:

1. Finds the server's settings files: the files the egg manages, plus text settings files (`.properties`, `.cfg`, `.ini`, `.json`, `.yml`, `.toml`, and similar, up to 256 KB) in the server folder and two folder levels below it. Logs, worlds, mods, and Steam folders are skipped.
2. Lets the AI choose the file, then sends it a copy with passwords and other secret values replaced by `[REDACTED]`.
3. Accepts only exact line changes. It refuses changes to hidden values, to settings Pelican manages from the Startup page, and to lines it cannot find exactly once.
4. Shows the change before and after. Nothing is written until you click **Apply change**. Wingman checks the file has not changed in the meantime and keeps the old file next to it as `<file>.wingman-<date>.bak`.

Restart the server afterwards for most changes to take effect.

### One-click fixes

For three well-understood problems, the report offers a fix button. Nothing changes until you click it and confirm:

| Problem found | Button | What it does | Permission needed |
|---|---|---|---|
| Java version too old | **Switch Java version** | Switches the server to a newer Java image from its egg's list (suggesting the closest one that works) and restarts it | `startup.docker-image` |
| Game files missing (for example `wine: failed to open "...exe"`, a missing server JAR, or a failed install) | **Reinstall server** | Runs Pelican's normal reinstall so the egg's install script downloads the files again. With **Back up the server first** on, Wingman makes a panel backup and starts the reinstall only after the backup succeeds | `settings.reinstall` (and `backup.create` to back up first) |
| Minecraft EULA not accepted | **Accept EULA** | After you agree to the linked Minecraft EULA, writes `eula=true` to `eula.txt` and restarts the server | `file.update` |

**Back up first** needs the server to allow at least one backup (its backup limit, set by an admin) and room under that limit; Wingman never deletes an older backup to make room. If the backup fails, the reinstall is cancelled and the Wingman box says so. The switch's default can be changed in the plugin settings.

Fixes are chosen only by Wingman's built-in error checks, never by the AI model. Permissions are checked again when you click. The restart after a fix also needs `control.restart`; without it, you are told to start the server yourself. An administrator's custom Docker image is never replaced. Each fix is recorded in the server's activity log, and a user can apply at most three fixes per server every five minutes. The automatic watcher never applies a fix by itself.

### AI egg check (admins)

Sometimes the server is fine and the **egg** (the template that installs and starts it) is wrong. Examples: a misspelled program name in the startup command, or an install script that keeps going after a failed download. Panel admins see an **Egg check** section in the Wingman box with an **Ask AI to check the egg** button. The AI reads the egg, the server's last install log, and recent console output, then either explains why the egg is not the problem or suggests a specific change.

Nothing changes until you review the suggestion and click **Apply to egg**:

- Every change is shown before and after; install-script changes are shown line by line. Risky additions are called out in a warning box: new download addresses, piping downloads into a shell, deleting files outside the server folder, `chmod 777`, base64, `eval`, raw network connections, and new Docker images.
- The suggestion can change the startup command, the Docker image list, variable defaults, the install script, and the install container. This server's own startup command, image, and variables follow the egg only where they still used the egg's old values.
- The confirmation shows how many servers use the egg. Install-script changes apply to each of them the next time it is installed or reinstalled. You can choose to reinstall this server right away.
- Wingman saves the egg's previous version first (in `storage/app/private/wingman-ai/egg-backups/`, the last five changes per egg). **Undo** restores it, unless someone edited the egg again since; the backup file stays available for manual restore.
- Applying requires the admin permissions to edit both the egg and the server (`update egg`, `update server`). The AI never writes anything itself, and the automatic watcher never asks for egg changes.
- Suggestions expire after 30 minutes, are bound to the admin who asked, and are refused if the egg changed in the meantime. Each admin can ask at most five times per server per hour.

Console output can contain text typed by players. The AI is told to treat everything it reads as untrusted, but a suggestion could still be influenced by such text, so always read the change before applying it. Egg checks need an AI connection; they send the egg's install script and install log to your AI provider, with passwords and keys masked.

## Install

You need administrator access to the Pelican **panel**, not only access to a game server's files.

For Linux, run the automatic installer on the machine hosting the panel:

```bash
curl -fsSL https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/main/install.sh | sudo bash -s -- --panel /var/www/pelican
```

Use your actual panel path. The command checks compatibility and downloads the plugin from one verified GitHub revision. It runs Pelican registration as the panel's service account and keeps a backup for rollback. Rerun it to update; private AI settings and disabled/enabled state are preserved. A custom setup can use `--user ACCOUNT`; `--check` shows versions without installing, and `--version v0.3.0` selects a published version. Use `--force` only for an intentional reinstall or downgrade.

For installation through the browser:

1. Confirm your panel is **1.0.0-rc.1** before installing this build.
2. In Pelican's admin area, open **Plugins** and use **Import** to upload `wingman-ai.zip`.
3. Choose **Install**, wait for the panel's queue worker to finish, and enable the plugin if necessary.
4. Confirm the panel's queue worker is running. Automatic protection will watch the next normal server startup.
5. Open a server's **Console**. The assistant appears above the console, with automatic status and the latest permitted report.

For manual installation, copy this folder to `/var/www/pelican/plugins/wingman-ai` (adjust for your panel directory), then run `php artisan p:plugin:install` from the panel directory and select the plugin.

## Updates

Run the Linux installer command again, or use Pelican's **Plugins → Update** button when a compatible newer version is available. The [GitHub update log](https://github.com/GhostingGodzilla/Wingman-AI/blob/main/CHANGELOG.md) explains each update. [GitHub Releases](https://github.com/GhostingGodzilla/Wingman-AI/releases) provides dated summaries and versioned downloads.

The latest update log is also included as `CHANGELOG.md` in the plugin ZIP. Existing AI settings remain outside the plugin folder, so replacing the plugin does not erase them.

## Connect an AI model

Open **Plugins → Wingman AI → Settings**. Basic error checks work immediately without a model or API key. To enable AI:

1. Enable **Use AI analysis**.
2. Enter a trusted **Chat Completions API base URL**, including the API prefix such as `/v1`.
3. Enter an available **model name**, and the provider's **API key** if required.
4. Choose the output token setting accepted by that service and save.

For an OpenAI connection, the base URL is `https://api.openai.com/v1`; use `max_completion_tokens` and a model available to your account that supports Chat Completions. Hosted API requests may incur charges from the provider. This plugin does not include an AI subscription or free API access.

For an Ollama or similar local connection, use its compatible endpoint, such as `http://127.0.0.1:11434/v1`, a model already installed there, and `max_tokens`. The address must be reachable from the machine running the **panel**. If the panel runs inside a container, `127.0.0.1` points to that container. This plugin does not install or run a local model for you.

AI requests use the Chat Completions format for compatibility with hosted and local services. An invalid model, provider outage, unsupported token setting, invalid JSON response, or request timeout produces a basic-check report instead.

## Access and data

- Existing Pelican permissions apply. Reading output requires console websocket access; manually starting requires `control.start`. Manual startup settings are included only with `startup.read`. Viewing an automatic report requires both console access and `startup.read`, since background analysis runs with the current server owner's permitted context. The manual assistant's own websocket token is read only.
- Backup doctor needs `backup.read` to view, `backup.create` to back up, and `schedule.create` with `backup.create` to schedule nightly backups. Settings changes need `file.read`, `file.read-content`, and `file.update`; pending changes are stored encrypted for 15 minutes.
- Crash and install checks run in the background with the server owner's permitted context, like automatic startup reports, and need the same access to view (console and `startup.read`). Performance checks and questions need console access; startup details are included only with `startup.read`.
- Clicking a fix button and confirming authorizes only that fix and, with `control.restart`, a restart. Each fix requires its own permission, listed under [One-click fixes](#one-click-fixes).
- Clicking **Start & Diagnose** authorizes a normal server start. Opening the Console page only retrieves the automatic status/report; it does not start a server. Cancelling a manual capture does not stop the server or disable automatic protection.
- Automatic reports and existing-log checks are collected through panel-to-Wings requests. Manual live console captures use your browser's Wings connection, so closing the page ends a manual live capture. The browser does not receive the AI API key.
- A diagnosis sent to AI includes bounded recent output, resource limits, the egg name, and permitted startup/runtime information. Console output can contain player chat, filenames, and other private content. Only enable AI with a provider you trust.
- Credential patterns, credential-named egg variable values (password, token, key, secret, and similar), and hidden values that look like credentials are redacted before AI submission. Ordinary hidden settings such as `1`, port numbers, and paths stay readable, so they do not garble the log. This is best effort, not a guarantee for arbitrary text. Only a small allowlist of viewable runtime selectors is included as separate context. No whole configuration files, worlds, or directories are uploaded.
- Settings, including the API key, are encrypted using the panel's `APP_KEY` and stored in `storage/app/private/wingman-ai/settings.enc`. They survive plugin updates. Back up the panel's key and private storage together. The settings form leaves the saved key blank; leaving it blank preserves it.
- Manual capture sessions are bound to the authenticated user and server, expire, and cannot be replayed for additional diagnoses. A configurable per-user limit bounds manual requests over a 24-hour window. Automatic reports have separate per-server limits and a cooldown. These are not monetary spending caps; set a budget with your provider too.
- The latest automatic report is encrypted in the panel cache for up to 24 hours and may disappear sooner if the cache is cleared. It is not a historical archive. Manual reports stay in the current page's Livewire state. Raw console logs are not stored in a plugin history database.

## Verification and development

This release includes dependency-free PHP tests for redaction and diagnosis, JavaScript tests for the capture state machine, and integration tests with framework stubs. From this plugin directory, run each command separately:

```sh
php tests/php/helpers_test.php
php tests/php/automatic_state_test.php
php tests/integration/run.php
php tests/monitor.test.php
php tests/manual_history.test.php
php tests/automatic.test.php
php tests/ui_automatic.test.php
node --test tests/js/startup-monitor.test.cjs
```

The test suites use separate PHP processes because their framework substitutes differ. These checks do not replace a live panel smoke test. See `VALIDATION.md` for the checks performed on this build.

From the repository root, release and installer checks are:

```sh
php tools/release-notes.php --check
php tests/release/release-notes.test.php
php tests/packaging.test.php
php tests/installer-review/run.php
bash tests/installer/run.sh
```

To package the plugin, run `php tools/package.php` from this directory. The ZIP is written to the workspace's `dist/` directory and includes source, views, defaults, documentation, and license; no saved connection settings are included.

Before using on a live server, verify these scenarios on a disposable Pelican server: a successful startup, an immediate error, a long startup/timeout, a scheduled start while the browser is closed, a normal stop, a crash loop, an already running server, unavailable Wings, an AI outage, and a subuser without start or startup-read permission.

## References

- [Pelican plugin documentation](https://pelican.dev/docs/panel/advanced/plugins/)
- [Pelican Panel 1.0.0-rc.1 source](https://github.com/pelican/panel/tree/v1.0.0-rc.1)
- [Wings startup state callback source](https://github.com/pelican-dev/wings/blob/7b489da7b8196d0b4baef3e2db062c06d743e2f9/server/server.go)
- [Chat Completions API reference](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create)
- [Ollama compatible API documentation](https://docs.ollama.com/api/openai-compatibility)

## License

MIT. This is an independent plugin and is not an official Pelican release.
