# Wingman AI updates

This is the update log for Wingman AI. Each release explains what changed and anything users need to do.

## [Unreleased]

<!-- Add new user-facing changes here before the next release. -->

## [0.7.1] - 2026-10-15

### Clearer AI errors

- When an AI request fails, Wingman now says why instead of "did not answer in time or rejected the request": the API key was rejected, the model name is unknown, the output token setting is not accepted, the request limit or free daily quota is used up, the AI service had a problem, it took too long, or the panel could not connect. Each message says what to change.
- Reports that fall back to built-in checks now say why there is no AI explanation.
- The panel log records each failed AI request with its reason and HTTP status (never the AI service's reply), so an admin can see why AI answers are missing.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.7.0] - 2026-10-15

### Server health

- New **Server health** section in the Wingman box.
- **Backups:** shows how many backups a server keeps and the newest one. It warns when the backup limit is 0, there are no backups, the newest is over a week old, nothing is scheduled, or scheduled backups cannot run because the panel's task timer is not running.
- **Back up now** starts a panel backup. **Back up every night** creates a normal Pelican schedule that backs the server up at 4 AM and replaces the oldest unlocked backup when the limit is reached.
- **Last 24 hours:** crashes, starts, stops, reinstalls, backups, and disk growth at a glance.
- **Usage and limits:** Wingman records CPU, memory, and disk use every 10 minutes and, after a day, suggests better limits (raise, lower, or set one) and warns before the disk fills up.

### Change a game setting

- Describe a change in plain words ("allow 8 players", "turn on whitelist"). Wingman finds the right settings file and proposes exact line changes. It shows them before and after, and writes nothing until you click **Apply change**.
- Passwords in the file are hidden from the AI. Wingman refuses to change hidden values or settings Pelican manages from the Startup page, and keeps the old file as a `.bak` copy.

### Easier to read

- Ask Wingman, the egg check, and the new sections are collapsible and remember whether you opened them, even while the box refreshes.

### Settings

- New settings to turn off the settings helper and usage tracking. Both are on by default.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.6.1] - 2026-10-14

### Fixed

- Fixed the server **Console** page failing with "500 | Server Error" (`Undefined variable $displayReport`) after updating to 0.6.0. A shorthand in the Wingman box's page template made the panel print part of it as text instead of running it. Update to get the Console page back; no settings or reports are lost.
- Added a check that rejects that template shorthand, so this cannot happen again.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.6.0] - 2026-10-14

### Crash watch

- Wingman now explains crashes that happen while a server is running, not only failed starts. It uses the crash report from Wings (exit code, out of memory, last lines) and recent console output. The report shows as **Crash while running** and stays even if Wings restarts the server.
- Crashes during a watched start are still explained by the startup report, so there are no duplicate reports. Crash reports use the same cooldown and daily limit as other automatic reports.

### Install check

- After every install or reinstall, Wingman reads the install log and warns when it failed, including installs that Pelican marked as successful although a download failed (such as SteamCMD's "Missing configuration" error). The report offers **Reinstall server**, and a clean install clears the warning.

### Back up before reinstalling

- Reinstall fixes, both the one-click fix and the AI egg check, have a **Back up the server first** switch. Wingman makes a panel backup and starts the reinstall only after the backup succeeds. If the backup fails, the reinstall is cancelled and the Wingman box says so.
- Backups need the server's backup limit to be at least 1 with room left; Wingman never deletes older backups. The switch's default can be changed in the plugin settings.

### Check performance

- New **Check performance** button: takes three quick readings of CPU, memory, and disk use, reads recent console output for lag warnings, and explains which limits are being hit and what to do. It works without AI, and uses AI for a fuller explanation when connected.

### Ask Wingman

- People with console access can ask questions about their server and get a plain-language answer based on its game, settings, and recent console output. Wingman cannot change anything from here. Questions are limited per person per day (20 by default) and need an AI connection.

### Settings

- New settings: crash watch, install check, the default for backing up before a reinstall, and Ask Wingman with its daily limit. All are on by default.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.5.0] - 2026-10-14

### AI egg check for admins

- Panel admins can ask the AI whether a server's egg is causing its problem. The AI reads the egg, the last install log, and recent console output, and either explains why the egg is fine or suggests a specific change.
- Suggestions can change the startup command, Docker images, variable defaults, the install script, or the install container. They are shown before and after, with install-script changes line by line, and risky additions are flagged (new download addresses, piping downloads into a shell, deleting outside the server folder, and more).
- Nothing changes until an admin who may edit both the egg and the server clicks **Apply to egg** and confirms. The confirmation shows how many servers use the egg and offers to reinstall this server.
- Wingman backs up the egg before every change (last five per egg). **Undo** restores it, unless the egg was edited again since.
- Suggestions expire after 30 minutes, belong to the admin who asked, and are refused if the egg changed in the meantime. Each admin can ask five times per server per hour.

