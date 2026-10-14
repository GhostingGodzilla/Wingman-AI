<?php

declare(strict_types=1);

function wingmanSummaryHasContent(string $notes): bool
{
    $meaningfulNotes = preg_replace('/<!--.*?-->/s', '', $notes);
    $meaningfulNotes = preg_replace('/^[ \t]*#{1,6}[ \t]+.*$/m', '', $meaningfulNotes ?? '');
    $meaningfulNotes = preg_replace('/^[ \t]*\[[^\]\n]+\]:.*$/m', '', $meaningfulNotes ?? '');

    return preg_match('/[\p{L}\p{N}]/u', $meaningfulNotes ?? '') === 1;
}

/** @return list<array{0: string, 1: string, 2: string}> */
function wingmanChangelogSections(string $changelog): array
{
    $changelog = str_replace(["\r\n", "\r"], "\n", $changelog);
    preg_match_all('/^##[ \t]+([^\n]+)\n(.*?)(?=^##[ \t]+|\z)/ms', $changelog, $sections, PREG_SET_ORDER);

    return $sections;
}

/**
 * Read a release summary from the same changelog users see on GitHub.
 * No dependencies or application bootstrap are needed.
 *
 * @return array{version: string, date: string, notes: string, body: string}
 */
function wingmanReleaseNotes(string $repositoryRoot, ?string $tag = null): array
{
    $metadataPath = $repositoryRoot . '/wingman-ai/plugin.json';
    $metadataContents = @file_get_contents($metadataPath);
    if ($metadataContents === false) {
        throw new RuntimeException('Could not read wingman-ai/plugin.json.');
    }
    try {
        $metadata = json_decode($metadataContents, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('plugin.json is not valid JSON.', 0, $exception);
    }
    $version = is_array($metadata) ? ($metadata['version'] ?? null) : null;
    $number = '(?:0|[1-9][0-9]*)';
    $prerelease = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
    $semver = '/^' . $number . '\.' . $number . '\.' . $number
        . '(?:-' . $prerelease . '(?:\.' . $prerelease . ')*)?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    if (!is_string($version) || preg_match($semver, $version) !== 1) {
        throw new RuntimeException('plugin.json must contain a valid semantic version, such as 0.3.0.');
    }
    if ($tag !== null && $tag !== 'v' . $version) {
        throw new RuntimeException("Release tag must be v{$version}; received {$tag}.");
    }

    $changelog = @file_get_contents($repositoryRoot . '/CHANGELOG.md');
    if ($changelog === false) {
        throw new RuntimeException('Could not read CHANGELOG.md. Every release needs a summary.');
    }
    $matching = [];
    foreach (wingmanChangelogSections($changelog) as $section) {
        // A bracketed version is required so a prefix such as 0.3.0-rc.1
        // cannot accidentally satisfy the released 0.3.0 summary check.
        if (preg_match('/^\[' . preg_quote($version, '/') . '\](?:[ \t]|$)/D', trim($section[1])) === 1) {
            $matching[] = $section;
        }
    }
    if (count($matching) !== 1) {
        throw new RuntimeException("CHANGELOG.md must contain exactly one ## [{$version}] - YYYY-MM-DD section.");
    }
    $section = $matching[0];
    if (preg_match('/^\[' . preg_quote($version, '/') . '\][ \t]+-[ \t]+(\d{4}-\d{2}-\d{2})$/D', trim($section[1]), $heading) !== 1) {
        throw new RuntimeException("The changelog section for {$version} needs a release date: ## [{$version}] - YYYY-MM-DD.");
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $heading[1]);
    if ($date === false || $date->format('Y-m-d') !== $heading[1]) {
        throw new RuntimeException("The release date for {$version} is invalid.");
    }
    $notes = trim($section[2]);
    // Empty headings, Markdown link definitions, and editorial comments do
    // not give users an update summary.
    if (!wingmanSummaryHasContent($notes)) {
        throw new RuntimeException("The changelog section for {$version} needs a nonempty update summary.");
    }

    return [
        'version' => $version,
        'date' => $heading[1],
        'notes' => $notes,
        'body' => "Released {$heading[1]}.\n\n{$notes}\n",
    ];
}

