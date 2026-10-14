# Wingman AI

A Pelican console assistant that watches server startup, explains likely causes of failure, and suggests practical fixes. It uses the selected server's egg, runtime information, and console output to work across different games and applications.

## Install

This first build targets **Pelican Panel 1.0.0-rc.1**. Check your installed version before importing it; earlier panel releases need an adapted build.

1. Download [`wingman-ai.zip`](dist/wingman-ai.zip).
2. Open the Pelican admin area and go to **Plugins → Import**.
3. Upload the ZIP, install it, and enable the plugin if necessary.
4. Open a server's **Console** and choose **Start & Diagnose** in **Wingman AI**.

Basic error checks work without an AI service. For a deeper explanation, configure a trusted hosted or local model in the plugin's settings. The plugin observes already active servers without restarting them and gives instructions for the user to review and apply.

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
