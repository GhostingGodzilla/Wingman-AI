<?php

namespace Pelican\AiHelpAssistant\Services;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One-click repairs for a few deterministic diagnoses. A person starts every repair
 * from a report and confirms it; automatic observation never calls apply().
 */
class RepairService
{
    private const PERMISSIONS = [
        'java_image' => SubuserPermission::StartupDockerImage,
        'reinstall' => SubuserPermission::SettingsReinstall,
        'eula' => SubuserPermission::FileUpdate,
    ];

    /**
     * Describe the repair this user may apply to the report, or null when none applies.
     *
     * @return array{type: string, images?: array<string, string>, suggested?: ?string}|null
     */
    public function offer(Server $server, User $user, ?array $report): ?array
    {
        $fix = is_array($report['fix'] ?? null) ? $report['fix'] : null;
        $type = $fix['type'] ?? null;
        if (!is_string($type) || !isset(self::PERMISSIONS[$type]) || !$user->can(self::PERMISSIONS[$type], $server)) {
            return null;
        }
        if ($type === 'reinstall') {
            return ['type' => $type, 'backup' => app(SafeReinstallService::class)->backupStatus($server, $user)];
        }
        if ($type !== 'java_image') {
            return ['type' => $type];
        }

        $images = $this->imageChoices($server);
        if ($images === []) {
            return null;
        }

        return ['type' => $type, 'images' => $images, 'suggested' => $this->suggestImage($images, (int) ($fix['java'] ?? 0))];
    }

    /** Apply the report's repair after reauthorizing it; returns a message for the user. */
    public function apply(Server $server, User $user, ?array $report, ?string $image = null, bool $backupFirst = false): string
    {
        $offer = $this->offer($server, $user, $report);
        $this->assert($offer !== null, 'This fix is no longer available. Run a new diagnosis.');
        if ($offer['type'] === 'java_image') {
            $this->assert(is_string($image) && array_key_exists($image, $offer['images']), 'Choose one of the Java images offered for this server.');
        }

        $key = 'wingman-ai:repair:' . $user->id . ':' . $server->id;
        $this->assert(!RateLimiter::tooManyAttempts($key, 3), 'Please wait a few minutes before applying another fix.');
        RateLimiter::hit($key, 300);

        // Shares the capture's power lock so a repair cannot race a diagnostic start.
        return Cache::lock('wingman-ai:power:' . $server->id, 15)->block(3, function () use ($server, $user, $offer, $image, $backupFirst) {
            $server->refresh();
            try {
                $server->validateCurrentState();
            } catch (Throwable) {
                $this->assert(false, 'The server is installing, restoring, transferring, or suspended. Try again when it is available.');
            }

            return match ($offer['type']) {
                'java_image' => $this->switchImage($server, $user, $offer, $image),
                'reinstall' => app(SafeReinstallService::class)->reinstall($server, $user, $backupFirst, 'repair'),
                'eula' => $this->acceptEula($server, $user),
            };
        });
    }

    private function switchImage(Server $server, User $user, array $offer, string $image): string
    {
        $original = $server->image;
        $server->forceFill(['image' => $image])->saveOrFail();
        Activity::event('server:startup.image')->subject($server)->actor($user)
            ->property(['old' => $original, 'new' => $image, 'source' => 'wingman-ai'])->log();

        return $this->restart($server, $user, 'Switched to ' . $offer['images'][$image] . '.');
    }

    private function acceptEula(Server $server, User $user): string
    {
        app(DaemonFileRepository::class)->setServer($server)->putContent('eula.txt', 'eula=true');
        Activity::event('server:file.write')->subject($server)->actor($user)
            ->property(['file' => '/eula.txt', 'source' => 'wingman-ai'])->log();

        return $this->restart($server, $user, 'Minecraft EULA accepted.');
    }

    /** Restart only with the user's own power permission; Wingman watches the new attempt. */
    private function restart(Server $server, User $user, string $done): string
    {
        if (!$user->can(SubuserPermission::ControlRestart, $server)) {
            return $done . ' Start the server when you are ready.';
        }
        try {
            app(AutomaticStartupService::class)->attempt($server, 'restart');
        } catch (Throwable) {
            // Observation is optional; it must never block the repair's restart.
        }
        app(DaemonServerRepository::class)->setServer($server)->power('restart')->throw();
        Activity::event('server:power.restart')->subject($server)->actor($user)->property('source', 'wingman-ai')->log();

        return $done . ' The server is restarting.';
    }

    /**
     * The egg's own image list, keyed by image. An administrator's custom image is
     * left alone, matching Pelican's built-in Java version prompt.
     *
     * @return array<string, string>
     */
    private function imageChoices(Server $server): array
    {
        $images = [];
        foreach ((array) ($server->egg->docker_images ?? []) as $label => $image) {
            if (is_string($image) && $image !== '') {
                $images[$image] = is_string($label) && $label !== '' ? $label : $image;
            }
        }
        if (!array_key_exists((string) $server->image, $images)) {
            return [];
        }
        unset($images[$server->image]);

        return $images;
    }

    /** Prefer the exact Java version, otherwise the closest newer one. */
    private function suggestImage(array $images, int $java): ?string
    {
        if ($java <= 0) {
            return null;
        }
        $best = null;
        $bestVersion = PHP_INT_MAX;
        foreach ($images as $image => $label) {
            if (!preg_match('/(?:java|jdk|jre|temurin)[ _:-]?(\d+)/i', $label . ' ' . $image, $match)) {
                continue;
            }
            $version = (int) $match[1];
            if ($version >= $java && $version < $bestVersion) {
                [$best, $bestVersion] = [$image, $version];
            }
        }

        return $best;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['repair' => $message]);
        }
    }
}
