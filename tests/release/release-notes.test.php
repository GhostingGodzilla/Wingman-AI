<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/release-notes.php';

$fixtureRoot = sys_get_temp_dir() . '/wingman-release-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot . '/wingman-ai', 0755, true);
$checks = 0;

function releaseFixture(string $metadata, ?string $changelog): void
{
    global $fixtureRoot;
    file_put_contents($fixtureRoot . '/wingman-ai/plugin.json', $metadata);
    if ($changelog === null) {
        if (is_file($fixtureRoot . '/CHANGELOG.md')) {
            unlink($fixtureRoot . '/CHANGELOG.md');
        }
    } else {
        file_put_contents($fixtureRoot . '/CHANGELOG.md', $changelog);
    }
}

function releaseAssert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function releaseRejects(callable $operation, string $expected): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        releaseAssert(str_contains($exception->getMessage(), $expected), 'Unexpected rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection containing: ' . $expected);
}

try {
    $metadata = '{"version":"0.3.0"}';
    $notes = "### Added\n\n- Linux installer and update command.\n- GitHub release summaries.";
    $changelog = "# Changelog\n\n## [Unreleased]\n\n- This must not appear in release notes.\n\n## [0.3.0] - 2026-10-14\n\n{$notes}\n\n## [0.2.0] - 2026-10-13\n\n- Older features.\n";
    releaseFixture($metadata, $changelog);
    $release = wingmanReleaseNotes($fixtureRoot, 'v0.3.0');
    releaseAssert($release['version'] === '0.3.0', 'Read the wrong release version.');
    releaseAssert($release['date'] === '2026-10-14', 'Read the wrong release date.');
    releaseAssert($release['notes'] === $notes, 'Release notes included another changelog section.');
    releaseAssert(!str_contains($release['body'], 'Older features') && !str_contains($release['body'], 'must not appear'), 'Published notes contain unrelated sections.');

    releaseFixture($metadata, str_replace("\n", "\r\n", $changelog));
    releaseAssert(wingmanReleaseNotes($fixtureRoot)['notes'] === $notes, 'Windows line endings change the release notes.');
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot, 'v0.2.0'), 'Release tag must be v0.3.0');
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot, '0.3.0'), 'Release tag must be v0.3.0');

    releaseFixture($metadata, null);
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'Could not read CHANGELOG.md');
    releaseFixture($metadata, "# Changelog\n\n## [Unreleased]\n\n- Future summary.\n");
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'exactly one ## [0.3.0]');
    releaseFixture($metadata, "## [0.3.0-rc.1] - 2026-10-14\n\n- Prerelease only.\n");
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'exactly one ## [0.3.0]');
    releaseFixture($metadata, $changelog . "\n## [0.3.0] - 2026-10-14\n\n- Duplicate.\n");
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'exactly one');
    releaseFixture($metadata, "## [0.3.0]\n\n- Missing date.\n");
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'needs a release date');
    releaseFixture($metadata, "## [0.3.0] - 2026-02-30\n\n- Impossible date.\n");
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'release date for 0.3.0 is invalid');
    releaseFixture($metadata, "## [0.3.0] - 2026-10-14\n\n### Added\n\n<!-- Fill this in -->\n[0.3.0]: https://example.com/\n\n## [0.2.0] - 2026-10-13\n\n- Older content cannot substitute for a summary.\n");
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'needs a nonempty update summary');

    foreach (['{}', '{"version":3}', '{"version":"v0.3.0"}', '{"version":"0.03.0"}', '{"version":"0.3"}', '{"version":"0.3.0-01"}'] as $invalidMetadata) {
        releaseFixture($invalidMetadata, $changelog);
        releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'valid semantic version');
    }
    releaseFixture('{not json}', $changelog);
    releaseRejects(fn () => wingmanReleaseNotes($fixtureRoot), 'not valid JSON');

    releaseFixture('{"version":"1.2.3-rc.1+build.4"}', "## [1.2.3-rc.1+build.4] - 2026-10-14\n\n- Publish a prerelease.\n");
    releaseAssert(wingmanReleaseNotes($fixtureRoot, 'v1.2.3-rc.1+build.4')['version'] === '1.2.3-rc.1+build.4', 'Valid prerelease metadata was rejected.');

    releaseFixture($metadata, $changelog);
    $output = $fixtureRoot . '/notes.md';
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--tag=v0.3.0', '--output', $output]) === 0, 'CLI extraction failed.');
    releaseAssert(file_get_contents($output) === wingmanReleaseNotes($fixtureRoot)['body'], 'CLI output differs from the release summary.');
    unlink($output);
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--check']) === 0, 'CLI validation failed.');
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--tag', 'v0.4.0']) === 1, 'CLI accepted a mismatched release tag.');
    releaseAssert(wingmanReleaseNotesMain(['--unknown']) === 1, 'CLI accepted an unknown option.');
    releaseAssert(wingmanReleaseNotesMain(['--tag']) === 1, 'CLI accepted an option without a value.');
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--root', $fixtureRoot]) === 1, 'CLI accepted a duplicate option.');
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--output', $fixtureRoot . '/missing/notes.md']) === 1, 'CLI ignored a failed release-notes write.');

    // A valid historical release entry alone must not satisfy the promise
    // that every new user-facing update has its own summary.
    file_put_contents($fixtureRoot . '/README.md', "Original installation instructions.\n");
    mkdir($fixtureRoot . '/tests');
    file_put_contents($fixtureRoot . '/tests/example.php', "<?php // Baseline test.\n");
    wingmanReleaseGit($fixtureRoot, ['init', '--quiet']);
    wingmanReleaseGit($fixtureRoot, ['config', 'user.name', 'Wingman Release Test']);
    wingmanReleaseGit($fixtureRoot, ['config', 'user.email', 'release-test@example.invalid']);
    wingmanReleaseGit($fixtureRoot, ['add', '.']);
    wingmanReleaseGit($fixtureRoot, ['-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Fixture baseline']);
    $base = trim(wingmanReleaseGit($fixtureRoot, ['rev-parse', 'HEAD']) ?? '');
    wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0');
    releaseAssert(true, 'An unchanged repository should pass.');
    releaseRejects(fn () => wingmanRequireChangedSummary($fixtureRoot, 'main', '0.3.0'), 'complete Git commit SHA');
    releaseRejects(fn () => wingmanRequireChangedSummary($fixtureRoot, str_repeat('f', 40), '0.3.0'), 'Could not compare the change');

    file_put_contents($fixtureRoot . '/tests/example.php', "<?php // Improve only the test.\n");
    wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0');
    releaseAssert(true, 'Tests alone should not require a user-facing update summary.');
    file_put_contents($fixtureRoot . '/install.sh', "#!/usr/bin/env bash\n");
    releaseRejects(fn () => wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0'), 'User-facing updates require an update summary');
    unlink($fixtureRoot . '/install.sh');
    file_put_contents($fixtureRoot . '/README.md', "New installation instructions.\n");
    releaseRejects(fn () => wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0'), 'User-facing updates require an update summary');
    releaseFixture($metadata, str_replace('- Older features.', '- Edited older release.', $changelog));
    releaseRejects(fn () => wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0'), 'edits to older releases do not count');
    releaseFixture($metadata, str_replace('- This must not appear in release notes.', '<!-- Still no summary -->', $changelog));
    releaseRejects(fn () => wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0'), 'Describe this update');
    releaseFixture($metadata, str_replace('- This must not appear in release notes.', '- Explain the new installer command.', $changelog));
    wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0');
    releaseAssert(true, 'An edited Unreleased summary should pass.');
    releaseFixture($metadata, str_replace('- Linux installer and update command.', '- Add a simpler Linux installer command.', $changelog));
    wingmanRequireChangedSummary($fixtureRoot, $base, '0.3.0');
    releaseAssert(true, 'An edited current-version summary should pass.');
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--check', '--base', $base]) === 0, 'CLI base summary check failed.');
    releaseFixture($metadata, $changelog);
    releaseAssert(wingmanReleaseNotesMain(['--root', $fixtureRoot, '--check', '--base', $base]) === 1, 'CLI accepted a user-facing update with no new summary.');

    foreach (['README.md', 'install.sh', 'scripts/pelican-install.php', 'wingman-ai/src/Doctor.php', 'wingman-ai/config/wingman-ai.php', 'wingman-ai/resources/views/doctor.blade.php', 'wingman-ai/tools/package.php', 'dist/release.json', 'update.json', '.github/workflows/release.yml'] as $path) {
        releaseAssert(wingmanUserFacingPath($path), "User-facing path {$path} must require an update summary.");
    }
    foreach (['AGENTS.md', 'tests/release/example.php', 'wingman-ai/tests/php/example.php', 'CHANGELOG.md'] as $path) {
        releaseAssert(!wingmanUserFacingPath($path), "Internal-only path {$path} should not require a user-facing summary.");
    }
    fwrite(STDOUT, "Release summary checks passed ({$checks} assertions).\n");
} finally {
    // Only recursively remove the unique fixture directory created above.
    $resolvedFixture = realpath($fixtureRoot);
    $resolvedTemporaryRoot = realpath(sys_get_temp_dir());
    if ($resolvedFixture !== false && $resolvedTemporaryRoot !== false
        && dirname($resolvedFixture) === $resolvedTemporaryRoot
        && str_starts_with(basename($resolvedFixture), 'wingman-release-')) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedFixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                // Git object files may be marked read-only on Windows.
                chmod($file->getPathname(), 0600);
                unlink($file->getPathname());
            }
        }
        rmdir($resolvedFixture);
    }
}
