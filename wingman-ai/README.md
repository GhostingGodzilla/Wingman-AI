# Wingman AI for Pelican

A console assistant that automatically watches server starts, highlights likely causes of failure, and gives steps to fix them. It uses the selected server's egg, available startup settings, and console output to identify the application rather than assuming a particular game.

This release targets **Pelican Panel 1.0.0-rc.1**, its plugin API 1, and PHP 8.4/8.5. The manifest deliberately restricts installation to that panel version because Pelican's console and internal services can change between releases. An older panel needs an adapted build; do not remove the version restriction just to force installation.

## What it does

**Automatic startup protection is enabled by default.** Start your server using Pelican's normal controls, an API request, or a schedule. Wingman AI observes those start/restart requests, receives authenticated Wings state changes, and watches startup through background jobs, even while your browser is closed. It never sends a power action from the automatic watcher.

Successful starts stay quiet after a short running observation. If startup fails or reaches the configured watch timeout, the watcher retrieves the latest Wings console lines, prepares a report, and makes it available on the server's **Console** page. The console refreshes the automatic status and latest report every ten seconds. A timeout is an unconfirmed result, not proof of a crash.

Open **Plugins → Wingman AI → Settings** to disable automatic protection or change its limits. The default is at most 20 automatic reports per server in a 24-hour window, with at least five minutes between reports, to limit repeated reports during crash loops. AI calls are made only for automatic failure or timeout reports. Basic checks are used when AI is disabled, unconfigured, or unavailable.

The background watcher requires Pelican's working **asynchronous queue worker** and Wings state callbacks. Supported background queue drivers are database, Redis, SQS, and Beanstalkd. A `sync` or deferred queue driver cannot run it. If the queue worker is stopped or Wings cannot reach the panel, automatic detection cannot run reliably. This is startup troubleshooting, not continuous application or host health monitoring.

If Wings rejects a normal Pelican start before entering `starting`, the observed start request still creates a watch. If no startup transition is confirmed before the deadline, it produces an inconclusive timeout report. A direct Wings request made outside Pelican that fails before any state callback is outside the observer's visibility.

For an immediate manual check, choose **Start & Diagnose**. The assistant connects to the server's console first, then requests a normal start if it is offline. It observes an already running or starting server without restarting it. This manual capture needs the page to stay open.

The report contains a likely cause, confidence, evidence, and numbered fix steps. It can recognize missing JARs/executables, incompatible Java versions, address binding errors, permissions, memory errors, disk space problems, dependency errors, and other startup failures. An optional AI model can explain unfamiliar errors. Coverage depends on what the application reports; no tool can establish every cause from console output alone.

**Analyze existing logs** reads recent Wings console history without starting the server. Automatic reports also use recent logs that may include an earlier run; matching errors are treated as possible causes rather than proof about the latest attempt. A running state only means the server remained running during observation. The assistant does not edit files or apply fixes.

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
- Clicking **Start & Diagnose** authorizes a normal server start. Opening the Console page only retrieves the automatic status/report; it does not start a server. Cancelling a manual capture does not stop the server or disable automatic protection.
- Automatic reports and existing-log checks are collected through panel-to-Wings requests. Manual live console captures use your browser's Wings connection, so closing the page ends a manual live capture. The browser does not receive the AI API key.
- A diagnosis sent to AI includes bounded recent output, resource limits, the egg name, and permitted startup/runtime information. Console output can contain player chat, filenames, and other private content. Only enable AI with a provider you trust.
- Credential patterns and hidden or credential-named egg variable values are redacted before AI submission. This is best effort, not a guarantee for arbitrary text. Only a small allowlist of viewable runtime selectors is included as separate context. No whole configuration files, worlds, or directories are uploaded.
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
