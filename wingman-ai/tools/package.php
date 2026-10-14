<?php

declare(strict_types=1);

// Run with PHP CLI; the panel already requires ext-zip.
if (PHP_SAPI !== 'cli' || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Run this with PHP CLI and the zip extension enabled.\n");
    exit(1);
}

$root = dirname(__DIR__);
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

$verify = new ZipArchive();
if ($verify->open($path) !== true || $verify->getFromName('wingman-ai/plugin.json') === false || $verify->numFiles !== $count) {
    throw new RuntimeException('Archive verification failed.');
}
$verify->close();
fwrite(STDOUT, "Packaged $count files: $path\nSHA256: " . hash_file('sha256', $path) . "\n");
