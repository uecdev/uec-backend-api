<?php

namespace App\Integrations\Concerns;

use App\Integrations\IntegrationException;
use App\Models\IntegrationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared outbound HTTP behaviour for third-party clients:
 * timeouts, bounded retries on connection failures / 5xx, and an
 * integration_logs row per call (no request/response bodies, no secrets).
 */
trait LogsIntegrationCalls
{
    abstract protected function integrationName(): string;

    protected function http(int $timeout = 15, int $retries = 2): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout(5)
            ->retry($retries, 300, function (Throwable $e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError());
            }, throw: false);
    }

    /**
     * @param  callable(): Response  $send
     */
    protected function call(string $operation, string $method, string $endpoint, callable $send, ?string $correlationId = null): Response
    {
        $started = hrtime(true);
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            $this->writeLog($operation, $method, $endpoint, null, false, $started, $correlationId, 'Connection failed: '.$e->getMessage());
            throw new IntegrationException($this->integrationName(), 'Could not reach provider.', null, [], $e);
        }

        $ok = $response->successful();
        $this->writeLog($operation, $method, $endpoint, $response->status(), $ok, $started, $correlationId, $ok ? null : $this->summariseError($response));

        return $response;
    }

    private function summariseError(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json)
            ? ($json['message'] ?? $json['responseMessage'] ?? $json['error']['message'] ?? $json['error'] ?? null)
            : null;

        return mb_substr(is_string($message) ? $message : 'HTTP '.$response->status(), 0, 500);
    }

    private function writeLog(string $operation, string $method, string $endpoint, ?int $status, bool $ok, int $started, ?string $correlationId, ?string $error): void
    {
        try {
            IntegrationLog::query()->create([
                'integration' => $this->integrationName(),
                'operation' => $operation,
                'method' => $method,
                'endpoint' => mb_substr($endpoint, 0, 255),
                'status_code' => $status,
                'success' => $ok,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'correlation_id' => $correlationId,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            Log::error('integration_log.write_failed', ['integration' => $this->integrationName(), 'error' => $e->getMessage()]);
        }
    }
}
