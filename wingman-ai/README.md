# Wingman AI for Pelican

A console assistant that watches a server start, highlights likely causes of failure, and gives steps to fix them. It uses the selected server's egg, available startup settings, and console output to identify the application rather than assuming a particular game.

This first release targets **Pelican Panel 1.0.0-rc.1**, its plugin API 1, and PHP 8.4/8.5. The manifest deliberately restricts installation to that panel version because Pelican's console and internal services can change between releases. An older panel needs an adapted build; do not remove the version restriction just to force installation.

## What it does

On a server's Console page, open **Wingman AI** and choose **Start & Diagnose**. The assistant connects to that server's console first, then requests a normal start if it is offline. It observes an already running or starting server without restarting it.

The report contains a likely cause, confidence, evidence, and numbered fix steps. It can recognize missing JARs/executables, incompatible Java versions, address binding errors, permissions, memory errors, disk space problems, dependency errors, and other startup failures. An optional AI model can explain unfamiliar errors. Coverage depends on what the application reports; no tool can establish every cause from console output alone.

**Analyze existing logs** reads recent Wings console history without starting the server. Historical errors are treated as historical evidence. A timeout is inconclusive; a running state only means the server remained running during observation. The assistant does not edit files or apply fixes.

## Install

You need administrator access to the Pelican **panel**, not only access to a game server's files.

1. Confirm your panel is **1.0.0-rc.1** before installing this build.
2. In Pelican's admin area, open **Plugins** and use **Import** to upload `wingman-ai.zip`.
3. Choose **Install**, wait for the panel's queue worker to finish, and enable the plugin if necessary.
4. Open a server's **Console**. The assistant appears above the console.

For manual installation, copy this folder to `/var/www/pelican/plugins/wingman-ai` (adjust for your panel directory), then run `php artisan p:plugin:install` from the panel directory and select the plugin.

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

- Existing Pelican permissions apply. Reading output requires console websocket access; starting requires `control.start`. Startup settings are included only with `startup.read`. The assistant's own websocket token is read only.
- Clicking **Start & Diagnose** authorizes a normal server start. Opening the Console page never starts a diagnosis or a server. Cancellation ends observation; it does not stop the server.
- Console data is collected by your browser from the server's Wings connection. Keep the page open while it watches. Closing the page ends capture. The browser does not receive the AI API key.
- A diagnosis sent to AI includes bounded recent output, resource limits, the egg name, and permitted startup/runtime information. Console output can contain player chat, filenames, and other private content. Only enable AI with a provider you trust.
- Credential patterns and hidden or credential-named egg variable values are redacted before AI submission. This is best effort, not a guarantee for arbitrary text. Only a small allowlist of viewable runtime selectors is included as separate context. No whole configuration files, worlds, or directories are uploaded.
- Settings, including the API key, are encrypted using the panel's `APP_KEY` and stored in `storage/app/private/wingman-ai/settings.enc`. They survive plugin updates. Back up the panel's key and private storage together. The settings form leaves the saved key blank; leaving it blank preserves it.
- Capture sessions are bound to the authenticated user and server, expire, and cannot be replayed for additional diagnoses. A configurable per-user limit bounds requests over a 24-hour window. It is not a monetary spending cap; set a budget with your provider too.
- There is no persistent diagnosis history. The current report is shown in the current page's Livewire state. Captured raw logs are not written to a plugin history database.

## Verification and development

This release includes dependency-free PHP tests for redaction and diagnosis, JavaScript tests for the capture state machine, and integration tests with framework stubs. From this plugin directory, run each command separately:

```sh
php tests/php/helpers_test.php
php tests/integration/run.php
php tests/monitor.test.php
node --test tests/js/startup-monitor.test.cjs
```

The test suites use separate PHP processes because their framework substitutes differ. These checks do not replace a live panel smoke test. See `VALIDATION.md` for the checks performed on this build.

To package the plugin, run `php tools/package.php` from this directory. The ZIP is written to the workspace's `dist/` directory and includes source, views, defaults, documentation, and license; no saved connection settings are included.

Before using on a live server, verify these scenarios on a disposable Pelican server: a successful startup, an immediate error, a long startup/timeout, an already running server, unavailable Wings, an AI outage, and a subuser without start or startup-read permission.

## References

- [Pelican plugin documentation](https://pelican.dev/docs/panel/advanced/plugins/)
- [Pelican Panel 1.0.0-rc.1 source](https://github.com/pelican/panel/tree/v1.0.0-rc.1)
- [Chat Completions API reference](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create)
- [Ollama compatible API documentation](https://docs.ollama.com/api/openai-compatibility)

## License

MIT. This is an independent plugin and is not an official Pelican release.
