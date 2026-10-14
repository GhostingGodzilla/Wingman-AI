# Wingman AI updates

This is the update log for Wingman AI. Each release explains what changed and anything users need to do.

## [Unreleased]

<!-- Add new user-facing changes here before the next release. -->

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

[Unreleased]: https://github.com/GhostingGodzilla/Wingman-AI/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/GhostingGodzilla/Wingman-AI/releases/tag/v0.3.0
[0.2.0]: https://github.com/GhostingGodzilla/Wingman-AI/commit/7c9182ffed703a36d182ab188ac2c58d494aa73e
[0.1.0]: https://github.com/GhostingGodzilla/Wingman-AI/commit/8d759582f80dfd0029a5458f93898c25cb860122
