<?php

declare(strict_types=1);

require __DIR__ . '/framework-stubs.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pelican\\AiHelpAssistant\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Pelican\AiHelpAssistant\Services\AiClient;
use Pelican\AiHelpAssistant\Services\AiServiceException;
use Pelican\AiHelpAssistant\Services\AssistantSettings;
use Pelican\AiHelpAssistant\Services\DiagnosisService;

class MemorySettings extends AssistantSettings
{
    public function __construct(public array $values = []) {}

    public function get(): array
    {
        return array_replace(require dirname(__DIR__, 2) . '/config/wingman-ai.php', $this->values);
    }
}

class RecordingClient extends AiClient
{
    public array $inputs = [];
    public string $answer;
    public ?Throwable $failure = null;

    public function __construct()
    {
        $this->answer = json_encode([
            'summary' => 'The application could not open its server JAR.',
            'likely_cause' => 'The configured file is missing.',
            'confidence' => 'high',
            'evidence' => ['Error: Unable to access jarfile server.jar'],
            'steps' => ['Check the filename in Startup and Files.'],
            'needs_more_information' => [],
        ], JSON_THROW_ON_ERROR);
    }

    public function analyze(array $settings, array $input, ?string $instructions = null, ?int $maxTokens = null, int $timeout = 45): string
    {
        $this->inputs[] = $input;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->answer;
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectThrows(callable $action, string $class, ?int $status = null): Throwable
{
    try {
        $action();
    } catch (Throwable $error) {
        expect($error instanceof $class, 'Expected ' . $class . ', received ' . $error::class);
        if ($status !== null) {
            expect($error->status === $status, 'Unexpected HTTP status.');
        }

        return $error;
    }

    throw new RuntimeException('Expected ' . $class . ' was not thrown.');
}

function variable(string $name, ?string $value, bool $viewable = true, string $default = ''): object
{
    return (object) ['env_variable' => $name, 'server_value' => $value, 'default_value' => $default, 'user_viewable' => $viewable];
}

function fixture(array $settings = [], bool $startupRead = true): array
{
    $server = new Server();
    $server->boundVariables = [variable('SERVER_JARFILE', 'server.jar'), variable('CUSTOM_TOKEN', 'custom-private-value-918273', false)];
    $client = new RecordingClient();
    $service = new DiagnosisService(new MemorySettings(array_replace([
        'ai_enabled' => true, 'base_url' => 'https://ai.example.test/v1', 'model' => 'test-model', 'api_key' => 'provider-private-key-778899',
    ], $settings)), $client);
    $user = new User($startupRead ? ['websocket.connect', 'startup.read'] : ['websocket.connect']);

    return [$service, $server, $user, $client];
}

function capture(array $extra = []): array
{
    return array_replace(['logs' => 'Error: Unable to access jarfile server.jar', 'outcome' => 'failed', 'observed_state' => 'offline', 'observations' => []], $extra);
}

function settingsForm(array $extra = []): array
{
    $values = require dirname(__DIR__, 2) . '/config/wingman-ai.php';
    unset($values['max_log_bytes']);

    return array_replace($values, ['clear_api_key' => false], $extra);
}

$tests = [];
$tests['console permission and disabled plugin reject analysis before reading context'] = static function (): void {
    [$service, $server, , $client] = fixture();
    expectThrows(fn () => $service->diagnose($server, new User([]), capture()), IntegrationAbortException::class, 403);
    expect($server->relationQueries === 0 && $client->inputs === [], 'Unauthorized requests must do no context or AI work.');
    [$service, $server, $user, $client] = fixture(['enabled' => false]);
    expectThrows(fn () => $service->diagnose($server, $user, capture()), IntegrationAbortException::class, 403);
    expect($server->relationQueries === 0 && $client->inputs === [], 'Disabled plugin still performed work.');
};

$tests['startup permission limits outbound context while hidden variables still redact logs'] = static function (): void {
    [$service, $server, $user, $client] = fixture(startupRead: false);
    $service->diagnose($server, $user, capture(['logs' => 'custom-private-value-918273 printed by application']));
    $input = $client->inputs[0];
    foreach (['startup', 'docker_image', 'runtime_variables'] as $key) {
        expect(!array_key_exists($key, $input['context']), 'Startup-only data leaked without startup.read: ' . $key);
    }
    expect(!str_contains(json_encode($input), 'custom-private-value-918273'), 'Hidden variable leaked without startup.read.');
    expect($server->relationQueries === 1, 'Variables were not fetched through the bound server relation.');
};

$tests['each server gets only its own bound runtime variables'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $server->boundVariables = [variable('SERVER_JARFILE', 'first.jar')];
    $service->diagnose($server, $user, capture());
    $second = new Server();
    $second->id = 2;
    $second->boundVariables = [variable('SERVER_JARFILE', 'second.jar')];
    $service->diagnose($second, new User(['websocket.connect', 'startup.read'], 2), capture());
    expect($client->inputs[0]['context']['runtime_variables']['SERVER_JARFILE'] === 'first.jar', 'First server values were lost.');
    expect($client->inputs[1]['context']['runtime_variables']['SERVER_JARFILE'] === 'second.jar', 'Cross-server variable contamination.');
    expect($second->relationQueries === 1 && $server->relationQueries === 1, 'Variable access was not explicitly bound.');
};

$tests['known secrets never leave the panel in context logs or observations'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $server->startup = 'run --password=hardcoded-private-54321 --credential=custom-private-value-918273';
    $server->egg->name = 'Egg custom-private-value-918273';
    $server->image = 'image:custom-private-value-918273';
    $service->diagnose($server, $user, capture([
        'logs' => 'custom-private-value-918273 provider-private-key-778899',
        'observations' => ['message' => 'custom-private-value-918273'],
    ]));
    $input = json_encode($client->inputs[0]);
    foreach (['custom-private-value-918273', 'provider-private-key-778899', 'hardcoded-private-54321'] as $secret) {
        expect(!str_contains($input, $secret), 'Outbound payload leaked ' . $secret);
    }
    expect($client->inputs[0]['context']['runtime_variables']['SERVER_JARFILE'] === 'server.jar', 'Useful runtime selector was hidden.');
};

$tests['empty allowlisted variable cannot remove a preceding redaction secret'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $server->boundVariables = [variable('CUSTOM_TOKEN', 'regression-private-135790', false), variable('JAVA_VERSION', '')];
    $service->diagnose($server, $user, capture(['logs' => 'Application printed regression-private-135790']));
    expect(!str_contains(json_encode($client->inputs[0]), 'regression-private-135790'), 'An empty selector removed the previous secret.');
};

$tests['state and observation keys cannot bypass secret redaction'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $service->diagnose($server, $user, capture([
        'observed_state' => 'custom-private-value-918273',
        'observations' => ['custom-private-value-918273' => 'a value'],
    ]));
    expect(!str_contains(json_encode($client->inputs[0]), 'custom-private-value-918273'), 'Unredacted capture metadata leaked a secret.');
};

$tests['AI report remains valid and redacted when it echoes credential assignments'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $client->answer = json_encode([
        'summary' => 'password=custom-private-value-918273 caused an error',
        'likely_cause' => 'The console printed custom-private-value-918273.',
        'confidence' => 'high',
        'evidence' => ['api_key=provider-private-key-778899'],
        'steps' => ['Check credentials.'], 'needs_more_information' => [],
    ], JSON_THROW_ON_ERROR);
    $report = $service->diagnose($server, $user, capture());
    expect($report['source'] === 'ai', 'Redacting the JSON text corrupted a valid AI report.');
    expect(!str_contains(json_encode($report), 'custom-private-value-918273'), 'AI report leaked an egg secret.');
    expect(!str_contains(json_encode($report), 'provider-private-key-778899'), 'AI report leaked the provider key.');
};

$tests['AI report redacts known secrets before field length truncation'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $secret = 'unique-private-prefix-' . str_repeat('R', 100);
    $server->boundVariables = [variable('PRIVATE_VALUE', $secret, false)];
    $client->answer = json_encode([
        'summary' => str_repeat('a', 490) . $secret,
        'likely_cause' => 'A test failure.', 'confidence' => 'low',
        'evidence' => [], 'steps' => [], 'needs_more_information' => [],
    ], JSON_THROW_ON_ERROR);
    $report = $service->diagnose($server, $user, capture());
    expect($report['source'] === 'ai', 'A valid report was rejected.');
    expect(!str_contains($report['summary'], 'unique-pri'), 'Truncation exposed a prefix of a known secret.');
};

$tests['malformed and failed AI responses fall back without exposing provider errors'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    foreach (['not JSON', '{"summary":"Incomplete report"}', '["not a report"]'] as $raw) {
        $client->answer = $raw;
        $report = $service->diagnose($server, $user, capture());
        expect($report['source'] === 'rules' && str_contains($report['notice'], 'answer Wingman could not use. Basic error checks are shown instead.'), 'Malformed response did not fall back.');
    }
    $client->failure = new RuntimeException('Provider response includes provider-private-key-778899');
    $report = $service->diagnose($server, $user, capture());
    expect($report['source'] === 'rules', 'Provider exception did not fall back.');
    expect(!str_contains(json_encode($report), 'provider-private-key-778899'), 'Provider exception leaked its details.');
    $client->failure = AiServiceException::fromResponse(429, 'provider-private-key-778899');
    $report = $service->diagnose($server, $user, capture());
    expect($report['notice'] === 'No AI explanation: The AI service\'s request limit or free daily quota is used up (HTTP 429). Wait a while and try again, or check your plan. Basic error checks are shown instead.', 'The report must say why there is no AI explanation.');
};

$tests['disabled AI or missing connection uses basic checks without a provider request'] = static function (): void {
    foreach ([['ai_enabled' => false], ['base_url' => ''], ['model' => '']] as $settings) {
        [$service, $server, $user, $client] = fixture($settings);
        $report = $service->diagnose($server, $user, capture());
        expect($report['source'] === 'rules' && $client->inputs === [], 'Unconfigured AI attempted a request.');
        expect(str_contains(strtolower($report['likely_cause']), 'jar'), 'Basic checks missed a known JAR error.');
    }
};

$tests['running state takes precedence over a historical startup error'] = static function (): void {
    [$service, $server, $user] = fixture(['ai_enabled' => false]);
    $report = $service->diagnose($server, $user, capture(['outcome' => 'running', 'observed_state' => 'running']));
    expect(str_contains(strtolower($report['summary']), 'running'), 'Historical error was presented as a current running-server failure.');
};

$tests['settings save through encrypted private storage and never refill the API key'] = static function (): void {
    File::$files = [];
    File::$modes = [];
    $settings = new AssistantSettings();
    $settings->save(settingsForm(['api_key' => 'saved-private-key-2468']));
    expect(count(File::$files) === 1, 'Settings were not persisted.');
    $path = array_key_first(File::$files);
    expect(str_contains($path, 'app/private/wingman-ai/settings.enc'), 'Settings are outside private application storage.');
    expect(!str_contains(File::$files[$path], 'saved-private-key-2468'), 'Plaintext API key was written to storage.');
    expect(File::$modes[$path] === 0600 && File::$modes[dirname($path)] === 0700, 'Private file or directory mode was not requested.');
    expect($settings->get()['api_key'] === 'saved-private-key-2468', 'API key failed to round-trip.');
    expect($settings->formData()['api_key'] === '', 'Saved key was sent back to the browser.');
    $settings->save(settingsForm());
    expect($settings->get()['api_key'] === 'saved-private-key-2468', 'Blank form erased the saved key.');
    $settings->save(settingsForm(['api_key' => 'replacement-key-3579']));
    expect($settings->get()['api_key'] === 'replacement-key-3579', 'Replacement key was ignored.');
    $settings->save(settingsForm(['clear_api_key' => true]));
    expect($settings->get()['api_key'] === '', 'Explicit key removal was ignored.');
};

$tests['automatic protection defaults on and its disable and report limits survive settings saves'] = static function (): void {
    File::$files = [];
    $settings = new AssistantSettings();
    expect($settings->get()['automatic_enabled'] === true, 'New installations did not enable automatic protection.');
    $settings->save(settingsForm(['automatic_enabled' => false, 'auto_daily_limit' => '7', 'auto_cooldown_seconds' => '600']));
    $saved = $settings->get();
    expect($saved['automatic_enabled'] === false, 'Disabling automatic protection did not persist.');
    expect($saved['auto_daily_limit'] === 7 && $saved['auto_cooldown_seconds'] === 600, 'Automatic limits did not persist as integers.');
    expect($settings->formData()['automatic_enabled'] === false, 'The settings form did not preserve automatic protection preference.');
};

$tests['unreadable settings disable AI and require an administrator to save again'] = static function (): void {
    $path = storage_path('app/private/wingman-ai/settings.enc');
    File::$files = [$path => 'corrupted-encrypted-settings'];
    $settings = new AssistantSettings();
    expect($settings->get()['ai_enabled'] === false, 'Corrupted settings did not disable AI.');
    expect(isset($settings->get()['settings_error']), 'Corrupted settings had no recovery notice.');
    expect($settings->formData()['api_key'] === '', 'Corrupted settings exposed a key.');
};

$tests['endpoint settings permit local AI and reject credentials query fragments and other protocols'] = static function (): void {
    foreach (['http://127.0.0.1:11434/v1', 'http://localhost:11434/v1', 'https://ai.example.test/v1'] as $url) {
        AssistantSettings::validateBaseUrl($url);
    }
    foreach (['file:///private/key', 'ftp://ai.example.test/v1', 'https://user:password@ai.example.test/v1', 'https://user@ai.example.test/v1', 'https://ai.example.test/v1?key=secret', 'https://ai.example.test/v1#secret', '/relative/path'] as $url) {
        expectThrows(fn () => AssistantSettings::validateBaseUrl($url), ValidationException::class);
    }
    expectThrows(fn () => (new AssistantSettings())->save(settingsForm(['ai_enabled' => true])), ValidationException::class);
};

function httpResponse(bool $successful, string $body, mixed $content, int $status = 0): object
{
    return new class($successful, $body, $content, $status ?: ($successful ? 200 : 500)) {
        public function __construct(private bool $ok, private string $bodyValue, private mixed $content, private int $code) {}
        public function successful(): bool { return $this->ok; }
        public function status(): int { return $this->code; }
        public function body(): string { return $this->bodyValue; }
        public function json(string $key): mixed { return $key === 'choices.0.message.content' ? $this->content : null; }
    };
}

$tests['AI transport uses configured provider authentication token parameter and bounded nonredirecting request'] = static function (): void {
    Http::$response = httpResponse(true, '{"choices":[]}', 'valid answer');
    Http::$failure = null;
    $client = new AiClient();
    $settings = (new MemorySettings(['base_url' => 'http://localhost:11434/v1/', 'model' => 'local-model', 'api_key' => 'transport-secret-key', 'token_parameter' => 'max_tokens']))->get();
    expect($client->analyze($settings, ['capture' => 'safe evidence']) === 'valid answer', 'Response content was not extracted.');
    $methods = array_column(Http::$calls, 0);
    foreach (['connectTimeout', 'timeout', 'withOptions', 'withToken', 'post'] as $method) {
        expect(in_array($method, $methods, true), 'Missing transport setting: ' . $method);
    }
    $call = Http::$calls[array_search('post', $methods, true)];
    expect($call[1] === 'http://localhost:11434/v1/chat/completions', 'Incorrect provider endpoint.');
    expect(isset($call[2]['max_tokens']) && !isset($call[2]['max_completion_tokens']), 'Wrong configured output token parameter.');
    expect($call[2]['stream'] === false, 'Unexpected streaming request.');
    expect(!str_contains(json_encode($call[2]), 'transport-secret-key'), 'Provider key leaked into the AI prompt.');
    $options = Http::$calls[array_search('withOptions', $methods, true)][1];
    expect($options['allow_redirects'] === false, 'Redirects could forward credentials.');
    $settings['api_key'] = '';
    $settings['token_parameter'] = 'max_completion_tokens';
    $client->analyze($settings, []);
    $methods = array_column(Http::$calls, 0);
    expect(!in_array('withToken', $methods, true), 'Keyless local endpoint received authorization.');
    expect(isset(Http::$calls[array_search('post', $methods, true)][2]['max_completion_tokens']), 'OpenAI output token parameter ignored.');
};

$tests['AI transport explains failures by reason without exposing provider bodies'] = static function (): void {
    $settings = (new MemorySettings(['base_url' => 'https://ai.example.test/v1', 'model' => 'test']))->get();
    $client = new AiClient();
    $GLOBALS['integration_logs'] = [];
    foreach ([
        [httpResponse(false, 'provider-secret-body', null, 401), 'key', 'rejected the API key (HTTP 401)'],
        [httpResponse(false, '{"error":{"message":"API key not valid. provider-secret-body","status":"INVALID_ARGUMENT"}}', null, 400), 'key', 'rejected the API key (HTTP 400)'],
        [httpResponse(false, '{"error":"Unsupported parameter: max_tokens provider-secret-body"}', null, 400), 'token_setting', 'output token setting'],
        [httpResponse(false, 'provider-secret-body', null, 404), 'model', 'model name (HTTP 404)'],
        [httpResponse(false, 'provider-secret-body RESOURCE_EXHAUSTED', null, 429), 'limit', 'free daily quota is used up (HTTP 429)'],
        [httpResponse(false, 'provider-secret-body', null, 503), 'service', 'problem on its side (HTTP 503)'],
        [httpResponse(false, 'provider-secret-body', null, 422), 'rejected', 'rejected the request (HTTP 422)'],
        [httpResponse(true, '{}', null), 'empty', 'empty answer'],
        [httpResponse(true, '{}', ''), 'empty', 'Maximum AI output tokens'],
        [httpResponse(true, str_repeat('x', 1024 * 1024 + 1), 'answer'), 'too_large', 'too large'],
    ] as [$response, $reason, $message]) {
        Http::$response = $response;
        Http::$failure = null;
        $error = expectThrows(fn () => $client->analyze($settings, []), AiServiceException::class);
        expect($error->reason === $reason && str_contains($error->getMessage(), $message), 'Wrong reason for ' . $reason . ': ' . $error->getMessage());
        expect(!str_contains($error->getMessage(), 'provider-secret-body'), 'Raw provider body was exposed.');
    }
    foreach ([['cURL error 28: Operation timed out after 45001 milliseconds', 'timeout'], ['cURL error 6: Could not resolve host', 'connection']] as [$failure, $reason]) {
        Http::$failure = new RuntimeException($failure);
        $error = expectThrows(fn () => $client->analyze($settings, []), AiServiceException::class);
        expect($error->reason === $reason, 'Wrong reason for a connection failure: ' . $error->reason);
    }
    Http::$failure = null;
    expect(count($GLOBALS['integration_logs']) === 12 && $GLOBALS['integration_logs'][0][1] === ['reason' => 'key', 'status' => 401], 'Each failure must be logged with its reason and status only.');
    expect(!str_contains(json_encode($GLOBALS['integration_logs']), 'provider-secret-body'), 'The log must not contain provider bodies.');
    expect(AiServiceException::describe(new RuntimeException('connection reset')) === (new AiServiceException('connection'))->getMessage(), 'Unknown failures must read as connection problems.');
};


$tests['AI reports keep only the deterministic repair offer'] = static function (): void {
    $answer = static fn (array $fix): string => json_encode([
        'summary' => 'A model explanation.', 'likely_cause' => 'A model cause.', 'confidence' => 'high',
        'evidence' => [], 'steps' => ['A model step.'], 'needs_more_information' => [], 'fix' => $fix,
    ], JSON_THROW_ON_ERROR);
    [$service, $server, $user, $client] = fixture();
    $client->answer = $answer(['type' => 'eula']);
    $report = $service->diagnose($server, $user, capture());
    expect($report['source'] === 'ai' && ($report['fix'] ?? null) === ['type' => 'reinstall'], 'An AI report must carry the rule-based repair, not the model-supplied one.');
    [$service, $server, $user, $client] = fixture();
    $client->answer = $answer(['type' => 'reinstall']);
    $report = $service->diagnose($server, $user, capture(['logs' => 'Error: listen EADDRINUSE: address already in use 0.0.0.0:25565']));
    expect($report['source'] === 'ai' && !array_key_exists('fix', $report), 'A model must not be able to add a repair offer.');
};


$tests['ordinary hidden settings no longer garble logs while passwords stay masked'] = static function (): void {
    [$service, $server, $user, $client] = fixture();
    $server->boundVariables = [variable('XVFB', '1', false), variable('WINEPATH', '/home/container', false), variable('RCON_PASSWORD', 'rcon-secret-4455', false)];
    $service->diagnose($server, $user, capture(['logs' => "[21:49:10] :/home/container\$ xvfb-run wine ./Game.exe\nrcon login rcon-secret-4455\nwine: failed to open \"./Game.exe\""]));
    $sent = json_encode($client->inputs[0]);
    expect(str_contains($sent, '[21:49:10]') && str_contains($sent, ':\/home\/container$'), 'Ordinary hidden values garbled the log sent to AI.');
    expect(!str_contains($sent, 'rcon-secret-4455'), 'A password still reached the AI.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}
fwrite(STDOUT, count($tests) . ' integration scenarios, ' . $failures . ' failures.' . PHP_EOL);
exit($failures > 0 ? 1 : 0);