### Clearer logs for the AI

- Fixed hidden server settings with ordinary values, such as `1`, `win64`, ports, or paths, being blanked out everywhere in the console log before it reached the AI. Values named like passwords, tokens, or keys, and hidden values that look like credentials, are still masked.

### Fix button

- The fix box now has its colored border even when the panel has not rebuilt its styles for plugins.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.4.0] - 2026-10-14

### One-click fixes

- Reports now offer a fix button for three common startup problems. Nothing changes until you click it and confirm in a pop-up:
  - **Java version too old:** switch the server to a newer Java image from its egg's list. Wingman suggests the closest version that works and restarts the server.
  - **Game files missing:** run Pelican's normal reinstall so the install script downloads the files again. Back up first, because a reinstall can overwrite files.
  - **Minecraft EULA not accepted:** after you agree to the linked EULA, write `eula=true` to `eula.txt` and restart the server.
- Each fix needs the matching Pelican permission (`startup.docker-image`, `settings.reinstall`, or `file.update`), checked again when you click. The automatic restart also needs `control.restart`.
- Fixes come only from Wingman's built-in error checks, never from the AI model. Each one is recorded in the server's activity log, and each user is limited to three fixes per server every five minutes. The automatic watcher still never changes anything by itself.

### Better detection

- Recognizes Wine games whose program file is missing (for example `wine: failed to open "./Game.exe"`) as missing game files.
- Recognizes Minecraft's "requires running the server with Java N or above" message as a Java version problem.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.3.1] - 2026-10-14

### Installer no longer breaks the panel

- Fixed the Linux install/update command taking the whole panel down with an HTTP 500 error when another installed plugin (for example Player Counter) uses Composer packages. Pelican reinstalls every plugin's packages during registration; the installer now keeps Composer in the same no-dev mode the panel was installed with, so developer-only packages are no longer added.
- If a previous install attempt already caused the 500 error, repair the panel first by running `composer install --no-dev --optimize-autoloader` as the panel account in the Pelican folder, then rerun the install command.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**.

## [0.3.0] - 2026-10-14

### Easier installation and updates

- Added a Linux install/update command that downloads Wingman AI and registers it with Pelican without a manual ZIP upload.
- Added checks for the panel version, service account, download checksum, and plugin archive before installation.
- Updates preserve the plugin's enabled/disabled state and private AI settings, keep a backup, and restore the previous plugin if registration fails.
- Added a version manifest and Pelican's plugin update feed, so compatible installations can also use the panel's Update button.

### Clear update summaries

- Added this dated changelog and linked it from the GitHub installation guide.
- Added release automation that publishes the plugin ZIP, checksum, installer, and a summary on GitHub Releases.
- Added checks and maintenance instructions requiring an edited summary with future user-facing updates.

Compatibility remains Pelican Panel **1.0.0-rc.1**, plugin API 1, and PHP **8.4/8.5**. The installer does not upgrade Pelican or install an AI model.

## [0.2.0] - 2026-10-14

- Added automatic startup monitoring for normal console, API, and scheduled starts, including background checks while the console is closed.
- Successful starts stay quiet; failures and timeouts produce a diagnosis with suggested fixes.
- Added cooldowns and daily limits to reduce repeated AI requests during crash loops.
- Added permission-controlled automatic reports and encrypted retention of the latest report.
- Fixed existing-log analysis for stopped servers by retrieving history through Wings REST.

## [0.1.0] - 2026-10-14

- Added the first Wingman AI console assistant with Start & Diagnose and existing-log analysis.
- Added basic error checks and optional hosted or local AI explanations with evidence and suggested fix steps.
- Added server permissions, credential redaction, and encrypted AI connection settings.

[Unreleased]: https://github.com/GhostingGodzilla/Wingman-AI/compare/v0.7.1...HEAD
[0.3.0]: https://github.com/GhostingGodzilla/Wingman-AI/releases/tag/v0.3.0
[0.2.0]: https://github.com/GhostingGodzilla/Wingman-AI/commit/7c9182ffed703a36d182ab188ac2c58d494aa73e
[0.1.0]: https://github.com/GhostingGodzilla/Wingman-AI/commit/8d759582f80dfd0029a5458f93898c25cb860122
