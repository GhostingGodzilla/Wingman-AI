<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

/**
 * Works out which public ports a game server needs, and picks free ones from the
 * admin's forwarded block. Admin and remote-control ports are never made public.
 */
final class GamePorts
{
    /** Games that also use the next few ports after their main port, without a setting for them. */
    private const FOLLOWING_PORTS = [
        '7 days to die' => 3,
        'valheim' => 2,
        'unturned' => 2,
        'arma 3' => 4,
        'ark: survival evolved' => 1,
    ];

    /** Remote admin consoles and web panels stay inside the container. */
    private const PRIVATE_PORT = '/RCON|TELNET|WEB|HTTP|ADMIN|CONTROL|API|DASHBOARD|METRIC|PROMETHEUS|MANAGE|CONSOLE/i';

    /**
     * @param  array<string, string>  $variables  Egg variable names and their current values.
     * @return array{following: int, variables: list<string>, private: list<string>}
     */
    public static function needs(string $game, array $variables): array
    {
        $following = 0;
        foreach (self::FOLLOWING_PORTS as $name => $count) {
            if (str_contains(strtolower($game), $name)) {
                $following = $count;
                break;
            }
        }
        $public = [];
        $private = [];
        foreach ($variables as $name => $value) {
            if (!self::looksLikePort($name, $value)) {
                continue;
            }
            if (preg_match(self::PRIVATE_PORT, $name) === 1) {
                $private[] = $name;
            } else {
                $public[] = $name;
            }
        }
        sort($public);
        sort($private);

        return ['following' => $following, 'variables' => $public, 'private' => $private];
    }

    /**
     * One unbroken run of free ports: the main port, the ports that follow it, then one per setting.
     *
     * @param  array{following: int, variables: list<string>}  $needs
     * @param  list<int>  $taken  Ports already allocated on the node.
     * @return array{main: int, following: list<int>, variables: array<string, int>}|null
     */
    public static function plan(array $needs, array $taken, int $start, int $end): ?array
    {
        $count = 1 + $needs['following'] + count($needs['variables']);
        $taken = array_flip($taken);
        for ($first = $start; $first + $count - 1 <= $end; $first++) {
            $free = true;
            for ($port = $first; $port < $first + $count; $port++) {
                if (isset($taken[$port])) {
                    $free = false;
                    $first = $port; // Skip past the taken port.
                    break;
                }
            }
            if ($free) {
                $variables = [];
                foreach ($needs['variables'] as $index => $name) {
                    $variables[$name] = $first + 1 + $needs['following'] + $index;
                }

                return [
                    'main' => $first,
                    'following' => $needs['following'] > 0 ? range($first + 1, $first + $needs['following']) : [],
                    'variables' => $variables,
                ];
            }
        }

        return null;
    }

    private static function looksLikePort(string $name, string $value): bool
    {
        return preg_match('/PORT|QUERY|STEAM|BEACON|PEER/i', $name) === 1
            && preg_match('/APPID|APP_ID|BRANCH|BETA|USER|PASS|TOKEN|KEY/i', $name) !== 1
            && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535;
    }
}