/** @param list<string> $arguments */
function wingmanReleaseGit(string $repositoryRoot, array $arguments, bool $allowFailure = false): ?string
{
    $pipes = [];
    $process = proc_open(['git', '-C', $repositoryRoot, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run Git to check the update summary.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        if ($allowFailure) {
            return null;
        }
        throw new RuntimeException('Could not compare the change with its base commit: ' . trim($error));
    }

    return $output;
}

function wingmanUserFacingPath(string $path): bool
{
    return preg_match('~^(?:install\.sh|update\.json|README\.md|LICENSE|scripts/|docs/|dist/|tools/|\.github/workflows/|wingman-ai/(?:plugin\.json|README\.md|VALIDATION\.md|LICENSE|config/|src/|resources/|tools/package\.php))~D', $path) === 1;
}

/** @return array<string, string> */
function wingmanActiveSummaries(string $changelog, string $version): array
{
    $summaries = [];
    foreach (wingmanChangelogSections($changelog) as $section) {
        if (preg_match('/^\[(Unreleased|' . preg_quote($version, '/') . ')\](?:[ \t]|$)/D', trim($section[1]), $heading) === 1) {
            if (isset($summaries[$heading[1]])) {
                throw new RuntimeException("CHANGELOG.md contains duplicate [{$heading[1]}] sections.");
            }
            $summaries[$heading[1]] = trim($section[2]);
        }
    }

    return $summaries;
}

function wingmanRequireChangedSummary(string $repositoryRoot, string $base, string $version): void
{
    if (preg_match('/^(?:[a-fA-F0-9]{40}|[a-fA-F0-9]{64})$/D', $base) !== 1) {
        throw new RuntimeException('--base requires a complete Git commit SHA.');
    }
    $changed = wingmanReleaseGit($repositoryRoot, ['diff', '--name-only', '--no-renames', $base, '--', '.']);
    $untracked = wingmanReleaseGit($repositoryRoot, ['ls-files', '--others', '--exclude-standard']);
    $paths = preg_split('/\r?\n/', trim(($changed ?? '') . "\n" . ($untracked ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!array_filter($paths, 'wingmanUserFacingPath')) {
        return;
    }
    if (!in_array('CHANGELOG.md', $paths, true)) {
        throw new RuntimeException('User-facing updates require an update summary in CHANGELOG.md.');
    }
    $changelog = @file_get_contents($repositoryRoot . '/CHANGELOG.md');
    if ($changelog === false) {
        throw new RuntimeException('Could not read CHANGELOG.md.');
    }
    // A new repository may not have had a changelog at its base commit.
    $previous = wingmanReleaseGit($repositoryRoot, ['show', $base . ':CHANGELOG.md'], true) ?? '';
    $previousSummaries = wingmanActiveSummaries($previous, $version);
    foreach (wingmanActiveSummaries($changelog, $version) as $section => $notes) {
        if (wingmanSummaryHasContent($notes) && $notes !== ($previousSummaries[$section] ?? '')) {
            return;
        }
    }

    throw new RuntimeException('Describe this update in [Unreleased] or the current version in CHANGELOG.md; edits to older releases do not count.');
}

/** @param list<string> $arguments */
function wingmanReleaseNotesMain(array $arguments): int
{
    $options = ['root' => dirname(__DIR__), 'tag' => null, 'output' => null, 'base' => null, 'check' => false];
    try {
        $seen = [];
        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--help' || $argument === '-h') {
                fwrite(STDOUT, "Usage: php tools/release-notes.php [--check] [--tag vX.Y.Z] [--base SHA] [--output FILE] [--root DIR]\n\nValidates the plugin version and its dated CHANGELOG.md summary.\n--base also requires a changed summary for user-facing updates.\nWithout --check or --output, prints only the current release notes.\n");
                return 0;
            }
            if ($argument === '--check') {
                $options['check'] = true;
                continue;
            }
            if (preg_match('/^--(root|tag|output|base)(?:=(.*))?$/D', $argument, $match) !== 1) {
                throw new RuntimeException("Unknown option: {$argument}");
            }
            $name = $match[1];
            if (isset($seen[$name])) {
                throw new RuntimeException("Option --{$name} was supplied more than once.");
            }
            $value = array_key_exists(2, $match) ? $match[2] : ($arguments[++$index] ?? '');
            if ($value === '' || str_starts_with($value, '--')) {
                throw new RuntimeException("Option --{$name} requires a value.");
            }
            $options[$name] = $value;
            $seen[$name] = true;
        }
        $release = wingmanReleaseNotes($options['root'], $options['tag']);
        if ($options['base'] !== null) {
            wingmanRequireChangedSummary($options['root'], $options['base'], $release['version']);
        }
        if ($options['output'] !== null) {
            if (@file_put_contents($options['output'], $release['body']) === false) {
                throw new RuntimeException("Could not write release notes: {$options['output']}");
            }
            fwrite(STDOUT, "Wrote Wingman AI {$release['version']} release notes to {$options['output']}.\n");
        } elseif (!$options['check']) {
            fwrite(STDOUT, $release['body']);
        }
        if ($options['check']) {
            fwrite(STDOUT, "Wingman AI {$release['version']} has an update summary dated {$release['date']}.\n");
        }
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Release notes: ' . $exception->getMessage() . "\n");
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(wingmanReleaseNotesMain(array_slice($argv, 1)));
}
