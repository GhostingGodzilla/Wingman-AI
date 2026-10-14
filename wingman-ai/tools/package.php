<?php

declare(strict_types=1);

// Run with PHP CLI; the panel already requires ext-zip.
if (PHP_SAPI !== 'cli' || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Run this with PHP CLI and the zip extension enabled.\n");
    exit(1);
}

$root = dirname(__DIR__);
$repository = dirname($root);
$metadata = json_decode((string) file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$version = $metadata['version'] ?? '';
$panelVersion = $metadata['panel_version'] ?? '';
if (!is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D', $version)
    || !is_string($panelVersion) || $panelVersion === '' || ($metadata['id'] ?? null) !== 'wingman-ai') {
    throw new RuntimeException('The plugin manifest has an invalid ID, version, or panel version.');
}
$tag = null;
if (count($argv) > 1) {
    if (count($argv) !== 3 || $argv[1] !== '--release-tag' || $argv[2] !== 'v' . $version) {
        throw new RuntimeException('Usage: php tools/package.php [--release-tag v' . $version . ']');
    }
    $tag = $argv[2];
}
require $repository . '/tools/release-notes.php';
wingmanReleaseNotes($repository, $tag);
$destination = dirname($root) . '/dist';
if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
    throw new RuntimeException('Could not create the dist directory.');
}
$path = $destination . '/wingman-ai.zip';
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create the plugin archive.');
}

$count = 0;
foreach (['plugin.json', 'README.md', 'VALIDATION.md', 'LICENSE', 'config', 'src', 'resources'] as $entry) {
    $entryPath = $root . '/' . $entry;
    $files = is_dir($entryPath)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entryPath, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($entryPath)];
    foreach ($files as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (!$zip->addFile($file->getPathname(), 'wingman-ai/' . $relative)) {
            throw new RuntimeException('Could not add a source file to the archive.');
        }
        $count++;
    }
}
$zip->close();

$changelogZip = new ZipArchive();
if ($changelogZip->open($path) !== true || !$changelogZip->addFile($repository . '/CHANGELOG.md', 'wingman-ai/CHANGELOG.md')) {
    throw new RuntimeException('Could not add the update log to the plugin archive.');
}
$count++;
$changelogZip->close();

$verify = new ZipArchive();
if ($verify->open($path) !== true || $verify->getFromName('wingman-ai/plugin.json') === false || $verify->numFiles !== $count) {
    throw new RuntimeException('Archive verification failed.');
}
$verify->close();
$sha256 = hash_file('sha256', $path);
$downloadUrl = $tag === null
    ? 'https://raw.githubusercontent.com/GhostingGodzilla/Wingman-AI/main/dist/wingman-ai.zip'
    : 'https://github.com/GhostingGodzilla/Wingman-AI/releases/download/' . $tag . '/wingman-ai.zip';
$notesUrl = $tag === null
    ? 'https://github.com/GhostingGodzilla/Wingman-AI/blob/main/CHANGELOG.md'
    : 'https://github.com/GhostingGodzilla/Wingman-AI/releases/tag/' . $tag;
$artifacts = [
    $path . '.sha256' => $sha256 . "  wingman-ai.zip\n",
    $destination . '/release.json' => json_encode([
        'version' => $version,
        'panel_version' => $panelVersion,
        'download_url' => $downloadUrl,
        'sha256' => $sha256,
        'release_notes_url' => $notesUrl,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    $repository . '/update.json' => json_encode([
        $panelVersion => ['version' => $version, 'download_url' => $downloadUrl, 'sha256' => $sha256],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
];
foreach ($artifacts as $artifact => $contents) {
    if (file_put_contents($artifact, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('Could not write a release artifact.');
    }
}
fwrite(STDOUT, "Packaged $count files: $path\nVersion: $version\nSHA256: $sha256\n");
