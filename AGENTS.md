# Wingman AI maintenance

Wingman AI is an independent Pelican Panel plugin. The user requires an update summary for every update.

## Changes and release notes

- Include a plain-language summary of each user-facing change in the root `CHANGELOG.md`. During development use `[Unreleased]`; for a release move the notes into a dated `## [X.Y.Z] - YYYY-MM-DD` section.
- Keep `wingman-ai/plugin.json` and the released changelog version in sync. Use a new semantic version for each release, and never move or overwrite a published version tag.
- Every release needs a useful summary of what changed and any installation or compatibility changes. Do not count headings, placeholders, or edits only to an older release as the new update summary.
- Run `php tools/release-notes.php --check --base <previous-commit-SHA>` before publishing user-facing changes. GitHub checks enforce this requirement as well.
- Preserve the Linux install/update command and existing users' private AI settings. Do not upgrade Pelican or modify game files as part of a plugin update.

## Package and publish

- Build the tracked installation artifacts with `php wingman-ai/tools/package.php`. Commit the ZIP, SHA-256 file, release manifest, and root `update.json` together with the source and changelog.
- `--release-tag vX.Y.Z` is for the GitHub release workflow: it builds manifests pointing at that release's attached ZIP. Do not commit this workflow-only manifest over the normal tracked manifest.
- Run the relevant plugin, installer, release-summary, and PHP syntax checks. Keep `wingman-ai/VALIDATION.md` accurate about what was actually tested; tests with framework substitutes do not prove a live Pelican installation.
- Push the source to `main`. A matching new `vX.Y.Z` tag triggers the checked GitHub Release with its summary and installation downloads.

## Compatibility and security

- The current build explicitly targets Pelican `1.0.0-rc.1`, plugin API 1, and PHP 8.4/8.5. Do not widen compatibility without checking the target panel's actual plugin and console APIs.
- Keep AI API keys out of source, browser state, logs, and release artifacts. Saved keys stay encrypted in the panel's private storage.
- Reauthorize server access on each public action. Automatic observation must never issue a power action or apply a repair.
- Installer paths, archive entries, backups, and rollback targets must stay within the selected panel's verified plugin directory. Do not follow symlinks or remove unrelated files.
