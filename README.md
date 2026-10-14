# Wingman AI

A Pelican assistant that automatically watches server startups, explains likely causes of failure, and suggests practical fixes. It uses the selected server's egg, runtime information, and console output to work across different games and applications.

## Install on Linux

This build targets **Pelican Panel 1.0.0-rc.1**. Check your installed version before importing it; earlier panel releases need an adapted build.

Run this on the Linux machine hosting your **Pelican Panel**:

```bash
curl -fsSL https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/main/install.sh | sudo bash -s -- --panel /var/www/pelican
```

Replace `/var/www/pelican` if your panel is elsewhere. The installer downloads a checked package, installs it through Pelican, and prints the next steps. PHP CLI 8.4/8.5 and a working Pelican installation are required. It uses the panel's existing service account and keeps a backup when updating.

**To update, run the same command again.** Saved AI settings and an existing disabled/enabled preference are preserved. If your panel uses a custom service account, add `--user ACCOUNT`. Add `--check` to show available/installed versions without installing, or `--version v0.3.0` to select a published version.

## Install through the panel

1. Download [`wingman-ai.zip`](dist/wingman-ai.zip).
2. Open the Pelican admin area and go to **Plugins → Import**.
3. Upload the ZIP, install it, and enable the plugin if necessary.
4. Start a server normally. Wingman AI automatically watches the startup and shows a report in its **Console** if startup fails or times out.

Automatic protection is enabled by default and uses Pelican's background queue worker. It covers normal console, API, and scheduled starts while the browser is closed. Successful starts stay quiet; AI analysis is requested only for automatic failure or timeout reports. Switch automatic protection off in plugin settings if preferred. The manual **Start & Diagnose** and **Analyze existing logs** buttons remain available.

Basic error checks work without an AI service. For a deeper explanation, configure a trusted hosted or local model in the plugin's settings. The automatic watcher never starts, restarts, stops, or repairs a server. It gives instructions for the user to review and apply.

Read the [full installation and configuration guide](wingman-ai/README.md) for supported connection settings, access controls, and data handling.

## Updates and release notes

Read the [update log](CHANGELOG.md) for the history, or open [GitHub Releases](https://github.com/GhostingGodzilla/Wingman-AI/releases) for a version's summary and downloads. Compatible installations can also use Pelican's **Plugins → Update** button when a newer Wingman AI version is available.

Every user-facing update includes an edited changelog summary. The repository checks enforce this, and a matching version tag publishes those notes as a GitHub Release.

## Source and verification

The plugin source is in [`wingman-ai/`](wingman-ai/). To rebuild the installable ZIP:

```sh
php wingman-ai/tools/package.php
```

The archive, SHA-256 file, and installer manifest are written to `dist/`. The build also refreshes the Pelican update feed in `update.json`. Tests and commands are documented in the [plugin guide](wingman-ai/README.md#verification-and-development); the [validation record](wingman-ai/VALIDATION.md) explains their scope.

Maintainers: add release notes to `CHANGELOG.md`, bump the plugin version, rebuild and commit the source and downloads, then push a matching `vX.Y.Z` tag after the checks pass. [AGENTS.md](AGENTS.md) records the release rules for future work.

Local automated checks pass. A live Pelican installation, real Wings startup, and actual AI model request have not yet been tested in this workspace.

## License

[MIT](wingman-ai/LICENSE). Wingman AI is an independent plugin, not an official Pelican release.
