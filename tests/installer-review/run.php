<?php

declare(strict_types=1);

namespace Wingman\InstallerReview;

use RuntimeException;
use Throwable;
use ZipArchive;

use function Wingman\Installer\installPackage;

require dirname(__DIR__, 2) . '/scripts/pelican-install.php';

// Independent filesystem and hostile-input checks. These do not boot or imitate
// Laravel: production registration still requires testing on a real Panel.
$roots = [];

function expect(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function removeFixture(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                removeFixture($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}

function fixture(array $extra = []): array
{
    global $roots;
    $root = rtrim(sys_get_temp_dir(), '/\\') . '/wingman-installer-review-' . bin2hex(random_bytes(8));
    expect(mkdir($root, 0700), 'Could not create fixture root.');
    $roots[] = $root;
    $panel = $root . '/panel';
    foreach (['plugins', 'storage', 'bootstrap/cache'] as $directory) {
        mkdir($panel . '/' . $directory, 0755, true);
    }
    file_put_contents($panel . '/artisan', '<?php');
    file_put_contents($panel . '/bootstrap/app.php', '<?php');
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/wingman-ai/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['version'] = '0.3.0';
    $manifest = array_replace($manifest, $extra);
    $archive = $root . '/package.zip';
    makeArchive($archive, [
        'wingman-ai/plugin.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
        'wingman-ai/src/AiHelpAssistantPlugin.php' => '<?php // new version',
        'wingman-ai/src/Providers/AiHelpAssistantProvider.php' => '<?php // new provider',
        'wingman-ai/resources/views/widgets/ai-help-assistant.blade.php' => 'fixture view',
        'wingman-ai/config/wingman-ai.php' => '<?php return [];',
    ]);

    return [$root, $panel, $archive, $manifest];
}

function makeArchive(string $path, array $entries): void
{
    $zip = new ZipArchive();
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Could not make fixture zip.');
    foreach ($entries as $name => $body) {
        expect($zip->addFromString($name, $body), 'Could not add archive fixture.');
    }
    $zip->close();
}

function releaseFor(string $archive, array $manifest): array
{
    return [
        'id' => 'wingman-ai',
        'version' => $manifest['version'],
        'panel_version' => $manifest['panel_version'],
        'api_version' => 1,
        'tag' => 'v' . $manifest['version'],
        'sha256' => hash_file('sha256', $archive),
        'archive' => 'wingman-ai.zip',
    ];
}

function oldInstall(string $panel, string $status = 'disabled'): string
{
    $path = $panel . '/plugins/wingman-ai';
    mkdir($path);
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/wingman-ai/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['version'] = '0.2.0';
    $manifest['meta'] = ['status' => $status, 'load_order' => 7, 'status_message' => null];
    file_put_contents($path . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($path . '/old-source.php', 'old-source-remains-on-failure');

    return file_get_contents($path . '/plugin.json');
}

function reject(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected the installer to reject this input.');
}

$tests = [];
$tests['failed new installation removes the replacement'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture();
    $called = 0;
    $error = reject(static function () use ($panel, $archive, $manifest, &$called): void {
        installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): never {
            $called++;
            throw new RuntimeException('registration failed');
        });
    });
    expect($called === 1, 'Registration was not attempted exactly once.');
    expect(str_contains($error->getMessage(), 'registration failed'), 'Registration failure was hidden.');
    expect(!file_exists($panel . '/plugins/wingman-ai'), 'Failed first install left an installed-looking plugin.');
};

$tests['failed update restores original bytes and status'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture();
    $original = oldInstall($panel);
    reject(static function () use ($panel, $archive, $manifest): void {
        installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use ($panel): never {
            file_put_contents($panel . '/plugins/wingman-ai/plugin.json', '{"meta":{"status":"not_installed"}}');
            throw new RuntimeException('migration failed after metadata write');
        });
    });
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Original manifest did not survive rollback byte for byte.');
    expect(file_get_contents($panel . '/plugins/wingman-ai/old-source.php') === 'old-source-remains-on-failure', 'Original source was lost.');
};

$tests['archive paths cannot escape before registration'] = static function (): void {
    foreach (['../escaped.php', '/absolute.php', 'wingman-ai/../../escaped.php', 'wingman-ai\\..\\escaped.php'] as $badName) {
        [$root, $panel, $archive, $manifest] = fixture();
        $original = oldInstall($panel);
        $zip = new ZipArchive();
        expect($zip->open($archive) === true, 'Could not modify zip.');
        $zip->addFromString($badName, 'hostile-content');
        $zip->close();
        $called = 0;
        reject(static function () use ($panel, $archive, $manifest, &$called): void {
            installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): void { $called++; });
        });
        expect($called === 0, 'Unsafe zip reached registration: ' . $badName);
        expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Unsafe zip modified the old plugin.');
        expect(!file_exists($root . '/escaped.php') && !file_exists($panel . '/escaped.php'), 'Archive escaped staging.');
    }
};

