<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Support;

final class RuleDiagnostics
{
    public static function diagnose(array $capture, array $context = []): array
    {
        $logs = self::logs($capture);
        $observations = is_array($capture['observations'] ?? null) ? $capture['observations'] : [];
        $status = strtolower((string) ($capture['observed_state'] ?? $capture['state'] ?? $capture['status'] ?? $context['status'] ?? ''));
        $outcome = strtolower((string) ($capture['outcome'] ?? ''));
        $existingCapture = ($observations['capture_mode'] ?? '') === 'existing';
        $historical = $existingCapture || self::isTrue($observations['logs_may_include_previous_run'] ?? false);
        $matches = [];
        foreach (self::rules() as $rule) {
            if (preg_match_all($rule['pattern'], $logs, $found, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($found[0] as [$value, $offset]) {
                    $line = self::lineAt($logs, $offset);
                    if ($rule['id'] === 'memory' && preg_match('/(?:(?:oom[_ -]?killed|out of memory)\s*[:=]\s*(?:false|0|no)\b|not (?:an? )?out of memory)/i', $line)) {
                        continue;
                    }
                    $matches[] = ['offset' => $offset, 'line' => $line, 'rule' => $rule];
                }
            }
        }
        usort($matches, static fn (array $left, array $right): int => $right['offset'] <=> $left['offset']);
        $latest = $matches[0] ?? null;

        if ($outcome === 'connection_error') {
            return self::report(
                'The startup check could not collect reliable live server data.',
                'A connection or authentication problem interrupted communication with Wings. This does not establish an application crash or explain a game startup failure.',
                'low',
                ['Capture outcome: connection_error'],
                ['Check that the Pelican console can connect to this node.', 'Ask the administrator to verify Wings availability, node connection settings, and websocket/proxy connectivity.', 'Repeat the diagnosis after the connection works so the report uses fresh startup output.'],
                ['Fresh console output and current server state after restoring the node connection.'],
            );
        }

        // A running process is useful evidence, but does not guarantee readiness.
        if ($outcome === 'running' || in_array($status, ['running', 'online'], true)) {
            return self::report(
                'The server process is currently running.',
                'A current startup failure is not established by the running state. Earlier log errors may belong to a previous attempt or a nonfatal problem.',
                'medium',
                ['Current server state: ' . ($status !== '' ? $status : 'running')],
                ['Check whether the server accepts connections on its assigned address and port.', 'If it is still unavailable, capture the latest console output and describe the connection error.'],
                ['Whether players or clients can connect, and the most recent error after the latest start.'],
            );
        }

        if (!$existingCapture && self::isTrue($observations['oom_killed'] ?? $capture['oom_killed'] ?? $capture['oomKilled'] ?? false)) {
            return self::memoryKilledReport();
        }

        $readyPattern = '/(?:Done \([\d.,]+s\)! For help|startup (?:complete|completed)|ready for connections|server (?:is ready|started successfully))/i';
        preg_match_all($readyPattern, $logs, $readyMatches, PREG_OFFSET_CAPTURE);
        $ready = end($readyMatches[0]);
        if (!$historical && !in_array($outcome, ['timeout', 'failed'], true)
            && !in_array($status, ['offline', 'stopped', 'stopping', 'crashed'], true)
            && $ready !== false && ($latest === null || $ready[1] > $latest['offset'])
            && !preg_match('/server marked as offline|process exited|shutting down|stopping server/i', substr($logs, $ready[1]))) {
            return self::report(
                'The available log contains a successful startup message.',
                'No recognized fatal startup error appears after the available readiness output. The current container state and connectivity still need to be checked.',
                'medium',
                [self::lineAt($logs, $ready[1])],
                ['Check the current server status and whether it accepts connections.', 'If it stopped later, capture the lines after the readiness message from the latest attempt.'],
                ['Current server state and any later shutdown or crash output.'],
            );
        }

        if ($latest !== null) {
            $rule = $latest['rule'];
            $steps = $rule['steps'];
            $java = $rule['id'] === 'java' ? self::requiredJava($latest['line']) : null;
            if ($java !== null) {
                array_unshift($steps, 'The reported error requires Java ' . $java . ' or newer. Select a compatible Java Docker image for this server.');
            }
            $fix = self::fixFor($rule['id'], $latest['line'], $java);
            if ($outcome === 'timeout') {
                return self::withFix(self::report(
                    'The startup check timed out; the final result is unconfirmed.',
                    ($historical ? 'Some captured output may be historical. ' : '') . 'The captured output suggests a possible problem: ' . $rule['cause'] . ' A timeout alone does not prove the server crashed.',
                    'low',
                    [($historical ? 'Existing log (may be historical): ' : '') . $latest['line'], 'Capture outcome: timeout'],
                    array_merge(['Check the current server state and collect any console lines produced after the capture ended.'], $steps),
                    ['The current server state and output after the timed-out capture.'],
                ), $fix);
            }
            if ($historical) {
                array_unshift($steps, 'Capture a fresh start attempt to confirm this existing-log error still occurs before changing files.');
                return self::withFix(self::report(
                    $outcome === 'failed' && !$existingCapture ? 'A startup failure was observed; its log cause needs confirmation.' : 'Existing console logs contain a possible startup issue.',
                    ($outcome === 'failed' && !$existingCapture ? 'The failed state was observed, but this log evidence may belong to a previous run. ' : 'This evidence may be historical and does not confirm a current failure. ') . 'The matched error suggests a possible cause: ' . $rule['cause'],
                    'medium',
                    ['Existing log (may be historical): ' . $latest['line']],
                    $steps,
                    ['Fresh startup output to confirm which error belongs to the current attempt.'],
                ), $fix);
            }
            return self::withFix(self::report($rule['summary'], $rule['cause'], $rule['confidence'], [$latest['line']], $steps, $rule['needs'] ?? []), $fix);
        }

        if ($outcome === 'timeout') {
            return self::report(
                'The startup check timed out before a final result was confirmed.',
                'The server may still be starting, waiting for input, stalled, or unreachable. The timeout alone does not identify a crash or its cause.',
                'low',
                ['Capture outcome: timeout'],
                ['Check the current state and console for output after the capture ended.', 'Check the application’s normal startup duration and whether it is waiting for an interactive prompt or a dependency.', 'Collect a longer fresh capture if it is still starting, and ask the administrator to inspect node errors if it is unreachable.'],
                ['Current server state and the latest console output after the capture timeout.'],
            );
        }

        $exitCode = $observations['exit_code'] ?? $capture['exit_code'] ?? $capture['exitCode'] ?? null;
        if ((string) $exitCode === '137' || preg_match('/exit(?:ed)?(?: with)?(?:\s+code|\s+status)?\s*[:=]?\s*137\b/i', $logs)) {
            return self::report(
                'The process exited with code 137; the cause is not confirmed.',
                'Exit code 137 usually indicates SIGKILL. It can result from an out-of-memory kill or an external forced stop, so it does not prove a memory shortage on its own.',
                'low',
                [$historical ? 'Existing log (may be historical): exit code 137' : 'Exit code: 137'],
                ['Check whether someone or a scheduled action forcibly stopped the server.', 'Ask the node administrator to check Docker OOMKilled and kernel OOM events for the same attempt.', 'Compare the server memory limit and startup memory flags before changing resources.'],
                ['The runtime OOMKilled flag or node logs at the failure time.'],
            );
        }

        return self::report(
            $logs === '' ? 'There is not enough startup output to identify a cause.' : 'The available output does not identify a definite startup cause.',
            ($historical ? 'Existing logs may be historical. ' : '') . 'No supported error signature was found. A stopped state or a generic nonzero exit code does not explain why the application failed.',
            'low',
            $exitCode !== null ? ['Reported exit code: ' . SecretRedactor::redact((string) $exitCode)] : [],
            ['Capture the console output from the latest failed start, including the first error and its surrounding lines.', 'Check the configured startup command, selected egg Docker image, resource limits, and required files against the application requirements.', 'If the container never launches or the node is unreachable, ask the administrator for Wings and container runtime errors at that time.'],
            ['The latest failed-start console output.', 'Application/game version, startup command, Docker image, and current server state.'],
        );
    }

    private static function logs(array $capture): string
    {
        foreach (['console', 'logs', 'log', 'output', 'content', 'console_output', 'console_lines'] as $field) {
            if (!array_key_exists($field, $capture)) {
                continue;
            }
            $value = $capture[$field];
            if (is_array($value)) {
                $lines = [];
                array_walk_recursive($value, static function ($line) use (&$lines): void {
                    if (is_string($line)) {
                        $lines[] = $line;
                    }
                });
                $value = implode("\n", $lines);
            }
            if (is_string($value) && trim($value) !== '') {
                return SecretRedactor::redact(substr($value, -60000));
            }
        }

        return '';
    }

    private static function lineAt(string $logs, int $offset): string
    {
        $start = strrpos(substr($logs, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($logs, "\n", $offset);
        $line = trim(substr($logs, $start, $end === false ? null : $end - $start));
        return function_exists('mb_substr') ? mb_substr($line, 0, 600, 'UTF-8') : substr($line, 0, 600);
    }

    private static function memoryKilledReport(): array
    {
        return self::report(
            'The container was reported as killed for exceeding memory.',
            'The runtime reports an out-of-memory kill. Check both the server memory allocation and memory pressure on the node.',
            'high',
            ['Runtime reported oom_killed: true'],
            ['Compare the server memory limit with its startup heap or memory flags. Leave room for native memory and other processes.', 'Ask the node administrator to check kernel or Docker OOM events and available node memory.', 'Adjust memory limits or application usage based on those findings, then retry once.'],
        );
    }

    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function report(string $summary, string $cause, string $confidence, array $evidence, array $steps, array $needs = []): array
    {
        return [
            'summary' => $summary,
            'likely_cause' => $cause,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'steps' => $steps,
            'needs_more_information' => $needs,
            'source' => 'rules',
        ];
    }

    /**
     * Only a few diagnoses map to a repair Pelican can apply safely, and only from
     * deterministic rule matches. AI output never selects or changes a repair.
     */
    private static function fixFor(string $rule, string $line, ?int $java): ?array
    {
        if ($rule === 'java') {
            return $java !== null ? ['type' => 'java_image', 'java' => $java] : ['type' => 'java_image'];
        }
        if ($rule === 'missing_files' || ($rule === 'jar' && preg_match('/unable to access jarfile|[^\s]+\.jar[^\r\n]{0,100}(?:no such file|not found)/i', $line))) {
            return ['type' => 'reinstall'];
        }

        return $rule === 'eula' ? ['type' => 'eula'] : null;
    }

    private static function requiredJava(string $line): ?int
    {
        if (preg_match('/class (?:file )?version\s+(\d+)(?:\.\d+)?/i', $line, $version) && (int) $version[1] >= 49) {
            return (int) $version[1] - 44;
        }
        if (preg_match('/requires running the server with Java (\d+) or above/i', $line, $version)) {
            return (int) $version[1];
        }

        return null;
    }

    private static function withFix(array $report, ?array $fix): array
    {
        if ($fix !== null) {
            $report['fix'] = $fix;
        }

        return $report;
    }

    private static function rules(): array
    {
        return [
            [
                'id' => 'jar', 'confidence' => 'high',
                'pattern' => '/unable to access jarfile|invalid or corrupt jarfile|could not find or load main class|[^\s]+\.jar[^\r\n]{0,100}(?:no such file|not found)/i',
                'summary' => 'The configured Java application cannot be loaded.',
                'cause' => 'The startup JAR or main class is missing, unreadable, invalid, or does not match the startup command.',
                'steps' => ['Check that the JAR named in the startup command or SERVER_JARFILE variable exists in the server files, with matching spelling and case.', 'Verify that it is the correct runnable server JAR for this application and that the container user can read it.', 'If the application uses a main class or classpath, compare those startup arguments with its documented launch command.'],
            ],
            [
                'id' => 'java', 'confidence' => 'high',
                'pattern' => '/UnsupportedClassVersionError|compiled by a more recent version of (?:the )?Java Runtime|class file version [\d.]+[^\r\n]*recognizes class file versions|requires running the server with Java \d+ or above/i',
                'summary' => 'The Java runtime is older than the application requires.',
                'cause' => 'The application or a dependency was compiled for a newer Java version than the container is running.',
                'steps' => ['Compare the application and mod/plugin Java requirements with the Java version provided by the selected Docker image.', 'Select a compatible Java image in Pelican and retry. Changing a Java version on the host alone does not change the container runtime.', 'Ensure mods or plugins also support the chosen application and Java versions.'],
            ],
            [
                'id' => 'port', 'confidence' => 'high',
                'pattern' => '/address already in use|EADDRINUSE|EADDRNOTAVAIL|cannot assign requested address|failed to bind (?:to )?(?:port|address)|bind(?:ing)?[^\r\n]{0,80}(?:failed|failure)/i',
                'summary' => 'The server cannot bind its configured address or port.',
                'cause' => 'The requested port is already in use, or the configured bind address is unavailable inside the container.',
                'steps' => ['Compare the application listen port with the assigned Pelican allocation and its startup variables.', 'Check for another server instance using the same port before changing allocations.', 'Verify the bind address is valid inside the container. Applications commonly listen on 0.0.0.0, but use the address format required by this application.'],
            ],
            [
                'id' => 'permission', 'confidence' => 'medium',
                'pattern' => '/permission denied|\bEACCES\b|\bEPERM\b|operation not permitted/i',
                'summary' => 'A required file or operation is blocked by permissions.',
                'cause' => 'The process cannot access a file, directory, port, or operation required during startup.',
                'steps' => ['Identify the path or operation named in the error and confirm it belongs to this startup attempt.', 'Ask the administrator to verify that the container user has the required ownership and access to that specific path.', 'For an executable, verify its execute permission and that the egg uses the expected working directory. Avoid granting broad write permissions.'],
            ],
            [
                'id' => 'memory', 'confidence' => 'high',
                'pattern' => '/OutOfMemoryError|JavaScript heap out of memory|out of memory|oom[-_ ]?kill(?:ed|er)?|killed process \d+/i',
                'summary' => 'The output reports a memory exhaustion condition.',
                'cause' => 'The application heap, container allocation, or node memory was exhausted. The error location determines which limit needs attention.',
                'steps' => ['Compare the startup heap or memory flags with the Pelican memory limit, leaving room for native memory and the runtime.', 'Review recent workload, world/modpack size, and memory-heavy plugins for unexpected usage.', 'Ask the administrator to check node memory and runtime OOM events before deciding whether to increase limits or reduce usage.'],
            ],
            [
                'id' => 'disk', 'confidence' => 'high',
                'pattern' => '/no space left on device|\bENOSPC\b|disk quota exceeded|\bEDQUOT\b/i',
                'summary' => 'The server cannot write because storage or a quota is full.',
                'cause' => 'Available disk space, filesystem inodes, or an enforced disk quota has been exhausted.',
                'steps' => ['Check the server disk quota and current usage in Pelican.', 'Ask the administrator to check node free space and inode availability.', 'Review large logs, backups, and application data; preserve needed files and adjust storage or remove only items you have confirmed are disposable.'],
            ],
            [
                'id' => 'dependencies', 'confidence' => 'high',
                'pattern' => '/ModuleNotFoundError|cannot find module|ERR_MODULE_NOT_FOUND|missing(?: or unsupported)? (?:mandatory|required) dependenc|UnknownDependencyException|incompatible mods found|mod resolution encountered an incompatible mod set|requires[^\r\n]{0,100}(?:which is missing|not installed)|NoClassDefFoundError/i',
                'summary' => 'A required module, mod, plugin, or dependency is unavailable.',
                'cause' => 'A dependency is missing or incompatible with the application or runtime version. A Java NoClassDefFoundError can also reflect a dependency that failed to initialize; inspect its preceding cause.',
                'steps' => ['Find the exact missing dependency and required version in the error and its preceding cause.', 'Check the application, loader, mod/plugin, and runtime versions are compatible.', 'Install or correct the named dependency using the application egg or package instructions, then capture a fresh startup attempt.'],
            ],
            [
                'id' => 'eula', 'confidence' => 'high',
                'pattern' => '/(?:need to|must) agree to (?:the )?EULA|eula\.txt[^\r\n]{0,100}(?:false|not accepted|not agreed)/i',
                'summary' => 'Startup is waiting for the application EULA to be accepted.',
                'cause' => 'The application requires the server owner to review and accept its end-user license agreement before it will start.',
                'steps' => ['Read the EULA referenced in the console output.', 'If you agree, use Pelican’s EULA prompt or update the application EULA setting as its instructions describe.', 'Start the server again and check the new output.'],
            ],
            [
                'id' => 'config', 'confidence' => 'medium',
                'pattern' => '/JSONDecodeError|TomlDecodeError|ScannerException|ParserException|mapping values are not allowed here|while parsing (?:a |the )?(?:block|flow)|unexpected token[^\r\n]{0,100}JSON|(?:failed|unable) to parse[^\r\n]{0,100}config|(?:configuration|config)[^\r\n]{0,100}(?:syntax error|parse error|invalid syntax)/i',
                'summary' => 'A configuration file contains invalid syntax.',
                'cause' => 'The application could not parse a required configuration file. The reported filename and line or column identify where to begin checking.',
                'steps' => ['Back up the specific configuration file before editing it.', 'Inspect the filename and line/column in the error for quoting, commas, indentation, or unsupported values.', 'Compare that section with configuration examples for the installed application version, correct it, and retry.'],
            ],
            [
                'id' => 'library', 'confidence' => 'high',
                'pattern' => '/error while loading shared libraries|cannot open shared object file|DllNotFoundException|lib[^\s]+\.so(?:\.\d+)*[^\r\n]{0,70}(?:not found|no such file)/i',
                'summary' => 'The executable is missing a required runtime library.',
                'cause' => 'The selected container image does not provide a required shared library, or the application binary is incompatible with its runtime environment.',
                'steps' => ['Identify the exact library and application binary named by the error.', 'Check that the egg selects the application’s supported Docker image and CPU architecture.', 'Ask the administrator or egg maintainer to supply the dependency in the container image. Installing a library only on the host does not supply it inside the container.'],
            ],
            [
                'id' => 'missing_files', 'confidence' => 'high',
                'pattern' => '/wine: (?:failed to open|cannot find) [^\r\n]{0,200}\.exe/i',
                'summary' => 'The game or application files are missing.',
                'cause' => 'The startup command points to a program file that is not in the server folder. The installation may not have finished, or the files were removed or renamed.',
                'steps' => ['Check the server Files page for the program named in the error, including its exact spelling and folder.', 'If it is missing, reinstall the server so its install script downloads the files again. Back up worlds and configuration first; a reinstall can overwrite files.', 'If the file exists under a different name or folder, correct the startup command or variables to match.'],
            ],
            [
                'id' => 'executable', 'confidence' => 'high',
                'pattern' => '/command not found|(?:\/bin\/(?:ba)?sh[^\r\n]*|exec[^\r\n]{0,100}|\/usr\/bin\/env[^\r\n]{0,100}|\.sh: line \d+[^\r\n]{0,100}):[^\r\n]{0,100}(?:not found|no such file or directory)|cannot execute[^\r\n]{0,100}(?:not found|no such file)/i',
                'summary' => 'The startup command refers to an unavailable executable.',
                'cause' => 'The executable path is incorrect, the file was not installed, or the selected container image lacks the required runtime or interpreter.',
                'steps' => ['Compare the startup executable path and working directory with the actual server files, including filename case.', 'Verify the selected egg Docker image includes the runtime or interpreter required by the startup command.', 'If a script exists but cannot execute, inspect its shebang and line endings, and confirm the named interpreter exists inside the container.'],
            ],
            [
                'id' => 'daemon', 'confidence' => 'medium',
                'pattern' => '/OCI runtime (?:create|exec) failed|exec format error|pull access denied|manifest[^\r\n]{0,100}(?:not found|unknown)|cannot connect to the Docker daemon|(?:failed|unable) to (?:create|start)[^\r\n]{0,100}(?:container|docker)|container[^\r\n]{0,100}(?:does not exist|not found)/i',
                'summary' => 'The failure may be in container startup rather than the application.',
                'cause' => 'The container runtime could not create or start the environment, or it could not use the selected image or executable.',
                'steps' => ['Ask the node administrator to inspect Wings and Docker errors at the exact failure time.', 'Verify the configured image exists, can be pulled with the configured credentials, and supports the node CPU architecture.', 'Check the egg startup executable and node runtime health before changing application files.'],
                'needs' => ['The Wings/container runtime error for the same startup attempt.'],
            ],
        ];
    }
}
