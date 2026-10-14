<?php

declare(strict_types=1);

namespace Wingman\Installer;

use App\Enums\PluginStatus;
use App\Models\Plugin;
use App\Services\Helpers\PluginService;
use App\Services\Helpers\SoftwareVersionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;
use ZipArchive;

const PLUGIN_ID = 'wingman-ai';
const PANEL_VERSION = '1.0.0-rc.1';
const MAX_ARCHIVE_BYTES = 25 * 1024 * 1024;
const MAX_EXPANDED_BYTES = 64 * 1024 * 1024;

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Could not read JSON: ' . $path);
    }
    $value = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException('Expected a JSON object: ' . $path);
    }

    return $value;
}

function writeJson(string $path, array $value): void
{
    $contents = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('Could not save plugin metadata.');
    }
}

function checkRuntime(): void
{
    if (PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80600) {
        throw new RuntimeException('Use PHP CLI 8.4 or 8.5 for Pelican ' . PANEL_VERSION . '; current CLI is ' . PHP_VERSION . '.');
    }
    if (!class_exists(ZipArchive::class) || !function_exists('proc_open')) {
        throw new RuntimeException('Enable PHP ZIP and proc_open for the PHP CLI before installing.');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        throw new RuntimeException('Run the Pelican helper as the non-root panel service account.');
    }
}

/** Return a canonical plugins folder; never accept a symlinked destination. */
function pluginsDirectory(string $panel): string
{
    $realPanel = realpath($panel);
    if ($realPanel === false || !is_file($realPanel . '/artisan') || !is_file($realPanel . '/bootstrap/app.php')) {
        throw new RuntimeException('Invalid Pelican Panel folder.');
    }
    $plugins = $realPanel . DIRECTORY_SEPARATOR . 'plugins';
    if (is_link($plugins) || !is_dir($plugins) || realpath($plugins) !== $plugins) {
        throw new RuntimeException('The plugins folder must be a real directory directly inside Pelican.');
    }
    if (!is_writable($plugins)) {
        throw new RuntimeException('The panel account cannot write ' . $plugins . '. Set its ownership for your Pelican service account and retry.');
    }

    return $plugins;
}

