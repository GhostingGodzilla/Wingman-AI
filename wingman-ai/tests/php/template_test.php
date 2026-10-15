<?php

declare(strict_types=1);

// Blade compiles "@php(...)" to "<?php(...", which PHP prints as plain text. Before a
// "@php ... @endphp" block it also swallows everything up to that block's end, so
// variables set there are never defined and the Console page fails with HTTP 500.
$checks = 0;
$template = file_get_contents(dirname(__DIR__, 2) . '/resources/views/widgets/wingman-ai.blade.php');
$check = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$check(!str_contains($template, '@php('), 'Use @php ... @endphp blocks; the inline @php(...) form breaks this template.');
$check(preg_match_all('/(?<!@)@php\b/', $template) === preg_match_all('/@endphp\b/', $template), 'Every @php block needs a matching @endphp.');
$check(substr_count($template, '@if') + substr_count($template, '@unless') >= substr_count($template, '@endif') + substr_count($template, '@endunless'), 'Unbalanced @if/@endif blocks.');

echo 'Template: ' . $checks . " checks passed.\n";