$tests['Unix symlink archive entries are rejected'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture();
    $zip = new ZipArchive();
    expect($zip->open($archive) === true, 'Could not modify zip.');
    $zip->addFromString('wingman-ai/external-link', '../../outside');
    $zip->setExternalAttributesName('wingman-ai/external-link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    $called = 0;
    reject(static function () use ($panel, $archive, $manifest, &$called): void {
        installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): void { $called++; });
    });
    expect($called === 0, 'Archive symlink reached registration.');
    expect(!file_exists($panel . '/plugins/wingman-ai'), 'Archive symlink created a target plugin.');
};

$tests['an archive for another plugin cannot replace Wingman'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture(['id' => 'other-plugin']);
    $original = oldInstall($panel);
    reject(static fn () => installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function (): void {}));
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Wrong-plugin archive replaced Wingman.');
};

$tests['checksum failure cannot reach registration or replace source'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture();
    $original = oldInstall($panel);
    $release = releaseFor($archive, $manifest);
    $release['sha256'] = str_repeat('0', 64);
    $called = 0;
    reject(static function () use ($panel, $archive, $release, &$called): void {
        installPackage($panel, $archive, $release, false, static function () use (&$called): void { $called++; });
    });
    expect($called === 0, 'Unverified archive reached registration.');
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Unverified archive replaced source.');
};

$tests['successful update preserves disabled state and ignores archived metadata'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture(['meta' => ['status' => 'enabled', 'load_order' => 999]]);
    $original = oldInstall($panel);
    $calls = 0;
    $result = installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function (string $passedPanel, bool $enable, string $expectedStatus, string $version) use ($panel, &$calls): void {
        $calls++;
        expect($passedPanel === $panel && $enable === false && $expectedStatus === 'disabled' && $version === '0.3.0', 'Disabled update registration arguments were incorrect.');
        $deployed = json_decode(file_get_contents($panel . '/plugins/wingman-ai/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($deployed['meta']['status'] === 'disabled' && $deployed['meta']['load_order'] === 7, 'Archive metadata overrode the old installation state.');
    });
    expect($calls === 1 && $result['changed'] === true && $result['status'] === 'disabled', 'Successful update result was incorrect.');
    expect($result['backup'] !== null && file_get_contents($result['backup'] . '/plugin.json') === $original, 'The documented backup was not retained.');
    expect(!file_exists($panel . '/plugins/wingman-ai/old-source.php'), 'An obsolete source file remained in the new version.');
};

$tests['first install discards archive claims to be enabled'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture(['meta' => ['status' => 'enabled', 'load_order' => 999]]);
    installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function (string $passedPanel, bool $enable, string $expectedStatus) use ($panel): void {
        expect($passedPanel === $panel && $enable && $expectedStatus === 'enabled', 'First install was not registered for enabling.');
        $deployed = json_decode(file_get_contents($panel . '/plugins/wingman-ai/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        expect(!isset($deployed['meta']), 'First install trusted archived installation metadata.');
    });
};

$tests['same version is a no-op and downgrade is refused'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture(['version' => '0.2.0']);
    $original = oldInstall($panel);
    $called = 0;
    $result = installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): void { $called++; });
    expect(!$result['changed'] && $called === 0, 'Same-version update registered again.');
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Same-version no-op rewrote the original.');
    $manifest['version'] = '0.1.0';
    $release = releaseFor($archive, $manifest);
    reject(static fn () => installPackage($panel, $archive, $release, false, static function (): void {}));
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Refused downgrade changed installed files.');
};

$tests['unsupported API and database changes cannot begin registration'] = static function (): void {
    foreach (['api', 'database'] as $kind) {
        [, $panel, $archive, $manifest] = fixture($kind === 'api' ? ['api_version' => 2] : []);
        $original = oldInstall($panel);
        if ($kind === 'database') {
            $zip = new ZipArchive();
            expect($zip->open($archive) === true, 'Could not modify zip.');
            $zip->addFromString('wingman-ai/database/migrations/new.php', '<?php');
            $zip->close();
        }
        $called = 0;
        reject(static function () use ($panel, $archive, $manifest, &$called): void {
            installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): void { $called++; });
        });
        expect($called === 0, 'An unsupported ' . $kind . ' change reached registration.');
        expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'An unsupported update replaced source.');
    }
};