function bootstrapPanel(string $panel): object
{
    if (!chdir($panel)) {
        throw new RuntimeException('Could not enter the Pelican Panel folder.');
    }
    require $panel . '/vendor/autoload.php';
    $app = require $panel . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

/**
 * Pelican's installPlugin() runs `composer require` for every enabled plugin's
 * packages, even though Wingman AI has none. Composer defaults to dev mode, which
 * rebuilds a --no-dev panel with dev packages and an autoloader that requires the
 * panel's absent tests/ folder, so every page returns HTTP 500. Keep Composer in
 * the mode the panel was installed with; the registration child inherits this.
 */
function matchComposerInstallMode(string $panel): void
{
    if (getenv('COMPOSER_NO_DEV') !== false) {
        return;
    }
    $installed = $panel . '/vendor/composer/installed.json';
    if (!is_file($installed)) {
        return;
    }
    $data = json_decode((string) file_get_contents($installed), true, 512, JSON_THROW_ON_ERROR);
    if (is_array($data) && ($data['dev'] ?? null) === false && !putenv('COMPOSER_NO_DEV=1')) {
        throw new RuntimeException('Could not keep Composer in the panel\'s no-dev mode.');
    }
}

function preflight(string $panel, string $requiredVersion): void
{
    checkRuntime();
    pluginsDirectory($panel);
    if ($requiredVersion !== PANEL_VERSION) {
        throw new RuntimeException('This installer only supports Pelican ' . PANEL_VERSION . '.');
    }
    $app = bootstrapPanel($panel);
    if (!class_exists(Plugin::class) || !method_exists(PluginService::class, 'installPlugin') || !method_exists(Plugin::class, 'refreshRows')) {
        throw new RuntimeException('This Pelican installation does not support the required plugin API.');
    }
    $version = $app->make(SoftwareVersionService::class)->currentComparableVersion();
    if ($version !== $requiredVersion || Plugin::SUPPORTED_API_VERSION !== 1) {
        throw new RuntimeException('Wingman AI requires Pelican ' . $requiredVersion . ' / plugin API 1. Installed version: ' . ($version ?? 'canary/unknown') . '.');
    }
    if (!is_writable($panel . '/storage') || !is_writable($panel . '/bootstrap/cache')) {
        throw new RuntimeException('The selected panel account cannot write Pelican storage and bootstrap/cache.');
    }
    echo 'Pelican version, plugin API, and writable folders checked.' . PHP_EOL;
}

/** @return array<string, mixed> */
function validateManifest(string $path, array $release): array
{
    $data = readJson($path);
    foreach ([
        'id' => PLUGIN_ID,
        'namespace' => 'Pelican\\AiHelpAssistant',
        'class' => 'AiHelpAssistantPlugin',
        'category' => 'plugin',
        'api_version' => 1,
        'panel_version' => PANEL_VERSION,
        'version' => $release['version'],
    ] as $field => $expected) {
        if (($data[$field] ?? null) !== $expected) {
            throw new RuntimeException('Unexpected Wingman AI manifest field: ' . $field);
        }
    }
    if ($release['panel_version'] !== PANEL_VERSION || !empty($data['composer_packages'])) {
        throw new RuntimeException('Unsupported plugin compatibility or Composer packages.');
    }
    // A file-only rollback cannot undo a database migration or a seeder.
    if (is_dir(dirname($path) . '/database')) {
        throw new RuntimeException('This installer does not support plugin database migrations or seeders.');
    }
    foreach (['src/AiHelpAssistantPlugin.php', 'src/Providers/AiHelpAssistantProvider.php', 'config/wingman-ai.php'] as $required) {
        if (!is_file(dirname($path) . '/' . $required)) {
            throw new RuntimeException('The plugin archive is missing ' . $required . '.');
        }
    }

    return $data;
}

/** Validate every entry before writing anything. Only regular files/directories. */
function validatedEntries(ZipArchive $zip): array
{
    if ($zip->numFiles < 1 || $zip->numFiles > 5000) {
        throw new RuntimeException('Unexpected archive file count.');
    }
    $total = 0;
    $seen = [];
    $entries = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (!is_string($name) || preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $name) || !str_starts_with($name, PLUGIN_ID . '/')) {
            throw new RuntimeException('The archive must contain only the wingman-ai/ folder with safe paths.');
        }
        $trimmed = rtrim($name, '/');
        foreach (explode('/', $trimmed) as $component) {
            if ($component === '' || $component === '.' || $component === '..' || str_contains($component, ':')) {
                throw new RuntimeException('The archive contains an unsafe path.');
            }
        }
        if (isset($seen[$trimmed])) {
            throw new RuntimeException('The archive contains duplicate paths.');
        }
        $seen[$trimmed] = true;
        $attributes = 0;
        $operatingSystem = 0;
        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            throw new RuntimeException('Could not inspect an archive entry type.');
        }
        $type = ($attributes >> 16) & 0170000;
        $directory = str_ends_with($name, '/');
        if (!in_array($type, [0, 0100000, 0040000], true) || ($type === 0040000 && !$directory) || ($type === 0100000 && $directory)) {
            throw new RuntimeException('The archive contains a symlink or unsupported file type.');
        }
        $stat = $zip->statIndex($index);
        if ($stat === false || ($stat['encryption_method'] ?? 0) !== 0 || ($stat['size'] ?? -1) < 0) {
            throw new RuntimeException('The archive contains an unreadable or encrypted entry.');
        }
        $total += $stat['size'];
        if ($total > MAX_EXPANDED_BYTES) {
            throw new RuntimeException('The expanded plugin archive is too large.');
        }
        $entries[] = [$index, $name, $directory, $stat['size']];
    }

    return $entries;
}

/** Extract into a newly created private staging directory after full validation. */
function extractPackage(string $archive, string $stage): string
{
    if (!is_file($archive) || filesize($archive) > MAX_ARCHIVE_BYTES) {
        throw new RuntimeException('The ZIP is missing or exceeds the archive size limit.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Could not open the Wingman AI ZIP.');
    }
    try {
        $entries = validatedEntries($zip);
        foreach ($entries as [$index, $name, $directory, $size]) {
            $destination = $stage . '/' . $name;
            if ($directory) {
                if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
                    throw new RuntimeException('Could not create an archive directory.');
                }
                continue;
            }
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
                throw new RuntimeException('Could not create an archive parent directory.');
            }
            $input = $zip->getStream($name);
            $output = fopen($destination, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Could not extract an archive entry.');
            }
            try {
                if (stream_copy_to_stream($input, $output, $size + 1) !== $size) {
                    throw new RuntimeException('The extracted file size does not match the archive.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            chmod($destination, 0644);
        }
    } finally {
        $zip->close();
    }

    return $stage . '/' . PLUGIN_ID;
}

/** Delete only the installer-owned temporary stage under the verified plugins directory. */
function removeStage(string $stage, string $plugins): void
{
    if (dirname($stage) !== $plugins || !str_starts_with(basename($stage), '.wingman-ai-stage-') || is_link($stage) || !is_dir($stage)) {
        throw new RuntimeException('Refusing to remove an unexpected staging path.');
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) throw new RuntimeException('Could not remove temporary directory.');
        } elseif (!unlink($item->getPathname())) {
            throw new RuntimeException('Could not remove temporary file.');
        }
    }
    if (!rmdir($stage)) throw new RuntimeException('Could not remove the staging directory.');
}

