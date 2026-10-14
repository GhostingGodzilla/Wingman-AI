# Build verification

Verified on October 14, 2026 against the public Pelican Panel `v1.0.0-rc.1` source at commit `46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00`. Automatic integration also references Wings state callbacks and its recent-log endpoint at commit `7b489da7b8196d0b4baef3e2db062c06d743e2f9`.

Passed checks:

- 99 helper assertions covering credential redaction, common startup causes, conservative uncertainty, historical automatic logs, and AI report normalization.
- 57 automatic state assertions covering duplicate or delayed callbacks, start intent, preboot timeouts, restart shutdowns, stale jobs, running observation, explicit stops, and early crashes.
- 17 service integration scenarios using narrow framework substitutes, covering permissions, server-specific variables, redaction before truncation, AI transport behavior, invalid responses, automatic settings, and settings persistence contracts.
- 12 monitoring contract scenarios covering server/user binding, permissions, fresh status checks, normal starts, running servers, failed starts, capture expiry/replay, cancellation, and request bounds.
- 20 automatic backend scenarios covering authenticated callbacks and outgoing power requests, preboot attempts, restart shutdowns, background checks, encrypted reports, owner permissions, plugin disable, stale jobs, and request limits.
- 4 existing-history scenarios covering offline REST logs, whole-line bounds, permission checks, and failed retrieval without power actions.
- 12 automatic UI scenarios covering console start/restart observation, cache-only polling, report permissions, locked state, status filtering, prior results, and manual report preservation.
- 13 JavaScript behavior scenarios covering authentication order, immediate crashes, startup timeouts, running observation, offline history without a socket, redaction budgets, log bounds, and socket loss during a start request.
- PHP syntax validation for every PHP file.
- ZIP creation and manifest/file-count verification.

Tests ran locally using PHP 8.2.12 and Node.js. Plugin syntax stays compatible with that PHP parser; the supported Pelican release itself requires PHP 8.4 or 8.5. The stubs exercise actual plugin service code but do not prove Laravel/Filament internals, rendered Blade layouts, or real Wings behavior.

A live Pelican installation, real server startup, and an actual external or local AI model request have **not** been tested in this workspace. No home-server connection or API key was available. The package should receive the disposable-server smoke checks described in `README.md` before production use.
