# Build verification

Verified on October 14, 2026 against the public Pelican Panel `v1.0.0-rc.1` source at commit `46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00`. Automatic integration also references Wings state callbacks and its recent-log endpoint at commit `7b489da7b8196d0b4baef3e2db062c06d743e2f9`.

Passed checks:

- 124 helper assertions covering credential redaction, common startup causes, conservative uncertainty, historical automatic logs, AI report normalization, and which diagnoses may offer a one-click fix.
- 57 automatic state assertions covering duplicate or delayed callbacks, start intent, preboot timeouts, restart shutdowns, stale jobs, running observation, explicit stops, and early crashes.
- 19 service integration scenarios using narrow framework substitutes, covering permissions, server-specific variables, redaction before truncation, AI transport behavior, invalid responses, automatic settings, settings persistence contracts, AI output being unable to add or change a fix, and ordinary hidden settings no longer garbling logs.
- 12 monitoring contract scenarios covering server/user binding, permissions, fresh status checks, normal starts, running servers, failed starts, capture expiry/replay, cancellation, and request bounds.
- 20 automatic backend scenarios covering authenticated callbacks and outgoing power requests, preboot attempts, restart shutdowns, background checks, encrypted reports, owner permissions, plugin disable, stale jobs, and request limits.
- 4 existing-history scenarios covering offline REST logs, whole-line bounds, permission checks, and failed retrieval without power actions.
- 16 automatic UI scenarios covering console start/restart observation, cache-only polling, report permissions, locked state, status filtering, prior results, manual report preservation, fix-button visibility, polling never applying a fix or egg change, and egg-check errors without details.
- 46 egg-helper assertions covering AI egg-suggestion validation (unknown fields, multi-line commands, unknown variables, invalid images, oversized or masked scripts), line diffs and collapsing, review warnings for risky script additions, and which variable values are masked.
- 9 egg-advisor scenarios covering admin-only access, what the AI sees (and never sees), proposal binding and expiry, applying with backups, server values that follow the egg, stale and invalid proposals, variable rules, reinstall, failed writes, undo and its refusal after later edits, AI failures, missing logs, and rate limits.
- 7 repair scenarios covering per-fix permissions, egg-only Java image choices and suggestions, administrator custom images, image validation before rate limiting, reinstall and EULA behavior, restart permission, audit events, busy servers, rate limits, and power failures.
- 13 JavaScript behavior scenarios covering authentication order, immediate crashes, startup timeouts, running observation, offline history without a socket, redaction budgets, log bounds, and socket loss during a start request.
- 9 offline installer shell scenarios covering immutable downloads, service-account selection, paths with spaces, version selection, checksum failures, and failed preflight. Linux identity, network, and Pelican execution were substituted for these local checks.
- 15 independent installer scenarios covering archive paths and types, checksums, preserved metadata, same-version behavior, rollback, overlapping installation, archive expansion limits, and keeping Composer in the panel's install mode for the registration step. Four real symlink fixtures skip on this Windows environment and are included in the Ubuntu workflow.
- 56 release-summary assertions covering dated notes, tag/version matching, and edited summaries for new user-facing changes.
- 24 packaging assertions covering ZIP contents, changelog inclusion, matching checksums, Pelican update feeds, and versioned release downloads.
- PHP syntax validation for every PHP file.
- ZIP creation and manifest/file-count verification.

Tests ran locally using PHP 8.2.12 and Node.js. Plugin syntax stays compatible with that PHP parser; the supported Pelican release itself requires PHP 8.4 or 8.5. The stubs exercise actual plugin service code but do not prove Laravel/Filament internals, rendered Blade layouts, or real Wings behavior.

Installer shell checks ran under Git Bash. GitHub Actions is configured to repeat the checks on Ubuntu with PHP 8.4 and run filesystem symlink cases there. A real Linux/Pelican installation is still outside these isolated tests.

A live Pelican installation, real server startup, and an actual external or local AI model request have **not** been tested in this workspace. No home-server connection or API key was available. The package should receive the disposable-server smoke checks described in `README.md` before production use.

Version 0.3.1 (October 14, 2026): the Composer install-mode fix was tested on Ubuntu with PHP 8.5. The installer and release checks were rerun, and detection was checked against a live Pelican `1.0.0-rc.1` panel's `vendor/composer/installed.json`, which correctly selected `COMPOSER_NO_DEV=1`. That panel had previously gone down (HTTP 500) when the 0.3.0 installer registered the plugin, because Pelican ran `composer require` for Player Counter's packages in dev mode.

Version 0.4.0 (October 14, 2026): one-click fixes were tested with the framework substitutes above on Ubuntu with PHP 8.5. The new widget and repair classes were also loaded against a live Pelican `1.0.0-rc.1` installation (Filament 5.10, Livewire 4.4). That check confirmed they declare cleanly with Filament's action and schema traits, and that every Pelican class, permission, and Filament method they call exists. The widget template was compiled with Laravel's Blade compiler and syntax-checked. Opening the confirmation pop-ups in a browser and applying each fix on a real server still require a manual check on the panel.

Version 0.5.0 (October 14, 2026): the AI egg check was tested with the framework substitutes above on Ubuntu with PHP 8.5. Its widget and service classes were loaded against the live Pelican `1.0.0-rc.1` installation, confirming that the Egg, EggVariable, Server, DB, Validator, and Filament Toggle/Action methods they use exist. The template was compiled and syntax-checked. A real AI egg review, applying a change on a live egg, Undo, and the pop-ups in a browser have **not** been tested yet and need a manual check on the panel.