/**
 * Deploy atomically and register via a fresh process. The callback must throw on
 * registration/verification failure. Settings in storage/ are never copied.
 *
 * @return array{changed: bool, version: string, backup: ?string, status: string}
 */
function installPackage(string $panel, string $archive, array $release, bool $force, callable $register): array
{
    $plugins = pluginsDirectory($panel);
    $target = $plugins . '/' . PLUGIN_ID;
    if (is_link($target) || (file_exists($target) && !is_dir($target))) {
        throw new RuntimeException('Refusing to replace a symlink or non-directory plugin target.');
    }
    $lockPath = $plugins . '/.wingman-ai-install.lock';
    if (is_link($lockPath)) throw new RuntimeException('Refusing a symlinked installer lock.');
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another Wingman AI installation is already running.');
    }
    $stage = $plugins . '/.wingman-ai-stage-' . bin2hex(random_bytes(8));
    $backup = null;
    $swapped = false;
    try {
        if (!isset($release['sha256'], $release['version'], $release['panel_version']) || !is_string($release['sha256']) || !preg_match('/^[a-fA-F0-9]{64}$/D', $release['sha256']) || !is_string($release['version']) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D', $release['version'])) {
            throw new RuntimeException('Invalid release metadata.');
        }
        if (!is_file($archive) || !hash_equals(strtolower($release['sha256']), hash_file('sha256', $archive))) {
            throw new RuntimeException('The package SHA-256 does not match the release manifest.');
        }
        $existing = null;
        if (is_dir($target)) {
            if (is_link($target . '/plugin.json')) throw new RuntimeException('Refusing symlinked plugin metadata.');
            $existing = readJson($target . '/plugin.json');
            if (($existing['id'] ?? null) !== PLUGIN_ID) {
                throw new RuntimeException('The existing folder belongs to a different plugin.');
            }
            if (!$force && version_compare($release['version'], (string) ($existing['version'] ?? '0.0.0'), '<')) {
                throw new RuntimeException('Refusing a downgrade. Use --force with an explicit version if intended.');
            }
        }
        $oldStatus = $existing['meta']['status'] ?? 'not_installed';
        if (!in_array($oldStatus, ['not_installed', 'enabled', 'disabled', 'errored', 'incompatible'], true)) {
            throw new RuntimeException('The existing plugin has an unrecognized installation status.');
        }
        if (!$force && ($existing['version'] ?? null) === $release['version'] && in_array($oldStatus, ['enabled', 'disabled'], true)) {
            return ['changed' => false, 'version' => $release['version'], 'backup' => null, 'status' => $oldStatus];
        }
        if (!mkdir($stage, 0700)) throw new RuntimeException('Could not create the staging directory.');
        $source = extractPackage($archive, $stage);
        $newData = validateManifest($source . '/plugin.json', $release);
        unset($newData['meta']);
        if (isset($existing['meta'])) $newData['meta'] = $existing['meta'];
        writeJson($source . '/plugin.json', $newData);
        if ($existing !== null) {
            $backup = $plugins . '/.wingman-ai-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
            if (!rename($target, $backup)) throw new RuntimeException('Could not back up the current plugin.');
        }
        try {
            if (!rename($source, $target)) throw new RuntimeException('Could not move the new plugin into place.');
            $swapped = true;
            $enable = $existing === null || $oldStatus === 'not_installed';
            $register($panel, $enable, $enable ? 'enabled' : $oldStatus, $release['version']);
        } catch (Throwable $exception) {
            // Keep a failed new copy in the stage for bounded cleanup. Never
            // delete or recursively move a user-supplied destination.
            if ($swapped && !rename($target, $stage . '/failed-plugin')) {
                throw new RuntimeException('Registration failed and rollback could not move the new copy. Previous files remain at ' . ($backup ?? '(fresh install)') . '. Original error: ' . $exception->getMessage(), 0, $exception);
            }
            if ($backup !== null && !rename($backup, $target)) {
                throw new RuntimeException('Registration failed and rollback could not restore the previous copy. Recover it from ' . $backup . '. Original error: ' . $exception->getMessage(), 0, $exception);
            }
            throw new RuntimeException('Installation failed; ' . ($backup !== null ? 'previous plugin files and metadata restored.' : 'the new plugin was removed.') . ' ' . $exception->getMessage(), 0, $exception);
        }

        return ['changed' => true, 'version' => $release['version'], 'backup' => $backup, 'status' => $enable ? 'enabled' : $oldStatus];
    } finally {
        if (is_dir($stage) && !is_link($stage)) removeStage($stage, $plugins);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function runChild(array $arguments): void
{
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if ($process === false || proc_close($process) !== 0) {
        throw new RuntimeException('Pelican ' . ($arguments[0] ?? 'registration') . ' did not complete successfully.');
    }
}

function registerPlugin(string $panel, bool $enable): void
{
    $app = bootstrapPanel($panel);
    Plugin::refreshRows();
    $plugin = Plugin::findOrFail(PLUGIN_ID);
    if (!$plugin->isCompatible() || !$plugin->isApiVersionSupported()) {
        throw new RuntimeException('Pelican rejected plugin compatibility.');
    }
    $app->make(PluginService::class)->installPlugin($plugin, $enable);
}

function verifyPlugin(string $panel, string $expectedStatus, string $version): void
{
    bootstrapPanel($panel);
    Plugin::refreshRows();
    $plugin = Plugin::findOrFail(PLUGIN_ID);
    if ($plugin->version !== $version || $plugin->status->value !== $expectedStatus || !$plugin->isCompatible()) {
        throw new RuntimeException('Pelican did not retain the expected plugin version/status after a fresh boot.');
    }
    if (!class_exists('Pelican\\AiHelpAssistant\\AiHelpAssistantPlugin') || !class_exists('Pelican\\AiHelpAssistant\\Providers\\AiHelpAssistantProvider')) {
        throw new RuntimeException('Pelican could not autoload the installed plugin classes.');
    }
    cache()->forget('plugins.wingman-ai.update');
    cache()->forget('plugins.wingman-ai.readme');
    foreach (['view:clear', 'queue:restart'] as $command) {
        if (Artisan::call($command) !== 0) throw new RuntimeException('Pelican could not run ' . $command . '.');
    }
}

function main(array $arguments): int
{
    try {
        $action = $arguments[1] ?? '';
        $panel = realpath($arguments[2] ?? '') ?: '';
        if ($panel === '') throw new RuntimeException('The panel folder does not exist.');
        switch ($action) {
            case 'preflight':
                preflight($panel, $arguments[3] ?? '');
                break;
            case 'install':
                $release = readJson($arguments[4] ?? '');
                preflight($panel, (string) ($release['panel_version'] ?? ''));
                matchComposerInstallMode($panel);
                $result = installPackage($panel, $arguments[3] ?? '', $release, ($arguments[5] ?? '') === '1', function (string $panel, bool $enable, string $expectedStatus, string $version): void {
                    runChild(['register', $panel, $enable ? '1' : '0']);
                    runChild(['verify', $panel, $expectedStatus, $version]);
                });
                echo $result['changed'] ? 'Installed Wingman AI ' . $result['version'] . ' (' . $result['status'] . ').' : 'Wingman AI ' . $result['version'] . ' is already installed (' . $result['status'] . ').';
                echo PHP_EOL;
                if ($result['backup'] !== null) echo 'Previous plugin backup: ' . $result['backup'] . PHP_EOL;
                break;
            case 'register':
                checkRuntime();
                registerPlugin($panel, ($arguments[3] ?? '') === '1');
                break;
            case 'verify':
                checkRuntime();
                verifyPlugin($panel, $arguments[3] ?? '', $arguments[4] ?? '');
                break;
            default:
                throw new RuntimeException('Use install.sh to run this helper.');
        }

        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Wingman AI: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main($argv));
}