$tests['symlinked destinations and metadata do not touch an outside folder'] = static function (): void {
    foreach (['target', 'plugins', 'metadata'] as $kind) {
        [$root, $panel, $archive, $manifest] = fixture();
        $outside = $root . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'outside-must-survive');
        if ($kind === 'target') {
            $link = $panel . '/plugins/wingman-ai';
            $destination = $outside;
        } elseif ($kind === 'plugins') {
            rmdir($panel . '/plugins');
            $link = $panel . '/plugins';
            $destination = $outside;
        } else {
            oldInstall($panel);
            unlink($panel . '/plugins/wingman-ai/plugin.json');
            file_put_contents($outside . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $link = $panel . '/plugins/wingman-ai/plugin.json';
            $destination = $outside . '/plugin.json';
        }
        if (!@symlink($destination, $link)) {
            echo 'SKIP operating system does not permit creating ' . $kind . ' symlink fixtures.' . PHP_EOL;
            continue;
        }
        $called = 0;
        reject(static function () use ($panel, $archive, $manifest, &$called): void {
            installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): void { $called++; });
        });
        expect($called === 0 && file_get_contents($outside . '/keep.txt') === 'outside-must-survive', 'Symlinked ' . $kind . ' reached or modified the outside directory.');
    }
};

$tests['rollback cleanup unlinks injected symlinks without following them'] = static function (): void {
    [$root, $panel, $archive, $manifest] = fixture();
    $outside = $root . '/outside';
    mkdir($outside);
    file_put_contents($outside . '/keep.txt', 'outside-must-survive');
    $probe = $root . '/symlink-probe';
    if (!@symlink($outside, $probe)) {
        echo 'SKIP operating system does not permit symlink cleanup fixtures.' . PHP_EOL;
        return;
    }
    unlink($probe);
    $original = oldInstall($panel);
    reject(static function () use ($panel, $archive, $manifest, $outside): void {
        installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use ($panel, $outside): never {
            expect(symlink($outside, $panel . '/plugins/wingman-ai/external'), 'Could not inject cleanup symlink.');
            throw new RuntimeException('failed registration with a symlink left by plugin code');
        });
    });
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Cleanup test did not restore original manifest.');
    expect(file_get_contents($outside . '/keep.txt') === 'outside-must-survive', 'Rollback cleanup followed an injected symlink.');
};

$tests['a second install cannot overlap registration and a failed install can retry'] = static function (): void {
    [, $panel, $archive, $manifest] = fixture();
    $original = oldInstall($panel);
    $release = releaseFor($archive, $manifest);
    reject(static function () use ($panel, $archive, $release): void {
        installPackage($panel, $archive, $release, false, static function () use ($panel, $archive, $release): never {
            $blocked = reject(static fn () => installPackage($panel, $archive, $release, false, static function (): void {}));
            expect(str_contains($blocked->getMessage(), 'already running'), 'A concurrent installer was not blocked by the lock.');
            throw new RuntimeException('first registration failed');
        });
    });
    expect(file_get_contents($panel . '/plugins/wingman-ai/plugin.json') === $original, 'Concurrent installation disturbed rollback.');
    $result = installPackage($panel, $archive, $release, false, static function (): void {});
    expect($result['changed'], 'Failed registration left a lock that prevented retry.');
};

$tests['a small ZIP with oversized expanded contents is rejected before registration'] = static function (): void {
    [$root, $panel, $archive, $manifest] = fixture();
    $payload = $root . '/oversized-payload';
    $file = fopen($payload, 'wb');
    expect(is_resource($file), 'Could not create sparse ZIP fixture.');
    expect(ftruncate($file, 65 * 1024 * 1024), 'Could not size sparse ZIP fixture.');
    fclose($file);
    $zip = new ZipArchive();
    expect($zip->open($archive) === true, 'Could not modify zip.');
    $zip->addFile($payload, 'wingman-ai/oversized-payload');
    $zip->close();
    expect(filesize($archive) < 25 * 1024 * 1024, 'ZIP fixture did not compress below the archive limit.');
    $called = 0;
    $error = reject(static function () use ($panel, $archive, $manifest, &$called): void {
        installPackage($panel, $archive, releaseFor($archive, $manifest), false, static function () use (&$called): void { $called++; });
    });
    expect(str_contains($error->getMessage(), 'expanded') && $called === 0, 'Oversized expanded archive was not rejected at validation.');
    expect(!file_exists($panel . '/plugins/wingman-ai'), 'Oversized expanded archive reached the plugin destination.');
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
} finally {
    foreach ($roots as $root) {
        $prefix = rtrim(sys_get_temp_dir(), '/\\') . '/wingman-installer-review-';
        expect(str_starts_with($root, $prefix), 'Fixture cleanup escaped its named temporary directory.');
        removeFixture($root);
    }
}
echo count($tests) . ' independent installer checks, ' . $failed . ' failures.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
