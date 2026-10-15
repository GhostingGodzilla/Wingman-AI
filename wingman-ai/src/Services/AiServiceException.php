<?php

declare(strict_types=1);

namespace Pelican\AiHelpAssistant\Services;

use RuntimeException;
use Throwable;

/**
 * A failed AI request, sorted into a reason people can act on. The provider's reply is
 * only inspected for known phrases and never kept: it can echo console text or credentials.
 */
class AiServiceException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $status = null)
    {
        parent::__construct(self::explain($reason, $status));
    }

    public static function fromResponse(int $status, string $body): self
    {
        return new self(match (true) {
            $status === 401, $status === 403 => 'key',
            $status === 400 && preg_match('/api[ _-]?key|API_KEY_INVALID|unauthenticated/i', $body) === 1 => 'key',
            $status === 400 && preg_match('/max_(?:completion_)?tokens/i', $body) === 1 => 'token_setting',
            $status === 404, $status === 400 && preg_match('/model\b[^"]{0,80}(?:not (?:found|supported|exist)|unknown|invalid|does not exist)/i', $body) === 1 => 'model',
            $status === 429 => 'limit',
            $status >= 500 => 'service',
            default => 'rejected',
        }, $status);
    }

    /** Connection problems and timeouts, or an already classified failure. */
    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        return new self(preg_match('/timed out|cURL error 28|timeout/i', $exception->getMessage()) === 1 ? 'timeout' : 'connection');
    }

    /** A message for people for any failure of an AI request. */
    public static function describe(Throwable $exception): string
    {
        return self::fromThrowable($exception)->getMessage();
    }

    private static function explain(string $reason, ?int $status): string
    {
        $code = $status !== null ? ' (HTTP ' . $status . ')' : '';

        return match ($reason) {
            'key' => 'The AI service rejected the API key' . $code . '. Check the key in Wingman AI settings.',
            'model' => 'The AI service does not recognize the model name' . $code . '. Check the model in Wingman AI settings.',
            'token_setting' => 'The AI service does not accept the output token setting' . $code . '. Switch "Output token setting" in Wingman AI settings.',
            'limit' => 'The AI service\'s request limit or free daily quota is used up' . $code . '. Wait a while and try again, or check your plan.',
            'service' => 'The AI service had a problem on its side' . $code . '. Try again later.',
            'timeout' => 'The AI service did not answer in time. Try again; if it keeps happening, choose a faster model.',
            'connection' => 'Wingman could not connect to the AI service. Check the API address in Wingman AI settings and the panel\'s internet access.',
            'empty' => 'The AI service returned an empty answer. Raise "Maximum AI output tokens" in Wingman AI settings.',
            'too_large' => 'The AI service returned an answer that was too large.',
            default => 'The AI service rejected the request' . $code . '. Check the model name and the output token setting in Wingman AI settings.',
        };
    }
}
