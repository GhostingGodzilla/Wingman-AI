<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$script = $root . '/wingman-ai/tools/package.php';
$count = 0;
function packageExpect(bool $condition, string $message): void
{
    global $count;
    $count++;
    if (!$condition) throw new RuntimeException($message);
}
function packageRun(string ...$arguments): int
{
    global $script;
    $process = proc_open([PHP_BINARY, $script, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run packaging.');
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
}
function packageCheck(string $expectedUrl, string $expectedNotes): string
{
    global $root;
    $metadata = json_decode(file_get_contents($root . '/wingman-ai/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
    $manifest = json_decode(file_get_contents($root . '/dist/release.json'), true, 32, JSON_THROW_ON_ERROR);
    $feed = json_decode(file_get_contents($root . '/update.json'), true, 32, JSON_THROW_ON_ERROR);
    $hash = hash_file('sha256', $root . '/dist/wingman-ai.zip');
    packageExpect($manifest['version'] === $metadata['version'], 'Release manifest version differs from plugin.');
    packageExpect($manifest['panel_version'] === $metadata['panel_version'], 'Compatibility differs between artifacts.');
    packageExpect($manifest['sha256'] === $hash, 'Manifest checksum does not match the downloadable ZIP.');
    packageExpect(file_get_contents($root . '/dist/wingman-ai.zip.sha256') === $hash . "  wingman-ai.zip\n", 'Checksum file does not match the ZIP.');
    packageExpect($manifest['download_url'] === $expectedUrl, 'Manifest points to the wrong download.');
    packageExpect($manifest['release_notes_url'] === $expectedNotes, 'Manifest points to the wrong update summary.');
    $update = $feed[$metadata['panel_version']] ?? [];
    packageExpect(($update['version'] ?? null) === $metadata['version'] && ($update['sha256'] ?? null) === $hash && ($update['download_url'] ?? null) === $expectedUrl, 'Pelican update feed disagrees with the package.');
    $zip = new ZipArchive();
    packageExpect($zip->open($root . '/dist/wingman-ai.zip') === true, 'ZIP is unreadable.');
    $inside = json_decode($zip->getFromName('wingman-ai/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
    packageExpect($inside === $metadata, 'ZIP contains old plugin metadata.');
    packageExpect($zip->getFromName('wingman-ai/CHANGELOG.md') === file_get_contents($root . '/CHANGELOG.md'), 'ZIP lacks the current update summary.');
    $zip->close();
    return $hash;
}

$metadata = json_decode(file_get_contents($root . '/wingman-ai/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$tag = 'v' . $metadata['version'];
try {
    packageExpect(packageRun() === 0, 'Default package build failed.');
    $hash = packageCheck('https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/main/dist/wingman-ai.zip', 'https://github.com/GhostingGodzilla/Wingman-AI/blob/main/CHANGELOG.md');
    packageExpect(packageRun('--release-tag', 'v9999.0.0') !== 0, 'A tag/version mismatch was accepted.');
    packageExpect(hash_file('sha256', $root . '/dist/wingman-ai.zip') === $hash, 'Rejected build modified the downloadable package.');
    packageExpect(packageRun('--release-tag', $tag) === 0, 'Tagged package build failed.');
    packageCheck('https://github.com/GhostingGodzilla/Wingman-AI/releases/download/' . $tag . '/wingman-ai.zip', 'https://github.com/GhostingGodzilla/Wingman-AI/releases/tag/' . $tag);
    echo "Packaging: $count checks passed.\n";
} finally {
    // Keep the tracked installer manifest pointing at the repository package.
    if (packageRun() !== 0) throw new RuntimeException('Could not restore the normal installation artifacts.');
}
