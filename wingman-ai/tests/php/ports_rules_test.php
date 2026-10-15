<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Support/GamePorts.php';

use Pelican\AiHelpAssistant\Support\GamePorts;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// What each game needs.
$ror = GamePorts::needs('Risk of Rain 2', ['QUERY' => '27016', 'STEAM' => '27017', 'SRCDS_APPID' => '1180760', 'PLAYERS' => '4', 'WINDOWS_INSTALL' => '1', 'STEAM_USER' => '']);
$check($ror === ['following' => 0, 'variables' => ['QUERY', 'STEAM'], 'private' => []], 'Risk of Rain 2 needs its query and Steam ports: ' . json_encode($ror));
$days = GamePorts::needs('7 Days To Die', ['TELNET_PORT' => '8081', 'MAX_PLAYERS' => '8']);
$check($days === ['following' => 3, 'variables' => [], 'private' => ['TELNET_PORT']], '7 Days To Die uses the next 3 ports and keeps telnet private: ' . json_encode($days));
$rust = GamePorts::needs('Rust', ['RCON_PORT' => '28016', 'APP_PORT' => '28082', 'QUERY_PORT' => '28017', 'WEB_PORT' => '8080', 'SERVER_PORT' => '28015']);
$check($rust['variables'] === ['APP_PORT', 'QUERY_PORT', 'SERVER_PORT'] && $rust['private'] === ['RCON_PORT', 'WEB_PORT'], 'Admin consoles must stay private: ' . json_encode($rust));
$check(GamePorts::needs('Minecraft', ['SERVER_JARFILE' => 'server.jar', 'STEAM_APPID' => '4020', 'BEACON_PASSWORD' => '1234', 'QUERY' => 'enabled', 'PEER_PORT' => '70000']) === ['following' => 0, 'variables' => [], 'private' => []], 'Non-port values, app IDs, passwords, and out-of-range numbers are not ports.');
$check(GamePorts::needs('Valheim BepINex', [])['following'] === 2 && GamePorts::needs('Arma 3', [])['following'] === 4, 'Known games must reserve the ports after their main port.');

// Planning from the forwarded block.
$plan = GamePorts::plan($ror, [], 27000, 27099);
$check($plan === ['main' => 27000, 'following' => [], 'variables' => ['QUERY' => 27001, 'STEAM' => 27002]], 'The first server gets the start of the block.');
$check(GamePorts::plan($days, [27000, 27001, 27002], 27000, 27099) === ['main' => 27003, 'following' => [27004, 27005, 27006], 'variables' => []], 'The next server gets the next free run.');
$check(GamePorts::plan($ror, [27001, 27005], 27000, 27010)['main'] === 27002, 'A run must skip past taken ports.');
$check(GamePorts::plan($ror, [27002], 27000, 27010)['main'] === 27003, 'A run must not overlap a taken port.');
$check(GamePorts::plan($ror, range(27000, 27097), 27000, 27099) === null, 'A full block has no room.');
$check(GamePorts::plan(['following' => 0, 'variables' => []], range(27000, 27098), 27000, 27099) === ['main' => 27099, 'following' => [], 'variables' => []], 'The last port of the block can be used.');

echo 'Port rules: ' . $checks . " checks passed.\n";
