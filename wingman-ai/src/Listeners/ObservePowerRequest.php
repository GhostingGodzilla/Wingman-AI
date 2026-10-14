<?php

namespace Pelican\AiHelpAssistant\Listeners;

use App\Models\Server;
use Illuminate\Http\Client\Events\RequestSending;
use Pelican\AiHelpAssistant\Services\AutomaticStartupService;
use Throwable;

/** Observes existing panel/API/schedule power requests; never issues one itself. */
class ObservePowerRequest
{
    public function handle(RequestSending $event): void
    {
        try {
            $request = $event->request;
            if ($request->method() !== 'POST') {
                return;
            }
            $data = $request->data();
            if (!is_array($data) || !in_array($data['action'] ?? null, ['start', 'restart'], true)) {
                return;
            }
            $url = $request->url();
            $parts = parse_url($url);
            if (!is_array($parts) || isset($parts['query']) || isset($parts['fragment'])
                || isset($parts['user']) || isset($parts['pass'])
                || !preg_match('~^/api/servers/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/power$~iD', $parts['path'] ?? '', $matches)) {
                return;
            }
            $server = Server::query()->where('uuid', $matches[1])->first();
            if (!$server instanceof Server) {
                return;
            }
            // Match the standard Http::daemon macro's exact destination and Bearer
            // credential. Similar paths on another host are not server start intent.
            $expectedUrl = rtrim($server->node->getConnectionAddress(), '/') . '/api/servers/' . $server->uuid . '/power';
            $authorization = $request->header('Authorization');
            $token = (string) $server->node->daemon_token;
            if ($url !== $expectedUrl || $token === '' || !is_array($authorization) || count($authorization) !== 1
                || !is_string($authorization[0]) || !hash_equals('Bearer ' . $token, $authorization[0])) {
                return;
            }

            // Only bounded intent state and job identifiers are retained. Headers,
            // bodies, credentials, responses, and error text are never persisted.
            app(AutomaticStartupService::class)->attempt($server, $data['action']);
        } catch (Throwable $exception) {
            // The event fires inside ordinary panel HTTP calls; observer failures
            // must not block the original start or reveal request credentials.
            logger()->warning('Wingman could not observe a power request.', ['exception_type' => $exception::class]);
        }
    }
}
