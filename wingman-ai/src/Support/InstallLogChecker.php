<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

/**
 * Finds failures in an install log. Many egg install scripts keep going after an
 * error, so Pelican can mark a server installed although its files never arrived.
 */
final class InstallLogChecker
{
    /** Returns a report when the install failed or its log shows errors, otherwise null. */
    public static function check(string $log, bool $successful): ?array
    {
        $log = SecretRedactor::redact(str_replace("\r\n", "\n", $log));
        $found = [];
        foreach (self::rules() as $rule) {
            if (preg_match_all($rule['pattern'], $log, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [, $offset]) {
                    $found[] = ['offset' => $offset, 'line' => self::lineAt($log, $offset), 'rule' => $rule];
                }
            }
        }
        if ($found === [] && $successful) {
            return null;
        }

        // The first rule in priority order explains the failure; the evidence keeps log order.
        $primary = null;
        foreach (self::rules() as $rule) {
            foreach ($found as $match) {
                if ($match['rule']['id'] === $rule['id']) {
                    $primary = $match;
                    break 2;
                }
            }
        }
        usort($found, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        $evidence = array_values(array_unique(array_column($found, 'line')));

        if ($primary === null) {
            $tail = array_slice(array_values(array_filter(explode("\n", trim($log)), static fn (string $line) => trim($line) !== '')), -5);

            return self::report(
                'The installation failed.',
                'Wings reported that the install script did not finish successfully, but the log does not contain a recognized error.',
                'medium',
                $tail === [] ? ['The install log is empty.'] : $tail,
                ['Reinstall the server once; temporary download or network problems often clear up.', 'If it fails again, read the end of the install log in the admin area (Servers → this server → View install log).', 'If the install script itself looks wrong, an admin can use Egg check.'],
            );
        }

        $rule = $primary['rule'];
        $steps = $rule['steps'];
        if ($rule['id'] === 'steam_install' && stripos($primary['line'], 'Missing configuration') !== false) {
            array_unshift($steps, 'This SteamCMD error ("Missing configuration") often happens on the first try and goes away when you reinstall.');
        }
        if ($rule['id'] === 'steam_install' && stripos($primary['line'], 'No subscription') !== false) {
            array_unshift($steps, '"No subscription" means this game needs a Steam account that owns it, or the egg uses the wrong app ID.');
        }

        return self::report(
            $successful ? 'The install finished with errors: ' . $rule['summary'] : $rule['summary'],
            $rule['cause'] . ($successful ? ' The install script kept going after the error, so Pelican marked the server as installed anyway.' : ''),
            $rule['confidence'],
            array_slice($evidence, 0, 6),
            $steps,
        );
    }

    private static function report(string $summary, string $cause, string $confidence, array $evidence, array $steps): array
    {
        return [
            'summary' => $summary,
            'likely_cause' => $cause,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'steps' => $steps,
            'needs_more_information' => [],
            'source' => 'rules',
            'fix' => ['type' => 'reinstall'],
        ];
    }

    private static function lineAt(string $log, int $offset): string
    {
        $start = strrpos(substr($log, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($log, "\n", $offset);
        $line = trim(substr($log, $start, $end === false ? null : $end - $start));

        return function_exists('mb_substr') ? mb_substr($line, 0, 400, 'UTF-8') : substr($line, 0, 400);
    }

    /** Ordered by how well each explains a failed install. */
    private static function rules(): array
    {
        $retry = 'If it keeps failing, an admin can use Egg check to see whether the install script needs to retry or stop on errors.';

        return [
            [
                'id' => 'steam_install', 'confidence' => 'high',
                'pattern' => "/ERROR! (?:Failed to install app '\\d+'[^\\n]*|Timeout downloading item[^\\n]*|Download item \\d+ failed[^\\n]*)/i",
                'summary' => 'SteamCMD could not download the game.',
                'cause' => 'SteamCMD reported an error while downloading the game files, so they are missing or incomplete.',
                'steps' => ['Reinstall the server.', 'Make sure the server has enough disk space for the game.', $retry],
            ],
            [
                'id' => 'steam_state', 'confidence' => 'high',
                'pattern' => "/Error! App '\\d+' state is 0x[0-9a-f]+ after update job/i",
                'summary' => 'SteamCMD stopped with an error state.',
                'cause' => 'SteamCMD finished the update job in an error state; codes like this usually mean a failed download, a full disk, or a temporary Steam outage.',
                'steps' => ['Check that the server and node have enough free disk space.', 'Reinstall the server.', $retry],
            ],
            [
                'id' => 'steam_login', 'confidence' => 'high',
                'pattern' => '/FAILED (?:login|to log ?in)[^\n]*|Login Failure[^\n]*|Invalid Password[^\n]*|Two-factor code mismatch[^\n]*/i',
                'summary' => 'Logging in to Steam failed.',
                'cause' => 'The install used a Steam account whose login was rejected, so nothing was downloaded.',
                'steps' => ['Check the Steam username and password in the server\'s Startup settings.', 'Accounts with Steam Guard usually cannot be used here; use a separate account without it, or anonymous login if the game allows it.', 'Reinstall after fixing the login.'],
            ],
            [
                'id' => 'download', 'confidence' => 'high',
                'pattern' => '/curl: \(\d+\)[^\n]*|wget: [^\n]*(?:unable to resolve|failed)[^\n]*|ERROR \d{3}: [^\n]*/i',
                'summary' => 'A download failed during installation.',
                'cause' => 'The install script could not download a required file: the address may be wrong or moved, the site may be down, or the node may have no internet or DNS access.',
                'steps' => ['Reinstall once in case the site was briefly unavailable.', 'If it fails the same way, the download address in the egg may be out of date. An admin can use Egg check.', 'Ask the node admin to confirm the node can reach the internet.'],
            ],
            [
                'id' => 'archive', 'confidence' => 'high',
                'pattern' => '/gzip: [^\n]*not in gzip format|tar: [^\n]*(?:Error is not recoverable|Unexpected EOF)[^\n]*|unzip: +cannot find[^\n]*|End-of-central-directory signature not found[^\n]*/i',
                'summary' => 'A downloaded file could not be unpacked.',
                'cause' => 'An archive the install script downloaded was damaged or was not an archive at all, often because the download returned an error page.',
                'steps' => ['Reinstall to download it again.', 'If it keeps failing, the download address in the egg may be wrong. An admin can use Egg check.'],
            ],
            [
                'id' => 'disk', 'confidence' => 'high',
                'pattern' => '/No space left on device[^\n]*|Disk write failure[^\n]*|Not enough disk space[^\n]*/i',
                'summary' => 'The disk ran out of space during installation.',
                'cause' => 'The server\'s disk limit or the node\'s disk is full, so the files could not be written.',
                'steps' => ['Check the server\'s disk limit and the node\'s free space.', 'Free up space or raise the limit, then reinstall.'],
            ],
            [
                'id' => 'missing_file', 'confidence' => 'medium',
                'pattern' => "/(?:cp|mv): cannot stat '[^']+'[^\\n]*/",
                'summary' => 'A file the install expected was missing.',
                'cause' => 'The install script tried to copy or move a file that was not there, usually because an earlier download step failed.',
                'steps' => ['Look for an earlier error in the install log; fix that first.', 'Reinstall the server.', $retry],
            ],
            [
                'id' => 'command', 'confidence' => 'medium',
                'pattern' => '/^[^\n]*: command not found$|^[^\n]*: not found$/m',
                'summary' => 'The install container is missing a command the script needs.',
                'cause' => 'The install script calls a program that is not available in its install container.',
                'steps' => ['An admin can use Egg check, or change the egg\'s install container to one that includes the missing program.', 'Reinstall after the egg is fixed.'],
            ],
            [
                'id' => 'permission', 'confidence' => 'medium',
                'pattern' => '/^[^\n]*Permission denied[^\n]*$/m',
                'summary' => 'The install script was not allowed to access a file.',
                'cause' => 'A file or folder the install needs could not be read or written.',
                'steps' => ['Reinstall the server.', 'If it fails the same way, ask an admin to check the server\'s files and the egg\'s install script.'],
            ],
            [
                'id' => 'error', 'confidence' => 'low',
                'pattern' => '/^(?:ERROR|FATAL)\b[^\n]*$/m',
                'summary' => 'The install log contains errors.',
                'cause' => 'The install script printed an error. It may or may not have stopped the installation.',
                'steps' => ['Read the error lines below.', 'Reinstall the server if the game files look incomplete.', $retry],
            ],
        ];
    }
}
