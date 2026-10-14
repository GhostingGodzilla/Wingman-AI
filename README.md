# Wingman AI

A Pelican assistant that automatically watches server startups, explains likely causes of failure, and suggests practical fixes. It uses the selected server's egg, runtime information, and console output to work across different games and applications.

## Install

This build targets **Pelican Panel 1.0.0-rc.1**. Check your installed version before importing it; earlier panel releases need an adapted build.

1. Download [`wingman-ai.zip`](dist/wingman-ai.zip).
2. Open the Pelican admin area and go to **Plugins → Import**.
3. Upload the ZIP, install it, and enable the plugin if necessary.
4. Start a server normally. Wingman AI automatically watches the startup and shows a report in its **Console** if startup fails or times out.

Automatic protection is enabled by default and uses Pelican's background queue worker. It covers normal console, API, and scheduled starts while the browser is closed. Successful starts stay quiet; AI analysis is requested only for automatic failure or timeout reports. Switch automatic protection off in plugin settings if preferred. The manual **Start & Diagnose** and **Analyze existing logs** buttons remain available.

Basic error checks work without an AI service. For a deeper explanation, configure a trusted hosted or local model in the plugin's settings. The automatic watcher never starts, restarts, stops, or repairs a server. It gives instructions for the user to review and apply.

Read the [full installation and configuration guide](wingman-ai/README.md) for supported connection settings, access controls, and data handling.

## Source and verification

The plugin source is in [`wingman-ai/`](wingman-ai/). To rebuild the installable ZIP:

```sh
php wingman-ai/tools/package.php
```

The archive is written to `dist/wingman-ai.zip`. Tests and commands are documented in the [plugin guide](wingman-ai/README.md#verification-and-development); the [validation record](wingman-ai/VALIDATION.md) explains their scope.

Local automated checks pass. A live Pelican installation, real Wings startup, and actual AI model request have not yet been tested in this workspace.

## License

[MIT](wingman-ai/LICENSE). Wingman AI is an independent plugin, not an official Pelican release.
